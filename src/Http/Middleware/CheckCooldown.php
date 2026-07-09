<?php

declare(strict_types=1);

namespace ZaberDev\Cooldown\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use ZaberDev\Cooldown\Contracts\CooldownManagerContract;

/**
 * Route middleware to enforce cooldown rate limiting on HTTP endpoints.
 */
class CheckCooldown
{
    /**
     * Handle an incoming request, throwing HTTP 429 if on active cooldown.
     *
     * @param Request $request
     * @param Closure(Request): Response $next
     * @param string $action The action key for tracking.
     * @param string $duration Duration string or seconds (default: 60 seconds).
     * @param string|null $driver Optional storage driver name override.
     */
    public function handle(Request $request, Closure $next, string $action, string $duration = '60', ?string $driver = null): Response
    {
        /** @var CooldownManagerContract $manager */
        $manager = app(CooldownManagerContract::class);

        $target = $request->user() ?? $request->ip();

        $pending = $manager->for($action, $target);

        if ($driver !== null) {
            $pending->using($driver);
        }

        // If currently on cooldown, enforce throws HTTP 429 CooldownActiveException with Retry-After header
        $pending->enforce();

        $response = $next($request);

        // Initiate the cooldown upon successful response (HTTP 2xx or 3xx redirection)
        if ($response->isSuccessful() || $response->isRedirection()) {
            $pending->for($duration);
        }

        return $response;
    }
}
