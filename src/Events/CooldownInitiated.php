<?php

declare(strict_types=1);

namespace ZaberDev\Cooldown\Events;

use Carbon\CarbonImmutable;

/**
 * Event dispatched when a cooldown is initiated or updated.
 */
readonly class CooldownInitiated
{
    public function __construct(
        public string $key,
        public string $action,
        public mixed $target,
        public CarbonImmutable $expiresAt,
    ) {
    }
}
