<?php

namespace Joelseneque\AiPages\Sources;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Joelseneque\AiPages\Sources\Drivers\AssetSource;
use Joelseneque\AiPages\Sources\Drivers\FileSource;
use Joelseneque\AiPages\Sources\Drivers\GoogleDriveSource;
use Joelseneque\AiPages\Sources\Drivers\UrlSource;

/**
 * Takes whatever the user threw at the form and turns it into Sources.
 *
 * Deliberately not typed by document *kind* from the user's point of view —
 * they paste a link, drop a file or type text, and we work out the rest.
 */
class SourceResolver
{
    public function __construct(
        protected UrlSource $url,
        protected GoogleDriveSource $drive,
        protected FileSource $file,
        protected AssetSource $asset,
    ) {}

    /**
     * @param  array  $inputs  Each: ['type' => ..., 'value' => ...] or an UploadedFile.
     * @return array<Source>
     */
    public function resolve(array $inputs): array
    {
        return collect($inputs)
            ->map(fn ($input) => $this->one($input))
            ->flatten()
            ->filter()
            ->values()
            ->all();
    }

    protected function one(mixed $input): array
    {
        if ($input instanceof UploadedFile) {
            return [$this->file->fromUpload($input)];
        }

        if (is_string($input)) {
            $input = ['type' => 'auto', 'value' => $input];
        }

        $type = Arr::get($input, 'type', 'auto');
        $value = Arr::get($input, 'value');

        if (! filled($value)) {
            return [];
        }

        if ($type === 'auto') {
            $type = $this->sniff($value);
        }

        return match ($type) {
            'url' => [$this->url->fetch($value)],
            'drive' => $this->drive->fetch($value),
            'asset' => [$this->asset->fetch($value)],
            'file' => [$this->file->fromPath($value)],
            default => [Source::text(Arr::get($input, 'label', 'Pasted text'), $value, 'text')],
        };
    }

    protected function sniff(string $value): string
    {
        $value = trim($value);

        if (! preg_match('~^https?://~i', $value)) {
            return 'text';
        }

        return GoogleDriveSource::handles($value) ? 'drive' : 'url';
    }
}
