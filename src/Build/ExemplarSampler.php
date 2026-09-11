<?php

namespace Joelseneque\AiPages\Build;

use Illuminate\Support\Arr;
use Statamic\Facades\Entry;
use Statamic\Facades\YAML;

/**
 * Pulls real, stored examples of a block out of the site's own entries.
 *
 * A single worked example from the site does more for output quality than any
 * amount of prose instruction — it shows the model the actual field values, the
 * house phrasing and the level of detail, all at once.
 */
class ExemplarSampler
{
    protected array $cache = [];

    public function __construct(protected int $perSet = 2) {}

    /**
     * @return array<array> Raw `values` arrays for the given set handle.
     */
    public function forSet(string $collection, string $setHandle, ?string $site = null): array
    {
        $key = "{$collection}:{$setHandle}:{$site}";

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $found = [];

        foreach ($this->entries($collection, $site) as $entry) {
            $this->collect($entry->data()->all(), $setHandle, $found);

            if (count($found) >= $this->perSet * 3) {
                break;
            }
        }

        // Prefer the fullest examples — they show the most fields in use.
        usort($found, fn ($a, $b) => strlen(json_encode($b)) <=> strlen(json_encode($a)));

        return $this->cache[$key] = array_slice($found, 0, $this->perSet);
    }

    public function toYaml(array $exemplars): string
    {
        if (! $exemplars) {
            return '';
        }

        return collect($exemplars)
            ->map(fn ($values) => "```yaml\n".trim(YAML::dump($this->clean($values)))."\n```")
            ->implode("\n\n");
    }

    /**
     * A whole entry, rendered as an example page structure.
     */
    public function pageOutline(string $collection, ?string $site = null, int $take = 2): string
    {
        $out = [];

        foreach ($this->entries($collection, $site)->take($take * 4) as $entry) {
            $sequence = [];
            $this->collectSequence($entry->data()->all(), $sequence);

            if (count($sequence) < 3) {
                continue;
            }

            $out[] = '- **'.$entry->get('title').'**: '.implode(' → ', array_slice($sequence, 0, 15));

            if (count($out) >= $take) {
                break;
            }
        }

        return implode("\n", $out);
    }

    protected function entries(string $collection, ?string $site)
    {
        return Entry::query()
            ->where('collection', $collection)
            ->when($site, fn ($q) => $q->where('site', $site))
            ->whereStatus('published')
            ->limit(120)
            ->get();
    }

    protected function collect(mixed $value, string $setHandle, array &$found, int $depth = 0): void
    {
        if ($depth > 14 || ! is_array($value)) {
            return;
        }

        if (($value['type'] ?? null) === 'set' && Arr::get($value, 'attrs.values.type') === $setHandle) {
            $found[] = Arr::get($value, 'attrs.values');
        } elseif (($value['type'] ?? null) === $setHandle && isset($value['id'])) {
            $found[] = $value;
        }

        foreach ($value as $child) {
            if (is_array($child)) {
                $this->collect($child, $setHandle, $found, $depth + 1);
            }
        }
    }

    protected function collectSequence(mixed $value, array &$sequence, int $depth = 0): void
    {
        if ($depth > 14 || ! is_array($value)) {
            return;
        }

        if (($value['type'] ?? null) === 'set' && $type = Arr::get($value, 'attrs.values.type')) {
            $sequence[] = $type;
        }

        foreach ($value as $child) {
            if (is_array($child)) {
                $this->collectSequence($child, $sequence, $depth + 1);
            }
        }
    }

    /**
     * Strip ids and trim long rich text — the shape matters, not the volume.
     */
    protected function clean(mixed $value, int $depth = 0): mixed
    {
        if (! is_array($value) || $depth > 8) {
            return $value;
        }

        $out = [];

        foreach ($value as $key => $child) {
            if ($key === 'id') {
                continue;
            }

            $out[$key] = is_array($child) ? $this->clean($child, $depth + 1) : $child;
        }

        return $out;
    }
}
