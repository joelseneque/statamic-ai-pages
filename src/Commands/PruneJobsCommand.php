<?php

namespace Joelseneque\AiPages\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Joelseneque\AiPages\Jobs\JobStore;
use Statamic\Console\RunsInPlease;

class PruneJobsCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'ai-pages:prune {--days=30 : Keep records newer than this}';

    protected $description = 'Delete old AI Pages build records and the uploads they used';

    public function handle(JobStore $jobs): int
    {
        $days = (int) $this->option('days');

        $this->line("Pruning build records older than {$days} days…");
        $this->info($jobs->prune($days).' records deleted.');

        $uploads = rtrim(config('ai-pages.storage_path'), '/').'/uploads';
        $deleted = 0;

        if (File::exists($uploads)) {
            foreach (File::directories($uploads) as $directory) {
                if (File::lastModified($directory) < now()->subDays($days)->timestamp) {
                    File::deleteDirectory($directory);
                    $deleted++;
                }
            }
        }

        $this->info($deleted.' upload folders deleted.');

        return self::SUCCESS;
    }
}
