<?php

declare(strict_types=1);

namespace ZaberDev\Cooldown\Contracts;

use Closure;
use Illuminate\Database\Eloquent\Model;
use ZaberDev\Cooldown\Support\PendingCooldown;

/**
 * Interface defining the API exposed by the CooldownManager and Facade.
 */
interface CooldownManagerContract
{
    /**
     * Get a driver instance by name.
     *
     * @param \UnitEnum|string|null $driver
     */
    public function driver($driver = null): CooldownDriverContract;

    /**
     * Register a custom driver creator Closure.
     *
     * @param string $driver
     * @return $this
     */
    public function extend($driver, Closure $callback);

    /**
     * Create a new PendingCooldown fluent builder for the given action and optional target.
     *
     * @param string $action The action being throttled (e.g., 'send_sms', 'login').
     * @param Model|string|int|null $target Optional target model or unique identifier.
     */
    public function for(string $action, Model|string|int|null $target = null): PendingCooldown;

    /**
     * Alias for `for()` specifically designed for targeting an action directly on a model or identifier.
     */
    public function on(string $action, Model|string|int|null $target = null): PendingCooldown;

    /**
     * Check whether an action is currently active (on cooldown) for the target.
     */
    public function active(string $action, Model|string|int|null $target = null): bool;

    /**
     * Check whether an action has passed (expired or never initiated) for the target.
     */
    public function passed(string $action, Model|string|int|null $target = null): bool;

    /**
     * Immediately reset (delete) the cooldown for the action and optional target.
     */
    public function reset(string $action, Model|string|int|null $target = null): bool;
}
