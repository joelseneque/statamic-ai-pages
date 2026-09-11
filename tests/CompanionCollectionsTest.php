<?php

namespace Joelseneque\AiPages\Tests;

use Joelseneque\AiPages\Build\CompanionCollections;

class CompanionCollectionsTest extends TestCase
{
    private function blueprint(array $sets = [], array $fields = []): array
    {
        return ['fields' => $fields, 'sets' => $sets];
    }

    public function test_it_finds_creatable_relationships_inside_sets(): void
    {
        $blueprint = $this->blueprint(sets: [
            'faqs' => ['handle' => 'faqs', 'fields' => [
                ['handle' => 'faq_entries', 'type' => 'entries', 'collections' => ['faqs'], 'create' => true],
            ]],
        ]);

        $this->assertSame(['faqs'], CompanionCollections::handles($blueprint));
    }

    public function test_a_relationship_without_create_is_reference_only(): void
    {
        // No `create` flag means the site only ever picks existing entries here,
        // so we must never invent new ones.
        $blueprint = $this->blueprint(sets: [
            'post_grid' => ['handle' => 'post_grid', 'fields' => [
                ['handle' => 'posts', 'type' => 'entries', 'collections' => ['articles']],
            ]],
        ]);

        $this->assertSame([], CompanionCollections::handles($blueprint));
    }

    public function test_it_finds_relationships_on_top_level_fields(): void
    {
        $blueprint = $this->blueprint(fields: [
            ['handle' => 'related', 'type' => 'entries', 'collections' => ['guides'], 'create' => true],
        ]);

        $this->assertSame(['guides'], CompanionCollections::handles($blueprint));
    }

    public function test_it_looks_inside_nested_field_groups(): void
    {
        $blueprint = $this->blueprint(sets: [
            'block' => ['handle' => 'block', 'fields' => [
                ['handle' => 'group', 'type' => 'group', 'fields' => [
                    ['handle' => 'faq_entries', 'type' => 'entries', 'collections' => ['faqs'], 'create' => true],
                ]],
            ]],
        ]);

        $this->assertSame(['faqs'], CompanionCollections::handles($blueprint));
    }

    public function test_the_description_names_the_block_that_uses_it(): void
    {
        $blueprint = $this->blueprint(sets: [
            'faqs' => ['handle' => 'faqs', 'fields' => [
                ['handle' => 'faq_entries', 'type' => 'entries', 'collections' => ['faqs'], 'create' => true],
            ]],
        ]);

        $this->assertStringContainsString('`faqs`', CompanionCollections::describe($blueprint));
        $this->assertStringContainsString('faqs.faq_entries', CompanionCollections::describe($blueprint));
    }

    public function test_a_blueprint_with_no_creatable_relationships_describes_nothing(): void
    {
        $this->assertNull(CompanionCollections::describe($this->blueprint()));
    }
}
