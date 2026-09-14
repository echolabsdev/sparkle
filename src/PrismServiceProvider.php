<?php

namespace Prism\Prism;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Prism\Prism\Console\Commands\MakeToolCommand;
use Prism\Prism\Telemetry\ContextStack;

class PrismServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/prism.php' => config_path('prism.php'),
        ], 'prism-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeToolCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../stubs/prism-tool.stub' => base_path('stubs/prism-tool.stub'),
            ], 'prism-stubs');
        }

        if (config('prism.prism_server.enabled')) {
            Route::group([
                'middleware' => config('prism.prism_server.middleware', []),
            ], function (): void {
                $this->loadRoutesFrom(__DIR__.'/Routes/PrismServer.php');
            });
        }
    }

    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/prism.php',
            'prism'
        );

        $this->app->singleton(
            PrismManager::class,
            fn (): PrismManager => new PrismManager($this->app)
        );

        $this->app->alias(PrismManager::class, 'prism-manager');

        $this->app->singleton(
            Prism::class,
            fn (): Prism => new Prism
        );

        $this->app->alias(Prism::class, 'prism');

        $this->app->singleton(
            'prism-server',
            fn (): PrismServer => new PrismServer
        );

        $this->app->singleton(ContextStack::class);
    }
}
