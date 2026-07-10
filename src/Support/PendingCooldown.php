<?php

declare(strict_types=1);

namespace ZaberDev\Cooldown\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use ZaberDev\Cooldown\Contracts\Cooldownable;
use ZaberDev\Cooldown\Contracts\CooldownDriverContract;
use ZaberDev\Cooldown\Contracts\CooldownManagerContract;
use ZaberDev\Cooldown\DTO\CooldownInfo;
use ZaberDev\Cooldown\Events\CooldownInitiated;
use ZaberDev\Cooldown\Events\CooldownPassed;
use ZaberDev\Cooldown\Events\CooldownReset;
use ZaberDev\Cooldown\Exceptions\CooldownActiveException;

/**
 * Fluent builder and inspector for managing a specific cooldown instance.
 */
class PendingCooldown
{
    /**
     * @param CooldownManagerContract $manager The central cooldown manager.
     * @param string $action The action or operation being throttled.
     * @param Model|string|int|null $target Optional target entity or identifier.
     * @param string|null $driver Optional driver name to override the default.
     */
    public function __construct(
        protected CooldownManagerContract $manager,
        protected string $action,
        protected mixed $target = null,
        protected ?string $driver = null,
    ) {
    }

    /**
     * Specify a particular storage driver to use for this cooldown operation.
     */
    public function using(string $driver): self
    {
        $this->driver = $driver;

        return $this;
    }

    /**
     * Initiate or extend the cooldown for a specific duration.
     *
     * @param int|string|DateInterval|DateTimeInterface $duration Seconds, human string (e.g. '5 minutes'), DateInterval, or DateTimeInterface.
     */
    public function for(int|string|DateInterval|DateTimeInterface $duration): CooldownInfo
    {
        $expiresAt = $this->calculateExpiration($duration);
        $driver = $this->getDriverInstance();
        $key = $this->resolveKey();

        $info = $driver->put($key, $expiresAt);

        $this->dispatchEvent(new CooldownInitiated($key, $this->action, $this->target, $info->expiresAt));

        return $info;
    }

    /**
     * Alias for `for()` offering natural phrasing when passing human interval strings (e.g., `expiresIn('15 minutes')`).
     */
    public function expiresIn(int|string|DateInterval|DateTimeInterface $duration): CooldownInfo
    {
        return $this->for($duration);
    }

    /**
     * Initiate or extend the cooldown until an exact date or timestamp.
     */
    public function until(DateTimeInterface|string $date): CooldownInfo
    {
        if (is_string($date)) {
            $date = CarbonImmutable::parse($date);
        }

        return $this->for($date);
    }

    /**
     * Determine whether the cooldown is currently active (has not expired).
     */
    public function active(): bool
    {
        $info = $this->info();

        if ($info === null) {
            return false;
        }

        return $info->isValid();
    }

    /**
     * Expressive alias for `active()`.
     */
    public function isOnCooldown(): bool
    {
        return $this->active();
    }

    /**
     * Determine whether the cooldown period has passed (expired or never initiated).
     */
    public function passed(): bool
    {
        $passed = ! $this->active();

        if ($passed) {
            $this->dispatchEvent(new CooldownPassed($this->resolveKey(), $this->action, $this->target));
        }

        return $passed;
    }

    /**
     * Enforce the cooldown by throwing a CooldownActiveException if currently active.
     *
     * @throws CooldownActiveException
     */
    public function enforce(?string $message = null): void
    {
        $info = $this->info();

        if ($info !== null && $info->isValid()) {
            if ($message !== null) {
                throw new CooldownActiveException($message, $info);
            }

            throw CooldownActiveException::forAction($this->action, $info);
        }

        if ($this->isLocked()) {
            $lockInfo = new CooldownInfo(
                key: $this->resolveKey(),
                expiresAt: CarbonImmutable::now()->addSeconds(3),
                createdAt: CarbonImmutable::now()
            );

            $msg = $message ?? "Action [{$this->action}] is currently being processed. Please wait.";

            throw new CooldownActiveException($msg, $lockInfo);
        }
    }

    /**
     * Get the remaining time in seconds until the cooldown expires.
     */
    public function remaining(): int
    {
        return $this->info()?->remainingSeconds() ?? 0;
    }

    /**
     * Get a human-readable representation of the remaining cooldown time.
     *
     * @param array<string, mixed> $options Carbon diffForHumans options.
     */
    public function remainingForHumans(array $options = []): string
    {
        return $this->info()?->remainingForHumans($options) ?? 'expired';
    }

    /**
     * Get the exact datetime when this cooldown will expire, or null if not active.
     */
    public function expiresAt(): ?CarbonImmutable
    {
        return $this->info()?->expiresAt;
    }

    /**
     * Retrieve the full immutable CooldownInfo DTO if the cooldown is active.
     */
    public function info(): ?CooldownInfo
    {
        return $this->getDriverInstance()->get($this->resolveKey());
    }

    /**
     * Immediately reset (clear) the cooldown for this action and target.
     */
    public function reset(): bool
    {
        $key = $this->resolveKey();
        $forgotten = $this->getDriverInstance()->forget($key);

        if ($forgotten) {
            $this->dispatchEvent(new CooldownReset($key, $this->action, $this->target));
        }

        return $forgotten;
    }

    /**
     * Attempt to acquire an atomic in-flight reservation lock for this action and target.
     */
    public function acquireLock(int $seconds = 15): bool
    {
        $driver = $this->getDriverInstance();
        $key = $this->resolveKey();

        if (method_exists($driver, 'acquireLock')) {
            return $driver->acquireLock($key, $seconds);
        }

        return false;
    }

    /**
     * Release an atomic in-flight reservation lock for this action and target.
     */
    public function releaseLock(): bool
    {
        $driver = $this->getDriverInstance();
        $key = $this->resolveKey();

        if (method_exists($driver, 'releaseLock')) {
            return $driver->releaseLock($key);
        }

        return false;
    }

    /**
     * Determine if an atomic in-flight reservation lock currently exists for this action and target.
     */
    public function isLocked(): bool
    {
        $driver = $this->getDriverInstance();
        $key = $this->resolveKey();

        if (method_exists($driver, 'isLocked')) {
            return $driver->isLocked($key);
        }

        return false;
    }

    /**
     * Execute a callback inside an atomic in-flight reservation, enforcing cooldown before and acquiring lock.
     * Initiates the cooldown only when the callback completes successfully.
     *
     * @template T
     * @param Closure(): T $callback
     * @param int|string|DateInterval|DateTimeInterface $duration
     * @param int $lockSeconds
     * @return T
     *
     * @throws CooldownActiveException
     */
    public function block(Closure $callback, int|string|DateInterval|DateTimeInterface $duration = 60, int $lockSeconds = 15): mixed
    {
        $this->enforce();

        if (! $this->acquireLock($lockSeconds)) {
            $this->enforce("Action [{$this->action}] is currently being processed. Please wait.");
        }

        try {
            $result = $callback();

            $this->for($duration);

            return $result;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Resolve the storage key uniquely identifying this action and target combination.
     */
    public function resolveKey(): string
    {
        if ($this->target === null) {
            return $this->action;
        }

        if ($this->target instanceof Cooldownable) {
            return $this->action . ':' . $this->target->getCooldownTargetIdentifier();
        }

        if ($this->target instanceof Model) {
            $type = str_replace('\\', '_', $this->target->getMorphClass());
            return $this->action . ':' . $type . ':' . $this->target->getKey();
        }

        return $this->action . ':' . (string) $this->target;
    }

    /**
     * Resolve the appropriate storage driver instance from the manager.
     */
    protected function getDriverInstance(): CooldownDriverContract
    {
        return $this->manager->driver($this->driver);
    }

    /**
     * Calculate an absolute CarbonImmutable timestamp from the provided duration input.
     */
    protected function calculateExpiration(int|string|DateInterval|DateTimeInterface $duration): CarbonImmutable
    {
        if ($duration instanceof DateTimeInterface) {
            return CarbonImmutable::instance($duration);
        }

        $now = CarbonImmutable::now();

        if (is_int($duration) || is_numeric($duration)) {
            return $now->addSeconds((int) $duration);
        }

        if ($duration instanceof DateInterval) {
            return $now->add($duration);
        }

        if (is_string($duration)) {
            try {
                $interval = CarbonInterval::fromString($duration);
                return $now->add($interval);
            } catch (\Throwable $e) {
                return CarbonImmutable::parse($duration);
            }
        }

        return $now;
    }

    /**
     * Helper to safely dispatch events if enabled in the package configuration.
     */
    protected function dispatchEvent(object $event): void
    {
        if (config('cooldowns.events.dispatch', true) && class_exists(Event::class)) {
            Event::dispatch($event);
        }
    }
}
