<?php

namespace Joelseneque\AiPages\Support;

use Joelseneque\AiPages\Schema\Conventions;
use League\CommonMark\CommonMarkConverter;
use Tiptap\Editor;
use Tiptap\Extensions\StarterKit;
use Tiptap\Marks;
use Tiptap\Nodes;

/**
 * Markdown -> Bard (ProseMirror) nodes.
 *
 * We ask the model for Markdown rather than ProseMirror JSON. Models write
 * clean Markdown reliably and clean ProseMirror rarely, and the conversion is
 * deterministic, so this removes a whole class of malformed-output failures.
 *
 * Node attributes come from what the site measurably does in that context, not
 * from a guess — see Conventions.
 */
class MarkdownToBard
{
    public function __construct(
        protected Conventions $conventions,
        protected ?string $collection = null,
    ) {}

    public static function make(?string $collection = null): self
    {
        return new self(app(Conventions::class), $collection);
    }

    public function forCollection(?string $collection): self
    {
        return new self($this->conventions, $collection);
    }

    /**
     * @param  string|null  $context  Field path the content belongs to, e.g. `boxed_section.box_content`.
     */
    public function convert(?string $markdown, ?string $context = null): array
    {
        if (! filled($markdown)) {
            return [];
        }

        $html = (new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]))->convert($markdown)->getContent();

        $doc = $this->editor()->setContent($html)->getDocument();

        return $this->normalise($doc['content'] ?? [], $context);
    }

    /**
     * Bard stores a flat array of nodes; `type: set` nodes are the blocks.
     *
     * `enabled` is deliberately omitted — Statamic treats a set node without it
     * as enabled, and writing it makes generated entries diff noisily against
     * hand-authored ones.
     */
    public function setNode(string $type, array $values, ?string $id = null): array
    {
        return [
            'type' => 'set',
            'attrs' => [
                'id' => $id ?: Id::generate(),
                'values' => array_merge(['type' => $type], $values),
            ],
        ];
    }

    protected function editor(): Editor
    {
        return new Editor([
            'extensions' => [
                new StarterKit(['codeBlock' => false]),
                new Marks\Link,
                new Marks\Underline,
                new Marks\Subscript,
                new Marks\Superscript,
                new Nodes\Table,
                new Nodes\TableRow,
                new Nodes\TableCell,
                new Nodes\TableHeader,
            ],
        ]);
    }

    /**
     * Tiptap's PHP port leaves shapes Bard's editor won't accept — list items
     * whose children are bare inline nodes, headings without a level. Fix those,
     * then stamp the attrs this site uses in this context.
     */
    protected function normalise(array $nodes, ?string $context): array
    {
        return collect($nodes)->map(function ($node) use ($context) {
            $type = $node['type'] ?? null;

            if (in_array($type, ['listItem', 'tableCell', 'tableHeader', 'blockquote'], true)) {
                $node['content'] = $this->wrapInlines($node['content'] ?? [], $context);
            } elseif (isset($node['content']) && ! $this->isInlineContainer($type)) {
                $node['content'] = $this->normalise($node['content'], $context);
            }

            if ($type === 'heading') {
                $node['attrs']['level'] = (int) ($node['attrs']['level'] ?? 2);
            }

            if ($type && $measured = $this->conventions->nodeAttrs($this->collection, $context, $type)) {
                // Never let a measured default overwrite a real per-node value.
                $node['attrs'] = array_merge($measured, $node['attrs'] ?? []);
            }

            return $node;
        })->values()->all();
    }

    /**
     * ProseMirror requires block children inside list items; wrap loose text.
     */
    protected function wrapInlines(array $children, ?string $context): array
    {
        $out = [];
        $buffer = [];

        $flush = function () use (&$buffer, &$out, $context) {
            if (! $buffer) {
                return;
            }

            $paragraph = ['type' => 'paragraph', 'content' => $buffer];

            if ($measured = $this->conventions->nodeAttrs($this->collection, $context, 'paragraph')) {
                $paragraph['attrs'] = $measured;
            }

            $out[] = $paragraph;
            $buffer = [];
        };

        foreach ($children as $child) {
            if (in_array($child['type'] ?? '', ['text', 'hardBreak'], true)) {
                $buffer[] = $child;

                continue;
            }

            $flush();

            $out[] = $this->normalise([$child], $context)[0] ?? $child;
        }

        $flush();

        return $out;
    }

    protected function isInlineContainer(?string $type): bool
    {
        return in_array($type, ['paragraph', 'heading', 'text'], true);
    }
}
