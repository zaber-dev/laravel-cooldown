<?php

declare(strict_types=1);

namespace ZaberDev\Cooldown\Tests\Feature;

use Illuminate\Support\Facades\Route;
use ZaberDev\Cooldown\Exceptions\CooldownActiveException;
use ZaberDev\Cooldown\Facades\Cooldown;
use ZaberDev\Cooldown\Tests\TestCase;

class ConcurrencyAndLockingTest extends TestCase
{
    public function test_cache_driver_acquire_release_and_is_locked(): void
    {
        $pending = Cooldown::for('test_cache_lock')->using('cache');

        $this->assertFalse($pending->isLocked());

        $acquired = $pending->acquireLock(10);
        $this->assertTrue($acquired);
        $this->assertTrue($pending->isLocked());

        // Second acquire attempt should fail
        $acquiredAgain = $pending->acquireLock(10);
        $this->assertFalse($acquiredAgain);

        $released = $pending->releaseLock();
        $this->assertTrue($released);
        $this->assertFalse($pending->isLocked());
    }

    public function test_database_driver_acquire_release_and_is_locked(): void
    {
        $pending = Cooldown::for('test_db_lock')->using('database');

        $this->assertFalse($pending->isLocked());

        $acquired = $pending->acquireLock(10);
        $this->assertTrue($acquired);
        $this->assertTrue($pending->isLocked());

        // Second acquire attempt should fail
        $acquiredAgain = $pending->acquireLock(10);
        $this->assertFalse($acquiredAgain);

        $released = $pending->releaseLock();
        $this->assertTrue($released);
        $this->assertFalse($pending->isLocked());
    }

    public function test_block_method_executes_callback_and_sets_cooldown_on_success(): void
    {
        $pending = Cooldown::for('block_action');

        $executed = false;
        $result = $pending->block(function () use (&$executed) {
            $executed = true;
            return 'success_value';
        }, 60);

        $this->assertTrue($executed);
        $this->assertEquals('success_value', $result);
        $this->assertTrue($pending->active());
        $this->assertFalse($pending->isLocked());
    }

    public function test_block_method_releases_lock_without_setting_cooldown_on_exception(): void
    {
        $pending = Cooldown::for('error_block_action');

        try {
            $pending->block(function () {
                throw new \RuntimeException('Simulated error');
            }, 60);
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertEquals('Simulated error', $e->getMessage());
        }

        $this->assertFalse($pending->isLocked());
        $this->assertFalse($pending->active());
    }

    public function test_block_method_throws_cooldown_active_exception_when_already_locked(): void
    {
        $pending = Cooldown::for('locked_block_action');
        $pending->acquireLock(15);

        $this->expectException(CooldownActiveException::class);
        $this->expectExceptionCode(429);

        $pending->block(function () {
            return 'should_not_run';
        }, 60);
    }

    public function test_middleware_blocks_concurrent_request_while_in_flight(): void
    {
        Route::post('/slow-endpoint', function () {
            // Simulate a second concurrent request arriving for the exact same target while Request 1 is mid-execution
            $response2 = $this->postJson('/slow-endpoint');
            $response2->assertStatus(429);
            $this->assertStringContainsString('is currently being processed', (string) $response2->getContent());

            return response()->json(['status' => 'ok']);
        })->middleware('cooldown:slow_action,60');

        $response1 = $this->postJson('/slow-endpoint');
        $response1->assertOk()->assertJson(['status' => 'ok']);

        $this->assertTrue(Cooldown::active('slow_action', '127.0.0.1'));
        $this->assertFalse(Cooldown::for('slow_action', '127.0.0.1')->isLocked());
    }

    public function test_middleware_releases_lock_on_failed_response_and_does_not_set_cooldown(): void
    {
        Route::post('/failing-in-flight', function () {
            $this->assertTrue(Cooldown::for('failing_action', '127.0.0.1')->isLocked());

            return response()->json(['error' => 'validation failed'], 422);
        })->middleware('cooldown:failing_action,60');

        $response = $this->postJson('/failing-in-flight');
        $response->assertStatus(422);

        $this->assertFalse(Cooldown::for('failing_action', '127.0.0.1')->isLocked());
        $this->assertFalse(Cooldown::active('failing_action', '127.0.0.1'));
    }
}
