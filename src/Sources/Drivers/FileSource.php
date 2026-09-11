<?php

namespace Joelseneque\AiPages\Sources\Drivers;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Joelseneque\AiPages\Sources\Source;
use RuntimeException;
use ZipArchive;

class FileSource
{
    public function fromUpload(UploadedFile $file): Source
    {
        $this->guard($file->getClientOriginalName(), $file->getSize());

        return $this->fromBinary(
            $file->getClientOriginalName(),
            (string) file_get_contents($file->getRealPath()),
            Str::lower($file->getClientOriginalExtension())
        );
    }

    public function fromPath(string $path): Source
    {
        if (! is_file($path)) {
            throw new RuntimeException("No file at {$path}.");
        }

        $this->guard($path, (int) filesize($path));

        return $this->fromBinary(basename($path), (string) file_get_contents($path), Str::lower(pathinfo($path, PATHINFO_EXTENSION)));
    }

    public function fromBinary(string $label, string $contents, string $extension): Source
    {
        return match ($extension) {
            // Claude reads PDFs natively — text and layout — so don't pre-extract.
            'pdf' => Source::document($label, base64_encode($contents), 'application/pdf'),

            'png' => Source::image($label, base64_encode($contents), 'image/png'),
            'jpg', 'jpeg' => Source::image($label, base64_encode($contents), 'image/jpeg'),
            'gif' => Source::image($label, base64_encode($contents), 'image/gif'),
            'webp' => Source::image($label, base64_encode($contents), 'image/webp'),

            'docx' => Source::text($label, $this->docx($contents), 'docx'),
            'html', 'htm' => Source::text($label, (new UrlSource)->readable($contents), 'html'),
            'rtf' => Source::text($label, $this->rtf($contents), 'rtf'),

            default => Source::text($label, $this->utf8($contents), 'text'),
        };
    }

    /**
     * Pull the text out of a .docx without adding a Word-parsing dependency —
     * it's a zip with an XML document inside.
     */
    protected function docx(string $contents): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'aipages').'.docx';
        file_put_contents($tmp, $contents);

        try {
            $zip = new ZipArchive;

            if ($zip->open($tmp) !== true) {
                throw new RuntimeException("That .docx couldn't be opened. If it's an old .doc, re-save it as .docx.");
            }

            $xml = $zip->getFromName('word/document.xml') ?: '';
            $zip->close();

            if ($xml === '') {
                throw new RuntimeException('That .docx had no readable document body.');
            }

            // Headings carry a style name we can map to Markdown.
            $xml = preg_replace_callback(
                '~<w:p\b[^>]*>(.*?)</w:p>~s',
                function ($m) {
                    $level = preg_match('~w:val="Heading(\d)"~', $m[1], $h) ? (int) $h[1] : 0;
                    $bullet = str_contains($m[1], '<w:numPr>');
                    $text = trim(html_entity_decode(strip_tags(
                        preg_replace('~<w:tab\b[^>]*/?>~', ' ', $m[1]) ?? $m[1]
                    ), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                    if ($text === '') {
                        return "\n";
                    }

                    return match (true) {
                        $level > 0 => "\n".str_repeat('#', $level)." {$text}\n",
                        $bullet => "\n- {$text}",
                        default => "\n{$text}\n",
                    };
                },
                $xml
            ) ?? $xml;

            $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return trim(preg_replace('~\n\s*\n\s*\n+~', "\n\n", $text) ?? $text);
        } finally {
            @unlink($tmp);
        }
    }

    protected function rtf(string $contents): string
    {
        $text = preg_replace('~\\\\[a-z]+-?\d*\s?~i', ' ', $contents) ?? $contents;
        $text = str_replace(['{', '}'], '', $text);

        return trim(preg_replace('~\s+~', ' ', $text) ?? $text);
    }

    protected function utf8(string $contents): string
    {
        return mb_check_encoding($contents, 'UTF-8')
            ? $contents
            : (mb_convert_encoding($contents, 'UTF-8', 'Windows-1252') ?: $contents);
    }

    protected function guard(string $name, int $bytes): void
    {
        $extension = Str::lower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = config('ai-pages.sources.allowed_extensions', []);

        if ($allowed && ! in_array($extension, $allowed, true)) {
            throw new RuntimeException("Can't read .{$extension} files. Allowed: ".implode(', ', $allowed).'.');
        }

        $max = (int) config('ai-pages.sources.max_upload_mb', 30);

        if ($bytes > $max * 1024 * 1024) {
            throw new RuntimeException("That file is over the {$max}MB limit.");
        }
    }
}
