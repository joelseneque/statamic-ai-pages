<?php

namespace Joelseneque\AiPages\Schema;

use Illuminate\Support\Arr;
use Statamic\Facades\Entry;

/**
 * Learns how a particular site actually writes its content, by reading it.
 *
 * Two sites with identical fieldsets store very different things. One stamps
 * `{textAlign: left, class: p2}` on paragraphs inside columns but leaves
 * paragraphs inside boxes bare; another does neither. Guessing wrong produces
 * content that looks subtly broken next to the real pages, so we measure it.
 *
 * Everything here is keyed by CONTEXT — the field path the node was found in —
 * because that is the level at which these conventions actually vary.
 */
class BardConventionDetector
{
    /** [context][nodeType][attr][value] => count */
    protected array $nodeAttrs = [];

    /** [context][nodeType] => count */
    protected array $nodeCounts = [];

    protected array $setCounts = [];

    /** [setHandle][context] => count */
    protected array $setPlacements = [];

    /** [setHandle][field][value] => count */
    protected array $setFieldValues = [];

    /** [setHandle] => count of times the set appeared */
    protected array $setTotals = [];

    protected array $sequences = [];

    public function analyse(string $collection, ?string $site = null, int $limit = 150): array
    {
        $entries = Entry::query()
            ->where('collection', $collection)
            ->when($site, fn ($q) => $q->where('site', $site))
            ->limit($limit)
            ->get();

        foreach ($entries as $entry) {
            foreach ($entry->data()->all() as $handle => $value) {
                if (is_array($value)) {
                    $this->walk($value, $handle);
                }
            }

            $sequence = [];
            $this->topLevelSequence($entry->data()->all(), $sequence);

            if (count($sequence) > 1) {
                $this->sequences[] = $sequence;
            }
        }

        return [
            'entries_analysed' => $entries->count(),
            'node_counts' => $this->nodeCounts,
            'node_attrs' => $this->summariseAttrs(),
            'default_attrs' => $this->defaultAttrs(),
            'set_counts' => $this->sorted($this->setCounts),
            'set_placements' => $this->setPlacements,
            'set_field_values' => $this->summariseFieldValues(),
            'field_defaults' => $this->fieldDefaults(),
            'common_sequences' => $this->commonSequences(),
        ];
    }

    /**
     * Node attrs to stamp on newly generated prose, keyed by context.
     *
     * A value has to hold for a clear majority of that node type in that
     * context before it becomes a default — a convention followed half the
     * time isn't one.
     */
    public function defaultAttrs(float $threshold = 0.6, int $minimum = 4): array
    {
        $defaults = [];

        foreach ($this->nodeAttrs as $context => $nodes) {
            foreach ($nodes as $nodeType => $attrs) {
                $total = $this->nodeCounts[$context][$nodeType] ?? 0;

                if ($total < $minimum) {
                    continue;
                }

                foreach ($attrs as $attr => $values) {
                    // Per-node properties, never a site-wide default.
                    if (in_array($attr, ['level', 'id', 'start'], true)) {
                        continue;
                    }

                    arsort($values);
                    $top = array_key_first($values);

                    if ($values[$top] / $total >= $threshold) {
                        $defaults[$context][$nodeType][$attr] = $this->decode($top);
                    }
                }
            }
        }

        return $defaults;
    }

    /**
     * Field values this site fills in so consistently that we can supply them
     * ourselves rather than spending tokens asking the model to guess.
     *
     * This is where all the layout boilerplate lives — container widths,
     * margins, animation toggles — none of which a content model should be
     * reasoning about in the first place.
     */
    public function fieldDefaults(float $threshold = 0.8, int $minimum = 4): array
    {
        $defaults = [];

        foreach ($this->setFieldValues as $setHandle => $fields) {
            $total = $this->setTotals[$setHandle] ?? 0;

            if ($total < $minimum) {
                continue;
            }

            foreach ($fields as $handle => $values) {
                arsort($values);
                $top = array_key_first($values);

                // Only when the field is both near-universal and near-constant.
                if (array_sum($values) / $total >= $threshold && $values[$top] / $total >= $threshold) {
                    $defaults[$setHandle][$handle] = $this->decode($top);
                }
            }
        }

        return $defaults;
    }

    /**
     * Walk a value tree, tracking the field path we're inside.
     */
    protected function walk(mixed $value, string $context, int $depth = 0): void
    {
        if ($depth > 16 || ! is_array($value)) {
            return;
        }

        $type = $value['type'] ?? null;

        // A Bard set node.
        if ($type === 'set' && $setType = Arr::get($value, 'attrs.values.type')) {
            $this->recordSet($setType, $context, Arr::get($value, 'attrs.values', []));

            foreach (Arr::get($value, 'attrs.values', []) as $field => $child) {
                if (is_array($child)) {
                    $this->walk($child, "{$setType}.{$field}", $depth + 1);
                }
            }

            return;
        }

        // A replicator row.
        if (is_string($type) && isset($value['id']) && ! isset($value['content'], $value['text'])) {
            $this->recordSet($type, $context, $value);

            foreach ($value as $field => $child) {
                if (is_array($child) && ! in_array($field, ['id', 'type', 'enabled'], true)) {
                    $this->walk($child, "{$type}.{$field}", $depth + 1);
                }
            }

            return;
        }

        // A ProseMirror node.
        if (is_string($type) && (isset($value['content']) || isset($value['attrs']) || isset($value['text']))) {
            $this->nodeCounts[$context][$type] = ($this->nodeCounts[$context][$type] ?? 0) + 1;

            foreach ($value['attrs'] ?? [] as $attr => $attrValue) {
                if (is_array($attrValue)) {
                    continue;
                }

                $key = $this->encode($attrValue);
                $this->nodeAttrs[$context][$type][$attr][$key] = ($this->nodeAttrs[$context][$type][$attr][$key] ?? 0) + 1;
            }

            foreach ($value['content'] ?? [] as $child) {
                if (is_array($child)) {
                    $this->walk($child, $context, $depth + 1);
                }
            }

            return;
        }

        foreach ($value as $key => $child) {
            if (! is_array($child)) {
                continue;
            }

            // A named sub-field extends the context; a list index doesn't.
            $this->walk($child, is_int($key) ? $context : "{$context}.{$key}", $depth + 1);
        }
    }

    protected function recordSet(string $handle, string $context, array $values): void
    {
        $this->setCounts[$handle] = ($this->setCounts[$handle] ?? 0) + 1;
        $this->setTotals[$handle] = ($this->setTotals[$handle] ?? 0) + 1;
        $this->setPlacements[$handle][$context] = ($this->setPlacements[$handle][$context] ?? 0) + 1;

        foreach ($values as $field => $value) {
            if (in_array($field, ['id', 'type', 'enabled'], true)) {
                continue;
            }

            if (! is_string($value) && ! is_bool($value) && ! is_int($value)) {
                continue;
            }

            // Long or sentence-like values are copy, not settings.
            if (is_string($value) && (strlen($value) > 40 || str_contains(trim($value), ' '))) {
                continue;
            }

            $key = $this->encode($value);
            $this->setFieldValues[$handle][$field][$key] = ($this->setFieldValues[$handle][$field][$key] ?? 0) + 1;
        }
    }

    protected function topLevelSequence(array $data, array &$sequence): void
    {
        foreach ($data as $value) {
            if (! is_array($value)) {
                continue;
            }

            foreach ($value as $node) {
                if (is_array($node) && ($node['type'] ?? null) === 'set') {
                    if ($type = Arr::get($node, 'attrs.values.type')) {
                        $sequence[] = $type;
                    }
                }
            }
        }
    }

    protected function summariseAttrs(): array
    {
        $out = [];

        foreach ($this->nodeAttrs as $context => $nodes) {
            foreach ($nodes as $nodeType => $attrs) {
                foreach ($attrs as $attr => $values) {
                    arsort($values);
                    $out[$context][$nodeType][$attr] = array_slice($values, 0, 6, true);
                }
            }
        }

        return $out;
    }

    protected function summariseFieldValues(): array
    {
        $out = [];

        foreach ($this->setFieldValues as $handle => $fields) {
            foreach ($fields as $field => $values) {
                if (count($values) > 12) {
                    continue;
                }

                arsort($values);
                $out[$handle][$field] = array_slice($values, 0, 6, true);
            }
        }

        return $out;
    }

    protected function commonSequences(int $take = 8): array
    {
        return collect($this->sequences)
            ->map(fn ($s) => implode(' → ', array_slice($s, 0, 14)))
            ->countBy()
            ->sortDesc()
            ->take($take)
            ->all();
    }

    protected function sorted(array $counts): array
    {
        arsort($counts);

        return $counts;
    }

    protected function encode(mixed $value): string
    {
        return match (true) {
            $value === null => '__null__',
            $value === true => '__true__',
            $value === false => '__false__',
            default => (string) $value,
        };
    }

    protected function decode(string $value): mixed
    {
        return match ($value) {
            '__null__' => null,
            '__true__' => true,
            '__false__' => false,
            default => $value,
        };
    }

    public function toMarkdown(array $analysis): string
    {
        $out = ["Measured from {$analysis['entries_analysed']} existing entries.\n"];

        if ($sets = $analysis['set_counts']) {
            $out[] = '## Blocks actually in use';
            $out[] = '';

            foreach ($sets as $handle => $count) {
                $where = collect($analysis['set_placements'][$handle] ?? [])
                    ->sortDesc()
                    ->take(3)
                    ->map(fn ($c, $context) => "`{$context}` ×{$c}")
                    ->implode(', ');

                $out[] = "- `{$handle}` — {$count}× · appears in: {$where}";
            }

            $out[] = '';
        }

        if ($sequences = $analysis['common_sequences']) {
            $out[] = '## Section orders that recur';
            $out[] = '';

            foreach ($sequences as $sequence => $count) {
                $out[] = "- ({$count}×) {$sequence}";
            }

            $out[] = '';
        }

        if ($values = $analysis['set_field_values']) {
            $out[] = '## Option values this site picks';
            $out[] = '';

            foreach ($values as $handle => $fields) {
                $lines = collect($fields)
                    ->map(fn ($counts, $field) => $field.': '
                        .collect($counts)->map(fn ($c, $v) => "`{$v}` ×{$c}")->implode(', '))
                    ->implode('; ');

                $out[] = "- **`{$handle}`** — {$lines}";
            }

            $out[] = '';
        }

        if ($defaults = $analysis['field_defaults']) {
            $out[] = '## Settings applied automatically';
            $out[] = '';
            $out[] = 'These are filled in from the site\'s own consistent usage when a block leaves them empty,';
            $out[] = 'so there is no need to reason about them.';
            $out[] = '';
            $out[] = '```json';
            $out[] = json_encode($defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $out[] = '```';
            $out[] = '';
        }

        if ($attrs = $analysis['default_attrs']) {
            $out[] = '## Node attributes applied to new prose, by context';
            $out[] = '';
            $out[] = '```json';
            $out[] = json_encode($attrs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $out[] = '```';
        }

        return implode("\n", $out);
    }
}
