<?php

namespace Joelseneque\AiPages\Build;

class BuildResult
{
    public function __construct(
        public array $outline = [],
        public array $sections = [],
        public array $data = [],
        public array $companions = [],
        public array $warnings = [],
        public array $fidelity = [],
        public array $usage = [],
        public float $cost = 0.0,
        public ?string $entryId = null,
        public ?string $entryUrl = null,
        public ?string $contentField = null,
    ) {}

    public function toArray(): array
    {
        return [
            'outline' => $this->outline,
            'sections' => array_map(fn ($s) => [
                'set' => $s['set'] ?? null,
                'heading' => $s['heading'] ?? null,
                'brief' => $s['brief'] ?? null,
            ], $this->sections),
            'companions' => $this->companions,
            'warnings' => $this->warnings,
            'fidelity' => $this->fidelity,
            'usage' => $this->usage,
            'cost' => $this->cost,
            'entry_id' => $this->entryId,
            'entry_url' => $this->entryUrl,
            'content_field' => $this->contentField,
        ];
    }
}
