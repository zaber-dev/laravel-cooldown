<?php

declare(strict_types=1);

namespace ZaberDev\Cooldown\Tests\Feature;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Event;
use ZaberDev\Cooldown\Events\CooldownInitiated;
use ZaberDev\Cooldown\Events\CooldownPassed;
use ZaberDev\Cooldown\Events\CooldownReset;
use ZaberDev\Cooldown\Exceptions\CooldownActiveException;
use ZaberDev\Cooldown\Exceptions\CooldownDriverNotFoundException;
use ZaberDev\Cooldown\Facades\Cooldown;
use ZaberDev\Cooldown\Tests\TestCase;

class FacadeAndManagerTest extends TestCase
{
    public function test_it_can_initiate_check_and_reset_cache_cooldowns_fluent_syntax(): void
    {
        Event::fake();

        $this->assertTrue(Cooldown::passed('send_email'));
        $this->assertFalse(Cooldown::active('send_email'));

        $info = Cooldown::for('send_email')->for(60);

        $this->assertEquals('send_email', $info->key);
        $this->assertTrue(Cooldown::active('send_email'));
        $this->assertFalse(Cooldown::passed('send_email'));

        Event::assertDispatched(CooldownInitiated::class, fn ($e) => $e->key === 'send_email' && $e->action === 'send_email');

        Cooldown::reset('send_email');

        $this->assertFalse(Cooldown::active('send_email'));
        $this->assertTrue(Cooldown::passed('send_email'));

        Event::assertDispatched(CooldownReset::class);
    }

    public function test_it_supports_human_interval_strings(): void
    {
        Cooldown::for('api_call')->expiresIn('5 minutes');

        $this->assertTrue(Cooldown::active('api_call'));
        $this->assertGreaterThanOrEqual(299, Cooldown::for('api_call')->remaining());
    }

    public function test_it_throws_cooldown_active_exception_on_enforce(): void
    {
        Cooldown::for('login_attempt')->for(120);

        $this->expectException(CooldownActiveException::class);
        $this->expectExceptionCode(429);

        Cooldown::for('login_attempt')->enforce();
    }

    public function test_it_can_switch_to_database_driver(): void
    {
        $this->assertFalse(Cooldown::driver('database')->get('db_action') !== null);

        $info = Cooldown::for('db_action')->using('database')->for(300);

        $this->assertEquals('db_action', $info->key);
        $this->assertTrue(Cooldown::for('db_action')->using('database')->active());

        // Cache driver should still be independent and unassigned for 'db_action'
        $this->assertFalse(Cooldown::for('db_action')->using('cache')->active());
    }

    public function test_it_throws_when_driver_not_found(): void
    {
        $this->expectException(CooldownDriverNotFoundException::class);
        Cooldown::driver('invalid_driver_name');
    }
}
