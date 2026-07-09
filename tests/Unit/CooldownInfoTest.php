<?php

declare(strict_types=1);

namespace ZaberDev\Cooldown\Tests\Unit;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use ZaberDev\Cooldown\DTO\CooldownInfo;

class CooldownInfoTest extends TestCase
{
    public function test_it_reports_valid_and_remaining_seconds_when_not_expired(): void
    {
        $now = CarbonImmutable::now();
        $expires = $now->addSeconds(120);

        $info = new CooldownInfo('test:key', $expires, $now);

        $this->assertTrue($info->isValid());
        $this->assertFalse($info->isExpired());
        $this->assertEquals(120, $info->remainingSeconds());
    }

    public function test_it_reports_expired_when_past_expiration_time(): void
    {
        $now = CarbonImmutable::now();
        $expires = $now->subSeconds(10);

        $info = new CooldownInfo('test:key', $expires, $now->subSeconds(20));

        $this->assertFalse($info->isValid());
        $this->assertTrue($info->isExpired());
        $this->assertEquals(0, $info->remainingSeconds());
        $this->assertEquals('expired', $info->remainingForHumans());
    }

    public function test_it_converts_to_array_correctly(): void
    {
        $now = CarbonImmutable::now();
        $expires = $now->addSeconds(60);

        $info = new CooldownInfo('test:key', $expires, $now);
        $array = $info->toArray();

        $this->assertEquals('test:key', $array['key']);
        $this->assertTrue($array['is_valid']);
        $this->assertEquals(60, $array['remaining_seconds']);
    }
}
