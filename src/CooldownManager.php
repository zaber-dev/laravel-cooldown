<?php

declare(strict_types=1);

namespace ZaberDev\Cooldown;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Manager;
use ZaberDev\Cooldown\Contracts\CooldownDriverContract;
use ZaberDev\Cooldown\Contracts\CooldownManagerContract;
use ZaberDev\Cooldown\Drivers\CacheCooldownDriver;
use ZaberDev\Cooldown\Drivers\DatabaseCooldownDriver;
use ZaberDev\Cooldown\Exceptions\CooldownDriverNotFoundException;
use ZaberDev\Cooldown\Support\PendingCooldown;

/**
 * Central manager responsible for resolving cooldown storage drivers and initiating fluent builders.
 */
class CooldownManager extends Manager implements CooldownManagerContract
{
    /**
     * Get the default driver name from application config.
     *
     * @return string
     */
    public function getDefaultDriver(): string
    {
        return $this->config->get('cooldowns.default', 'cache');
    }

    /**
     * Get a driver instance by name, with strict typing return.
     *
     * @param \UnitEnum|string|null $driver
     * @return CooldownDriverContract
     *
     * @throws CooldownDriverNotFoundException
     */
    public function driver($driver = null): CooldownDriverContract
    {
        $driverName = $driver instanceof \UnitEnum ? $driver->value : ($driver ?: $this->getDefaultDriver());

        try {
            /** @var CooldownDriverContract $resolved */
            $resolved = parent::driver($driverName);

            return $resolved;
        } catch (\InvalidArgumentException $e) {
            throw CooldownDriverNotFoundException::make((string) $driverName);
        }
    }

    /**
     * Create an instance of the Cache storage driver.
     */
    protected function createCacheDriver(): CooldownDriverContract
    {
        $config = $this->config->get('cooldowns.drivers.cache', []);
        $storeName = $config['store'] ?? null;
        $prefix = $config['prefix'] ?? 'cooldown:';

        $repository = $this->container['cache']->store($storeName);

        return new CacheCooldownDriver($repository, $prefix);
    }

    /**
     * Create an instance of the Database storage driver.
     */
    protected function createDatabaseDriver(): CooldownDriverContract
    {
        return new DatabaseCooldownDriver();
    }

    /**
     * Create a new PendingCooldown fluent builder for the given action and optional target.
     */
    public function for(string $action, Model|string|int|null $target = null): PendingCooldown
    {
        return new PendingCooldown($this, $action, $target);
    }

    /**
     * Alias for `for()` specifically designed for targeting an action directly on a model or identifier.
     */
    public function on(string $action, Model|string|int|null $target = null): PendingCooldown
    {
        return $this->for($action, $target);
    }

    /**
     * Check whether an action is currently active (on cooldown) for the target.
     */
    public function active(string $action, Model|string|int|null $target = null): bool
    {
        return $this->for($action, $target)->active();
    }

    /**
     * Check whether an action has passed (expired or never initiated) for the target.
     */
    public function passed(string $action, Model|string|int|null $target = null): bool
    {
        return $this->for($action, $target)->passed();
    }

    /**
     * Immediately reset (delete) the cooldown for the action and optional target.
     */
    public function reset(string $action, Model|string|int|null $target = null): bool
    {
        return $this->for($action, $target)->reset();
    }
}
