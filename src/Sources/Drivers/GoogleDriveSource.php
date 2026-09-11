<?php

namespace Joelseneque\AiPages\Sources\Drivers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Joelseneque\AiPages\Sources\Source;
use RuntimeException;

/**
 * Public Google Drive links, without needing OAuth.
 *
 * Docs, Sheets and Slides all have unauthenticated export endpoints as long as
 * the document is shared with "anyone with the link". Anything else goes down
 * the generic file-download path and is sniffed by content type.
 */
class GoogleDriveSource
{
    public function __construct(protected FileSource $files) {}

    public static function handles(string $url): bool
    {
        return Str::contains($url, ['docs.google.com', 'drive.google.com']);
    }

    /** @return array<Source> */
    public function fetch(string $url): array
    {
        [$kind, $id] = $this->identify($url);

        if (! $id) {
            throw new RuntimeException("Couldn't work out the file id from that Google link. Make sure it's a share link.");
        }

        $export = match ($kind) {
            'document' => "https://docs.google.com/document/d/{$id}/export?format=txt",
            'spreadsheets' => "https://docs.google.com/spreadsheets/d/{$id}/export?format=csv",
            'presentation' => "https://docs.google.com/presentation/d/{$id}/export/txt",
            default => "https://drive.google.com/uc?export=download&id={$id}",
        };

        $response = Http::timeout(config('ai-pages.sources.url_timeout', 30))
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; StatamicAiPages/1.0)'])
            ->get($export);

        if ($response->failed()) {
            throw new RuntimeException(
                "Google returned HTTP {$response->status()} for that link. "
                ."It probably isn't shared publicly — set it to \"Anyone with the link can view\"."
            );
        }

        $body = $response->body();

        // Drive serves an HTML interstitial rather than a 4xx for private files.
        if (Str::contains(Str::lower(Str::limit($body, 1500)), ['sign in', '<html', 'accounts.google.com'])
            && ! Str::contains($export, 'export?format=txt')) {
            throw new RuntimeException(
                'Google sent back a sign-in page. That file needs to be shared as "Anyone with the link can view".'
            );
        }

        $contentType = Str::before($response->header('content-type') ?: 'text/plain', ';');
        $label = "Google Drive ({$kind}) {$id}";

        if (Str::contains($contentType, 'pdf')) {
            return [Source::document($label, base64_encode($body), 'application/pdf', ['url' => $url])];
        }

        if (Str::startsWith($contentType, 'image/')) {
            return [Source::image($label, base64_encode($body), $contentType, ['url' => $url])];
        }

        if (Str::contains($contentType, ['officedocument.wordprocessing', 'msword'])) {
            return [$this->files->fromBinary($label, $body, 'docx')];
        }

        return [Source::text($label, trim($body), 'drive', ['url' => $url])];
    }

    protected function identify(string $url): array
    {
        foreach (['document', 'spreadsheets', 'presentation'] as $kind) {
            if (preg_match("~/{$kind}/d/([a-zA-Z0-9_-]+)~", $url, $m)) {
                return [$kind, $m[1]];
            }
        }

        if (preg_match('~/file/d/([a-zA-Z0-9_-]+)~', $url, $m)) {
            return ['file', $m[1]];
        }

        if (preg_match('~[?&]id=([a-zA-Z0-9_-]+)~', $url, $m)) {
            return ['file', $m[1]];
        }

        return ['file', null];
    }
}
