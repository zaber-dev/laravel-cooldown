# Learn: Building Modern, Driver-Based Packages in Laravel 13+

Welcome to the architectural learning guide for **Laravel Cooldown** (`zaber-dev/laravel-cooldown`). Whether you are evaluating this package for your enterprise application or studying modern Laravel package engineering for GitHub Community Exchange, this document provides a deep dive into the design patterns, abstractions, and developer ergonomics behind the project.

---

## 1. Core Architectural Goals

When building **Laravel Cooldown**, we aimed to solve several historical problems with temporal action throttling and rate limiting:
1. **Coupling to a Single Storage Engine**: Many packages hardcode `Cache::put()` or `DB::table()`, forcing developers to use either volatile memory or high-latency database operations exclusively.
2. **Loss of Precision in Time Math**: Floating-point subsecond discrepancies can cause rate-limit checks to expire prematurely or report inaccurate "remaining seconds" in API headers.
3. **Inconsistent Polymorphic Target Scoping**: Rate limiting a User (`App\Models\User:12`) vs an IP Address (`192.168.1.1`) vs an API Token usually requires disparate key formatting logic across application layers.
4. **Endpoint Middleware Gotchas**: Traditional rate-limiting middleware triggers the cooldown *before* the controller executes. If the controller throws a validation error or fails (`HTTP 400/500`), the user is unfairly locked out while trying to correct their input.

Let's explore how our architecture solves each of these challenges cleanly.

---

## 2. The Driver-Based Manager Pattern (`Illuminate\Support\Manager`)

At the heart of the package is the `CooldownManager`, extending `Illuminate\Support\Manager` and implementing `CooldownManagerContract`.

```
                    +-----------------------+
                    |  Cooldown (Facade)    |
                    +-----------+-----------+
                                |
                                v
                   +------------------------+
                   |    CooldownManager     |
                   +----+--------------+----+
                        |              |
         +--------------+              +--------------+
         | driver('cache')                            | driver('database')
         v                                            v
+-----------------------+                    +-----------------------+
|  CacheCooldownDriver  |                    | DatabaseCooldownDriver|
+-----------------------+                    +-----------------------+
| ->put() / ->get()     |                    | ->put() / ->get()     |
| (Redis/Memcached/Arr) |                    | (Eloquent / Prunable) |
+-----------------------+                    +-----------------------+
```

### Why a Manager?
By leveraging Laravel's `Manager` class, we decouple the API surface (`Cooldown::for()`) from the storage implementation (`CacheCooldownDriver` / `DatabaseCooldownDriver`).
- **Zero Overhead Resolution**: Drivers are lazily instantiated and cached (`$this->drivers[$name]`).
- **Runtime Swapping**: Developers can call `->using('database')` for critical financial transactions and `->using('cache')` for high-throughput API checks within the exact same request lifecycle.
- **Strict Interface Contracts**: Every driver strictly adheres to `CooldownDriverContract`, ensuring input/output parity across storage mediums.

---

## 3. Polymorphic Target Key Resolution

How does `$user->cooldown('send_sms')` know how to construct a unique, collision-free storage key?

In `PendingCooldown::resolveKey()`, we inspect the target's type:
```php
public function resolveKey(): string
{
    if ($this->target === null) {
        return $this->action;
    }

    if ($this->target instanceof Model) {
        // e.g., "send_sms:App\Models\User:12"
        return sprintf('%s:%s:%s', $this->action, $this->target->getMorphClass(), $this->target->getKey());
    }

    // e.g., "send_sms:192.168.1.50" or "send_sms:45"
    return sprintf('%s:%s', $this->action, $this->target);
}
```
- When passed an **Eloquent Model**, `getMorphClass()` respects custom morph maps defined in `Relation::enforceMorphMap()`, ensuring database migrations and cache keys remain stable even if class namespaces change.
- When passed a scalar (**String / Integer / IP Address**), it appends cleanly without type casting errors.

---

## 4. Immutable Data Transfer Objects (`CooldownInfo`)

Instead of returning raw timestamps or associative arrays that can be accidentally mutated or misparsed, every driver read returns a strongly typed `CooldownInfo` DTO:

```php
final class CooldownInfo
{
    public function __construct(
        public readonly string $key,
        public readonly CarbonImmutable $expiresAt,
        public readonly CarbonImmutable $createdAt,
    ) {}

    public function remainingSeconds(): int
    {
        if ($this->isExpired()) {
            return 0;
        }

        return (int) ceil(max(0, CarbonImmutable::now()->floatDiffInSeconds($this->expiresAt, false)));
    }
}
```

### Why `CarbonImmutable` & `ceil(floatDiffInSeconds)`?
1. **Immutability**: `CarbonImmutable` guarantees that downstream controllers or event listeners cannot accidentally alter the expiration timestamp when performing date comparisons (`$expiresAt->addDays(1)` returns a new instance, leaving the DTO intact).
2. **Subsecond Precision**: If a 120-second cooldown is set and checked 5 milliseconds later, standard integer `diffInSeconds()` truncates `119.995s` down to `119s`. By computing `ceil(floatDiffInSeconds())`, our API accurately reports `120` remaining seconds immediately after creation, preventing off-by-one errors in HTTP `Retry-After` headers.

---

## 5. Smart Endpoint Middleware (`CheckCooldown`)

Our route middleware (`CheckCooldown`) introduces an important behavioral improvement over naive rate limiters: **Success-Only Triggering**.

```php
public function handle(Request $request, Closure $next, string $action, string $duration = '60', ?string $driver = null): Response
{
    $manager = app(CooldownManagerContract::class);
    $target = $request->user() ?? $request->ip();
    $pending = $manager->for($action, $target);

    // 1. Check & Enforce existing permanent cooldown or active in-flight lock
    $pending->enforce();

    // 2. Acquire a short atomic in-flight reservation to block concurrent double-click bursts
    if (! $pending->acquireLock(15)) {
        $pending->enforce('Action is currently being processed. Please wait.');
    }

    try {
        $response = $next($request);

        // 3. ONLY initiate permanent cooldown if request succeeded or redirected (HTTP 2xx or 3xx)
        if ($response->isSuccessful() || $response->isRedirection()) {
            $pending->for($duration);
        }

        return $response;
    } finally {
        $pending->releaseLock();
    }
}
```
If a user submits a form and receives an HTTP `422 Unprocessable Entity` due to a typo in their email address, the cooldown is never initiated and the short in-flight reservation is released instantly. They can immediately fix their mistake and resubmit without waiting.

### Architectural Evolution: Check-Lock-Execute-Set via Atomic In-Flight Reservations
To solve the classic concurrency trade-off between **success-only initiation** (`2xx/3xx` only after execution) and **atomic burst protection** (preventing simultaneous double-click submissions during controller execution), `laravel-cooldown` implements an **Atomic In-Flight Reservation pattern**:

1. **Check (`enforce()`)**: Verify if a permanent cooldown or in-flight reservation is already active.
2. **Lock (`acquireLock(15)`)**: Atomically acquire a short, temporary in-flight reservation lock across the storage driver (`SETNX` in Cache or database transaction with row locks). If another thread is currently executing inside the controller, `acquireLock` fails and throws `HTTP 429`.
3. **Execute (`$next($request)`)**: Run the controller inside a `try...finally` block.
4. **Set (`for($duration)`) & Release (`releaseLock()`)**: If the controller returns a `2xx` or `3xx` response, the permanent temporal cooldown is stored. Regardless of outcome (`2xx`, `4xx validation error`, or `5xx exception`), the `finally` block releases the short in-flight reservation lock (`releaseLock()`).

This dual-layered architecture prevents race conditions between cooldown checks and cooldown creation across supported drivers without locking out users who experience validation or server errors. Developers can also use this exact pattern inside controllers via the fluent `$pending->block(Closure $callback, $duration, $lockSeconds = 15)` API.

---

## 6. Eloquent `Prunable` Integration

When using database-backed rate limiting, the database table can grow rapidly over time. Rather than requiring custom cron jobs or complex cleanup queries, the `ZaberDev\Cooldown\Models\Cooldown` model integrates directly with Laravel's native `Illuminate\Database\Eloquent\Prunable` trait:

```php
class Cooldown extends Model
{
    use Prunable;

    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<=', CarbonImmutable::now());
    }
}
```
This allows developers to run `php artisan model:prune` or schedule it via `Schedule::command('model:prune', ['--model' => Cooldown::class])->daily()` with zero custom code.

---

## Summary for Contributors & Students

By studying this codebase, you will see how modern Laravel 13 packages combine:
- **Service Providers** (`CooldownServiceProvider`) for dependency container registration and configuration publishing.
- **Contract-first engineering** (`CooldownDriverContract`, `CooldownManagerContract`) for mockable, highly testable code.
- **Fluent APIs** (`PendingCooldown`) to provide a delightful, readable developer experience (`Cooldown::for('action', $user)->for(300)`).
- **Orchestra Testbench 11** (`tests/TestCase.php`) for testing packages against real Laravel container pipelines in memory.

We invite you to explore the `src/` directory and see these patterns in action!
