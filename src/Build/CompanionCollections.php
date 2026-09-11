<?php

namespace Joelseneque\AiPages\Build;

use Illuminate\Support\Arr;

/**
 * Which other collections a page may create entries in as it is built.
 *
 * Statamic's entries fieldtype has a `create` flag that lets an editor make a
 * new entry inline rather than only picking an existing one. That is the site
 * already saying "these things get written as part of writing a page" — a page
 * about a procedure needs its own FAQs, and those live as entries in the faqs
 * collection, not as text on the page. We take that flag at its word rather
 * than inventing a second configuration for the same decision.
 */
class CompanionCollections
{
    /**
     * @return array<string, array> Keyed by collection handle, with the fields that reference it.
     */
    public static function for(array $blueprint): array
    {
        $found = [];

        self::scanFields($blueprint['fields'] ?? [], $found);

        foreach ($blueprint['sets'] ?? [] as $set) {
            self::scanFields($set['fields'] ?? [], $found, $set['handle']);
        }

        return $found;
    }

    public static function handles(array $blueprint): array
    {
        return array_keys(self::for($blueprint));
    }

    protected static function scanFields(array $fields, array &$found, ?string $setHandle = null): void
    {
        foreach ($fields as $field) {
            if (isset($field['fields'])) {
                self::scanFields($field['fields'], $found, $setHandle);
            }

            if (($field['type'] ?? null) !== 'entries' || ! ($field['create'] ?? false)) {
                continue;
            }

            foreach (Arr::wrap($field['collections'] ?? []) as $collection) {
                $found[$collection]['used_by'][] = trim(($setHandle ? $setHandle.'.' : '').$field['handle'], '.');
                $found[$collection]['display'] = $field['display'] ?? $field['handle'];
            }
        }
    }

    /**
     * A line for the planner explaining what it may create, and why.
     */
    public static function describe(array $blueprint): ?string
    {
        if (! $found = self::for($blueprint)) {
            return null;
        }

        $lines = [];

        foreach ($found as $collection => $meta) {
            $lines[] = "- `{$collection}` — referenced by "
                .implode(', ', array_map(fn ($f) => "`{$f}`", array_unique($meta['used_by'])));
        }

        return implode("\n", $lines);
    }
}
