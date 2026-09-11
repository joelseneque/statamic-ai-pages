<?php

namespace Joelseneque\AiPages\Tests;

use Joelseneque\AiPages\Build\FidelityGuard;

class FidelityGuardTest extends TestCase
{
    private function section(string $text): array
    {
        return ['set' => 'demo', 'values' => [
            'body' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => $text]],
            ]],
        ]];
    }

    public function test_untouched_copy_passes_clean(): void
    {
        $source = 'Not everyone who is overweight is ready for surgery, and not everyone needs it. '
            .'Our specialist team is here to help you work out what fits.';

        $result = (new FidelityGuard)->check($source, [
            $this->section('Not everyone who is overweight is ready for surgery, and not everyone needs it.'),
            $this->section('Our specialist team is here to help you work out what fits.'),
        ]);

        $this->assertSame([], $result['drifted']);
        $this->assertSame([], $result['missing']);
        $this->assertSame(1.0, $result['score']);
    }

    public function test_it_catches_a_rewritten_sentence(): void
    {
        $source = 'Not everyone who is overweight is ready for surgery, and not everyone needs it.';

        $result = (new FidelityGuard)->check($source, [
            $this->section('Surgery is not the right path for every patient, and plenty never need it at all.'),
        ]);

        $this->assertCount(1, $result['drifted']);
        $this->assertLessThan(1.0, $result['score']);
    }

    public function test_it_tolerates_smart_quotes_and_dashes(): void
    {
        // Markdown round-trips straight quotes into typographic ones; that is
        // not a rewording and must not be reported as one.
        $source = "The clinic's approach - reviewed every six weeks - suits most patients well.";

        $result = (new FidelityGuard)->check($source, [
            $this->section("The clinic\u{2019}s approach \u{2014} reviewed every six weeks \u{2014} suits most patients well."),
        ]);

        $this->assertSame([], $result['drifted']);
    }

    public function test_it_reports_source_copy_that_never_made_it_onto_the_page(): void
    {
        $source = 'The first sentence is present on the page in full and unchanged. '
            .'This second sentence was dropped entirely and should be reported as missing.';

        $result = (new FidelityGuard)->check($source, [
            $this->section('The first sentence is present on the page in full and unchanged.'),
        ]);

        $this->assertCount(1, $result['missing']);
        $this->assertStringContainsString('dropped entirely', $result['missing'][0]);
    }

    public function test_an_empty_source_cannot_fail(): void
    {
        $result = (new FidelityGuard)->check('', [$this->section('Anything at all.')]);

        $this->assertSame(1.0, $result['score']);
    }
}
