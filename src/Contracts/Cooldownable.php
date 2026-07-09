<?php

declare(strict_types=1);

namespace ZaberDev\Cooldown\Contracts;

use ZaberDev\Cooldown\Support\PendingCooldown;

/**
 * Interface implemented by Eloquent models or domain entities capable of possessing scoped cooldowns.
 */
interface Cooldownable
{
    /**
     * Start building or checking a cooldown specific to this target entity.
     *
     * @param string $action The action name.
     * @param string|null $driver Optional storage driver override.
     */
    public function cooldown(string $action, ?string $driver = null): PendingCooldown;

    /**
     * Get the unique string identifier representing this target for cooldown key resolution.
     */
    public function getCooldownTargetIdentifier(): string;

    /**
     * Reset all active cooldowns associated specifically with this target.
     *
     * @param string|null $driver Optional storage driver override.
     */
    public function resetAllCooldowns(?string $driver = null): int;
}
