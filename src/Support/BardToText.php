<?php

namespace Joelseneque\AiPages\Support;

/**
 * Flattens any Statamic field value — Bard nodes, Replicator arrays, groups,
 * plain strings — down to readable text. Used for sampling the site's existing
 * copy and for diffing AI output against a source.
 */
class BardToText
{
    public static function extract(mixed $value, int $depth = 0): string
    {
        if ($depth > 12) {
            return '';
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_bool($value) || $value === null) {
            return '';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        if (! is_array($value)) {
            return '';
        }

        // A ProseMirror text node.
        if (isset($value['type'], $value['text']) && $value['type'] === 'text') {
            return $value['text'];
        }

        $parts = [];

        // Headings and paragraphs become their own lines.
        $isBlock = isset($value['type']) && in_array($value['type'], [
            'heading', 'paragraph', 'listItem', 'blockquote', 'set',
        ], true);

        foreach ($value as $key => $child) {
            if (in_array($key, ['id', 'type', 'enabled', 'attrs'], true) && $key !== 'attrs') {
                continue;
            }

            if ($key === 'attrs') {
                // Set values live under attrs.values.
                $child = $child['values'] ?? null;

                if (! $child) {
                    continue;
                }
            }

            $text = self::extract($child, $depth + 1);

            if (trim($text) !== '') {
                $parts[] = $text;
            }
        }

        $glue = $isBlock ? ' ' : "\n";
        $text = trim(implode($glue, $parts));

        if (($value['type'] ?? null) === 'heading') {
            $level = (int) ($value['attrs']['level'] ?? 2);

            return "\n".str_repeat('#', $level)." {$text}\n";
        }

        return $isBlock ? "{$text}\n" : $text;
    }

    public static function words(mixed $value): int
    {
        return str_word_count(strip_tags(self::extract($value)));
    }
}
