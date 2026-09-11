<?php

namespace Joelseneque\AiPages\Edit;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Joelseneque\AiPages\Support\BardToText;
use Statamic\Contracts\Entries\Entry;

/**
 * Renders an existing entry as a readable, addressable outline.
 *
 * Handing a model the raw entry YAML wastes most of the context on ids and
 * ProseMirror scaffolding, and invites it to reply with a whole rewritten
 * document. A numbered digest keeps the request small and makes the reply a
 * short list of edits against stable section numbers instead.
 */
class EntryDigest
{
    public function __construct(protected int $excerptLength = 600) {}

    /**
     * @return array{markdown: string, sections: array, content_field: ?string}
     */
    public function make(Entry $entry, array $blueprint): array
    {
        $data = $entry->data()->all();
        $contentField = $this->contentField($data, $blueprint);
        $sections = [];

        $lines = [
            '# Current page: '.$entry->get('title'),
            'URL: '.($entry->url() ?: '(not routable)'),
            'Collection: '.$entry->collection()->handle().'  |  Blueprint: '.$entry->blueprint()->handle(),
            'Status: '.($entry->published() ? 'published' : 'draft'),
            '',
            '## Page settings',
            '',
        ];

        foreach ($blueprint['fields'] as $field) {
            $handle = $field['handle'];

            if ($handle === $contentField || ! array_key_exists($handle, $data)) {
                continue;
            }

            $value = $this->describeValue($data[$handle]);

            if ($value === '') {
                continue;
            }

            $lines[] = "- `{$handle}` ({$field['display']}): {$value}";
        }

        if ($contentField) {
            $lines[] = '';
            $lines[] = "## Sections (field `{$contentField}`)";
            $lines[] = '';

            foreach ($this->sections($data[$contentField] ?? []) as $index => $section) {
                $sections[] = $section;

                $number = $index + 1;
                $type = $section['type'] ?? 'prose';
                $lines[] = "### [{$number}] `{$type}`";
                $lines[] = '';
                $lines[] = Str::limit(trim(BardToText::extract($section['values'] ?? $section)), $this->excerptLength);
                $lines[] = '';
            }
        }

        return [
            'markdown' => implode("\n", $lines),
            'sections' => $sections,
            'content_field' => $contentField,
        ];
    }

    /**
     * Split a Bard value into addressable sections. Runs of loose prose between
     * sets become their own pseudo-section so they can be targeted too.
     */
    public function sections(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $sections = [];
        $prose = [];

        $flushProse = function () use (&$prose, &$sections) {
            if ($prose) {
                $sections[] = ['type' => null, 'nodes' => $prose];
                $prose = [];
            }
        };

        foreach ($value as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['type'] ?? null) === 'set') {
                $flushProse();

                $sections[] = [
                    'type' => Arr::get($node, 'attrs.values.type'),
                    'id' => Arr::get($node, 'attrs.id'),
                    'values' => Arr::get($node, 'attrs.values', []),
                ];

                continue;
            }

            // A replicator item rather than a Bard node.
            if (isset($node['type'], $node['id']) && ! isset($node['content'])) {
                $flushProse();
                $sections[] = ['type' => $node['type'], 'id' => $node['id'], 'values' => $node];

                continue;
            }

            $prose[] = $node;
        }

        $flushProse();

        return $sections;
    }

    protected function contentField(array $data, array $blueprint): ?string
    {
        return collect($blueprint['fields'])
            ->filter(fn ($f) => in_array($f['type'] ?? '', ['bard', 'replicator'], true) && ! empty($f['sets']))
            ->sortByDesc(fn ($f) => is_array($data[$f['handle']] ?? null) ? count($data[$f['handle']]) : 0)
            ->first()['handle'] ?? null;
    }

    protected function describeValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_scalar($value)) {
            return Str::limit((string) $value, 200);
        }

        if (is_array($value)) {
            $text = trim(BardToText::extract($value));

            return $text !== '' ? Str::limit($text, 300) : Str::limit(json_encode($value), 200);
        }

        return '';
    }
}
