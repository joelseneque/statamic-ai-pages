<?php

namespace Joelseneque\AiPages\Instructions;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The addon's instruction layer, stored as plain Markdown in the site.
 *
 * Markdown on disk (rather than a database table or a Statamic global) is
 * deliberate: these files are the most opinionated part of the setup, so they
 * want to be committed, diffed in a PR, and hand-edited when the AI's read of
 * the site isn't quite right.
 */
class InstructionRepository
{
    /**
     * Files the sweep may regenerate, and the ones it must never touch.
     */
    public const GENERATED = ['site-profile', 'tone-of-voice'];

    public const HAND_WRITTEN = ['house-rules'];

    public function __construct(protected ?string $path = null)
    {
        $this->path = $path ?: config('ai-pages.instructions_path');
    }

    public function path(?string $name = null): string
    {
        return $name ? $this->path.'/'.$name.'.md' : $this->path;
    }

    public function exists(string $name): bool
    {
        return File::exists($this->path($name));
    }

    public function get(string $name): ?string
    {
        return $this->exists($name) ? trim(File::get($this->path($name))) : null;
    }

    public function put(string $name, string $contents): void
    {
        File::ensureDirectoryExists($this->path);
        File::put($this->path($name), trim($contents)."\n");
    }

    public function updatedAt(string $name): ?int
    {
        return $this->exists($name) ? File::lastModified($this->path($name)) : null;
    }

    public function delete(string $name): void
    {
        File::delete($this->path($name));
    }

    /**
     * Schema guides are generated per collection.
     */
    public function schemaGuide(string $collection): ?string
    {
        return $this->get("schema/{$collection}");
    }

    public function putSchemaGuide(string $collection, string $contents): void
    {
        File::ensureDirectoryExists($this->path.'/schema');
        File::put($this->path("schema/{$collection}"), trim($contents)."\n");
    }

    /**
     * Every instruction file, for the CP listing.
     */
    public function inventory(): array
    {
        $files = collect(array_merge(self::GENERATED, self::HAND_WRITTEN))
            ->map(fn ($name) => [
                'name' => $name,
                'title' => Str::of($name)->replace('-', ' ')->title()->toString(),
                'generated' => in_array($name, self::GENERATED, true),
                'exists' => $this->exists($name),
                'updated_at' => $this->updatedAt($name),
                'words' => str_word_count((string) $this->get($name)),
            ]);

        $schema = collect(File::exists($this->path.'/schema') ? File::files($this->path.'/schema') : [])
            ->map(fn ($file) => [
                'name' => 'schema/'.$file->getFilenameWithoutExtension(),
                'title' => 'Schema — '.$file->getFilenameWithoutExtension(),
                'generated' => true,
                'exists' => true,
                'updated_at' => $file->getMTime(),
                'words' => str_word_count(File::get($file->getPathname())),
            ]);

        return $files->concat($schema)->values()->all();
    }

    /**
     * The instruction stack as cacheable system blocks, in priority order.
     * House rules come last so hand-written overrides win.
     */
    public function systemBlocks(?string $collection = null): array
    {
        $blocks = [];

        foreach ([
            'site-profile' => 'ABOUT THIS SITE',
            'tone-of-voice' => 'TONE OF VOICE',
        ] as $name => $heading) {
            if ($body = $this->get($name)) {
                $blocks[] = ['type' => 'text', 'text' => "# {$heading}\n\n{$body}"];
            }
        }

        if ($collection && $guide = $this->schemaGuide($collection)) {
            $blocks[] = ['type' => 'text', 'text' => "# HOW THIS SITE USES ITS BLOCKS\n\n{$guide}"];
        }

        if ($rules = $this->get('house-rules')) {
            $blocks[] = ['type' => 'text', 'text' => "# HOUSE RULES (these override anything above)\n\n{$rules}"];
        }

        // Mark the last block cacheable so the whole prefix is cached.
        if ($blocks) {
            $blocks[count($blocks) - 1]['cache_control'] = ['type' => 'ephemeral'];
        }

        return $blocks;
    }

    public function isConfigured(): bool
    {
        return $this->exists('site-profile');
    }
}
