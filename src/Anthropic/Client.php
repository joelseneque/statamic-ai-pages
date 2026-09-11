<?php

namespace Joelseneque\AiPages\Anthropic;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Client
{
    protected array $usage = [
        'input_tokens' => 0,
        'output_tokens' => 0,
        'cache_creation_input_tokens' => 0,
        'cache_read_input_tokens' => 0,
        'calls' => 0,
    ];

    public function __construct(protected array $config = [])
    {
        $this->config = array_merge(config('ai-pages'), $config);
    }

    public function configured(): bool
    {
        return ! empty($this->config['api_key']);
    }

    public function model(): string
    {
        return $this->config['model'];
    }

    public function fastModel(): string
    {
        return $this->config['fast_model'] ?: $this->config['model'];
    }

    /**
     * Ask the model for free-form text.
     *
     * @param  string|array  $system  A system prompt, or an array of system content blocks.
     * @param  array  $messages  Anthropic message array.
     */
    public function text(string|array $system, array $messages, array $options = []): Response
    {
        return $this->messages(array_merge([
            'system' => $system,
            'messages' => $messages,
        ], $options));
    }

    /**
     * Ask the model to fill in a JSON Schema, and get back a guaranteed shape.
     *
     * This is the workhorse: rather than begging for JSON in prose we define a
     * single tool whose input_schema is the shape we want, then force its use.
     */
    public function structured(
        string|array $system,
        array $messages,
        string $toolName,
        string $toolDescription,
        array $schema,
        array $options = []
    ): array {
        $response = $this->messages(array_merge([
            'system' => $system,
            'messages' => $messages,
            'tools' => [[
                'name' => $toolName,
                'description' => $toolDescription,
                'input_schema' => $schema,
            ]],
            'tool_choice' => ['type' => 'tool', 'name' => $toolName],
        ], $options));

        $input = $response->toolInput($toolName);

        if ($input === null) {
            throw new AnthropicException(
                "The model returned no structured output for [{$toolName}]. "
                .($response->wasTruncated()
                    ? 'The response hit the token ceiling — try a smaller scope or raise ai-pages.max_tokens.'
                    : 'Response text: '.Str::limit($response->text(), 300)),
                requestId: $response->requestId,
            );
        }

        return $input;
    }

    /**
     * Raw Messages API call, with retries and usage accounting.
     */
    public function messages(array $params): Response
    {
        if (! $this->configured()) {
            throw new AnthropicException('No Anthropic API key configured. Set ANTHROPIC_API_KEY in your .env.');
        }

        $payload = array_filter([
            'model' => $params['model'] ?? $this->config['model'],
            'max_tokens' => $params['max_tokens'] ?? $this->config['max_tokens'],
            'temperature' => $params['temperature'] ?? null,
            'system' => $this->normaliseSystem($params['system'] ?? null),
            'messages' => $this->normaliseMessages($params['messages'] ?? []),
            'tools' => $params['tools'] ?? null,
            'tool_choice' => $params['tool_choice'] ?? null,
        ], fn ($v) => $v !== null);

        $attempt = 0;
        $max = max(1, (int) $this->config['retries']);

        beginning:
        $attempt++;

        $response = Http::withHeaders([
            'x-api-key' => $this->config['api_key'],
            'anthropic-version' => $this->config['api_version'],
            'content-type' => 'application/json',
        ])
            ->timeout($this->config['timeout'])
            ->post(rtrim($this->config['base_url'], '/').'/v1/messages', $payload);

        $requestId = $response->header('request-id') ?: null;

        if ($response->failed()) {
            $error = AnthropicException::fromResponse($response->status(), $response->json() ?? [], $requestId);

            Log::warning('[ai-pages] Anthropic request failed', [
                'status' => $response->status(),
                'attempt' => $attempt,
                'request_id' => $requestId,
                'body' => Str::limit($response->body(), 800),
            ]);

            if ($error->isRetryable() && $attempt < $max) {
                $wait = (int) ($response->header('retry-after') ?: 0);
                usleep((($wait > 0 ? $wait : 2 ** $attempt) * 1_000_000));
                goto beginning;
            }

            throw $error;
        }

        $result = new Response($response->json(), $requestId);

        $this->recordUsage($result);

        return $result;
    }

    public function usage(): array
    {
        return $this->usage;
    }

    /**
     * Rough spend estimate. Anthropic pricing changes, so this is indicative
     * only and deliberately kept out of the way of anything load-bearing.
     */
    public function estimatedCost(): float
    {
        [$in, $out] = $this->rates();

        return round(
            ($this->usage['input_tokens'] / 1_000_000 * $in)
            + ($this->usage['cache_read_input_tokens'] / 1_000_000 * $in * 0.1)
            + ($this->usage['cache_creation_input_tokens'] / 1_000_000 * $in * 1.25)
            + ($this->usage['output_tokens'] / 1_000_000 * $out),
            4
        );
    }

    protected function rates(): array
    {
        $model = $this->config['model'];

        return match (true) {
            str_contains($model, 'haiku') => [1.0, 5.0],
            str_contains($model, 'opus') => [5.0, 25.0],
            default => [3.0, 15.0],
        };
    }

    protected function recordUsage(Response $response): void
    {
        foreach ($response->usage() as $key => $value) {
            $this->usage[$key] += $value;
        }

        $this->usage['calls']++;
    }

    /**
     * System prompts are passed as blocks so the big, stable ones (schema,
     * instructions) can be marked cacheable.
     */
    protected function normaliseSystem(string|array|null $system): array|string|null
    {
        if ($system === null || $system === '') {
            return null;
        }

        if (is_string($system)) {
            return $system;
        }

        $caching = (bool) $this->config['prompt_caching'];

        return collect($system)
            ->filter(fn ($block) => is_array($block) ? ! empty($block['text']) : filled($block))
            ->map(function ($block) use ($caching) {
                $block = is_string($block) ? ['type' => 'text', 'text' => $block] : $block;
                $block['type'] ??= 'text';

                if (! $caching) {
                    unset($block['cache_control']);
                }

                return $block;
            })
            ->values()
            ->all();
    }

    protected function normaliseMessages(array $messages): array
    {
        return collect($messages)->map(function ($message) {
            if (is_string($message)) {
                return ['role' => 'user', 'content' => $message];
            }

            return $message;
        })->values()->all();
    }
}
