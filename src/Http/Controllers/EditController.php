<?php

namespace Joelseneque\AiPages\Http\Controllers;

use Illuminate\Http\Request;
use Joelseneque\AiPages\Build\EntryWriter;
use Joelseneque\AiPages\Build\SourceBundle;
use Joelseneque\AiPages\Edit\EntryEditor;
use Joelseneque\AiPages\Jobs\JobStore;
use Joelseneque\AiPages\Sources\SourceResolver;
use Statamic\Facades\Entry;
use Throwable;

class EditController extends Controller
{
    public function create(string $entry)
    {
        $this->authorize('edit ai pages');
        $this->assertConfigured();

        abort_unless($record = Entry::find($entry), 404);
        $this->authorize('edit', $record);

        return view('ai-pages::tweak', [
            'entry' => $record,
        ]);
    }

    /**
     * Plan the change and work out the resulting data — but don't save it.
     * Editing an existing page is destructive in a way creating a draft isn't,
     * so nothing is written until someone has seen the diff.
     */
    public function store(string $entry, Request $request, EntryEditor $editor, SourceResolver $resolver, JobStore $jobs)
    {
        $this->authorize('edit ai pages');
        $this->assertConfigured();

        abort_unless($record = Entry::find($entry), 404);
        $this->authorize('edit', $record);

        $validated = $request->validate([
            'instruction' => ['required', 'string', 'min:3', 'max:10000'],
            'text' => ['nullable', 'string', 'max:200000'],
        ]);

        $job = $jobs->create([
            'type' => 'tweak',
            'entry_id' => $record->id(),
            'entry_title' => $record->get('title'),
            'collection' => $record->collection()->handle(),
            'instruction' => $validated['instruction'],
            'status' => 'running',
        ]);

        try {
            $sources = filled($validated['text'] ?? null)
                ? new SourceBundle($resolver->resolve([['type' => 'text', 'value' => $validated['text']]]))
                : null;

            $editor->onProgress(fn ($message, $meta) => $jobs->progress($job['id'], $message, $meta));

            $result = $editor->edit($record, $validated['instruction'], $sources);

            $jobs->update($job['id'], [
                'status' => 'awaiting_approval',
                'progress' => 'Ready for review',
                'patch' => $result['patch'],
                'pending_data' => $result['data'],
                'warnings' => $result['warnings'],
                'usage' => $result['usage'],
                'cost' => $result['cost'],
            ]);
        } catch (Throwable $e) {
            report($e);
            $jobs->fail($job['id'], $e);
        }

        return redirect()->route('statamic.cp.ai-pages.jobs.show', $job['id']);
    }

    public function apply(string $entry, string $job, JobStore $jobs, EntryWriter $writer)
    {
        $this->authorize('edit ai pages');

        abort_unless($record = Entry::find($entry), 404);
        $this->authorize('edit', $record);

        abort_unless($job = $jobs->find($job), 404);
        abort_unless(($job['status'] ?? null) === 'awaiting_approval', 409, 'That change has already been dealt with.');
        abort_unless(($job['entry_id'] ?? null) === $record->id(), 403);

        $writer->update($record, $job['pending_data'] ?? []);

        $jobs->update($job['id'], [
            'status' => 'completed',
            'progress' => 'Applied',
            'pending_data' => null,
        ]);

        return redirect($record->editUrl())->with('success', 'Changes applied.');
    }
}
