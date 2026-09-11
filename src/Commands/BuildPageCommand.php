<?php

namespace Joelseneque\AiPages\Commands;

use Illuminate\Console\Command;
use Joelseneque\AiPages\Anthropic\Client;
use Joelseneque\AiPages\Build\BuildRequest;
use Joelseneque\AiPages\Build\EntryWriter;
use Joelseneque\AiPages\Build\PageBuilder;
use Joelseneque\AiPages\Build\SourceBundle;
use Joelseneque\AiPages\Sources\SourceResolver;
use Statamic\Console\RunsInPlease;

class BuildPageCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'ai-pages:build
        {collection : The collection to build into}
        {--source=* : A file path, URL, Google Drive link, or literal text}
        {--mode=polish : verbatim, polish or generate}
        {--title= : Force the page title}
        {--brief= : Extra direction for the build}
        {--blueprint= : Which blueprint to use}
        {--site= : Which site}
        {--dry : Plan and build, but do not save the entry}';

    protected $description = 'Build a draft entry from source material';

    public function handle(
        SourceResolver $resolver,
        PageBuilder $builder,
        EntryWriter $writer,
        Client $client,
    ): int {
        if (! $client->configured()) {
            $this->error('No Anthropic API key. Set ANTHROPIC_API_KEY in your .env.');

            return self::FAILURE;
        }

        if (! $sources = $this->option('source')) {
            $this->error('Give it something to work from with --source.');

            return self::FAILURE;
        }

        $request = new BuildRequest(
            collection: $this->argument('collection'),
            sources: new SourceBundle($resolver->resolve(array_map(
                fn ($s) => is_file($s) ? ['type' => 'file', 'value' => $s] : ['type' => 'auto', 'value' => $s],
                $sources
            ))),
            blueprint: $this->option('blueprint'),
            site: $this->option('site'),
            mode: $this->option('mode'),
            title: $this->option('title'),
            brief: $this->option('brief'),
            dry: (bool) $this->option('dry'),
        );

        $this->info("Building a {$request->mode} draft in [{$request->collection}] with ".$client->model());
        $this->newLine();

        $builder->onProgress(fn ($message) => $this->line("  {$message}"));

        $result = $builder->build($request);

        $this->newLine();
        $this->info('Planned: '.($result->outline['title'] ?? 'Untitled'));

        foreach ($result->sections as $i => $section) {
            $this->line(sprintf('  %2d. %s', $i + 1, $section['set']));
        }

        if ($result->companions) {
            $this->newLine();
            $this->info('Supporting entries:');

            foreach ($result->companions as $companion) {
                $this->line(sprintf(
                    '  %s %s  <fg=gray>(%s)%s</>',
                    ($companion['reused'] ?? false) ? '·' : '+',
                    $companion['title'],
                    $companion['collection'],
                    ($companion['planned_only'] ?? false) ? ' — not written, dry run' : '',
                ));
            }
        }

        if ($result->warnings) {
            $this->newLine();
            $this->comment('Warnings:');

            foreach ($result->warnings as $warning) {
                $this->line("  - {$warning}");
            }
        }

        if ($fidelity = $result->fidelity) {
            $this->newLine();
            $this->line('  Verbatim score: '.($fidelity['score'] * 100).'%');

            foreach ($fidelity['drifted'] as $drift) {
                $this->warn("  Reworded in section {$drift['section']}: \"{$drift['output']}\"");
            }

            foreach ($fidelity['missing'] as $missing) {
                $this->warn("  Missing from the page: \"{$missing}\"");
            }
        }

        if ($this->option('dry')) {
            $this->newLine();
            $this->comment('Dry run — nothing saved.');

            return self::SUCCESS;
        }

        $entry = $writer->create($request, $result);

        $this->newLine();
        $this->info('Draft saved: '.$entry->editUrl());
        $this->line(sprintf(
            '  %d API calls · roughly $%s',
            $client->usage()['calls'],
            number_format($client->estimatedCost(), 2),
        ));

        return self::SUCCESS;
    }
}
