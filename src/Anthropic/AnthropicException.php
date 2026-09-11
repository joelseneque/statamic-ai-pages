<?php

namespace Joelseneque\AiPages\Anthropic;

use RuntimeException;

class AnthropicException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $type = null,
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message, $status ?? 0);
    }

    public static function fromResponse(int $status, array $body, ?string $requestId): self
    {
        $type = data_get($body, 'error.type');
        $message = data_get($body, 'error.message', 'Unknown error from the Anthropic API.');

        $friendly = match (true) {
            $status === 401 => 'Anthropic rejected the API key. Check ANTHROPIC_API_KEY in your .env.',
            $status === 403 => 'This API key is not permitted to use that model. Check ANTHROPIC_MODEL.',
            $status === 404 => 'Unknown model. Check ANTHROPIC_MODEL in your .env.',
            $status === 413 => 'The request was too large. Try fewer or smaller source documents.',
            $status === 429 => 'Anthropic rate limit reached. Give it a minute and try again.',
            $status >= 500 => 'Anthropic had a server error. Try again shortly.',
            default => $message,
        };

        return new self($friendly.($friendly === $message ? '' : " ({$message})"), $status, $type, $requestId);
    }

    public function isRetryable(): bool
    {
        return $this->status === 429 || $this->status === 408 || ($this->status !== null && $this->status >= 500);
    }
}
