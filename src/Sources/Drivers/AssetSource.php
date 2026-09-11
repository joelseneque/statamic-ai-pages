<?php

namespace Joelseneque\AiPages\Sources\Drivers;

use Joelseneque\AiPages\Sources\Source;
use RuntimeException;
use Statamic\Facades\Asset;

/**
 * Source material that's already in the asset library — handy for briefs the
 * client has uploaded through the CP rather than re-uploading here.
 */
class AssetSource
{
    public function __construct(protected FileSource $files) {}

    public function fetch(string $reference): Source
    {
        $asset = Asset::find($reference);

        if (! $asset) {
            throw new RuntimeException("No asset found for [{$reference}].");
        }

        return $this->files->fromBinary(
            $asset->basename(),
            (string) $asset->contents(),
            strtolower($asset->extension())
        );
    }
}
