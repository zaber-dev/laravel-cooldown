<?php

declare(strict_types=1);

namespace ZaberDev\Cooldown\Facades;

use Illuminate\Support\Facades\Facade;
use ZaberDev\Cooldown\Contracts\CooldownManagerContract;

/**
 * @method static \ZaberDev\Cooldown\Contracts\CooldownDriverContract driver(\UnitEnum|string|null $driver = null)
 * @method static \ZaberDev\Cooldown\Contracts\CooldownManagerContract extend(string $driver, \Closure $callback)
 * @method static \ZaberDev\Cooldown\Support\PendingCooldown for(string $action, \Illuminate\Database\Eloquent\Model|string|int|null $target = null)
 * @method static \ZaberDev\Cooldown\Support\PendingCooldown on(string $action, \Illuminate\Database\Eloquent\Model|string|int|null $target = null)
 * @method static bool active(string $action, \Illuminate\Database\Eloquent\Model|string|int|null $target = null)
 * @method static bool passed(string $action, \Illuminate\Database\Eloquent\Model|string|int|null $target = null)
 * @method static bool reset(string $action, \Illuminate\Database\Eloquent\Model|string|int|null $target = null)
 *
 * @see \ZaberDev\Cooldown\CooldownManager
 */
class Cooldown extends Facade
{
    /**
     * Get the registered name of the component in the IoC container.
     */
    protected static function getFacadeAccessor(): string
    {
        return CooldownManagerContract::class;
    }
}
