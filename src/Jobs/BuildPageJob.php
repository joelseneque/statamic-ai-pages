<?php

namespace Joelseneque\AiPages\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Joelseneque\AiPages\Build\BuildRequest;
use Joelseneque\AiPages\Build\EntryWriter;
use Joelseneque\AiPages\Build\PageBuilder;
use Joelseneque\AiPages\Build\SourceBundle;
use Joelseneque\AiPages\Sources\SourceResolver;
use Throwable;

class BuildPageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public string $jobId,
        public array $input,
        public array $sourceInputs,
    ) {}

    public function handle(
        SourceResolver $resolver,
        PageBuilder $builder,
        EntryWriter $writer,
        JobStore $jobs,
    ): void {
        try {
            $jobs->progress($this->jobId, 'Reading the source material');

            $request = BuildRequest::fromArray(
                $this->input,
                new SourceBundle($resolver->resolve($this->sourceInputs))
            );

            $builder->onProgress(fn ($message, $meta) => $jobs->progress($this->jobId, $message, $meta));

            $result = $builder->build($request);

            $jobs->progress($this->jobId, 'Saving the draft');

            $entry = $writer->create($request, $result);

            $result->entryId = $entry->id();
            $result->entryUrl = $entry->editUrl();

            $jobs->update($this->jobId, array_merge($result->toArray(), [
                'status' => 'completed',
                'progress' => 'Done',
            ]));
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
