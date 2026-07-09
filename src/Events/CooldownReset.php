<?php

declare(strict_types=1);

namespace ZaberDev\Cooldown\Events;

/**
 * Event dispatched when a cooldown is manually reset or flushed.
 */
readonly class CooldownReset
{
    public function __construct(
        public string $key,
        public string $action,
        public mixed $target,
    ) {
    }
}
