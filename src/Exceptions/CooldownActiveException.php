<?php

declare(strict_types=1);

namespace ZaberDev\Cooldown\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;
use ZaberDev\Cooldown\DTO\CooldownInfo;

/**
 * Exception thrown when an action is attempted while currently on an active cooldown.
 */
class CooldownActiveException extends HttpException
{
    public readonly CooldownInfo $cooldownInfo;
    public readonly int $retryAfterSeconds;

    public function __construct(
        string $message,
        CooldownInfo $cooldownInfo,
        ?\Throwable $previous = null,
        int $code = 0
    ) {
        $this->cooldownInfo = $cooldownInfo;
        $this->retryAfterSeconds = $cooldownInfo->remainingSeconds();

        $headers = [
            'Retry-After' => (string) $this->retryAfterSeconds,
        ];

        parent::__construct(429, $message, $previous, $headers, $code ?: 429);
    }

    public static function forAction(string $action, CooldownInfo $info): self
    {
        $remaining = $info->remainingForHumans();

        return new self(
            "Action [{$action}] is currently on cooldown. Please try again in {$remaining}.",
            $info
        );
    }
}
