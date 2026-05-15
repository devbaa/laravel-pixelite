<?php

declare(strict_types=1);

namespace Boralp\Pixelite\Tests;

use Boralp\Pixelite\PixeliteServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [PixeliteServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // Sensible defaults — tests override per-case as needed
        $app['config']->set('pixelite', [
            'compliance_mode' => 'none',
            'consent'  => ['required' => false, 'cookie_name' => 'pixelite_consent', 'default' => 'granted'],
            'ip'       => ['anonymization' => 'none'],
            'collect'  => [
                'geo'        => false,  // no GeoIP DB in tests
                'user_agent' => true,
                'referer'    => true,
                'utm'        => true,
                'click_ids'  => true,
                'screen'     => true,
                'timezone'   => true,
                'total_time' => true,
                'locale'     => true,
            ],
            'retention' => ['enabled' => false, 'raw_hours' => 24, 'visits_days' => 365],
            'rights'    => [
                'opt_out_enabled'  => false,
                'opt_out_cookie'   => 'pixelite_optout',
                'deletion_enabled' => true,
                'export_enabled'   => true,
            ],
            'profiling' => ['cross_session' => true, 'behavioral' => true],
            'tracking'  => [
                'user_id'   => ['format' => 'integer'],
                'team_id'   => ['enabled' => false, 'label' => 'team_id', 'resolver' => 'user.team_id', 'format' => 'integer'],
                'custom_id' => ['enabled' => false, 'label' => 'custom_id', 'resolver' => 'user.custom_id', 'format' => 'string'],
            ],
            'geo_db_path' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
