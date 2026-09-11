<?php

namespace Joelseneque\AiPages;

use Joelseneque\AiPages\Anthropic\Client;
use Joelseneque\AiPages\Instructions\InstructionRepository;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    protected $actions = [
        Actions\TweakWithAi::class,
    ];

    protected $commands = [
        Commands\SweepCommand::class,
        Commands\BuildPageCommand::class,
        Commands\PruneJobsCommand::class,
    ];

    public function register(): void
    {
        $this->app->singleton(Client::class, fn () => new Client);
        $this->app->singleton(InstructionRepository::class, fn () => new InstructionRepository);
        $this->app->singleton(Jobs\JobStore::class, fn () => new Jobs\JobStore);
        $this->app->singleton(Schema\Conventions::class, fn () => new Schema\Conventions);
        $this->app->bind(Build\ExemplarSampler::class, fn () => new Build\ExemplarSampler(
            (int) config('ai-pages.exemplars_per_collection', 2)
        ));
        $this->app->bind(Schema\BlueprintCompiler::class, fn () => new Schema\BlueprintCompiler);
        $this->app->bind(Schema\JsonSchemaBuilder::class, fn () => new Schema\JsonSchemaBuilder);
    }

    public function bootAddon(): void
    {
        $this
            ->bootConfig()
            ->bootViews()
            ->bootTranslations()
            ->bootPermissions()
            ->bootNavigation();
    }

    protected function bootConfig(): static
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-pages.php', 'ai-pages');

        $this->publishes([
            __DIR__.'/../config/ai-pages.php' => config_path('ai-pages.php'),
        ], 'ai-pages-config');

        return $this;
    }

    protected function bootViews(): static
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ai-pages');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/ai-pages'),
        ], 'ai-pages-views');

        return $this;
    }

    protected function bootTranslations(): static
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'ai-pages');

        return $this;
    }

    protected function bootPermissions(): static
    {
        Permission::group('ai-pages', 'AI Pages', function () {
            Permission::register('build ai pages')
                ->label('Build pages with AI')
                ->description('Create new draft entries from source material.');

            Permission::register('edit ai pages')
                ->label('Tweak pages with AI')
                ->description('Make AI-planned changes to existing entries.');

            Permission::register('manage ai pages instructions')
                ->label('Manage AI instructions')
                ->description('Run the site sweep and edit the site profile, tone of voice and house rules.');
        });

        return $this;
    }

    protected function bootNavigation(): static
    {
        Nav::extend(function ($nav) {
            $nav->content(__('ai-pages::messages.nav_title'))
                ->route('ai-pages.index')
                ->icon('sparkles')
                ->can('build ai pages')
                ->children([
                    $nav->item(__('ai-pages::messages.nav_build'))->route('ai-pages.build'),
                    $nav->item(__('ai-pages::messages.nav_history'))->route('ai-pages.jobs'),
                    $nav->item(__('ai-pages::messages.nav_instructions'))
                        ->route('ai-pages.instructions')
                        ->can('manage ai pages instructions'),
                ]);
        });

        return $this;
    }
}
