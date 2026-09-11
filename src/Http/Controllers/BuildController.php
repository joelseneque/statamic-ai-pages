<?php

namespace Joelseneque\AiPages\Http\Controllers;

use Illuminate\Http\Request;
use Joelseneque\AiPages\Build\BuildRequest;
use Joelseneque\AiPages\Instructions\InstructionRepository;
use Joelseneque\AiPages\Jobs\BuildPageJob;
use Joelseneque\AiPages\Jobs\JobStore;
use Joelseneque\AiPages\Schema\BlueprintCompiler;
use Joelseneque\AiPages\Schema\SetCatalogue;
use Joelseneque\AiPages\Support\QueueStatus;
use Statamic\Contracts\Entries\Collection as CollectionContract;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Site;

class BuildController extends Controller
{
    public function index(JobStore $jobs, InstructionRepository $instructions)
    {
        $this->authorize('build ai pages');

        return view('ai-pages::index', [
            'configured' => app(\Joelseneque\AiPages\Anthropic\Client::class)->configured(),
            'instructionsReady' => $instructions->isConfigured(),
            'inventory' => $instructions->inventory(),
            'recent' => array_slice($jobs->recent(8), 0, 8),
            'model' => config('ai-pages.model'),
            'runsInline' => QueueStatus::runsInline(),
            'suggestedQueue' => QueueStatus::suggestedConnection(),
        ]);
    }

    public function create()
    {
        $this->authorize('build ai pages');
        $this->assertConfigured();

        return view('ai-pages::build', [
            'collections' => $this->collections(),
            'sites' => Site::all()->map(fn ($s) => ['handle' => $s->handle(), 'name' => $s->name()])->values(),
            'modes' => BuildRequest::modeLabels(),
            'instructionsReady' => $this->instructionsReady(),
            'maxUploadMb' => config('ai-pages.sources.max_upload_mb'),
            'allowedExtensions' => config('ai-pages.sources.allowed_extensions'),
            'runsInline' => QueueStatus::runsInline(),
            'suggestedQueue' => QueueStatus::suggestedConnection(),
        ]);
    }

    public function store(Request $request, JobStore $jobs)
    {
        $this->authorize('build ai pages');
        $this->assertConfigured();

        $validated = $request->validate([
            'collection' => ['required', 'string', 'in:'.implode(',', array_keys($this->collections()))],
            'blueprint' => ['nullable', 'string'],
            'site' => ['nullable', 'string'],
            'mode' => ['required', 'in:'.implode(',', BuildRequest::modes())],
            'title' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'brief' => ['nullable', 'string', 'max:20000'],
            'text' => ['nullable', 'string', 'max:500000'],
            'urls' => ['nullable', 'string', 'max:5000'],
            'max_sections' => ['nullable', 'integer', 'min:1', 'max:30'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:'.((int) config('ai-pages.sources.max_upload_mb') * 1024)],
        ]);

        $sources = $this->sourceInputs($request, $validated);

        if (! $sources) {
            return back()->withInput()->withErrors([
                'text' => 'Give it something to work from — paste some text, add a link, or upload a document.',
            ]);
        }

        $job = $jobs->create([
            'type' => 'build',
            'collection' => $validated['collection'],
            'mode' => $validated['mode'],
            'title' => $validated['title'] ?? null,
            'sources' => collect($sources)->map(fn ($s) => is_array($s) ? $s['value'] : $s->getClientOriginalName())->all(),
        ]);

        $this->dispatchJob(new BuildPageJob($job['id'], $validated, $this->persistUploads($sources)));

        return redirect()->route('statamic.cp.ai-pages.jobs.show', $job['id']);
    }

    /**
     * The set menu for a collection, so the build form can show what it will
     * be choosing from before anything is spent.
     */
    public function sets(CollectionContract $collection, BlueprintCompiler $compiler)
    {
        // {collection} is bound on every CP route, so this arrives resolved.
        $this->authorize('build ai pages');

        abort_unless(array_key_exists($collection->handle(), $this->collections()), 404);

        $compiled = $compiler->forCollection($collection->handle());

        return collect($compiled['blueprints'])->map(fn ($bp) => [
            'title' => $bp['title'],
            'handle' => $bp['handle'],
            'sets' => collect(SetCatalogue::fromBlueprint($bp)->all())
                ->map(fn ($s) => ['handle' => $s['handle'], 'display' => $s['display'], 'group' => $s['group_display']])
                ->values(),
        ]);
    }

    protected function collections(): array
    {
        $allowed = config('ai-pages.collections', ['*']);

        return CollectionFacade::all()
            ->when($allowed !== ['*'], fn ($c) => $c->filter(fn ($col) => in_array($col->handle(), $allowed, true)))
            ->mapWithKeys(fn ($c) => [$c->handle() => $c->title()])
            ->all();
    }

    protected function sourceInputs(Request $request, array $validated): array
    {
        $sources = [];

        if ($text = trim($validated['text'] ?? '')) {
            $sources[] = ['type' => 'text', 'value' => $text, 'label' => 'Pasted text'];
        }

        foreach (preg_split('~[\r\n,]+~', $validated['urls'] ?? '') ?: [] as $url) {
            if ($url = trim($url)) {
                $sources[] = ['type' => 'auto', 'value' => $url];
            }
        }

        foreach ($request->file('files', []) as $file) {
            $sources[] = $file;
        }

        return $sources;
    }

    /**
     * Uploads can't be serialised onto a queue, so park them somewhere the
     * worker can read and pass paths instead.
     */
    protected function persistUploads(array $sources): array
    {
        $directory = rtrim(config('ai-pages.storage_path'), '/').'/uploads/'.now()->format('Y-m-d');

        return collect($sources)->map(function ($source) use ($directory) {
            if (is_array($source)) {
                return $source;
            }

            $name = now()->timestamp.'-'.preg_replace('~[^\w.\-]~', '_', $source->getClientOriginalName());
            $source->move($directory, $name);

            return ['type' => 'file', 'value' => $directory.'/'.$name];
        })->all();
    }

    protected function dispatchJob(object $job): void
    {
        $connection = config('ai-pages.queue');

        $connection
            ? dispatch($job)->onConnection($connection)
            : dispatch_sync($job);
    }
}
