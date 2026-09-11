<?php

namespace Joelseneque\AiPages\Build;

use Closure;
use Illuminate\Support\Arr;
use Joelseneque\AiPages\Anthropic\Client;
use Joelseneque\AiPages\Schema\BlueprintCompiler;
use Joelseneque\AiPages\Schema\Conventions;
use Joelseneque\AiPages\Schema\SetCatalogue;
use Joelseneque\AiPages\Schema\ValueCoercer;
use Joelseneque\AiPages\Support\MarkdownToBard;
use RuntimeException;

/**
 * Orchestrates a build: plan, then fill in each part, then assemble.
 *
 * Nothing is written to the site here — the result is handed back for preview
 * and only persisted when someone accepts it.
 */
class PageBuilder
{
    protected ?Closure $onProgress = null;

    public function __construct(
        protected Client $client,
        protected BlueprintCompiler $compiler,
        protected OutlinePlanner $planner,
        protected Hydrator $hydrator,
        protected Conventions $conventions,
    ) {}

    public function onProgress(Closure $callback): self
    {
        $this->onProgress = $callback;

        return $this;
    }

    public function build(BuildRequest $request): BuildResult
    {
        $blueprint = $this->blueprint($request);
        $sets = $blueprint['sets'];
        $catalogue = (new SetCatalogue($sets))->withPlacements(
            $this->conventions->forCollection($request->collection)['set_placements'] ?? []
        );

        // PDFs and images carry no text of their own; the verbatim guard needs
        // it, so transcribe once up front rather than per section.
        if ($request->mode === BuildRequest::MODE_VERBATIM) {
            $this->progress('Reading the source material');
            $request->sources = $request->sources->transcribe($this->client);
        }

        $this->progress('Planning the page');
        $outline = $this->planner->plan($request, $catalogue);

        $coercer = ValueCoercer::make($request->site, $request->collection);
        $contentField = $this->contentField($blueprint, $outline);

        // Everything outside the page-builder field: hero, SEO, settings.
        $this->progress('Writing the page settings');
        $data = $this->hydrateTopLevel($request, $blueprint, $outline, $sets, $coercer, $contentField);

        $sections = [];
        $planned = $outline['sections'] ?? [];
        $total = count($planned);

        foreach ($planned as $index => $section) {
            $handle = $section['set'] ?? null;

            if (! $handle || ! isset($sets[$handle])) {
                $coercer->warnings();
                $sections[] = null;

                continue;
            }

            $this->progress('Writing section '.($index + 1)." of {$total}: ".($sets[$handle]['display'] ?? $handle), [
                'step' => $index + 1,
                'total' => $total,
            ]);

            $section['_index'] = $index;

            $values = $this->hydrator->hydrateSet(
                $request,
                $sets[$handle],
                $section,
                $sets,
                array_merge($outline, ['sections' => $this->indexed($planned)]),
                $coercer,
            );

            $sections[] = array_merge($section, ['values' => $values]);
        }

        $sections = array_values(array_filter($sections));

        if ($contentField) {
            $data[$contentField] = $this->assembleContent($sections);
        }

        $data = array_merge($data, array_filter([
            'title' => $request->title ?: ($outline['title'] ?? null),
        ]));

        $fidelity = [];

        if ($request->mode === BuildRequest::MODE_VERBATIM) {
            $this->progress('Checking the wording against the source');
            $fidelity = FidelityGuard::make()->check($request->sources->plain(), $sections);
        }

        return new BuildResult(
            outline: $outline,
            sections: $sections,
            data: $data,
            warnings: $coercer->warnings(),
            fidelity: $fidelity,
            usage: $this->client->usage(),
            cost: $this->client->estimatedCost(),
            contentField: $contentField,
        );
    }

    /**
     * Which blueprint field holds the page's sections — the one whose available
     * sets best cover what the planner chose.
     */
    protected function contentField(array $blueprint, array $outline): ?string
    {
        $planned = collect($outline['sections'] ?? [])->pluck('set')->filter()->unique();

        $candidates = collect($blueprint['fields'])
            ->filter(fn ($f) => in_array($f['type'] ?? '', ['bard', 'replicator'], true) && ! empty($f['sets']));

        if ($candidates->isEmpty()) {
            return null;
        }

        return $candidates
            ->sortByDesc(fn ($f) => $planned->intersect($f['sets'])->count())
            ->first()['handle'];
    }

    protected function hydrateTopLevel(
        BuildRequest $request,
        array $blueprint,
        array $outline,
        array $sets,
        ValueCoercer $coercer,
        ?string $contentField,
    ): array {
        $fields = collect($blueprint['fields'])
            ->reject(fn ($f) => in_array($f['handle'], array_filter([$contentField, 'title', 'slug']), true))
            ->reject(fn ($f) => in_array($f['type'] ?? '', BlueprintCompiler::UNSAFE_TYPES, true))
            ->values()
            ->all();

        if (! $fields) {
            return [];
        }

        // The opening of the source usually feeds the hero and the meta.
        $sourceText = collect($outline['sections'] ?? [])
            ->pluck('source_text')
            ->filter()
            ->take(2)
            ->implode("\n\n");

        $values = $this->hydrator->hydrateFields(
            $request,
            $fields,
            'the page settings (hero, meta and options — not the body sections)',
            $outline,
            $sets,
            $coercer,
            $sourceText ?: null,
        );

        return $this->applySeo($values, $outline, $fields);
    }

    /**
     * The planner already wrote the meta title and description; drop them into
     * whichever SEO fields the blueprint happens to use, if they're still empty.
     */
    protected function applySeo(array $values, array $outline, array $fields): array
    {
        $map = [
            'seo_title' => ['seo_title', 'meta_title', 'title_tag'],
            'seo_description' => ['seo_description', 'meta_description', 'description'],
        ];

        $handles = collect($fields)->pluck('handle')->flip();

        foreach ($map as $from => $targets) {
            if (! $value = $outline[$from] ?? null) {
                continue;
            }

            foreach ($targets as $target) {
                if ($handles->has($target) && ! filled(Arr::get($values, $target))) {
                    $values[$target] = $value;

                    break;
                }
            }
        }

        return $values;
    }

    /**
     * Bard stores prose nodes and set nodes in one flat array.
     */
    protected function assembleContent(array $sections): array
    {
        $markdown = MarkdownToBard::make();

        return collect($sections)
            ->map(fn ($section) => $markdown->setNode($section['set'], Arr::except($section['values'], 'type')))
            ->values()
            ->all();
    }

    protected function blueprint(BuildRequest $request): array
    {
        $compiled = $this->compiler->forCollection($request->collection);
        $blueprints = $compiled['blueprints'];

        if ($request->blueprint) {
            if (! isset($blueprints[$request->blueprint])) {
                throw new RuntimeException("Blueprint [{$request->blueprint}] not found in [{$request->collection}].");
            }

            return $blueprints[$request->blueprint];
        }

        return reset($blueprints) ?: throw new RuntimeException("Collection [{$request->collection}] has no blueprints.");
    }

    protected function indexed(array $sections): array
    {
        foreach ($sections as $i => $section) {
            $sections[$i]['_index'] = $i;
        }

        return $sections;
    }

    protected function progress(string $message, array $meta = []): void
    {
        if ($this->onProgress) {
            ($this->onProgress)($message, $meta);
        }
    }
}
