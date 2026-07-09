<?php

declare(strict_types=1);

namespace ZaberDev\Cooldown\DTO;

use Carbon\CarbonImmutable;

/**
 * Immutable Data Transfer Object representing the state of a cooldown.
 */
readonly class CooldownInfo
{
    /**
     * @param string $key The unique identifier key for this cooldown.
     * @param CarbonImmutable $expiresAt The exact timestamp when this cooldown expires.
     * @param CarbonImmutable $createdAt The timestamp when this cooldown was initiated or updated.
     */
    public function __construct(
        public string $key,
        public CarbonImmutable $expiresAt,
        public CarbonImmutable $createdAt,
    ) {
    }

    /**
     * Determine if the cooldown is currently active (has not expired).
     */
    public function isValid(): bool
    {
        return CarbonImmutable::now()->isBefore($this->expiresAt);
    }

    /**
     * Determine if the cooldown has expired.
     */
    public function isExpired(): bool
    {
        return ! $this->isValid();
    }

    /**
     * Get the number of remaining seconds until expiration.
     * Returns 0 if the cooldown has already expired.
     */
    public function remainingSeconds(): int
    {
        if ($this->isExpired()) {
            return 0;
        }

        return (int) ceil(max(0, CarbonImmutable::now()->floatDiffInSeconds($this->expiresAt, false)));
    }

    /**
     * Get a human-readable representation of the remaining cooldown time.
     *
     * @param array<string, mixed> $options Carbon diffForHumans options.
     */
    public function remainingForHumans(array $options = []): string
    {
        if ($this->isExpired()) {
            return 'expired';
        }

        return CarbonImmutable::now()->diffForHumans($this->expiresAt, array_merge([
            'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE,
            'parts' => 2,
        ], $options));
    }

    /**
     * Convert the DTO to an array suitable for JSON serialization or logging.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'expires_at' => $this->expiresAt->toIso8601String(),
            'created_at' => $this->createdAt->toIso8601String(),
            'is_valid' => $this->isValid(),
            'remaining_seconds' => $this->remainingSeconds(),
        ];
    }
}
