<?php

namespace Joelseneque\AiPages\Support;

/**
 * Whether builds will actually survive being run.
 *
 * A full page build makes a dozen or more API calls and takes minutes. Run
 * inline, that blocks the Control Panel request until the web server gives up
 * — a 504 with no draft and no refund on the tokens already spent. So the CP
 * says so before you spend anything, rather than after.
 */
class QueueStatus
{
    /**
     * A build dispatched now would run inside the web request.
     */
    public static function runsInline(): bool
    {
        $connection = config('ai-pages.queue');

        if (! $connection) {
            return true;
        }

        return config("queue.connections.{$connection}.driver") === 'sync';
    }

    public static function connection(): ?string
    {
        return config('ai-pages.queue');
    }

    /**
     * A queued job that nothing has picked up almost always means no worker.
     */
    public static function looksStalled(array $job, int $afterSeconds = 45): bool
    {
        if (($job['status'] ?? null) !== 'queued') {
            return false;
        }

        return now()->diffInSeconds(\Carbon\Carbon::parse($job['created_at']), true) > $afterSeconds;
    }

    /**
     * Suggest a connection the site could actually use, for the setup hint.
     */
    public static function suggestedConnection(): string
    {
        foreach (['redis', 'database'] as $candidate) {
            if (config("queue.connections.{$candidate}")) {
                return $candidate;
            }
        }

        return 'database';
    }
}
