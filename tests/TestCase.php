<?php

namespace Joelseneque\AiPages\Tests;

use Joelseneque\AiPages\ServiceProvider;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;
}
