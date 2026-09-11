<?php

namespace Joelseneque\AiPages\Anthropic;

class Response
{
    public function __construct(
        protected array $payload,
        public readonly ?string $requestId = null,
    ) {}

    public function raw(): array
    {
        return $this->payload;
    }

    public function stopReason(): ?string
    {
        return $this->payload['stop_reason'] ?? null;
    }

    public function model(): ?string
    {
        return $this->payload['model'] ?? null;
    }

    /**
     * Concatenated text of every text block in the response.
     */
    public function text(): string
    {
        return collect($this->payload['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');
    }

    /**
     * The input of the first tool_use block, optionally filtered by tool name.
     * This is how we get guaranteed-shape structured output.
     */
    public function toolInput(?string $name = null): ?array
    {
        foreach ($this->payload['content'] ?? [] as $block) {
            if (($block['type'] ?? null) !== 'tool_use') {
                continue;
            }

            if ($name !== null && ($block['name'] ?? null) !== $name) {
                continue;
            }

            return $block['input'] ?? [];
        }

        return null;
    }

    public function usage(): array
    {
        $usage = $this->payload['usage'] ?? [];

        return [
            'input_tokens' => $usage['input_tokens'] ?? 0,
            'output_tokens' => $usage['output_tokens'] ?? 0,
            'cache_creation_input_tokens' => $usage['cache_creation_input_tokens'] ?? 0,
            'cache_read_input_tokens' => $usage['cache_read_input_tokens'] ?? 0,
        ];
    }

    public function wasTruncated(): bool
    {
        return $this->stopReason() === 'max_tokens';
    }
}
