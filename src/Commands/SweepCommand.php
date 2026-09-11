<?php

namespace Joelseneque\AiPages\Commands;

use Illuminate\Console\Command;
use Joelseneque\AiPages\Anthropic\Client;
use Joelseneque\AiPages\Instructions\InstructionRepository;
use Joelseneque\AiPages\Instructions\InstructionSweep;
use Joelseneque\AiPages\Instructions\SchemaGuideGenerator;
use Statamic\Console\RunsInPlease;

class SweepCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'ai-pages:sweep
        {--profile : Only regenerate the site profile}
        {--tone : Only regenerate the tone of voice guide}
        {--schema : Only regenerate the per-collection block guides}
        {--collection=* : Limit the schema pass to these collections}
        {--measure-only : Re-measure conventions from content without calling the API}
        {--notes= : Extra context to pass to the generators}';

    protected $description = 'Read the site and (re)build the instructions AI Pages writes from';

    public function handle(
        InstructionSweep $sweep,
        SchemaGuideGenerator $schema,
        InstructionRepository $instructions,
        Client $client,
    ): int {
        $collections = $this->option('collection') ?: $sweep->collections();

        if ($this->option('measure-only')) {
            foreach ($collections as $collection) {
                $analysis = $schema->remeasure($collection);
                $this->line("  <info>✓</info> {$collection} — {$analysis['entries_analysed']} entries measured");
            }

            $this->newLine();
            $this->info('Conventions updated. No API calls made.');

            return self::SUCCESS;
        }

        if (! $client->configured()) {
            $this->error('No Anthropic API key. Set ANTHROPIC_API_KEY in your .env.');

            return self::FAILURE;
        }

        $parts = array_keys(array_filter([
            'profile' => $this->option('profile'),
            'tone' => $this->option('tone'),
            'schema' => $this->option('schema'),
        ])) ?: ['profile', 'tone', 'schema'];

        $this->info('Sweeping the site with '.$client->model().'…');
        $this->newLine();

        $sweep->onProgress(function ($message, $meta) {
            $step = isset($meta['step']) ? "[{$meta['step']}/{$meta['total']}] " : '';
            $this->line("  {$step}{$message}");
        });

        $written = $sweep->run($parts, null, $this->option('collection'), $this->option('notes'));

        $this->newLine();
        $this->info('Written to '.$instructions->path().':');

        foreach ($written as $file) {
            $this->line("  <info>✓</info> {$file}.md");
        }

        $usage = $client->usage();
        $this->newLine();
        $this->line(sprintf(
            '  %d API calls · %s in / %s out tokens · roughly $%s',
            $usage['calls'],
            number_format($usage['input_tokens']),
            number_format($usage['output_tokens']),
            number_format($client->estimatedCost(), 2),
        ));

        $this->newLine();
        $this->comment('Read them before your first build — they are a starting point, not gospel.');
        $this->comment('house-rules.md is yours to write and is never overwritten.');

        return self::SUCCESS;
    }
}
