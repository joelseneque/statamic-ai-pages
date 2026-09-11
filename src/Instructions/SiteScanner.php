<?php

namespace Joelseneque\AiPages\Instructions;

use Illuminate\Support\Str;
use Joelseneque\AiPages\Support\BardToText;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;

/**
 * Deterministic pass over the site — no AI involved.
 *
 * This produces the factual digest that the profile and tone generators then
 * write prose from. Keeping the gathering separate means the expensive part is
 * reproducible and inspectable when the generated profile looks wrong.
 */
class SiteScanner
{
    public function scan(?string $site = null): array
    {
        $site = $site ?: Site::default()->handle();

        return [
            'site' => $this->site($site),
            'navigation' => $this->navigation($site),
            'collections' => $this->collections($site),
            'taxonomies' => $this->taxonomies(),
            'globals' => $this->globals($site),
        ];
    }

    public function toMarkdown(array $scan): string
    {
        $out = ["# Site scan\n"];

        $out[] = "## Site\n";
        foreach ($scan['site'] as $key => $value) {
            $out[] = "- {$key}: {$value}";
        }

        if ($scan['navigation']) {
            $out[] = "\n## Navigation\n";
            foreach ($scan['navigation'] as $nav => $tree) {
                $out[] = "### {$nav}";
                $out[] = $this->renderTree($tree);
            }
        }

        $out[] = "\n## Collections\n";
        foreach ($scan['collections'] as $handle => $collection) {
            $out[] = "### {$handle} — {$collection['title']} ({$collection['count']} entries)";
            $out[] = '- URL pattern: '.($collection['route'] ?: 'not routable');
            $out[] = '- Blueprints: '.implode(', ', $collection['blueprints']);

            foreach ($collection['samples'] as $sample) {
                $out[] = "\n**{$sample['title']}** (`{$sample['url']}`)";
                $out[] = Str::limit($sample['excerpt'], 700);
            }

            $out[] = '';
        }

        if ($scan['taxonomies']) {
            $out[] = "\n## Taxonomies\n";
            foreach ($scan['taxonomies'] as $handle => $taxonomy) {
                $out[] = "- **{$handle}** ({$taxonomy['title']}): ".implode(', ', $taxonomy['terms']);
            }
        }

        if ($scan['globals']) {
            $out[] = "\n## Globals\n";
            foreach ($scan['globals'] as $handle => $values) {
                $out[] = "### {$handle}";
                $out[] = '```yaml';
                $out[] = Str::limit(json_encode($values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 1500);
                $out[] = '```';
            }
        }

        return implode("\n", $out);
    }

    protected function site(string $handle): array
    {
        $site = Site::get($handle);

        return array_filter([
            'handle' => $handle,
            'name' => $site?->name(),
            'url' => $site?->absoluteUrl(),
            'locale' => $site?->locale(),
            'app_name' => config('app.name'),
        ]);
    }

    protected function navigation(string $site): array
    {
        return Nav::all()->mapWithKeys(function ($nav) use ($site) {
            $tree = $nav->in($site)?->tree() ?? [];

            return [$nav->title() => $this->flattenTree($tree, $site)];
        })->filter()->all();
    }

    protected function flattenTree(array $branches, string $site, int $depth = 0): array
    {
        if ($depth > 3) {
            return [];
        }

        return collect($branches)->map(function ($branch) use ($site, $depth) {
            $title = $branch['title'] ?? null;
            $url = null;

            if ($id = $branch['entry'] ?? null) {
                $entry = Entry::find($id);
                $title ??= $entry?->get('title');
                $url = $entry?->url();
            }

            return array_filter([
                'title' => $title ?: ($branch['url'] ?? 'Untitled'),
                'url' => $url ?: ($branch['url'] ?? null),
                'children' => $this->flattenTree($branch['children'] ?? [], $site, $depth + 1) ?: null,
            ]);
        })->values()->all();
    }

    protected function renderTree(array $items, int $depth = 0): string
    {
        return collect($items)->map(function ($item) use ($depth) {
            $line = str_repeat('  ', $depth).'- '.$item['title'].($item['url'] ? " ({$item['url']})" : '');

            if ($children = $item['children'] ?? null) {
                $line .= "\n".$this->renderTree($children, $depth + 1);
            }

            return $line;
        })->implode("\n");
    }

    protected function collections(string $site): array
    {
        $allowed = config('ai-pages.collections', ['*']);

        return CollectionFacade::all()
            ->when($allowed !== ['*'], fn ($c) => $c->filter(fn ($col) => in_array($col->handle(), $allowed, true)))
            ->mapWithKeys(function ($collection) use ($site) {
                $handle = $collection->handle();

                $entries = Entry::query()
                    ->where('collection', $handle)
                    ->where('site', $site)
                    ->where('status', 'published')
                    ->limit(200)
                    ->get();

                // Sample the meatiest entries — they carry the house style.
                $samples = $entries
                    ->map(fn ($entry) => [
                        'title' => (string) $entry->get('title'),
                        'url' => (string) ($entry->url() ?: $entry->slug()),
                        'excerpt' => trim(BardToText::extract($entry->data()->except([
                            'title', 'seo', 'template', 'page_head_code', 'page_footer_code',
                        ])->all())),
                    ])
                    ->sortByDesc(fn ($s) => strlen($s['excerpt']))
                    ->take(config('ai-pages.exemplars_per_collection', 3))
                    ->values()
                    ->all();

                return [$handle => [
                    'title' => $collection->title(),
                    'count' => $entries->count(),
                    'route' => $collection->route($site),
                    'blueprints' => $collection->entryBlueprints()->map->handle()->all(),
                    'samples' => $samples,
                ]];
            })
            ->all();
    }

    protected function taxonomies(): array
    {
        return Taxonomy::all()->mapWithKeys(fn ($taxonomy) => [
            $taxonomy->handle() => [
                'title' => $taxonomy->title(),
                'terms' => $taxonomy->queryTerms()->limit(40)->get()->map->slug()->all(),
            ],
        ])->all();
    }

    protected function globals(string $site): array
    {
        return GlobalSet::all()->mapWithKeys(function ($set) use ($site) {
            $values = $set->in($site)?->data()?->all() ?? [];

            // Strip anything that's clearly not descriptive of the business.
            return [$set->handle() => collect($values)
                ->reject(fn ($v, $k) => Str::contains($k, ['code', 'script', 'key', 'secret', 'token']))
                ->all()];
        })->filter()->all();
    }
}
