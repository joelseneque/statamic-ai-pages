<?php

namespace Joelseneque\AiPages\Http\Controllers;

use Illuminate\Http\Request;
use Joelseneque\AiPages\Instructions\InstructionRepository;
use Joelseneque\AiPages\Instructions\InstructionSweep;
use Joelseneque\AiPages\Jobs\JobStore;
use Joelseneque\AiPages\Jobs\SweepJob;
use Joelseneque\AiPages\Support\QueueStatus;

class InstructionsController extends Controller
{
    public function index(InstructionRepository $instructions, InstructionSweep $sweep)
    {
        $this->authorize('manage ai pages instructions');

        return view('ai-pages::instructions.index', [
            'inventory' => $instructions->inventory(),
            'collections' => $sweep->collections(),
            'configured' => app(\Joelseneque\AiPages\Anthropic\Client::class)->configured(),
            'runsInline' => QueueStatus::runsInline(),
            'suggestedQueue' => QueueStatus::suggestedConnection(),
        ]);
    }

    public function edit(string $name, InstructionRepository $instructions)
    {
        $this->authorize('manage ai pages instructions');

        $name = $this->clean($name);

        return view('ai-pages::instructions.edit', [
            'name' => $name,
            'contents' => $instructions->get($name) ?? '',
            'generated' => in_array($name, InstructionRepository::GENERATED, true) || str_starts_with($name, 'schema/'),
            'path' => $instructions->path($name),
        ]);
    }

    public function update(string $name, Request $request, InstructionRepository $instructions)
    {
        $this->authorize('manage ai pages instructions');

        $validated = $request->validate([
            'contents' => ['required', 'string', 'max:200000'],
        ]);

        $name = $this->clean($name);

        if (str_starts_with($name, 'schema/')) {
            $instructions->putSchemaGuide(substr($name, 7), $validated['contents']);
        } else {
            $instructions->put($name, $validated['contents']);
        }

        return redirect()
            ->route('statamic.cp.ai-pages.instructions')
            ->with('success', 'Saved.');
    }

    public function sweep(Request $request, JobStore $jobs)
    {
        $this->authorize('manage ai pages instructions');
        $this->assertConfigured();

        $validated = $request->validate([
            'parts' => ['required', 'array', 'min:1'],
            'parts.*' => ['in:profile,tone,schema'],
            'collections' => ['nullable', 'array'],
            'collections.*' => ['string'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'site' => ['nullable', 'string'],
        ]);

        $job = $jobs->create([
            'type' => 'sweep',
            'parts' => $validated['parts'],
            'collections' => $validated['collections'] ?? [],
        ]);

        $sweepJob = new SweepJob(
            $job['id'],
            $validated['parts'],
            $validated['site'] ?? null,
            $validated['collections'] ?? [],
            $validated['notes'] ?? null,
        );

        config('ai-pages.queue')
            ? dispatch($sweepJob)->onConnection(config('ai-pages.queue'))
            : dispatch_sync($sweepJob);

        return redirect()->route('statamic.cp.ai-pages.jobs.show', $job['id']);
    }

    /**
     * Instruction names come off the URL, and one of them legitimately contains
     * a slash (`schema/pages`), so normalise rather than trusting it.
     */
    protected function clean(string $name): string
    {
        $name = preg_replace('~[^a-z0-9\-_/]~i', '', $name) ?: '';
        $name = str_replace(['..', '//'], '', $name);

        abort_if($name === '', 404);

        $allowed = array_merge(
            InstructionRepository::GENERATED,
            InstructionRepository::HAND_WRITTEN,
        );

        abort_unless(in_array($name, $allowed, true) || str_starts_with($name, 'schema/'), 404);

        return $name;
    }
}
