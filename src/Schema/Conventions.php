<?php

namespace Joelseneque\AiPages\Schema;

use Illuminate\Support\Facades\File;

/**
 * The measured conventions for a site, cached to disk.
 *
 * Written by the sweep, read on every build. Keeping it as a separate artefact
 * from the human-readable guide means the generator gets exact values while the
 * editor gets prose, and neither has to be parsed out of the other.
 */
class Conventions
{
    protected array $data = [];

    protected bool $loaded = false;

    public function __construct(protected ?string $path = null)
    {
        $this->path = ($path ?: config('ai-pages.storage_path')).'/conventions.json';
    }

    public function put(string $collection, array $analysis): void
    {
        $this->load();

        $this->data[$collection] = [
            'measured_at' => now()->toIso8601String(),
            'entries_analysed' => $analysis['entries_analysed'] ?? 0,
            'node_attrs' => $analysis['default_attrs'] ?? [],
            'field_defaults' => $analysis['field_defaults'] ?? [],
            'set_placements' => $analysis['set_placements'] ?? [],
            'field_values' => $analysis['set_field_values'] ?? [],
        ];

        File::ensureDirectoryExists(dirname($this->path));
        File::put($this->path, json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function forCollection(?string $collection): array
    {
        $this->load();

        return $collection ? ($this->data[$collection] ?? []) : [];
    }

    public function measuredAt(string $collection): ?string
    {
        return $this->forCollection($collection)['measured_at'] ?? null;
    }

    /**
     * Attrs for a node type in a given field context, e.g. context
     * `section_content.content`, node `paragraph`.
     */
    public function nodeAttrs(?string $collection, ?string $context, string $nodeType): array
    {
        $all = $this->forCollection($collection)['node_attrs'] ?? [];

        if ($context && isset($all[$context][$nodeType])) {
            return $all[$context][$nodeType];
        }

        // Fall back to the commonest usage of this node type anywhere.
        $candidates = [];

        foreach ($all as $attrs) {
            if (isset($attrs[$nodeType])) {
                $candidates[] = $attrs[$nodeType];
            }
        }

        return $candidates ? $this->mostCommon($candidates) : [];
    }

    /**
     * Values the site fills in so consistently they don't need generating.
     */
    public function fieldDefaults(?string $collection, string $setHandle): array
    {
        return $this->forCollection($collection)['field_defaults'][$setHandle] ?? [];
    }

    /**
     * The short scalar values this site actually stores for a set's fields.
     * Ground truth beats the blueprint when the two disagree — see
     * JsonSchemaBuilder::enumOrString.
     */
    public function fieldValues(?string $collection, string $setHandle): array
    {
        return $this->forCollection($collection)['field_values'][$setHandle] ?? [];
    }

    /**
     * Where a set is actually used, for telling the planner what's plausible.
     */
    public function placements(?string $collection, string $setHandle): array
    {
        return $this->forCollection($collection)['set_placements'][$setHandle] ?? [];
    }

    public function isEmpty(?string $collection): bool
    {
        return $this->forCollection($collection) === [];
    }

    protected function mostCommon(array $candidates): array
    {
        $counts = [];

        foreach ($candidates as $attrs) {
            $counts[json_encode($attrs)] = ($counts[json_encode($attrs)] ?? 0) + 1;
        }

        arsort($counts);

        return json_decode((string) array_key_first($counts), true) ?: [];
    }

    protected function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;
        $this->data = File::exists($this->path)
            ? (json_decode(File::get($this->path), true) ?: [])
            : [];
    }
}
