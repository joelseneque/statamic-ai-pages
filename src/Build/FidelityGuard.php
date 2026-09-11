<?php

namespace Joelseneque\AiPages\Build;

use Illuminate\Support\Str;
use Joelseneque\AiPages\Support\BardToText;

/**
 * Checks that "keep exact wording" actually kept the exact wording.
 *
 * A prompt asking a model not to rewrite is a request, not a guarantee, so the
 * output is diffed back against the source. Anything that drifted is reported
 * against the section it came from, and — because the source spans are known —
 * we can also flag source copy that never made it onto the page at all.
 */
class FidelityGuard
{
    public function __construct(protected float $threshold = 0.92) {}

    public static function make(): self
    {
        return new self((float) config('ai-pages.verbatim_threshold', 0.92));
    }

    /**
     * @param  array  $sections  [['set' => .., 'values' => [..]], ...]
     * @return array{drifted: array, missing: array, score: float}
     */
    public function check(string $source, array $sections): array
    {
        $sourceSentences = $this->sentences($source);

        if (! $sourceSentences) {
            return ['drifted' => [], 'missing' => [], 'score' => 1.0];
        }

        $haystack = $this->normalise($source);
        $matched = [];
        $drifted = [];
        $checked = 0;

        foreach ($sections as $index => $section) {
            $text = BardToText::extract($section['values'] ?? []);

            foreach ($this->sentences($text) as $sentence) {
                $needle = $this->normalise($sentence);

                if ($needle === '' || Str::length($needle) < 25) {
                    continue;
                }

                $checked++;

                if (str_contains($haystack, $needle)) {
                    $matched[] = $needle;

                    continue;
                }

                [$best, $score] = $this->closest($needle, $sourceSentences);

                if ($score >= $this->threshold) {
                    $matched[] = $this->normalise($best);

                    continue;
                }

                $drifted[] = [
                    'section' => $index + 1,
                    'set' => $section['set'] ?? null,
                    'output' => Str::limit($sentence, 240),
                    'closest_source' => $score > 0.5 ? Str::limit($best, 240) : null,
                    'similarity' => round($score, 3),
                ];
            }
        }

        $missing = [];

        foreach ($sourceSentences as $sentence) {
            $needle = $this->normalise($sentence);

            if (Str::length($needle) < 40) {
                continue;
            }

            foreach ($matched as $hit) {
                if (str_contains($hit, $needle) || str_contains($needle, $hit)) {
                    continue 2;
                }
            }

            $missing[] = Str::limit($sentence, 240);
        }

        return [
            'drifted' => $drifted,
            'missing' => array_slice($missing, 0, 25),
            'score' => $checked ? round(1 - (count($drifted) / $checked), 3) : 1.0,
        ];
    }

    /**
     * @return array{0: string, 1: float}
     */
    protected function closest(string $needle, array $candidates): array
    {
        $best = '';
        $bestScore = 0.0;

        foreach ($candidates as $candidate) {
            $normalised = $this->normalise($candidate);

            // similar_text is O(n^2); skip candidates that can't possibly match.
            if (abs(Str::length($normalised) - Str::length($needle)) > Str::length($needle) * 0.5) {
                continue;
            }

            similar_text($needle, $normalised, $percent);

            if ($percent / 100 > $bestScore) {
                $bestScore = $percent / 100;
                $best = $candidate;
            }
        }

        return [$best, $bestScore];
    }

    protected function sentences(string $text): array
    {
        $text = trim(preg_replace('~\s+~u', ' ', strip_tags($text)) ?? $text);

        if ($text === '') {
            return [];
        }

        $parts = preg_split('~(?<=[.!?])\s+(?=[A-Z0-9"\'\x{2018}\x{201C}])~u', $text) ?: [$text];

        return array_values(array_filter(array_map('trim', $parts), fn ($s) => $s !== ''));
    }

    /**
     * Fold away everything a "verbatim" comparison shouldn't care about:
     * casing, whitespace, and the smart-quote/dash substitutions that happen
     * on the way through Markdown.
     */
    protected function normalise(string $text): string
    {
        $text = Str::lower(strip_tags($text));

        $text = strtr($text, [
            "\u{2018}" => "'", "\u{2019}" => "'", "\u{201C}" => '"', "\u{201D}" => '"',
            "\u{2013}" => '-', "\u{2014}" => '-', "\u{2026}" => '...', "\u{00A0}" => ' ',
        ]);

        $text = preg_replace('~[^\p{L}\p{N}\s]~u', '', $text) ?? $text;

        return trim(preg_replace('~\s+~u', ' ', $text) ?? $text);
    }
}
