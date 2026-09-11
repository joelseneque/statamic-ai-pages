<?php

namespace Joelseneque\AiPages\Tests;

use Joelseneque\AiPages\Schema\Conventions;
use Joelseneque\AiPages\Support\MarkdownToBard;

class MarkdownToBardTest extends TestCase
{
    private function converter(array $analysis = []): MarkdownToBard
    {
        $conventions = new Conventions(sys_get_temp_dir().'/ai-pages-test-'.uniqid());
        $conventions->put('pages', $analysis);

        return new MarkdownToBard($conventions, 'pages');
    }

    public function test_it_converts_headings_and_paragraphs(): void
    {
        $nodes = $this->converter()->convert("## A heading\n\nSome copy.");

        $this->assertSame('heading', $nodes[0]['type']);
        $this->assertSame(2, $nodes[0]['attrs']['level']);
        $this->assertSame('A heading', $nodes[0]['content'][0]['text']);
        $this->assertSame('paragraph', $nodes[1]['type']);
    }

    public function test_it_preserves_marks_and_links(): void
    {
        $nodes = $this->converter()->convert('Text with **bold** and a [link](/contact-us).');

        $marks = collect($nodes[0]['content'])->pluck('marks')->filter()->flatten(1)->pluck('type');

        $this->assertTrue($marks->contains('bold'));
        $this->assertTrue($marks->contains('link'));
    }

    public function test_list_items_wrap_their_text_in_paragraphs(): void
    {
        // Bard rejects list items whose children are bare inline nodes.
        $nodes = $this->converter()->convert("- one\n- two");

        $this->assertSame('bulletList', $nodes[0]['type']);
        $this->assertSame('paragraph', $nodes[0]['content'][0]['content'][0]['type']);
        $this->assertArrayNotHasKey('attrs', $nodes[0]['content'][0]);
    }

    public function test_it_stamps_the_measured_attrs_for_that_context(): void
    {
        $converter = $this->converter([
            'default_attrs' => [
                'hero_content.content' => [
                    'paragraph' => ['class' => 'p2', 'textAlign' => 'left'],
                    'bulletList' => ['class' => 'tick-list'],
                ],
            ],
        ]);

        $nodes = $converter->convert("Some copy.\n\n- a point", 'hero_content.content');

        $this->assertSame('p2', $nodes[0]['attrs']['class']);
        $this->assertSame('left', $nodes[0]['attrs']['textAlign']);
        $this->assertSame('tick-list', $nodes[1]['attrs']['class']);
    }

    public function test_a_different_context_gets_different_attrs(): void
    {
        $converter = $this->converter([
            'default_attrs' => [
                'hero_content.content' => ['paragraph' => ['class' => 'p2']],
                'boxed_section.box_content' => ['paragraph' => ['class' => null]],
            ],
        ]);

        $hero = $converter->convert('Copy.', 'hero_content.content');
        $box = $converter->convert('Copy.', 'boxed_section.box_content');

        $this->assertSame('p2', $hero[0]['attrs']['class']);
        $this->assertNull($box[0]['attrs']['class']);
    }

    public function test_set_nodes_omit_enabled_so_they_match_hand_authored_entries(): void
    {
        $node = $this->converter()->setNode('eyebrow', ['text' => 'The basics', 'style' => 'pill']);

        $this->assertSame('set', $node['type']);
        $this->assertSame('eyebrow', $node['attrs']['values']['type']);
        $this->assertSame('The basics', $node['attrs']['values']['text']);
        $this->assertArrayNotHasKey('enabled', $node['attrs']);
        $this->assertNotEmpty($node['attrs']['id']);
    }

    public function test_raw_html_in_the_source_is_stripped(): void
    {
        $nodes = $this->converter()->convert('Copy <script>alert(1)</script> here.');

        $this->assertStringNotContainsString('script', json_encode($nodes));
    }

    public function test_empty_input_produces_no_nodes(): void
    {
        $this->assertSame([], $this->converter()->convert(null));
        $this->assertSame([], $this->converter()->convert(''));
    }
}
