<?php

declare(strict_types=1);

namespace ZaberDev\Cooldown\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ZaberDev\Cooldown\Contracts\Cooldownable;
use ZaberDev\Cooldown\HasCooldowns;
use ZaberDev\Cooldown\Models\Cooldown;
use ZaberDev\Cooldown\Tests\TestCase;

class EloquentCooldownTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function test_it_scopes_cooldowns_by_model_using_cache_driver(): void
    {
        $user1 = TestUser::create(['name' => 'Alice']);
        $user2 = TestUser::create(['name' => 'Bob']);

        $user1->cooldown('send_sms')->for(60);

        $this->assertTrue($user1->cooldown('send_sms')->active());
        $this->assertFalse($user2->cooldown('send_sms')->active());

        $this->assertEquals(
            'send_sms:ZaberDev\Cooldown\Tests\Feature\TestUser:1',
            $user1->cooldown('send_sms')->resolveKey()
        );
    }

    public function test_it_stores_polymorphic_cooldowns_in_database_and_supports_reset_all(): void
    {
        $user = TestUser::create(['name' => 'Alice']);

        $user->cooldown('post_comment', 'database')->for(300);
        $user->cooldown('like_post', 'database')->for(300);

        $this->assertEquals(2, $user->cooldowns()->count());
        $this->assertTrue($user->cooldown('post_comment', 'database')->active());

        $deleted = $user->resetAllCooldowns('database');

        $this->assertEquals(2, $deleted);
        $this->assertEquals(0, $user->cooldowns()->count());
        $this->assertFalse($user->cooldown('post_comment', 'database')->active());
    }

    public function test_prunable_scope_removes_expired_database_cooldowns(): void
    {
        $user = TestUser::create(['name' => 'Alice']);

        Cooldown::query()->create([
            'key' => 'old:key',
            'action' => 'old_action',
            'target_type' => TestUser::class,
            'target_id' => $user->id,
            'expires_at' => now()->subDays(10),
        ]);

        $this->assertEquals(1, Cooldown::query()->count());
        $this->assertEquals(1, (new Cooldown())->prunable()->count());

        (new Cooldown())->prunable()->delete();

        $this->assertEquals(0, Cooldown::query()->count());
    }
}

class TestUser extends Model implements Cooldownable
{
    use HasCooldowns;

    protected $table = 'users';
    protected $guarded = [];
}
