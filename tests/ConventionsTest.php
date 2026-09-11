<?php

namespace Joelseneque\AiPages\Tests;

use Joelseneque\AiPages\Schema\Conventions;

class ConventionsTest extends TestCase
{
    private function conventions(array $analysis): Conventions
    {
        $conventions = new Conventions(sys_get_temp_dir().'/ai-pages-test-'.uniqid());
        $conventions->put('pages', $analysis);

        return $conventions;
    }

    public function test_node_attrs_are_looked_up_by_context(): void
    {
        $conventions = $this->conventions([
            'default_attrs' => [
                'hero_content.content' => ['paragraph' => ['class' => 'p2', 'textAlign' => 'left']],
                'boxed_section.box_content' => ['paragraph' => ['class' => null]],
            ],
        ]);

        $this->assertSame(
            ['class' => 'p2', 'textAlign' => 'left'],
            $conventions->nodeAttrs('pages', 'hero_content.content', 'paragraph')
        );

        $this->assertSame(
            ['class' => null],
            $conventions->nodeAttrs('pages', 'boxed_section.box_content', 'paragraph')
        );
    }

    public function test_an_unknown_context_falls_back_to_the_commonest_usage(): void
    {
        $conventions = $this->conventions([
            'default_attrs' => [
                'a.content' => ['paragraph' => ['class' => 'p2']],
                'b.content' => ['paragraph' => ['class' => 'p2']],
                'c.content' => ['paragraph' => ['class' => null]],
            ],
        ]);

        $this->assertSame(
            ['class' => 'p2'],
            $conventions->nodeAttrs('pages', 'somewhere.unseen', 'paragraph')
        );
    }

    public function test_field_defaults_are_scoped_to_a_set(): void
    {
        $conventions = $this->conventions([
            'field_defaults' => [
                'stats' => ['stats_columns' => '4', 'stats_style' => 'card'],
            ],
        ]);

        $this->assertSame(['stats_columns' => '4', 'stats_style' => 'card'], $conventions->fieldDefaults('pages', 'stats'));
        $this->assertSame([], $conventions->fieldDefaults('pages', 'faqs'));
    }

    public function test_an_unmeasured_collection_yields_nothing_rather_than_erroring(): void
    {
        $conventions = $this->conventions(['field_defaults' => []]);

        $this->assertTrue($conventions->isEmpty('articles'));
        $this->assertSame([], $conventions->nodeAttrs('articles', 'anything', 'paragraph'));
    }
}
