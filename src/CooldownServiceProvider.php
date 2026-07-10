<?php

declare(strict_types=1);

namespace ZaberDev\Cooldown;

use Illuminate\Support\ServiceProvider;
use ZaberDev\Cooldown\Contracts\CooldownManagerContract;
use ZaberDev\Cooldown\Http\Middleware\CheckCooldown;

/**
 * Laravel Service Provider for the ZaberDev Cooldown package.
 */
class CooldownServiceProvider extends ServiceProvider
{
    /**
     * Register package bindings and configuration.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/cooldowns.php', 'cooldowns');

        $this->app->singleton(CooldownManagerContract::class, function ($app) {
            return new CooldownManager($app);
        });

        $this->app->alias(CooldownManagerContract::class, 'cooldown');
    }

    /**
     * Bootstrap application services, publishing resources, and route middleware.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/cooldowns.php' => config_path('cooldowns.php'),
            ], 'cooldowns-config');

            $this->publishes([
                __DIR__ . '/../database/migrations/create_cooldowns_table.php.stub' => $this->getMigrationFileName('create_cooldowns_table.php'),
            ], 'cooldowns-migrations');

            $this->publishes([
                __DIR__ . '/../resources/boost/skills/laravel-cooldown' => $this->app->basePath('.ai/skills/laravel-cooldown'),
            ], ['cooldowns-skill', 'cooldowns-boost-skills']);

            $this->autoPublishBoostSkill();
        }

        $this->registerMiddleware();
    }

    /**
     * Register the CheckCooldown route middleware alias.
     */
    protected function registerMiddleware(): void
    {
        /** @var \Illuminate\Routing\Router $router */
        $router = $this->app['router'];

        $router->aliasMiddleware('cooldown', CheckCooldown::class);
    }

    /**
     * Automatically publish or synchronize the package AI skill when Laravel Boost is installed.
     */
    protected function autoPublishBoostSkill(): void
    {
        $boostDetected = class_exists(\Laravel\Boost\BoostServiceProvider::class)
            || is_dir($this->app->basePath('.ai/skills'));

        if (! $boostDetected) {
            return;
        }

        $source = __DIR__ . '/../resources/boost/skills/laravel-cooldown';
        $destination = $this->app->basePath('.ai/skills/laravel-cooldown');

        if (is_dir($source) && ! is_dir($destination)) {
            $this->app['files']->copyDirectory($source, $destination);
        }
    }

    /**
     * Determine the timestamped migration file name for publishing.
     */
    protected function getMigrationFileName(string $migrationFileName): string
    {
        $timestamp = date('Y_m_d_His');

        return database_path("migrations/{$timestamp}_{$migrationFileName}");
    }
}

