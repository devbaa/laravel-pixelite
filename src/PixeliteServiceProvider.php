<?php

namespace Boralp\Pixelite;

use Boralp\Pixelite\Console\Commands\ProcessVisitCommand;
use Boralp\Pixelite\Middleware\TrackVisit;
use Illuminate\Support\ServiceProvider;

class PixeliteServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish migrations
        $this->publishes([
            __DIR__.'/../database/migrations/' => database_path('migrations'),
        ], 'pixelite-migrations');

        // Publish JS assets
        $this->publishes([
            __DIR__.'/../resources/js/pixelite.min.js' => public_path('js/pixelite/pixelite.min.js'),
        ], 'pixelite-assets');

        // Load routes
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // Register artisan commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                ProcessVisitCommand::class,
            ]);
        }

        // Register middleware alias
        $this->app['router']->aliasMiddleware('pixelite.visit', TrackVisit::class);
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }
}
