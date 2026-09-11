<?php

namespace Joelseneque\AiPages\Tests;

use Joelseneque\AiPages\Support\QueueStatus;

class QueueStatusTest extends TestCase
{
    public function test_no_configured_connection_means_it_runs_inline(): void
    {
        config(['ai-pages.queue' => null]);

        $this->assertTrue(QueueStatus::runsInline());
    }

    public function test_a_sync_connection_still_counts_as_inline(): void
    {
        // Pointing at a connection whose driver is sync looks configured but
        // behaves exactly like having no queue at all.
        config([
            'ai-pages.queue' => 'sync',
            'queue.connections.sync.driver' => 'sync',
        ]);

        $this->assertTrue(QueueStatus::runsInline());
    }

    public function test_a_real_connection_does_not_run_inline(): void
    {
        config([
            'ai-pages.queue' => 'database',
            'queue.connections.database.driver' => 'database',
        ]);

        $this->assertFalse(QueueStatus::runsInline());
    }

    public function test_a_job_sitting_queued_is_reported_as_stalled(): void
    {
        $job = ['status' => 'queued', 'created_at' => now()->subMinutes(5)->toIso8601String()];

        $this->assertTrue(QueueStatus::looksStalled($job));
    }

    public function test_a_freshly_queued_job_is_not_stalled_yet(): void
    {
        $job = ['status' => 'queued', 'created_at' => now()->toIso8601String()];

        $this->assertFalse(QueueStatus::looksStalled($job));
    }

    public function test_a_running_job_is_never_stalled(): void
    {
        $job = ['status' => 'running', 'created_at' => now()->subHour()->toIso8601String()];

        $this->assertFalse(QueueStatus::looksStalled($job));
    }
}
