<?php

namespace Joelseneque\AiPages\Schema;

use Illuminate\Support\Arr;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Statamic\Support\Str;

/**
 * Walks a Statamic blueprint into a normalised, JSON-friendly tree.
 *
 * Statamic's own Fields object already resolves `import:` references to
 * fieldsets, so we lean on it rather than re-parsing YAML. What we add is a
 * flat index of every set that exists anywhere in the tree (Bard, Replicator,
 * nested Grids), which is what the outline planner picks from.
 */
class BlueprintCompiler
{
    /** Field types that carry no authorable content. */
    public const STRUCTURAL_TYPES = ['tab', 'section', 'html', 'revealer', 'spacer', 'import'];

    /** Field types we never let the model write into. */
    public const UNSAFE_TYPES = ['code', 'template', 'form', 'users', 'user', 'radius', 'video'];

    protected array $seenFieldsets = [];

    public function __construct(protected array $ignoreHandles = []) {}

    public function forCollection(string $collection): array
    {
        $c = CollectionFacade::findByHandle($collection);

        if (! $c) {
            throw new \InvalidArgumentException("Collection [{$collection}] not found.");
        }

        $blueprints = $c->entryBlueprints();

        return [
            'collection' => $collection,
            'collection_title' => $c->title(),
            'route' => $c->route($c->sites()->first()) ?: null,
            'blueprints' => $blueprints->mapWithKeys(fn ($bp) => [
                $bp->handle() => $this->forBlueprint($bp),
            ])->all(),
        ];
    }

    public function forBlueprint(Blueprint $blueprint): array
    {
        $sets = [];

        // Arrow functions capture by value, so the by-reference $sets
        // accumulator needs a real closure here.
        $fields = $blueprint->fields()->all()
            ->reject(fn (Field $f) => $this->shouldSkip($f))
            ->map(function (Field $f) use (&$sets) {
                return $this->field($f, $f->handle(), $sets);
            })
            ->values()
            ->all();

        return [
            'handle' => $blueprint->handle(),
            'title' => $blueprint->title(),
            'fields' => $fields,
            'sets' => $sets,
        ];
    }

    /**
     * Normalise a single field, collecting any sets it contains into $sets.
     */
    protected function field(Field $field, string $path, array &$sets): array
    {
        $config = $field->config();
        $type = $field->type();

        $spec = array_filter([
            'handle' => $field->handle(),
            'path' => $path,
            'type' => $type,
            'display' => $field->display(),
            'instructions' => $field->instructions(),
            'required' => $field->isRequired() ?: null,
            'default' => Arr::get($config, 'default'),
            'max_items' => Arr::get($config, 'max_items') ?: Arr::get($config, 'max_files'),
            'conditions' => $this->conditions($config),
            'options' => $this->options($config, $type),
            'character_limit' => Arr::get($config, 'character_limit'),
        ], fn ($v) => $v !== null && $v !== [] && $v !== '');

        // Relationship targets — the model needs to know what it may point at.
        foreach (['collections', 'taxonomies', 'container', 'folder', 'dictionary', 'mode'] as $key) {
            if ($value = Arr::get($config, $key)) {
                $spec[$key] = $value;
            }
        }

        // Rich text: we ask the model for Markdown and convert it ourselves.
        if (in_array($type, ['bard', 'markdown', 'textarea'], true)) {
            $spec['content_format'] = $type === 'bard' ? 'markdown+sets' : 'markdown';
        }

        // Recurse into containers.
        if (in_array($type, ['group', 'grid'], true)) {
            $spec['fields'] = $this->childFields(Arr::get($config, 'fields', []), $path, $sets);
        }

        if (in_array($type, ['bard', 'replicator'], true)) {
            $spec['sets'] = $this->sets(Arr::get($config, 'sets', []), $path, $sets);
        }

        return $spec;
    }

    protected function childFields(array $items, string $path, array &$sets): array
    {
        return (new Fields($items))->all()
            ->reject(fn (Field $f) => $this->shouldSkip($f))
            ->map(function (Field $f) use ($path, &$sets) {
                return $this->field($f, $path.'.'.$f->handle(), $sets);
            })
            ->values()
            ->all();
    }

    /**
     * Bard/Replicator sets. Statamic nests them one level deep in groups.
     * Returns the handles present on this field; the full spec goes in $sets.
     */
    protected function sets(array $groups, string $path, array &$sets): array
    {
        $handles = [];

        foreach ($groups as $groupHandle => $group) {
            // Ungrouped (legacy) sets sit directly at the top level.
            $groupSets = $group['sets'] ?? [$groupHandle => $group];
            $groupDisplay = $group['display'] ?? Str::title($groupHandle);

            foreach ($groupSets as $setHandle => $set) {
                if (! is_array($set)) {
                    continue;
                }

                $handles[] = $setHandle;

                // A set handle can legitimately appear on more than one field.
                // Keep the first definition and note the extra location.
                if (isset($sets[$setHandle])) {
                    $sets[$setHandle]['used_by'][] = $path;
                    $sets[$setHandle]['used_by'] = array_values(array_unique($sets[$setHandle]['used_by']));

                    continue;
                }

                $sets[$setHandle] = [
                    'handle' => $setHandle,
                    'display' => $set['display'] ?? Str::title($setHandle),
                    'group' => $groupHandle,
                    'group_display' => $groupDisplay,
                    'instructions' => $set['instructions'] ?? null,
                    'icon' => $set['icon'] ?? null,
                    'used_by' => [$path],
                    'fields' => [],
                ];

                // Assign after insertion so recursive sets don't loop forever.
                $sets[$setHandle]['fields'] = $this->childFields($set['fields'] ?? [], $setHandle, $sets);
            }
        }

        return array_values(array_unique($handles));
    }

    protected function options(array $config, string $type): ?array
    {
        $options = Arr::get($config, 'options');

        if (! $options) {
            return null;
        }

        // Statamic accepts both ['key' => 'Label'] and [['key' => .., 'value' => ..]].
        return collect($options)->map(function ($option, $key) {
            if (is_array($option)) {
                return ['value' => $option['key'] ?? $key, 'label' => $option['value'] ?? $option['key'] ?? $key];
            }

            return ['value' => is_int($key) ? $option : $key, 'label' => $option];
        })->values()->all();
    }

    protected function conditions(array $config): ?array
    {
        $found = [];

        foreach (['if', 'if_any', 'unless', 'unless_any', 'show_when', 'hide_when'] as $key) {
            if ($value = Arr::get($config, $key)) {
                $found[$key] = $value;
            }
        }

        return $found ?: null;
    }

    protected function shouldSkip(Field $field): bool
    {
        return in_array($field->type(), self::STRUCTURAL_TYPES, true)
            || in_array($field->handle(), $this->ignoreHandles, true);
    }
}
