<?php

namespace Joelseneque\AiPages\Jobs;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Statamic\Facades\User;

/**
 * Build records, as JSON files.
 *
 * Flat files rather than a migration: plenty of Statamic sites run without a
 * database, and a build record is write-once-read-a-few-times. Each record
 * keeps the plan, the warnings and the token spend so a bad result can be
 * diagnosed after the fact.
 */
class JobStore
{
    public function __construct(protected ?string $path = null)
    {
        $this->path = ($path ?: config('ai-pages.storage_path')).'/jobs';
    }

    public function create(array $attributes): array
    {
        $job = array_merge([
            'id' => (string) Str::uuid(),
            'status' => 'queued',
            'progress' => 'Queued',
            'step' => 0,
            'total' => 0,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'user' => User::current()?->id(),
            'user_name' => User::current()?->name(),
        ], $attributes);

        $this->write($job);

        return $job;
    }

    public function find(string $id): ?array
    {
        $file = $this->file($id);

        return File::exists($file) ? json_decode(File::get($file), true) : null;
    }

    public function update(string $id, array $attributes): ?array
    {
        if (! $job = $this->find($id)) {
            return null;
        }

        $job = array_merge($job, $attributes, ['updated_at' => now()->toIso8601String()]);

        $this->write($job);

        return $job;
    }

    public function progress(string $id, string $message, array $meta = []): void
    {
        $this->update($id, array_filter([
            'status' => 'running',
            'progress' => $message,
            'step' => $meta['step'] ?? null,
            'total' => $meta['total'] ?? null,
        ], fn ($v) => $v !== null));
    }

    public function fail(string $id, \Throwable $e): void
    {
        $this->update($id, [
            'status' => 'failed',
            'progress' => 'Failed',
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * @return array<array>
     */
    public function recent(int $limit = 30): array
    {
        if (! File::exists($this->path)) {
            return [];
        }

        return collect(File::files($this->path))
            ->sortByDesc(fn ($file) => $file->getMTime())
            ->take($limit)
            ->map(fn ($file) => json_decode(File::get($file->getPathname()), true))
            ->filter()
            ->values()
            ->all();
    }

    public function prune(int $keepDays = 30): int
    {
        if (! File::exists($this->path)) {
            return 0;
        }

        $cutoff = now()->subDays($keepDays)->timestamp;
        $deleted = 0;

        foreach (File::files($this->path) as $file) {
            if ($file->getMTime() < $cutoff) {
                File::delete($file->getPathname());
                $deleted++;
            }
        }

        return $deleted;
    }

    protected function write(array $job): void
    {
        File::ensureDirectoryExists($this->path);
        File::put($this->file($job['id']), json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function file(string $id): string
    {
        // Ids come from Str::uuid(), but never trust one straight off a route.
        return $this->path.'/'.preg_replace('~[^a-z0-9\-]~i', '', $id).'.json';
    }
}
