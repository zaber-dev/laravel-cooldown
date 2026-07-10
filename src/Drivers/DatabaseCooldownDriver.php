<?php

declare(strict_types=1);

namespace ZaberDev\Cooldown\Drivers;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use ZaberDev\Cooldown\Contracts\CooldownDriverContract;
use ZaberDev\Cooldown\DTO\CooldownInfo;
use ZaberDev\Cooldown\Models\Cooldown;

/**
 * Storage driver implementing persistent cooldown management using an Eloquent database table.
 */
class DatabaseCooldownDriver implements CooldownDriverContract
{
    /**
     * Retrieve the cooldown information for the given key, if it exists and is active.
     */
    public function get(string $key): ?CooldownInfo
    {
        $cooldown = Cooldown::forKey($key)->first();

        if (! $cooldown) {
            return null;
        }

        $expiresAt = CarbonImmutable::instance($cooldown->expires_at);

        if (CarbonImmutable::now()->isAfter($expiresAt)) {
            $cooldown->delete();

            return null;
        }

        return new CooldownInfo(
            key: $cooldown->key,
            expiresAt: $expiresAt,
            createdAt: CarbonImmutable::instance($cooldown->created_at ?: CarbonImmutable::now()),
        );
    }

    /**
     * Store or update a cooldown with the specified expiration duration or timestamp.
     */
    public function put(string $key, DateTimeInterface|int|string $expiresAtOrSeconds): CooldownInfo
    {
        $now = CarbonImmutable::now();

        if ($expiresAtOrSeconds instanceof DateTimeInterface) {
            $expiresAt = CarbonImmutable::instance($expiresAtOrSeconds);
        } elseif (is_numeric($expiresAtOrSeconds)) {
            $expiresAt = $now->addSeconds((int) $expiresAtOrSeconds);
        } else {
            $expiresAt = CarbonImmutable::parse($expiresAtOrSeconds);
        }

        if ($now->isAfter($expiresAt)) {
            $this->forget($key);

            return new CooldownInfo($key, $expiresAt, $now);
        }

        [$action, $targetType, $targetId] = $this->parseKeyComponents($key);

        $cooldown = Cooldown::query()->updateOrCreate(
            ['key' => $key],
            [
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'expires_at' => $expiresAt,
            ]
        );

        return new CooldownInfo(
            key: $cooldown->key,
            expiresAt: $expiresAt,
            createdAt: CarbonImmutable::instance($cooldown->created_at ?: $now),
        );
    }

    /**
     * Remove a specific cooldown from storage.
     */
    public function forget(string $key): bool
    {
        return Cooldown::forKey($key)->delete() > 0;
    }

    /**
     * Remove all cooldowns from storage, optionally filtered by prefix.
     */
    public function flush(?string $prefix = null): int
    {
        $query = Cooldown::query();

        if ($prefix !== null && $prefix !== '') {
            $query->where('key', 'like', $prefix . '%');
        }

        return $query->delete();
    }

    /**
     * Attempt to acquire an atomic in-flight reservation lock for the given key.
     */
    public function acquireLock(string $key, int $seconds = 15): bool
    {
        if ($seconds <= 0) {
            $this->releaseLock($key);

            return false;
        }

        $lockKey = 'lock:' . $key;
        $now = CarbonImmutable::now();
        $expiresAt = $now->addSeconds($seconds);

        return Cooldown::query()->getConnection()->transaction(function () use ($lockKey, $key, $expiresAt, $now) {
            $existing = Cooldown::query()->where('key', $lockKey)->lockForUpdate()->first();

            if ($existing) {
                if ($now->isAfter(CarbonImmutable::instance($existing->expires_at))) {
                    $existing->delete();
                } else {
                    return false;
                }
            }

            [$action, $targetType, $targetId] = $this->parseKeyComponents($key);

            try {
                Cooldown::query()->insert([
                    'key' => $lockKey,
                    'action' => 'lock:' . $action,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'expires_at' => $expiresAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return true;
            } catch (\Throwable $e) {
                return false;
            }
        });
    }

    /**
     * Release an atomic in-flight reservation lock for the given key.
     */
    public function releaseLock(string $key): bool
    {
        return Cooldown::forKey('lock:' . $key)->delete() > 0;
    }

    /**
     * Determine if an atomic in-flight reservation lock currently exists for the given key.
     */
    public function isLocked(string $key): bool
    {
        $lockKey = 'lock:' . $key;
        $existing = Cooldown::forKey($lockKey)->first();

        if (! $existing) {
            return false;
        }

        if (CarbonImmutable::now()->isAfter(CarbonImmutable::instance($existing->expires_at))) {
            $existing->delete();

            return false;
        }

        return true;
    }

    /**
     * Parse target type and ID metadata out of the formatted key string when storing in database.
     *
     * @return array{0: string, 1: ?string, 2: int|string|null}
     */
    protected function parseKeyComponents(string $key): array
    {
        $parts = explode(':', $key);
        $action = $parts[0] ?? $key;

        if (count($parts) >= 3) {
            $targetType = implode('\\', explode('_', $parts[1]));
            $targetId = $parts[2];

            return [$action, $targetType, $targetId];
        }

        return [$action, null, null];
    }
}
