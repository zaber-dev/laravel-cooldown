<?php

declare(strict_types=1);

namespace ZaberDev\Cooldown\Tests\Feature;

use Illuminate\Support\ServiceProvider;
use ZaberDev\Cooldown\CooldownServiceProvider;
use ZaberDev\Cooldown\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_it_registers_publishing_groups_for_skills(): void
    {
        $publishes = ServiceProvider::pathsToPublish(CooldownServiceProvider::class, 'cooldowns-skill');

        $this->assertNotEmpty($publishes);
        $source = array_key_first($publishes);
        $this->assertStringContainsString('resources/boost/skills/laravel-cooldown', str_replace('\\', '/', $source));
    }

    public function test_auto_publish_boost_skill_when_ai_skills_dir_exists(): void
    {
        $baseSkillsDir = $this->app->basePath('.ai/skills');
        $destination = $this->app->basePath('.ai/skills/laravel-cooldown');

        // Ensure clean state
        if (is_dir($baseSkillsDir)) {
            $this->app['files']->deleteDirectory($baseSkillsDir);
        }

        // Create base .ai/skills directory to simulate Laravel Boost / AI setup
        $this->app['files']->makeDirectory($baseSkillsDir, 0755, true);

        // Re-boot the service provider to trigger auto-publish
        $provider = new CooldownServiceProvider($this->app);
        $provider->boot();

        $this->assertFileExists($destination . '/SKILL.md');

        // Clean up after test
        if (is_dir($baseSkillsDir)) {
            $this->app['files']->deleteDirectory($baseSkillsDir);
        }
    }
}
