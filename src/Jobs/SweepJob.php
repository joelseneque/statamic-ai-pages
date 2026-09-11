<?php

namespace Joelseneque\AiPages\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Joelseneque\AiPages\Instructions\InstructionSweep;
use Throwable;

class SweepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public string $jobId,
        public array $parts = ['profile', 'tone', 'schema'],
        public ?string $site = null,
        public array $collections = [],
        public ?string $notes = null,
    ) {}

    public function handle(InstructionSweep $sweep, JobStore $jobs): void
    {
        try {
            $sweep->onProgress(fn ($message, $meta) => $jobs->progress($this->jobId, $message, $meta));

            $written = $sweep->run($this->parts, $this->site, $this->collections, $this->notes);

            $jobs->update($this->jobId, [
                'status' => 'completed',
                'progress' => 'Done',
                'written' => $written,
                'usage' => $sweep->usage(),
                'cost' => $sweep->cost(),
            ]);
        } catch (Throwable $e) {
            report($e);
            $jobs->fail($this->jobId, $e);
        }
    }

    public function failed(Throwable $e): void
    {
        app(JobStore::class)->fail($this->jobId, $e);
    }
}
