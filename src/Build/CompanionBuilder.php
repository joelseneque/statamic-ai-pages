<?php

namespace Joelseneque\AiPages\Build;

use Illuminate\Support\Str;
use Joelseneque\AiPages\Schema\BlueprintCompiler;
use Joelseneque\AiPages\Schema\ValueCoercer;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry;
use Throwable;

/**
 * Builds the supporting entries a page needs in order to work.
 *
 * On a site where FAQs are their own collection, "write a page about gastric
 * balloons" really means "write the page, and write the eight FAQ entries it
 * points at". Those are built here, before the page's own sections, so that by
 * the time the FAQ block is filled in the new entries genuinely exist and can
 * be referenced like any other.
 *
 * Existing entries are never touched — a companion is only ever created, and
 * only in a collection the blueprint marked creatable.
 */
class CompanionBuilder
{
    public function __construct(
        protected BlueprintCompiler $compiler,
        protected Hydrator $hydrator,
        protected EntryWriter $writer,
    ) {}

    /**
     * @param  array  $planned  [['collection' => 'faqs', 'title' => .., 'brief' => ..], ...]
     * @param  array  $allowed  Collection handles the blueprint permits.
     * @return array{created: array, warnings: array}
     */
    public function build(BuildRequest $request, array $planned, array $allowed, array $outline, callable $progress): array
    {
        $created = [];
        $warnings = [];
        $total = count($planned);

        foreach ($planned as $index => $companion) {
            $collection = $companion['collection'] ?? null;
            $title = trim((string) ($companion['title'] ?? ''));

            if (! $collection || ! in_array($collection, $allowed, true)) {
                $warnings[] = "Skipped a supporting entry for [{$collection}] — that collection isn't creatable from this page.";

                continue;
            }

            if ($title === '') {
                continue;
            }

            // Don't duplicate something the site already says.
            if ($existing = $this->existing($collection, $title, $request->site)) {
                $created[] = [
                    'collection' => $collection,
                    'title' => $existing->get('title'),
                    'slug' => $existing->slug(),
                    'id' => $existing->id(),
                    'reused' => true,
                ];

                continue;
            }

            $progress('Writing supporting entry '.($index + 1)." of {$total}: {$title}", [
                'step' => $index + 1,
                'total' => $total,
            ]);

            // A dry run must leave the site exactly as it found it, and these
            // are real entries in another collection — the easiest thing in the
            // whole build to create by accident and never notice.
            if ($request->dry) {
                $created[] = [
                    'collection' => $collection,
                    'title' => $title,
                    'slug' => Str::slug($title),
                    'id' => null,
                    'reused' => false,
                    'planned_only' => true,
                ];

                continue;
            }

            try {
                $entry = $this->one($request, $collection, $companion, $outline);
            } catch (Throwable $e) {
                $warnings[] = "Couldn't write the supporting entry “{$title}”: ".$e->getMessage();

                continue;
            }

            $created[] = [
                'collection' => $collection,
                'title' => $entry->get('title'),
                'slug' => $entry->slug(),
                'id' => $entry->id(),
                'edit_url' => $entry->editUrl(),
                'reused' => false,
            ];
        }

        return ['created' => $created, 'warnings' => $warnings];
    }

    protected function one(BuildRequest $request, string $collection, array $companion, array $outline)
    {
        $compiled = $this->compiler->forCollection($collection);
        $blueprint = reset($compiled['blueprints']);

        $coercer = ValueCoercer::make($request->site, $collection);

        // A companion is a small entry, so it is filled in one pass rather than
        // planned and hydrated section by section.
        $fields = collect($blueprint['fields'])
            ->reject(fn ($f) => in_array($f['handle'], ['title', 'slug'], true))
            ->reject(fn ($f) => in_array($f['type'] ?? '', BlueprintCompiler::UNSAFE_TYPES, true))
            ->values()
            ->all();

        $companionRequest = new BuildRequest(
            collection: $collection,
            sources: $request->sources,
            site: $request->site,
            mode: $request->mode,
            title: $companion['title'],
        );

        $data = $this->hydrator->hydrateFields(
            $companionRequest,
            $fields,
            "a “{$companion['title']}” entry in the {$collection} collection",
            array_merge($outline, ['summary' => $companion['brief'] ?? ($outline['summary'] ?? '')]),
            $blueprint['sets'] ?? [],
            $coercer,
            $companion['brief'] ?? null,
        );

        $data['title'] = $companion['title'];

        $result = new BuildResult(outline: $outline, data: $data);

        return $this->writer->create($companionRequest, $result);
    }

    protected function existing(string $collection, string $title, ?string $site)
    {
        if (! CollectionFacade::findByHandle($collection)) {
            return null;
        }

        return Entry::query()
            ->where('collection', $collection)
            ->when($site, fn ($q) => $q->where('site', $site))
            ->where('slug', Str::slug($title))
            ->first();
    }
}
