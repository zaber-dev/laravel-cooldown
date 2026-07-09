<?php

declare(strict_types=1);

namespace ZaberDev\Cooldown;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use ZaberDev\Cooldown\Contracts\CooldownManagerContract;
use ZaberDev\Cooldown\Models\Cooldown;
use ZaberDev\Cooldown\Support\PendingCooldown;

/**
 * Trait granting expressive, scoped cooldown capabilities to any Eloquent model or domain entity.
 */
trait HasCooldowns
{
    /**
     * Start building or inspecting a cooldown specific to this model instance.
     *
     * @param string $action The action being throttled (e.g. 'send_message', 'password_reset').
     * @param string|null $driver Optional storage driver override ('cache', 'database').
     */
    public function cooldown(string $action, ?string $driver = null): PendingCooldown
    {
        /** @var CooldownManagerContract $manager */
        $manager = app(CooldownManagerContract::class);

        $pending = $manager->for($action, $this);

        if ($driver !== null) {
            $pending->using($driver);
        }

        return $pending;
    }

    /**
     * Get the unique string identifier representing this model for cooldown storage.
     */
    public function getCooldownTargetIdentifier(): string
    {
        if (method_exists($this, 'getKey')) {
            return $this->getMorphClass() . ':' . (string) $this->getKey();
        }

        return spl_object_hash($this);
    }

    /**
     * Reset all active cooldowns targeting specifically this model instance across the storage driver.
     */
    public function resetAllCooldowns(?string $driver = null): int
    {
        /** @var CooldownManagerContract $manager */
        $manager = app(CooldownManagerContract::class);
        $driverInstance = $manager->driver($driver);

        $prefix = method_exists($this, 'getKey')
            ? str_replace('\\', '_', $this->getMorphClass()) . ':' . $this->getKey()
            : null;

        if ($prefix !== null && method_exists($driverInstance, 'flush')) {
            // Flush rows matching this target's signature
            if ($driver === 'database' || ($driver === null && $manager->getDefaultDriver() === 'database')) {
                return Cooldown::query()
                    ->where('target_type', $this->getMorphClass())
                    ->where('target_id', $this->getKey())
                    ->delete();
            }
        }

        return 0;
    }

    /**
     * Eloquent polymorphic relationship to persistent database cooldown records.
     */
    public function cooldowns(): MorphMany
    {
        return $this->morphMany(Cooldown::class, 'target');
    }
}
