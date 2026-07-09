<?php

declare(strict_types=1);

namespace ZaberDev\Cooldown\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use ZaberDev\Cooldown\CooldownServiceProvider;

class TestCase extends OrchestraTestCase
{
    public static $latestResponse = null;

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            CooldownServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('cooldowns.default', 'cache');
        $app['config']->set('cooldowns.drivers.cache.store', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        $migration = require __DIR__ . '/../database/migrations/create_cooldowns_table.php.stub';
        $migration->up();
    }
}
