<?php

namespace Joelseneque\AiPages\Sources;

/**
 * One piece of source material, already converted into Anthropic content
 * blocks. A source may be text, a native PDF block, or an image block.
 */
class Source
{
    public function __construct(
        public readonly string $kind,
        public readonly string $label,
        public readonly array $blocks,
        public readonly string $plain = '',
        public readonly array $meta = [],
    ) {}

    public static function text(string $label, string $text, string $kind = 'text', array $meta = []): self
    {
        return new self($kind, $label, [[
            'type' => 'text',
            'text' => $text,
        ]], $text, $meta);
    }

    public static function document(string $label, string $base64, string $mediaType, array $meta = []): self
    {
        return new self('document', $label, [[
            'type' => 'document',
            'source' => ['type' => 'base64', 'media_type' => $mediaType, 'data' => $base64],
        ]], '', $meta);
    }

    public static function image(string $label, string $base64, string $mediaType, array $meta = []): self
    {
        return new self('image', $label, [[
            'type' => 'image',
            'source' => ['type' => 'base64', 'media_type' => $mediaType, 'data' => $base64],
        ]], '', $meta);
    }

    public function withPlain(string $plain): self
    {
        return new self($this->kind, $this->label, $this->blocks, $plain, $this->meta);
    }

    public function needsTranscription(): bool
    {
        return $this->plain === '' && in_array($this->kind, ['document', 'image'], true);
    }
}
