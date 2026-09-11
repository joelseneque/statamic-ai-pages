<?php

namespace Joelseneque\AiPages\Tests;

use Joelseneque\AiPages\Schema\JsonSchemaBuilder;

class JsonSchemaBuilderTest extends TestCase
{
    private function build(array $fields, array $omit = [], array $measured = []): array
    {
        return (new JsonSchemaBuilder)
            ->withMeasuredValues($measured)
            ->forSet(['handle' => 'demo', 'fields' => $fields], $omit);
    }

    public function test_it_pins_the_set_handle_as_a_constant(): void
    {
        $schema = $this->build([]);

        $this->assertSame('demo', $schema['properties']['type']['const']);
        $this->assertContains('type', $schema['required']);
    }

    public function test_it_maps_field_types_to_json_types(): void
    {
        $schema = $this->build([
            ['handle' => 'headline', 'type' => 'text', 'display' => 'Headline'],
            ['handle' => 'featured', 'type' => 'toggle', 'display' => 'Featured'],
            ['handle' => 'columns', 'type' => 'integer', 'display' => 'Columns'],
            ['handle' => 'points', 'type' => 'list', 'display' => 'Points'],
        ]);

        $this->assertSame('string', $schema['properties']['headline']['type']);
        $this->assertSame('boolean', $schema['properties']['featured']['type']);
        $this->assertSame('number', $schema['properties']['columns']['type']);
        $this->assertSame('array', $schema['properties']['points']['type']);
    }

    public function test_rich_text_is_requested_as_markdown(): void
    {
        $schema = $this->build([
            ['handle' => 'body', 'type' => 'bard', 'display' => 'Body'],
        ]);

        $this->assertSame('string', $schema['properties']['body']['type']);
        $this->assertStringContainsString('Markdown', $schema['properties']['body']['description']);
    }

    public function test_single_value_relationships_are_not_wrapped_in_arrays(): void
    {
        $schema = $this->build([
            ['handle' => 'image', 'type' => 'assets', 'display' => 'Image', 'max_items' => 1],
            ['handle' => 'gallery', 'type' => 'assets', 'display' => 'Gallery'],
        ]);

        $this->assertSame('string', $schema['properties']['image']['type']);
        $this->assertSame('array', $schema['properties']['gallery']['type']);
    }

    public function test_unless_conditions_read_as_do_not_set_when(): void
    {
        $schema = $this->build([
            ['handle' => 'body', 'type' => 'text', 'display' => 'Body', 'conditions' => [
                'unless' => ['box_type' => 'equals stat'],
            ]],
        ]);

        $this->assertStringContainsString('Do NOT set when box_type equals stat', $schema['properties']['body']['description']);
    }

    public function test_if_conditions_read_as_only_set_when(): void
    {
        $schema = $this->build([
            ['handle' => 'figure', 'type' => 'text', 'display' => 'Figure', 'conditions' => [
                'if' => ['box_type' => 'equals stat'],
            ]],
        ]);

        $this->assertStringContainsString('Only set when box_type equals stat', $schema['properties']['figure']['description']);
    }

    public function test_conditional_fields_are_never_globally_required(): void
    {
        $schema = $this->build([
            ['handle' => 'always', 'type' => 'text', 'display' => 'Always', 'required' => true],
            ['handle' => 'sometimes', 'type' => 'text', 'display' => 'Sometimes', 'required' => true, 'conditions' => [
                'if' => ['other' => 'equals yes'],
            ]],
        ]);

        $this->assertContains('always', $schema['required']);
        $this->assertNotContains('sometimes', $schema['required']);
    }

    public function test_measured_fields_can_be_omitted_from_the_schema(): void
    {
        $schema = $this->build([
            ['handle' => 'headline', 'type' => 'text', 'display' => 'Headline'],
            ['handle' => 'margin_top', 'type' => 'select', 'display' => 'Margin'],
        ], omit: ['margin_top']);

        $this->assertArrayHasKey('headline', $schema['properties']);
        $this->assertArrayNotHasKey('margin_top', $schema['properties']);
    }

    public function test_declared_options_win_when_they_overlap_what_the_site_stores(): void
    {
        // The site has one legacy value that is no longer a valid option; the
        // blueprint's vocabulary should still be the one we offer.
        $schema = $this->build(
            fields: [['handle' => 'style', 'type' => 'select', 'display' => 'Style', 'options' => [
                ['value' => 'primary', 'label' => 'Primary'],
                ['value' => 'white-outline', 'label' => 'White outline'],
            ]]],
            measured: ['style' => ['primary' => 39, 'legacy-outline' => 12]],
        );

        $this->assertSame(['primary', 'white-outline'], $schema['properties']['style']['enum']);
    }

    public function test_stored_values_win_when_the_blueprint_vocabulary_does_not_match(): void
    {
        // A colour dictionary keyed on hex while the site stores swatch names.
        $schema = $this->build(
            fields: [['handle' => 'colour', 'type' => 'select', 'display' => 'Colour', 'options' => [
                ['value' => '#6F9792', 'label' => 'primary'],
                ['value' => '#ffffff', 'label' => 'white'],
            ]]],
            measured: ['colour' => ['white' => 19, 'primary-light' => 8]],
        );

        $this->assertSame(['white', 'primary-light'], $schema['properties']['colour']['enum']);
    }

    public function test_unsafe_field_types_are_never_offered(): void
    {
        $schema = $this->build([
            ['handle' => 'headline', 'type' => 'text', 'display' => 'Headline'],
            ['handle' => 'tracking', 'type' => 'code', 'display' => 'Tracking code'],
        ]);

        $this->assertArrayNotHasKey('tracking', $schema['properties']);
    }
}
