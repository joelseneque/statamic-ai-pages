<?php

namespace Joelseneque\AiPages\Support;

use Statamic\Support\Str;

class Id
{
    /**
     * Statamic uses 21-character nanoid-style ids for Replicator/Bard sets.
     */
    public static function generate(): string
    {
        return Str::random(21);
    }
}
