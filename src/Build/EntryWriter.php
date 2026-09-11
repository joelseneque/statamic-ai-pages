<?php

namespace Joelseneque\AiPages\Build;

use Illuminate\Support\Str;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;

/**
 * Persists a build.
 *
 * New entries are created unpublished by default — an AI draft should land
 * where a human sees it before the public does. Updates to an existing entry
 * always leave a revision behind if the collection has revisions on.
 */
class EntryWriter
{
    public function create(BuildRequest $request, BuildResult $result): \Statamic\Contracts\Entries\Entry
    {
        $site = $request->site ?: Site::default()->handle();
        $collection = CollectionFacade::findByHandle($request->collection);

        $slug = $this->uniqueSlug(
            $request->collection,
            $site,
            $request->suggestedSlug() ?: Str::slug($result->outline['slug'] ?? $result->outline['title'] ?? 'untitled')
        );

        $entry = Entry::make()
            ->collection($request->collection)
            ->blueprint($request->blueprint ?: $collection?->entryBlueprints()->first()?->handle())
            ->locale($site)
            ->slug($slug)
            ->published($request->publish && config('ai-pages.publish_new_entries', false))
            ->data($this->prepare($result->data));

        if ($request->parent && $collection?->structure()) {
            $entry->save();
            $collection->structure()->in($site)?->appendTo($request->parent, $entry)->save();

            return $entry;
        }

        $entry->save();

        return $entry;
    }

    public function update(\Statamic\Contracts\Entries\Entry $entry, array $data): \Statamic\Contracts\Entries\Entry
    {
        if ($entry->revisionsEnabled()) {
            $entry->makeWorkingCopy()
                ->user(User::current())
                ->message('Updated by AI Pages')
                ->save();
        }

        $entry->merge($this->prepare($data))->save();

        return $entry;
    }

    /**
     * Strip anything empty so the entry file stays readable, and never let a
     * generated value overwrite the entry's identity fields.
     */
    protected function prepare(array $data): array
    {
        return collect($data)
            ->except(['id', 'blueprint', 'collection', 'published'])
            ->reject(fn ($value) => $value === null || $value === '' || $value === [])
            ->all();
    }

    protected function uniqueSlug(string $collection, string $site, string $slug): string
    {
        $slug = Str::slug($slug) ?: 'untitled';
        $candidate = $slug;
        $suffix = 1;

        while (Entry::query()
            ->where('collection', $collection)
            ->where('site', $site)
            ->where('slug', $candidate)
            ->count() > 0) {
            $candidate = $slug.'-'.(++$suffix);
        }

        return $candidate;
    }
}
