<?php

namespace Joelseneque\AiPages\Instructions;

use Closure;
use Joelseneque\AiPages\Anthropic\Client;
use Joelseneque\AiPages\Build\SourceBundle;
use Statamic\Facades\Collection as CollectionFacade;

/**
 * The "learn this site" pass.
 *
 * Run it once when the addon is installed, and again whenever the blueprints or
 * the site's copy have moved on. Everything it produces lands in
 * resources/ai-pages as Markdown you can edit by hand — the generated files are
 * a starting point, not gospel.
 */
class InstructionSweep
{
    protected ?Closure $onProgress = null;

    public function __construct(
        protected Client $client,
        protected SiteProfileGenerator $profile,
        protected ToneOfVoiceGenerator $tone,
        protected SchemaGuideGenerator $schema,
        protected InstructionRepository $instructions,
    ) {}

    public function onProgress(Closure $callback): self
    {
        $this->onProgress = $callback;

        return $this;
    }

    /**
     * @param  array  $parts  Any of: profile, tone, schema.
     * @return array<string> The instruction files written.
     */
    public function run(
        array $parts = ['profile', 'tone', 'schema'],
        ?string $site = null,
        array $collections = [],
        ?string $notes = null,
        ?SourceBundle $toneSources = null,
    ): array {
        $written = [];
        $collections = $collections ?: $this->collections();
        $total = (int) in_array('profile', $parts, true)
            + (int) in_array('tone', $parts, true)
            + (in_array('schema', $parts, true) ? count($collections) : 0);
        $step = 0;

        if (in_array('profile', $parts, true)) {
            $this->progress('Reading the site', ++$step, $total);
            $this->profile->generate($site, $notes);
            $written[] = 'site-profile';
        }

        if (in_array('tone', $parts, true)) {
            $this->progress('Working out the tone of voice', ++$step, $total);
            $this->tone->generate($toneSources, $site, $notes);
            $written[] = 'tone-of-voice';
        }

        if (in_array('schema', $parts, true)) {
            foreach ($collections as $collection) {
                $this->progress("Studying how [{$collection}] pages are built", ++$step, $total);
                $this->schema->generate($collection, $site);
                $written[] = "schema/{$collection}";
            }
        }

        $this->ensureHouseRules();

        return $written;
    }

    public function usage(): array
    {
        return $this->client->usage();
    }

    public function cost(): float
    {
        return $this->client->estimatedCost();
    }

    public function collections(): array
    {
        $allowed = config('ai-pages.collections', ['*']);

        return CollectionFacade::all()
            ->when($allowed !== ['*'], fn ($c) => $c->filter(fn ($col) => in_array($col->handle(), $allowed, true)))
            ->map->handle()
            ->values()
            ->all();
    }

    /**
     * Hand-written rules are never overwritten by a sweep, so seed the file
     * once with a prompt to fill it in.
     */
    protected function ensureHouseRules(): void
    {
        if ($this->instructions->exists('house-rules')) {
            return;
        }

        $this->instructions->put('house-rules', <<<'MARKDOWN'
        # House rules

        Anything you write here wins over everything the sweep generated. It is never overwritten.

        Use it for the things a machine reading the site can't infer — decisions, not descriptions.

        ## Examples to replace with your own

        - Never state a price. Link to the fees page instead.
        - Every page that mentions eligibility gets an "Am I eligible?" button linking to /am-i-eligible.
        - Use "weight management", never "weight loss journey".
        - Australian English, always.
        - Don't open a page with a question.
        MARKDOWN);
    }

    protected function progress(string $message, int $step, int $total): void
    {
        if ($this->onProgress) {
            ($this->onProgress)($message, ['step' => $step, 'total' => $total]);
        }
    }
}
