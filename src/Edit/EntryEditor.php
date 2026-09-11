<?php

namespace Joelseneque\AiPages\Edit;

use Closure;
use Illuminate\Support\Arr;
use Joelseneque\AiPages\Anthropic\Client;
use Joelseneque\AiPages\Build\BuildRequest;
use Joelseneque\AiPages\Build\Hydrator;
use Joelseneque\AiPages\Build\SourceBundle;
use Joelseneque\AiPages\Schema\BlueprintCompiler;
use Joelseneque\AiPages\Schema\Conventions;
use Joelseneque\AiPages\Schema\SetCatalogue;
use Joelseneque\AiPages\Schema\ValueCoercer;
use Joelseneque\AiPages\Support\MarkdownToBard;
use RuntimeException;
use Statamic\Contracts\Entries\Entry;

/**
 * Applies a planned patch to an entry, producing new field data.
 *
 * Operations are resolved against the ORIGINAL section numbering, so a patch
 * that deletes section 2 and edits section 5 does what it looks like it does,
 * rather than shifting under its own feet.
 */
class EntryEditor
{
    protected ?Closure $onProgress = null;

    public function __construct(
        protected Client $client,
        protected BlueprintCompiler $compiler,
        protected EntryDigest $digester,
        protected PatchPlanner $planner,
        protected Hydrator $hydrator,
        protected Conventions $conventions,
    ) {}

    public function onProgress(Closure $callback): self
    {
        $this->onProgress = $callback;

        return $this;
    }

    /**
     * @return array{patch: array, data: array, warnings: array, digest: array, usage: array, cost: float}
     */
    public function edit(Entry $entry, string $instruction, ?SourceBundle $sources = null): array
    {
        $collection = $entry->collection()->handle();
        $compiled = $this->compiler->forCollection($collection);
        $blueprint = $compiled['blueprints'][$entry->blueprint()->handle()]
            ?? reset($compiled['blueprints']);

        $sets = $blueprint['sets'];
        $catalogue = (new SetCatalogue($sets))->withPlacements(
            $this->conventions->forCollection($collection)['set_placements'] ?? []
        );

        $this->progress('Reading the current page');
        $digest = $this->digester->make($entry, $blueprint);

        if ($sources && ! $sources->isEmpty()) {
            $instruction .= "\n\nUse this supplied material:\n".$sources->plain();
        }

        $this->progress('Planning the changes');
        $patch = $this->planner->plan($instruction, $digest, $catalogue, $collection);

        $this->progress('Writing the changes');
        $result = $this->apply($entry, $patch, $digest, $blueprint, $sets, $instruction);

        return [
            'patch' => $patch,
            'data' => $result['data'],
            'warnings' => $result['warnings'],
            'digest' => $digest,
            'usage' => $this->client->usage(),
            'cost' => $this->client->estimatedCost(),
        ];
    }

    protected function apply(
        Entry $entry,
        array $patch,
        array $digest,
        array $blueprint,
        array $sets,
        string $instruction,
    ): array {
        $contentField = $digest['content_field'];
        $sections = $digest['sections'];
        $coercer = ValueCoercer::make($entry->locale(), $entry->collection()->handle());
        $markdown = MarkdownToBard::make($entry->collection()->handle());

        // A synthetic request so the hydrator's prompts read the same as a build.
        $request = new BuildRequest(
            collection: $entry->collection()->handle(),
            sources: new SourceBundle,
            blueprint: $entry->blueprint()->handle(),
            site: $entry->locale(),
            mode: BuildRequest::MODE_GENERATE,
        );

        $outline = [
            'title' => $entry->get('title'),
            'summary' => $patch['summary'] ?? $instruction,
            'sections' => collect($sections)->map(fn ($s, $i) => [
                'set' => $s['type'] ?? 'prose',
                'heading' => null,
                '_index' => $i,
            ])->all(),
        ];

        $data = [];
        $inserts = [];
        $deletes = [];
        $moves = [];

        foreach ($patch['operations'] ?? [] as $operation) {
            $op = $operation['op'] ?? null;
            $index = isset($operation['index']) ? (int) $operation['index'] - 1 : null;

            switch ($op) {
                case 'update_settings':
                    $fields = collect($blueprint['fields'])
                        ->reject(fn ($f) => in_array($f['handle'], [$contentField, 'title', 'slug'], true))
                        ->reject(fn ($f) => in_array($f['type'] ?? '', BlueprintCompiler::UNSAFE_TYPES, true))
                        ->values()
                        ->all();

                    $data = array_merge($data, $this->hydrator->hydrateFields(
                        $request,
                        $fields,
                        'the page settings — apply this change and leave everything else as it is: '
                            .($operation['brief'] ?? $instruction),
                        $outline,
                        $sets,
                        $coercer,
                    ));
                    break;

                case 'edit_section':
                case 'replace_section':
                    if ($index === null || ! isset($sections[$index])) {
                        break;
                    }

                    $handle = $operation['set'] ?? $sections[$index]['type'];

                    if (! $handle || ! isset($sets[$handle])) {
                        break;
                    }

                    $existing = $sections[$index]['values'] ?? [];

                    $brief = $op === 'edit_section'
                        ? "This block already exists. Reproduce it exactly, changing only this: "
                            .($operation['brief'] ?? $instruction)
                            ."\n\nIts current values:\n```yaml\n".\Statamic\Facades\YAML::dump(Arr::except($existing, 'id'))."\n```"
                        : ($operation['brief'] ?? $instruction);

                    $values = $this->hydrator->hydrateSet(
                        $request,
                        $sets[$handle],
                        ['set' => $handle, 'brief' => $brief, '_index' => $index],
                        $sets,
                        $outline,
                        $coercer,
                    );

                    $sections[$index] = [
                        'type' => $handle,
                        'id' => $sections[$index]['id'] ?? null,
                        'values' => array_merge(['type' => $handle], $values),
                    ];
                    break;

                case 'insert_section':
                    $handle = $operation['set'] ?? null;

                    if (! $handle || ! isset($sets[$handle])) {
                        break;
                    }

                    $values = $this->hydrator->hydrateSet(
                        $request,
                        $sets[$handle],
                        ['set' => $handle, 'brief' => $operation['brief'] ?? $instruction, '_index' => $index ?? 0],
                        $sets,
                        $outline,
                        $coercer,
                    );

                    $inserts[] = ['after' => $index ?? -1, 'section' => [
                        'type' => $handle,
                        'values' => array_merge(['type' => $handle], $values),
                    ]];
                    break;

                case 'delete_section':
                    if ($index !== null) {
                        $deletes[] = $index;
                    }
                    break;

                case 'move_section':
                    if ($index !== null && isset($operation['to'])) {
                        $moves[] = ['from' => $index, 'to' => (int) $operation['to'] - 1];
                    }
                    break;
            }
        }

        if ($contentField) {
            $sections = $this->reorder($sections, $inserts, $deletes, $moves);

            $data[$contentField] = collect($sections)
                ->map(function ($section) use ($markdown) {
                    // Loose prose runs are spliced back in untouched.
                    if (($section['type'] ?? null) === null) {
                        return $section['nodes'] ?? [];
                    }

                    return [$markdown->setNode(
                        $section['type'],
                        Arr::except($section['values'] ?? [], 'type'),
                        $section['id'] ?? null
                    )];
                })
                ->flatten(1)
                ->values()
                ->all();
        }

        return ['data' => $data, 'warnings' => $coercer->warnings()];
    }

    /**
     * Resolve inserts, deletes and moves against the original indices.
     */
    protected function reorder(array $sections, array $inserts, array $deletes, array $moves): array
    {
        foreach ($deletes as $index) {
            unset($sections[$index]);
        }

        $sections = array_values($sections);

        // Inserts are applied from the end so earlier positions stay valid.
        usort($inserts, fn ($a, $b) => $b['after'] <=> $a['after']);

        foreach ($inserts as $insert) {
            $at = max(0, min(count($sections), $insert['after'] + 1));
            array_splice($sections, $at, 0, [$insert['section']]);
        }

        foreach ($moves as $move) {
            if (! isset($sections[$move['from']])) {
                continue;
            }

            $moving = array_splice($sections, $move['from'], 1);
            array_splice($sections, max(0, min(count($sections), $move['to'])), 0, $moving);
        }

        return $sections;
    }

    protected function progress(string $message): void
    {
        if ($this->onProgress) {
            ($this->onProgress)($message, []);
        }
    }
}
