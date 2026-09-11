<?php

namespace Joelseneque\AiPages\Schema;

use Illuminate\Support\Str;

/**
 * A compact, human-readable menu of the sections available on a blueprint.
 *
 * The outline planner sees only this — not the full schema — so that picking a
 * page structure stays cheap even on a blueprint with dozens of sets and
 * hundreds of fields. The chosen sets are then expanded one at a time.
 */
class SetCatalogue
{
    public function __construct(
        protected array $sets,
        protected array $placements = [],
    ) {}

    /**
     * Where each set is actually used on this site, measured from real entries.
     * Telling the planner that `section_content` only ever appears at the top
     * level stops it nesting one inside another.
     */
    public function withPlacements(array $placements): self
    {
        return new self($this->sets, $placements);
    }

    public static function fromBlueprint(array $compiled): self
    {
        return new self($compiled['sets'] ?? []);
    }

    public function handles(): array
    {
        return array_keys($this->sets);
    }

    public function has(string $handle): bool
    {
        return isset($this->sets[$handle]);
    }

    public function get(string $handle): ?array
    {
        return $this->sets[$handle] ?? null;
    }

    public function all(): array
    {
        return $this->sets;
    }

    /**
     * Markdown menu, grouped the way the Bard set picker groups them.
     */
    public function toMarkdown(): string
    {
        $out = [];

        foreach (collect($this->sets)->groupBy('group_display') as $group => $sets) {
            $out[] = "### {$group}";

            foreach ($sets as $set) {
                $summary = $this->summarise($set);
                $line = "- **`{$set['handle']}`** ({$set['display']}) — {$summary}";

                if ($usage = $this->usage($set['handle'])) {
                    $line .= " {$usage}";
                }

                $out[] = $line;
            }

            $out[] = '';
        }

        return trim(implode("\n", $out));
    }

    /**
     * One line describing what a set is for and what it holds, so the planner
     * can choose sensibly without seeing the full field definitions.
     */
    protected function summarise(array $set): string
    {
        if ($instructions = $set['instructions'] ?? null) {
            return Str::of($instructions)->squish()->limit(220)->toString();
        }

        $fields = collect($set['fields'] ?? [])
            ->map(fn ($f) => $this->fieldLabel($f))
            ->filter()
            ->take(10);

        if ($fields->isEmpty()) {
            return 'No configurable fields.';
        }

        return 'Fields: '.$fields->implode(', ').'.';
    }

    /**
     * A short note on how much this block is really used, and where. Blocks the
     * site has never used are flagged so they aren't picked by accident.
     */
    protected function usage(string $handle): ?string
    {
        if (! $this->placements) {
            return null;
        }

        $places = $this->placements[$handle] ?? [];

        if (! $places) {
            return '_(never used on this site — avoid unless clearly the right fit)_';
        }

        arsort($places);
        $total = array_sum($places);
        $where = implode(', ', array_slice(array_keys($places), 0, 2));

        return "_(used {$total}× on this site, in: {$where})_";
    }

    protected function fieldLabel(array $field): ?string
    {
        $type = $field['type'] ?? 'text';

        if (in_array($type, BlueprintCompiler::STRUCTURAL_TYPES, true)) {
            return null;
        }

        $label = $field['display'] ?? $field['handle'];

        return match ($type) {
            'bard', 'markdown', 'textarea' => "{$label} (rich text)",
            'assets' => "{$label} (image)",
            'replicator', 'grid' => "{$label} (repeatable)",
            'select', 'button_group', 'radio' => "{$label} (".collect($field['options'] ?? [])->pluck('value')->take(6)->implode('/').')',
            'toggle' => "{$label} (yes/no)",
            default => $label,
        };
    }
}
