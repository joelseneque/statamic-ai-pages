<?php

namespace Joelseneque\AiPages\Sources\Drivers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Joelseneque\AiPages\Sources\Source;
use RuntimeException;

class UrlSource
{
    public function fetch(string $url): Source
    {
        if (! config('ai-pages.sources.allow_url_fetch')) {
            throw new RuntimeException('Fetching URLs is disabled. Set ai-pages.sources.allow_url_fetch to true.');
        }

        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (compatible; StatamicAiPages/1.0)',
        ])
            ->timeout(config('ai-pages.sources.url_timeout', 30))
            ->get($url);

        if ($response->failed()) {
            throw new RuntimeException("Couldn't fetch {$url} — HTTP {$response->status()}.");
        }

        $contentType = Str::before($response->header('content-type') ?: 'text/html', ';');

        // Binaries (a PDF behind a link, say) go straight to the model.
        if (Str::contains($contentType, ['pdf'])) {
            return Source::document($url, base64_encode($response->body()), 'application/pdf', ['url' => $url]);
        }

        if (Str::startsWith($contentType, 'image/')) {
            return Source::image($url, base64_encode($response->body()), $contentType, ['url' => $url]);
        }

        return Source::text($url, $this->readable($response->body()), 'url', ['url' => $url]);
    }

    /**
     * Crude readability pass — strip the chrome, keep the prose. Good enough to
     * hand to a model, and avoids pulling in a parser dependency.
     */
    public function readable(string $html): string
    {
        $html = preg_replace('~<(script|style|noscript|svg|iframe|nav|footer|header|form)\b[^>]*>.*?</\1>~is', ' ', $html) ?? $html;
        $html = preg_replace('~<!--.*?-->~s', ' ', $html) ?? $html;

        // Keep block boundaries as newlines so structure survives.
        $html = preg_replace('~</(p|div|section|article|li|h[1-6]|tr|br)\s*/?>~i', "\n", $html) ?? $html;
        $html = preg_replace('~<h([1-6])[^>]*>~i', "\n".'#$1 ', $html) ?? $html;
        $html = preg_replace('~<li[^>]*>~i', "\n- ", $html) ?? $html;

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('~[ \t]+~', ' ', $text) ?? $text;
        $text = preg_replace('~\n\s*\n\s*\n+~', "\n\n", $text) ?? $text;

        // Turn the heading markers back into Markdown.
        $text = preg_replace_callback('~^#(\d) ~m', fn ($m) => str_repeat('#', (int) $m[1]).' ', $text) ?? $text;

        return trim($text);
    }
}
