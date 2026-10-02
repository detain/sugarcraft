<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;

/**
 * Audit R1 residual: the per-turn App EngineBackend builds carried no
 * compactor config, so the skill budget an engine turn spliced against was
 * whatever Runtime fell back to — decided apart from the launch notice that
 * names the deferred skills and from Chat's own compactor. The backend now
 * carries one, defaulting to the same {@see CompactorConfig::new()} the
 * launch notice prices against, and a configured one reaches the prompt.
 */
final class EngineBackendCompactorConfigTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir() . '/sc_eb_compactor_' . bin2hex(random_bytes(4));
        $this->useHomeSandbox($this->home);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        @rmdir($this->home);
        parent::tearDown();
    }

    public function testAConfiguredSkillBudgetReachesTheTurnsSystemPrompt(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse('done')]);

        EngineBackend::new($provider, 'm')
            ->withSkills([$this->smallSkill()])
            ->withCompactorConfig(new CompactorConfig(skillBudgetPerSkill: 50))
            ->complete([Message::user('hi')]);

        $prompt = (string) $provider->requests[0]->systemPrompt;
        $this->assertStringNotContainsString('SMALLCANARY', $prompt, 'the backend\'s per-skill budget defers the body');
        $this->assertStringContainsString('the skill budget is 50 per skill and', $prompt);
    }

    public function testWithoutOneTheTurnUsesTheLaunchNoticesDefaults(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse('done')]);
        $backend = EngineBackend::new($provider, 'm')->withSkills([$this->smallSkill()]);

        $backend->complete([Message::user('hi')]);

        $this->assertEquals(CompactorConfig::new(), $backend->compactorConfig());
        $this->assertStringContainsString('SMALLCANARY', (string) $provider->requests[0]->systemPrompt);
    }

    public function testNullRestoresTheDefaults(): void
    {
        $backend = EngineBackend::new(new ScriptedProvider([]), 'm')
            ->withCompactorConfig(new CompactorConfig(skillBudgetPerSkill: 50))
            ->withCompactorConfig(null);

        $this->assertEquals(CompactorConfig::new(), $backend->compactorConfig());
    }

    private function smallSkill(): Skill
    {
        return Skill::parse("---\ndescription: small\n---\nSMALLCANARY " . str_repeat('word ', 200) . "\n", 'small');
    }
}
