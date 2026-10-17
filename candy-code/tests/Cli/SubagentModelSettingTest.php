<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Skills\TemporaryDirectoryTrait;

/**
 * Roadmap 4.1-1: the `subagentModel` setting is the model every agent that
 * would otherwise inherit the session's runs on — and only those. An agent
 * whose preset names its own model keeps it; with the setting unset every
 * inheriting agent stays inheriting, so a `/model` switch still reaches it.
 */
final class SubagentModelSettingTest extends TestCase
{
    use HomeSandboxTrait;
    use TemporaryDirectoryTrait;

    private string $tempDir;
    private string $home;
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/candy_code_subagent_model_' . uniqid('', true);
        $this->repo = $this->tempDir . '/repo';
        mkdir($this->repo . '/.candy-code/agents', 0755, true);
        $this->home = $this->useHomeSandbox($this->tempDir . '/home');
        mkdir($this->home . '/.candy-code', 0700, true);

        file_put_contents(
            $this->repo . '/.candy-code/agents/inheritor.md',
            "---\ndescription: Inherits\nmodel: inherit\n---\nbody\n",
        );
        file_put_contents(
            $this->repo . '/.candy-code/agents/pinned.md',
            "---\ndescription: Pinned\nmodel: its-own-model\n---\nbody\n",
        );
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testTheSettingPinsEveryInheritingAgentAndNoOther(): void
    {
        file_put_contents($this->home . '/.candy-code/config.json', json_encode(['subagentModel' => 'helper-model']));

        $manager = Bootstrap::agentManager($this->repo);

        foreach (['inheritor', 'coder', 'reviewer'] as $name) {
            $agent = $manager->get($name);
            $this->assertNotNull($agent, $name);
            $this->assertSame('helper-model', $agent->model, "{$name} inherits, so the setting is its model");
            $this->assertFalse($agent->inheritsModel, "{$name} is pinned now");
        }

        $pinned = $manager->get('pinned');
        $this->assertNotNull($pinned);
        $this->assertSame('its-own-model', $pinned->model, 'a preset that names a model keeps it');
        $this->assertStringContainsString('helper-model', $manager->get('coder')?->systemPrompt() ?? '', 'the environment block names the model the agent runs on');
    }

    public function testUnsetEveryInheritingAgentKeepsFollowingTheSession(): void
    {
        $manager = Bootstrap::agentManager($this->repo);

        $this->assertTrue($manager->get('inheritor')?->inheritsModel);
        $this->assertTrue($manager->get('coder')?->inheritsModel, 'a built-in definition names no model of its own');
        $this->assertFalse($manager->get('pinned')?->inheritsModel);
    }
}
