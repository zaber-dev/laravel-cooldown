<?php

declare(strict_types=1);

namespace ZaberDev\Cooldown\Events;

/**
 * Event dispatched when checking a cooldown that has expired or passed.
 */
readonly class CooldownPassed
{
    public function __construct(
        public string $key,
        public string $action,
        public mixed $target,
    ) {
    }
}
