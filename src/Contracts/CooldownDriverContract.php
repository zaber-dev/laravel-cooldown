<?php

declare(strict_types=1);

namespace ZaberDev\Cooldown\Contracts;

use DateTimeInterface;
use ZaberDev\Cooldown\DTO\CooldownInfo;

/**
 * Interface defining the storage operations required for any cooldown driver.
 */
interface CooldownDriverContract
{
    /**
     * Retrieve the cooldown information for the given key, if it exists and is active.
     */
    public function get(string $key): ?CooldownInfo;

    /**
     * Store or update a cooldown with the specified expiration duration or timestamp.
     *
     * @param string $key The unique identifier key.
     * @param DateTimeInterface|int|string $expiresAtOrSeconds Absolute timestamp or duration in seconds.
     */
    public function put(string $key, DateTimeInterface|int|string $expiresAtOrSeconds): CooldownInfo;

    /**
     * Remove a specific cooldown from storage.
     */
    public function forget(string $key): bool;

    /**
     * Remove all cooldowns from storage, optionally filtered by a prefix.
     */
    public function flush(?string $prefix = null): int;

    /**
     * Attempt to acquire an atomic in-flight reservation lock for the given key.
     */
    public function acquireLock(string $key, int $seconds = 15): bool;

    /**
     * Release an atomic in-flight reservation lock for the given key.
     */
    public function releaseLock(string $key): bool;

    /**
     * Determine if an atomic in-flight reservation lock currently exists for the given key.
     */
    public function isLocked(string $key): bool;
}
