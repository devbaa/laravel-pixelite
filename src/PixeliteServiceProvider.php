<?php

declare(strict_types=1);

namespace Boralp\Pixelite;

use Boralp\Pixelite\Console\Commands\DaemonCommand;
use Boralp\Pixelite\Console\Commands\DeleteUserDataCommand;
use Boralp\Pixelite\Console\Commands\ExportUserDataCommand;
use Boralp\Pixelite\Console\Commands\InstallCommand;
use Boralp\Pixelite\Console\Commands\ProcessVisitCommand;
use Boralp\Pixelite\Console\Commands\PurgeDataCommand;
use Boralp\Pixelite\Middleware\TrackVisit;
use Boralp\Pixelite\Services\IpAnonymizer;
use Boralp\Pixelite\Services\PrivacyService;
use Boralp\Pixelite\Services\VisitProcessor;
use Illuminate\Support\ServiceProvider;

final class PixeliteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/pixelite.php', 'pixelite');

        $this->app->singleton(IpAnonymizer::class);
        $this->app->singleton(PrivacyService::class);
        $this->app->singleton(VisitProcessor::class);
    }

    public function boot(): void
    {
        // Config
        $this->publishes([
            __DIR__.'/../config/pixelite.php' => config_path('pixelite.php'),
        ], 'pixelite-config');

        // Migrations
        $this->publishes([
            __DIR__.'/../database/migrations/' => database_path('migrations'),
        ], 'pixelite-migrations');

        // JS assets
        $this->publishes([
            __DIR__.'/../resources/js/pixelite.min.js' => public_path('js/pixelite/pixelite.min.js'),
        ], 'pixelite-assets');

        // Routes
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // Artisan commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                ProcessVisitCommand::class,
                PurgeDataCommand::class,
                DeleteUserDataCommand::class,
                ExportUserDataCommand::class,
                DaemonCommand::class,
            ]);
        }

        // Middleware alias
        $this->app['router']->aliasMiddleware('pixelite.visit', TrackVisit::class);
    }
}
