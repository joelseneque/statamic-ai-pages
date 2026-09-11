<?php

namespace Joelseneque\AiPages\Schema;

use Illuminate\Support\Arr;
use Joelseneque\AiPages\Support\Id;
use Joelseneque\AiPages\Support\MarkdownToBard;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Entry;
use Statamic\Facades\Term;

/**
 * Turns raw model output into values Statamic will actually store.
 *
 * The JSON Schema gets us the right shape; this gets us the right *content* —
 * Markdown becomes Bard nodes, asset filenames become real asset paths, entry
 * titles become ids, and anything that can't be resolved is dropped with a
 * warning rather than saved as a dangling reference.
 */
class ValueCoercer
{
    protected array $warnings = [];

    public function __construct(
        protected MarkdownToBard $markdown,
        protected Conventions $conventions,
        protected ?string $site = null,
        protected ?string $collection = null,
    ) {}

    public static function make(?string $site = null, ?string $collection = null): self
    {
        return new self(
            MarkdownToBard::make($collection),
            app(Conventions::class),
            $site,
            $collection,
        );
    }

    /**
     * Fill in the layout settings this site applies so consistently that the
     * model should not be spending attention on them — container widths,
     * margins, animation toggles and the like. Only ever fills gaps.
     */
    public function applyMeasuredDefaults(string $setHandle, array $values): array
    {
        foreach ($this->conventions->fieldDefaults($this->collection, $setHandle) as $handle => $default) {
            if (! array_key_exists($handle, $values)) {
                $values[$handle] = $default;
            }
        }

        return $values;
    }

    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Coerce a flat map of values against a list of field specs.
     */
    public function coerce(array $values, array $fields, ?string $context = null): array
    {
        $specs = collect($fields)->keyBy('handle');
        $out = [];

        foreach ($values as $handle => $value) {
            // Structural keys, not blueprint fields — carry them through
            // rather than warning about them.
            if (in_array($handle, ['type', 'id', 'enabled'], true)) {
                $out[$handle] = $value;

                continue;
            }

            if (! $spec = $specs->get($handle)) {
                $this->warn("Dropped unknown field [{$handle}].");

                continue;
            }

            $coerced = $this->value($value, $spec, $context ? "{$context}.{$handle}" : $handle);

            if ($coerced !== null) {
                $out[$handle] = $coerced;
            }
        }

        return $out;
    }

    protected function value(mixed $value, array $spec, string $context): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return match ($spec['type'] ?? 'text') {
            'bard' => $this->bard($value, $context),
            'markdown', 'textarea' => is_string($value) ? $value : null,
            'toggle' => (bool) $value,
            'integer', 'range' => (int) $value,
            'float' => (float) $value,
            'list' => array_values(array_filter(array_map('strval', Arr::wrap($value)), 'filled')),
            'select', 'button_group', 'radio' => $this->option($value, $spec),
            'checkboxes' => array_values(array_filter(
                array_map(fn ($v) => $this->option($v, $spec), Arr::wrap($value))
            )),
            'assets' => $this->assets($value, $spec),
            'entries' => $this->relation($value, $spec, fn ($v) => $this->findEntry($v, $spec)),
            'terms' => $this->relation($value, $spec, fn ($v) => $this->findTerm($v, $spec)),
            'group' => $this->coerce(Arr::wrap($value), $spec['fields'] ?? [], $context),
            'grid' => $this->grid($value, $spec, $context),
            'replicator' => $this->replicator($value, $spec, $context),
            default => is_scalar($value) ? $value : null,
        };
    }

    /**
     * A Bard value is an array of ProseMirror nodes. The model gives us either
     * a Markdown string, or an array mixing Markdown strings and set objects.
     */
    protected function bard(mixed $value, string $context): array
    {
        $nodes = [];

        foreach (Arr::wrap(is_string($value) ? [$value] : $value) as $chunk) {
            if (is_string($chunk)) {
                $nodes = array_merge($nodes, $this->markdown->convert($chunk, $context));

                continue;
            }

            if (is_array($chunk) && isset($chunk['type'])) {
                // Already a node (a set we assembled ourselves).
                if ($chunk['type'] === 'set' || isset($chunk['content'])) {
                    $nodes[] = $chunk;

                    continue;
                }

                $nodes[] = $this->markdown->setNode($chunk['type'], Arr::except($chunk, 'type'));
            }
        }

        return $nodes;
    }

    protected function grid(mixed $value, array $spec, string $context): array
    {
        return collect(Arr::wrap($value))
            ->filter(fn ($item) => is_array($item))
            ->map(fn ($row) => array_merge(
                ['id' => Id::generate()],
                $this->coerce($row, $spec['fields'] ?? [], $context)
            ))
            ->values()
            ->all();
    }

    /**
     * Nested Replicator. Each item names its set via `type`; we look the set's
     * fields up from the compiled schema that was handed in with the spec.
     */
    protected function replicator(mixed $value, array $spec, string $context): array
    {
        $sets = $spec['_sets'] ?? [];

        return collect(Arr::wrap($value))
            ->filter(fn ($item) => is_array($item))
            ->map(function ($item) use ($sets) {
                $type = $item['type'] ?? null;

                if (! $type || ! isset($sets[$type])) {
                    $this->warn('Dropped replicator item with unknown set ['.($type ?? 'none').'].');

                    return null;
                }

                return $this->applyMeasuredDefaults($type, array_merge(
                    ['id' => Id::generate(), 'type' => $type, 'enabled' => true],
                    $this->coerce(Arr::except($item, ['type', 'id']), $sets[$type]['fields'] ?? [], $type)
                ));
            })
            ->filter()
            ->values()
            ->all();
    }

    protected function option(mixed $value, array $spec): ?string
    {
        $value = (string) $value;
        $options = collect($spec['options'] ?? [])->pluck('value')->map('strval');

        if ($options->isEmpty() || $options->contains($value)) {
            return $value;
        }

        // Be forgiving about the model returning the label instead of the key.
        $byLabel = collect($spec['options'])->first(
            fn ($o) => strcasecmp((string) $o['label'], $value) === 0
        );

        if ($byLabel) {
            return (string) $byLabel['value'];
        }

        $this->warn("Dropped invalid option [{$value}] for [{$spec['handle']}]; allowed: ".$options->implode(', ').'.');

        return null;
    }

    /**
     * Assets are only kept if the file genuinely exists — a hallucinated
     * filename would render as a broken image on the front end.
     */
    protected function assets(mixed $value, array $spec): mixed
    {
        $container = $spec['container'] ?? AssetContainer::all()->first()?->handle();

        $resolved = collect(Arr::wrap($value))
            ->map(function ($filename) use ($container) {
                $filename = ltrim((string) $filename, '/');

                if (! $container) {
                    return null;
                }

                if ($asset = Asset::find("{$container}::{$filename}")) {
                    return $asset->path();
                }

                // Try a filename match anywhere in the container.
                $match = AssetContainer::find($container)?->assets(null, true)
                    ->first(fn ($a) => $a->basename() === basename($filename));

                if ($match) {
                    return $match->path();
                }

                $this->warn("Skipped image [{$filename}] — no such asset in [{$container}]. Add it by hand.");

                return null;
            })
            ->filter()
            ->values();

        if ($resolved->isEmpty()) {
            return null;
        }

        return ($spec['max_items'] ?? null) === 1 ? $resolved->first() : $resolved->all();
    }

    protected function relation(mixed $value, array $spec, callable $finder): mixed
    {
        $ids = collect(Arr::wrap($value))->map($finder)->filter()->values();

        if ($ids->isEmpty()) {
            return null;
        }

        return ($spec['max_items'] ?? null) === 1 ? $ids->first() : $ids->all();
    }

    protected function findEntry(mixed $value, array $spec): ?string
    {
        $value = (string) $value;
        $collections = Arr::wrap($spec['collections'] ?? []);

        $query = Entry::query()->when($collections, fn ($q) => $q->whereIn('collection', $collections));

        if ($this->site) {
            $query->where('site', $this->site);
        }

        $entry = (clone $query)->where('slug', ltrim($value, '/'))->first()
            ?: (clone $query)->where('title', $value)->first()
            ?: Entry::find($value);

        if (! $entry) {
            $this->warn("Skipped link to [{$value}] — no matching entry found.");
        }

        return $entry?->id();
    }

    protected function findTerm(mixed $value, array $spec): ?string
    {
        $value = (string) $value;

        foreach (Arr::wrap($spec['taxonomies'] ?? []) as $taxonomy) {
            if ($term = Term::find("{$taxonomy}::{$value}")) {
                return $term->id();
            }
        }

        $this->warn("Skipped term [{$value}] — not found.");

        return null;
    }

    protected function warn(string $message): void
    {
        $this->warnings[] = $message;
    }
}
