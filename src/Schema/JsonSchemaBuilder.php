<?php

namespace Joelseneque\AiPages\Schema;

use Illuminate\Support\Arr;

/**
 * Turns normalised field specs into a JSON Schema, which we hand to the
 * Anthropic API as a tool's input_schema.
 *
 * This is what stops the model inventing field names or putting a string where
 * a boolean goes — the shape is enforced at the API layer rather than hoped for
 * in a prompt.
 */
class JsonSchemaBuilder
{
    /**
     * Field types the model must never write into.
     */
    protected array $skipTypes = [
        'code', 'template', 'form', 'users', 'user', 'revealer', 'html',
        'section', 'tab', 'spacer', 'hidden',
    ];

    /** [fieldHandle => [value => count]] measured from the site's own content. */
    protected array $measured = [];

    public function __construct(protected int $maxDepth = 6) {}

    /**
     * Supply the values this site actually stores for the set being built.
     */
    public function withMeasuredValues(array $measured): self
    {
        $clone = clone $this;
        $clone->measured = $measured;

        return $clone;
    }

    /**
     * Build a schema object for a list of field specs.
     */
    public function forFields(array $fields, int $depth = 0, array $omit = []): array
    {
        $properties = [];
        $required = [];

        foreach ($fields as $field) {
            if (in_array($field['type'] ?? '', $this->skipTypes, true)) {
                continue;
            }

            // Settings the site fills in identically every time are supplied
            // deterministically afterwards — no point spending a decision on them.
            if (in_array($field['handle'], $omit, true)) {
                continue;
            }

            $schema = $this->forField($field, $depth);

            if ($schema === null) {
                continue;
            }

            $properties[$field['handle']] = $schema;

            // A conditional field can't be globally required — the model needs
            // the freedom to omit it when its condition isn't met.
            if (($field['required'] ?? false) && empty($field['conditions'])) {
                $required[] = $field['handle'];
            }
        }

        return array_filter([
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ], fn ($v) => $v !== []);
    }

    /**
     * Schema for a whole set, including its `type` discriminator.
     */
    public function forSet(array $set, array $omit = []): array
    {
        $schema = $this->forFields($set['fields'] ?? [], 0, $omit);

        $schema['properties'] = array_merge([
            'type' => [
                'type' => 'string',
                'const' => $set['handle'],
                'description' => 'Always "'.$set['handle'].'".',
            ],
        ], $schema['properties'] ?? []);

        $schema['required'] = array_values(array_unique(array_merge(['type'], $schema['required'] ?? [])));

        return $schema;
    }

    protected function forField(array $field, int $depth): ?array
    {
        if ($depth > $this->maxDepth) {
            return null;
        }

        $type = $field['type'] ?? 'text';
        $schema = ['description' => $this->describe($field)];

        return match ($type) {
            'toggle' => $schema + ['type' => 'boolean'],

            'integer', 'range', 'float' => $schema + ['type' => 'number'],

            'list' => $schema + [
                'type' => 'array',
                'items' => ['type' => 'string'],
            ],

            'checkboxes' => $schema + [
                'type' => 'array',
                'items' => $this->enumOrString($field),
            ],

            'select', 'button_group', 'radio', 'colour_swatch', 'dictionary' => $this->selectSchema($field, $schema),

            'assets' => $this->cardinality($field, $schema, [
                'type' => 'string',
                'description' => 'Filename of an asset that already exists in the '
                    .($field['container'] ?? 'assets').' container. Leave out if you have no real asset to use.',
            ]),

            'entries' => $this->cardinality($field, $schema, [
                'type' => 'string',
                'description' => 'Slug or exact title of an existing entry in: '
                    .implode(', ', Arr::wrap($field['collections'] ?? ['any collection'])),
            ]),

            'terms' => $this->cardinality($field, $schema, [
                'type' => 'string',
                'description' => 'Slug of an existing term in: '
                    .implode(', ', Arr::wrap($field['taxonomies'] ?? ['any taxonomy'])),
            ]),

            'group' => $schema + $this->forFields($field['fields'] ?? [], $depth + 1),

            'grid' => $schema + [
                'type' => 'array',
                'items' => $this->forFields($field['fields'] ?? [], $depth + 1),
                'maxItems' => $field['max_items'] ?? null,
            ],

            'replicator' => array_merge($schema, [
                'type' => 'array',
                'description' => $schema['description'].' Each item must include a "type" naming one of: '
                    .implode(', ', $field['sets'] ?? []),
                'items' => ['type' => 'object'],
            ]),

            'bard' => array_merge($schema, [
                'type' => 'string',
                'description' => $schema['description']
                    .' Write Markdown — headings, paragraphs, bold, italic, links and lists. It is converted to rich text for you.',
            ]),

            'markdown', 'textarea' => $schema + ['type' => 'string'],

            'table' => $schema + [
                'type' => 'array',
                'items' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],

            default => $schema + ['type' => 'string'],
        };
    }

    protected function selectSchema(array $field, array $schema): array
    {
        $multiple = ($field['max_items'] ?? 1) !== 1 && ($field['multiple'] ?? false);

        if ($multiple) {
            return $schema + ['type' => 'array', 'items' => $this->enumOrString($field)];
        }

        return $schema + $this->enumOrString($field);
    }

    protected function enumOrString(array $field): array
    {
        $declared = collect($field['options'] ?? $this->dictionaryOptions($field) ?? [])
            ->map(fn ($o) => (string) $o['value'])
            ->values();

        $observed = collect(array_keys($this->measured[$field['handle']] ?? []))
            ->reject(fn ($v) => in_array($v, ['__null__', '__true__', '__false__'], true))
            ->values();

        if ($declared->isEmpty()) {
            return $observed->isEmpty()
                ? ['type' => 'string']
                : ['type' => 'string', 'enum' => $observed->all()];
        }

        if ($observed->isEmpty()) {
            return ['type' => 'string', 'enum' => $declared->all()];
        }

        // If what the site stores overlaps the declared options, the blueprint
        // is the right vocabulary and any odd stored value is legacy junk we
        // shouldn't reproduce. If there's no overlap at all, the blueprint is
        // lying — a dictionary keyed on hex while the site stores names, say —
        // so trust the content.
        return [
            'type' => 'string',
            'enum' => $declared->intersect($observed)->isNotEmpty()
                ? $declared->all()
                : $observed->all(),
        ];
    }

    /**
     * Single-value fields (max_items/max_files of 1) take a bare value in
     * Statamic, not an array — mirror that in the schema so we don't have to
     * unwrap it later.
     */
    protected function cardinality(array $field, array $schema, array $item): array
    {
        $max = $field['max_items'] ?? null;

        if ($max === 1) {
            return array_merge($item, ['description' => $schema['description'].' '.$item['description']]);
        }

        return array_filter($schema + [
            'type' => 'array',
            'items' => $item,
            'maxItems' => $max,
        ], fn ($v) => $v !== null);
    }

    /**
     * Fields backed by a Statamic dictionary (colour swatches, countries) carry
     * no inline options, so pull the real allowed values — otherwise the model
     * invents swatch names that silently render as nothing.
     */
    protected function dictionaryOptions(array $field): ?array
    {
        if (! $handle = $field['dictionary'] ?? null) {
            return null;
        }

        $handle = is_array($handle) ? ($handle['type'] ?? null) : $handle;

        if (! $handle || ! class_exists(\Statamic\Facades\Dictionary::class)) {
            return null;
        }

        try {
            $options = \Statamic\Facades\Dictionary::find($handle)?->options() ?? [];
        } catch (\Throwable) {
            return null;
        }

        if (! $options) {
            return null;
        }

        return collect($options)
            ->map(fn ($label, $value) => ['value' => $value, 'label' => is_string($label) ? $label : $value])
            ->values()
            ->all();
    }

    protected function describe(array $field): string
    {
        $parts = array_filter([
            $field['display'] ?? $field['handle'],
            $field['instructions'] ?? null,
            $this->describeConditions($field),
        ]);

        return implode(' — ', $parts);
    }

    protected function describeConditions(array $field): ?string
    {
        if (! $conditions = $field['conditions'] ?? null) {
            return null;
        }

        $described = [];

        foreach ($conditions as $kind => $rules) {
            $joiner = str_contains($kind, 'any') ? ' or ' : ' and ';

            // `unless: {x: equals y}` means "show this UNLESS x equals y" — so
            // the instruction is "do not set WHEN", not "unless".
            $prefix = str_starts_with($kind, 'unless') || $kind === 'hide_when'
                ? 'Do NOT set when'
                : 'Only set when';

            $clauses = collect($rules)->map(fn ($rule, $other) => "{$other} {$rule}")->implode($joiner);

            $described[] = "{$prefix} {$clauses}.";
        }

        return implode(' ', $described);
    }
}
