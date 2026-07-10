---
name: laravel-cooldown
description: Expert AI guidance, architectural best practices, and exact API patterns for implementing entity-scoped action cooldowns, rate limits, and workflow delays using `zaber-dev/laravel-cooldown` (`Cooldown` facade, `HasCooldowns` trait, polymorphic targeting, drivers, atomic `block()`, exception enforcing, and route middleware).
---

# Laravel Cooldown (`zaber-dev/laravel-cooldown`) Skill Guide

`zaber-dev/laravel-cooldown` is a driver-based cooldown management package for Laravel (11, 12 & 13+) designed for persistent, entity-scoped action cooldowns and workflow delays with interchangeable cache and database storage.

When working with or generating code using `laravel-cooldown`, strictly adhere to the patterns and architectural rules outlined below.

---

## 1. Core Architecture & Concepts

Unlike Laravel's built-in `RateLimiter` (which is memory/cache-bound and generic), `laravel-cooldown` provides:
1. **Polymorphic Entity Targeting**: Attach cooldowns directly to Eloquent models (`$user->cooldown('action')`) or scalar targets (`$ip`, `$userId`). When passed an Eloquent model, it resolves to `action:App\Models\User:12` respecting `Relation::enforceMorphMap()`.
2. **Multi-Driver Storage (`Manager` pattern)**: Swappable storage mediums per request (`cache` vs `database`).
3. **Immutable DTOs (`CooldownInfo`)**: Exact CarbonImmutable time calculations avoiding sub-second drift.
4. **Atomic In-Flight Execution (`block()`)**: Prevents race conditions and double-click bursts by acquiring temporary execution locks before running callbacks.
5. **Smart Route Middleware**: Checks and locks before controller execution, only initiating the permanent cooldown upon successful (`2xx`/`3xx`) responses so validation/server errors don't lock out users.

---

## 2. When to Use Which Driver

The package supports two primary drivers configured in `config/cooldowns.php`:
- **`cache` (Default)**: Uses Laravel's cache stores (Redis, Memcached, Array). Ideal for high-throughput API rate checks, OTP resend timers, and volatile action delays.
- **`database`**: Stores records in the `cooldowns` table with `target_type` / `target_id` / `expires_at`. Ideal when cooldowns must survive server restarts, audit trails are needed, or across microservices sharing a relational database without Redis.

```php
// Use cache driver explicitly
Cooldown::for('api_ping', $ip)->using('cache')->for(30);

// Use database driver explicitly
Cooldown::for('billing_charge', $user)->using('database')->for(86400);
```

---

## 3. Eloquent Model Integration (`HasCooldowns` Trait)

Always attach the `HasCooldowns` trait to Eloquent models that trigger cooldowns (e.g., `User`, `Team`, `ApiKey`):

```php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use ZaberDev\Cooldown\HasCooldowns;

class User extends Authenticatable
{
    use HasCooldowns;
}
```

### Model Cooldown Methods
```php
// Initiate or extend a cooldown targeting this user
$user->cooldown('send_sms')->for('5 minutes');
$user->cooldown('password_reset')->for(300); // 300 seconds

// Check status
if ($user->cooldown('send_sms')->active()) { // or ->isOnCooldown()
    $remaining = $user->cooldown('send_sms')->remaining(); // seconds (int)
    $human = $user->cooldown('send_sms')->remainingForHumans(); // e.g. "4 minutes"
}

// Check if cooldown has passed
if ($user->cooldown('send_sms')->passed()) {
    // Action allowed...
}

// Enforce cooldown (throws CooldownActiveException with Retry-After header)
$user->cooldown('send_sms')->enforce();
$user->cooldown('send_sms')->enforce('Please wait before resending an SMS.');

// Reset (clear) specific cooldown
$user->cooldown('send_sms')->reset();

// Reset ALL cooldowns targeting this model instance
$user->resetAllCooldowns(); // Or $user->resetAllCooldowns('database');

// Access persistent database records via Eloquent relation (when using database driver)
$user->cooldowns()->where('action', 'send_sms')->get();
```

---

## 4. Facade & Direct Usage (`Cooldown::`)

For global actions or scalar targets (`$ip`, `$token`, `$id`), use the `Cooldown` facade:

```php
use ZaberDev\Cooldown\Facades\Cooldown;

// Initiate cooldowns
Cooldown::for('global_broadcast')->for('1 hour'); // Or ->expiresIn('1 hour')
Cooldown::for('login_attempt', $ipAddress)->until(now()->addMinutes(15));
Cooldown::on('export_data', $userId)->for(600); // `on()` is an alias for `for()`

// Quick inspections on facade
if (Cooldown::active('global_broadcast')) {
    // ...
}

if (Cooldown::passed('login_attempt', $ipAddress)) {
    // ...
}

// Reset
Cooldown::reset('login_attempt', $ipAddress);
```

---

## 5. Atomic Execution & Double-Click Protection (`->block()`)

**Best Practice:** Whenever performing an action that should only execute once per interval and requires protection against concurrent burst requests (e.g., double-clicking a submit button), use `block()` instead of manual `enforce()` + `for()`:

```php
use ZaberDev\Cooldown\Facades\Cooldown;
use ZaberDev\Cooldown\Exceptions\CooldownActiveException;

try {
    $result = Cooldown::for('process_payout', $user)->block(
        callback: function () use ($payoutService, $user) {
            return $payoutService->transfer($user);
        },
        duration: 3600,     // Cooldown applied ONLY if callback succeeds
        lockSeconds: 15     // Temporary atomic lock during execution
    );
} catch (CooldownActiveException $e) {
    return response()->json([
        'error' => $e->getMessage(),
        'retry_after' => $e->getRetryAfter(), // Seconds until expiration
    ], 429);
}
```

---

## 6. Route & Endpoint Middleware (`cooldown`)

The package automatically registers the `cooldown` route middleware alias:

```php
use Illuminate\Support\Facades\Route;

// Syntax: cooldown:{action},{duration_in_seconds_or_string},{optional_driver}
Route::post('/send-otp', [OtpController::class, 'store'])
    ->middleware('cooldown:send_otp,120');

Route::post('/export-csv', [ExportController::class, 'create'])
    ->middleware('cooldown:export_csv,15 minutes,database');
```

**Key Behavior:**
- Automatically targets `$request->user() ?? $request->ip()`.
- Throws HTTP 429 (`CooldownActiveException`) before controller execution if on active cooldown or in-flight lock.
- Only sets the cooldown duration if `$response->isSuccessful() || $response->isRedirection()`. If validation fails (`HTTP 422`) or an exception occurs (`HTTP 500`), the lock releases cleanly without applying a cooldown.

---

## 7. Exception Handling & HTTP 429 Responses

`ZaberDev\Cooldown\Exceptions\CooldownActiveException` extends `Symfony\Component\HttpKernel\Exception\HttpException` (`HTTP 429`).
When thrown in web or API controllers, Laravel automatically renders a `429 Too Many Requests` response containing the `Retry-After` HTTP header.

```php
use ZaberDev\Cooldown\Exceptions\CooldownActiveException;

// Catching explicitly in service/action layers:
try {
    $user->cooldown('verification_email')->enforce();
} catch (CooldownActiveException $e) {
    // $e->getCooldownInfo() -> returns CooldownInfo DTO
    // $e->getRetryAfter()   -> returns integer remaining seconds
    // $e->getStatusCode()   -> 429
    // $e->getHeaders()      -> ['Retry-After' => ...]
}
```

---

## 8. Database Migration & Pruning

When using the `database` driver, ensure the migration is published and run:
```bash
php artisan vendor:publish --tag=cooldowns-migrations
php artisan migrate
```

To automatically clean up expired database records, schedule the pruning command in `routes/console.php` (Laravel 11+) or `app/Console/Kernel.php`:

```php
use Illuminate\Support\Facades\Schedule;
use ZaberDev\Cooldown\Models\Cooldown;

Schedule::command('model:prune', ['--model' => Cooldown::class])->daily();
```

---

## Summary Checklist for AI Agents

1. Always use `use ZaberDev\Cooldown\Facades\Cooldown;` when referencing the facade.
2. Ensure models use `use ZaberDev\Cooldown\HasCooldowns;` before calling `$model->cooldown()`.
3. Prefer `$pending->block(fn() => ...)` for state-mutating actions to gain both concurrency locking and automatic cooldown application upon success.
4. Use human-friendly duration intervals (`'10 minutes'`, `'2 hours'`) with `->for()` or `->expiresIn()` when appropriate for readability.
5. Rely on the `cooldown` middleware for simple HTTP endpoints where user or IP throttling is sufficient.
