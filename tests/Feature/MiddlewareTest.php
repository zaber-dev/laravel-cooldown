<?php

declare(strict_types=1);

namespace ZaberDev\Cooldown\Tests\Feature;

use Illuminate\Support\Facades\Route;
use ZaberDev\Cooldown\Facades\Cooldown;
use ZaberDev\Cooldown\Tests\TestCase;

class MiddlewareTest extends TestCase
{
    public function test_route_middleware_enforces_cooldown_and_sets_retry_after_header(): void
    {
        Route::post('/test-endpoint', function () {
            return response()->json(['status' => 'success']);
        })->middleware('cooldown:submit_form,60');

        // First request should succeed and trigger the cooldown
        $response1 = $this->postJson('/test-endpoint');
        $response1->assertOk()->assertJson(['status' => 'success']);

        $this->assertTrue(Cooldown::active('submit_form', '127.0.0.1'));

        // Second immediate request should be throttled with HTTP 429 and Retry-After header
        $response2 = $this->postJson('/test-endpoint');
        $response2->assertStatus(429);
        $this->assertTrue($response2->headers->has('Retry-After'));
        $this->assertNotEmpty($response2->headers->get('Retry-After'));
    }

    public function test_route_middleware_does_not_trigger_cooldown_on_failed_response(): void
    {
        Route::post('/failing-endpoint', function () {
            return response()->json(['error' => 'bad request'], 400);
        })->middleware('cooldown:failing_action,60');

        $response = $this->postJson('/failing-endpoint');
        $response->assertStatus(400);

        // Cooldown should NOT be active since the request failed (4xx/5xx)
        $this->assertFalse(Cooldown::active('failing_action', '127.0.0.1'));
    }
}
