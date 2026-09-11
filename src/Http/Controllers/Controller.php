<?php

namespace Joelseneque\AiPages\Http\Controllers;

use Joelseneque\AiPages\Anthropic\Client;
use Joelseneque\AiPages\Instructions\InstructionRepository;
use Statamic\Http\Controllers\CP\CpController;

abstract class Controller extends CpController
{
    protected function assertConfigured(): void
    {
        abort_unless(
            app(Client::class)->configured(),
            503,
            'AI Pages has no Anthropic API key. Add ANTHROPIC_API_KEY to your .env file.'
        );
    }

    protected function instructionsReady(): bool
    {
        return app(InstructionRepository::class)->isConfigured();
    }
}
