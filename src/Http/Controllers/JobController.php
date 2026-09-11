<?php

namespace Joelseneque\AiPages\Http\Controllers;

use Joelseneque\AiPages\Jobs\JobStore;
use Joelseneque\AiPages\Support\QueueStatus;
use Statamic\Facades\Entry;

class JobController extends Controller
{
    public function index(JobStore $jobs)
    {
        $this->authorize('build ai pages');

        return view('ai-pages::jobs.index', [
            'jobs' => $jobs->recent(50),
        ]);
    }

    public function show(string $job, JobStore $jobs)
    {
        $this->authorize('build ai pages');

        abort_unless($record = $jobs->find($job), 404);

        return view('ai-pages::jobs.show', [
            'job' => $record,
            'entry' => ($id = $record['entry_id'] ?? null) ? Entry::find($id) : null,
            'stalled' => QueueStatus::looksStalled($record),
            'queueConnection' => QueueStatus::connection(),
        ]);
    }

    /**
     * Polled by the job page while a build is running.
     */
    public function status(string $job, JobStore $jobs)
    {
        $this->authorize('build ai pages');

        abort_unless($record = $jobs->find($job), 404);

        return [
            'status' => $record['status'],
            'progress' => $record['progress'] ?? null,
            'step' => $record['step'] ?? null,
            'total' => $record['total'] ?? null,
            'error' => $record['error'] ?? null,
        ];
    }
}
