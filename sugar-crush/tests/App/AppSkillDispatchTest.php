<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\ExecutorInterface;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillRegistry;

/**
 * R16 — Skill flag enforcement at the App layer.
 *
 * @see App::userInvocableSkills()
 * @see App::dispatchSkill()
 * @see App::applySkillsToSystemPrompt()
 */
final class AppSkillDispatchTest extends TestCase
{
    private ProviderInterface $provider;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('anthropic');
    }

    private function skillFromYaml(string $frontmatter, string $name): Skill
    {
        return Skill::parse(
            "---\n{$frontmatter}\n---\n\nContent for {$name}.",
            $name
        );
    }

    // -------------------------------------------------------------------------
    // userInvocableSkills() — the user-invocable filter primitive. Nothing
    // in the codebase currently wires this into a live /skills or
    // command-palette listing (see App::userInvocableSkills() doc comment);
    // these tests only prove the filtering logic itself is correct.
    // -------------------------------------------------------------------------

    public function testUserInvocableSkillsExcludesNonUserInvocableSkill(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $palette = $this->skillFromYaml("description: Palette skill\nuser-invocable: true", 'palette-skill');
        $systemOnly = $this->skillFromYaml("description: System only skill\nuser-invocable: false", 'system-only-skill');
        $registry->register(['palette-skill' => $palette, 'system-only-skill' => $systemOnly]);

        $app = App::new($this->provider, 'test-model')->withAvailableSkills($registry);

        // Act
        $result = $app->userInvocableSkills();

        // Assert - the filter must never include a skill that opted out of
        // user invocation, even though it is registered and otherwise
        // enabled/discoverable.
        $names = array_map(fn(Skill $s) => $s->name, $result);
        $this->assertContains('palette-skill', $names);
        $this->assertNotContains('system-only-skill', $names);
    }

    public function testUserInvocableSkillsEmptyWhenNoneUserInvocable(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $systemOnly = $this->skillFromYaml("description: System only\nuser-invocable: false", 'system-only');
        $registry->register(['system-only' => $systemOnly]);

        $app = App::new($this->provider, 'test-model')->withAvailableSkills($registry);

        // Act
        $result = $app->userInvocableSkills();

        // Assert
        $this->assertEmpty($result);
    }

    // -------------------------------------------------------------------------
    // dispatchSkill() — context: fork skills go through the pool
    // -------------------------------------------------------------------------

    public function testDispatchSkillRunsForkContextSkillThroughPool(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $forkSkill = $this->skillFromYaml("description: Fork skill\ncontext: fork", 'fork-skill');
        $registry->register(['fork-skill' => $forkSkill]);

        $app = App::new($this->provider, 'test-model')->withAvailableSkills($registry);

        $expected = new AgentResult(
            agentId: 'irrelevant',
            status: AgentStatus::Completed,
            output: 'sub-agent output',
        );

        $executor = $this->createMock(ExecutorInterface::class);
        $executor->expects($this->once())
            ->method('execute')
            ->willReturn($expected);

        $pool = new AgentWorkerPool(maxConcurrent: 1, executor: $executor);

        // Act
        $result = $app->dispatchSkill($forkSkill, $pool, 'do the fork task');

        // Assert - dispatch actually went through AgentWorkerPool::executeOne(),
        // proven by the mock executor being invoked exactly once and its
        // result being returned unchanged.
        $this->assertNotNull($result);
        $this->assertSame('sub-agent output', $result->output);
    }

    public function testDispatchSkillReturnsNullForThreadContextSkill(): void
    {
        // Arrange - default context ("thread") must NOT be dispatched through
        // the pool at all; it stays inline in the main conversation.
        $registry = new SkillRegistry();
        $threadSkill = $this->skillFromYaml("description: Thread skill\ncontext: thread", 'thread-skill');
        $registry->register(['thread-skill' => $threadSkill]);

        $app = App::new($this->provider, 'test-model')->withAvailableSkills($registry);

        $executor = $this->createMock(ExecutorInterface::class);
        $executor->expects($this->never())->method('execute');

        $pool = new AgentWorkerPool(maxConcurrent: 1, executor: $executor);

        // Act
        $result = $app->dispatchSkill($threadSkill, $pool, 'do the thread task');

        // Assert
        $this->assertNull($result);
    }

    public function testApplySkillsToSystemPromptExcludesForkContextSkill(): void
    {
        // Arrange - a fork-context skill must not be inlined into the
        // system prompt; it is only reachable via dispatchSkill()/the pool.
        $registry = new SkillRegistry();
        $forkSkill = $this->skillFromYaml("description: Fork skill\ncontext: fork", 'fork-skill');
        $threadSkill = $this->skillFromYaml("description: Thread skill\ncontext: thread", 'thread-skill');
        $registry->register(['fork-skill' => $forkSkill, 'thread-skill' => $threadSkill]);

        $app = App::new($this->provider, 'test-model')
            ->withAvailableSkills($registry)
            ->withEnabledSkills([$forkSkill, $threadSkill]);

        // Act
        $result = $app->applySkillsToSystemPrompt('Base prompt.');

        // Assert
        $this->assertStringNotContainsString('fork-skill', $result);
        $this->assertStringContainsString('thread-skill', $result);
    }
}
