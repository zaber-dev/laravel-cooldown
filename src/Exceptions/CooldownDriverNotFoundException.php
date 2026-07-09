<?php

declare(strict_types=1);

namespace ZaberDev\Cooldown\Exceptions;

/**
 * Exception thrown when a requested cooldown storage driver has not been configured or registered.
 */
class CooldownDriverNotFoundException extends CooldownException
{
    public static function make(string $driver): self
    {
        return new self("Cooldown storage driver [{$driver}] is not supported or registered.");
    }
}
