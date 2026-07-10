<?php

declare(strict_types=1);

namespace ZaberDev\Cooldown\Drivers;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZaberDev\Cooldown\Contracts\CooldownDriverContract;
use ZaberDev\Cooldown\DTO\CooldownInfo;

/**
 * Storage driver implementing cooldown persistence via Laravel's Cache repositories.
 */
class CacheCooldownDriver implements CooldownDriverContract
{
    public function __construct(
        protected CacheRepository $cache,
        protected string $prefix = 'cooldown:'
    ) {
    }

    /**
     * Retrieve the cooldown information for the given key, if it exists and is active.
     */
    public function get(string $key): ?CooldownInfo
    {
        $payload = $this->cache->get($this->formatKey($key));

        if (! is_array($payload) || ! isset($payload['expires_at'], $payload['created_at'])) {
            return null;
        }

        $expiresAt = CarbonImmutable::createFromTimestamp($payload['expires_at']);

        if (CarbonImmutable::now()->isAfter($expiresAt)) {
            $this->forget($key);

            return null;
        }

        return new CooldownInfo(
            key: $key,
            expiresAt: $expiresAt,
            createdAt: CarbonImmutable::createFromTimestamp($payload['created_at']),
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

        $seconds = (int) $now->diffInSeconds($expiresAt, false);

        if ($seconds <= 0) {
            $this->forget($key);

            return new CooldownInfo($key, $expiresAt, $now);
        }

        $payload = [
            'key' => $key,
            'expires_at' => $expiresAt->getTimestamp(),
            'created_at' => $now->getTimestamp(),
        ];

        $this->cache->put($this->formatKey($key), $payload, $seconds);

        return new CooldownInfo($key, $expiresAt, $now);
    }

    /**
     * Remove a specific cooldown from storage.
     */
    public function forget(string $key): bool
    {
        return $this->cache->forget($this->formatKey($key));
    }

    /**
     * Remove all cooldowns from storage.
     * Note: Cache store clearing is driver-dependent. If tags are supported or a prefix is used,
     * this method flushes the active store or tagged space.
     */
    public function flush(?string $prefix = null): int
    {
        $this->cache->clear();

        return 0;
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

        return $this->cache->add($this->formatKey('lock:' . $key), true, $seconds);
    }

    /**
     * Release an atomic in-flight reservation lock for the given key.
     */
    public function releaseLock(string $key): bool
    {
        return $this->cache->forget($this->formatKey('lock:' . $key));
    }

    /**
     * Determine if an atomic in-flight reservation lock currently exists for the given key.
     */
    public function isLocked(string $key): bool
    {
        return $this->cache->has($this->formatKey('lock:' . $key));
    }

    /**
     * Format the raw storage key with the driver prefix.
     */
    protected function formatKey(string $key): string
    {
        return $this->prefix . $key;
    }
}
