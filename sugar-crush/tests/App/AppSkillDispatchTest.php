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
use SugarCraft\Crush\App\OpenSkillPickerMsg;
use SugarCraft\Crush\App\SelectSkillMsg;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tui\Pane;

/**
 * R16 — Skill flag enforcement at the App layer.
 *
 * @see App::userInvocableSkills()
 * @see App::dispatchSkill()
 * @see App::applySkillsToSystemPrompt()
 * @see App::update() OpenSkillPickerMsg / SelectSkillMsg handlers
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
    // userInvocableSkills() — the user-invocable filter primitive, now wired
    // into a real command surface via OpenSkillPickerMsg/SelectSkillMsg
    // below (see App::userInvocableSkills() doc comment for what remains a
    // separate, larger main-loop-wiring item vs. what is real today).
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
    // OpenSkillPickerMsg / SelectSkillMsg — the real command surface that
    // consumes userInvocableSkills() through App::update(), the same
    // Model-layer contract every other App command goes through.
    // -------------------------------------------------------------------------

    public function testOpenSkillPickerMsgPopulatesOptionsWithOnlyUserInvocableSkills(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $palette = $this->skillFromYaml("description: Palette skill\nuser-invocable: true", 'palette-skill');
        $systemOnly = $this->skillFromYaml("description: System only\nuser-invocable: false", 'system-only-skill');
        $registry->register(['palette-skill' => $palette, 'system-only-skill' => $systemOnly]);
        $app = App::new($this->provider, 'test-model')->withAvailableSkills($registry);

        // Act
        [$next, $cmd] = $app->update(new OpenSkillPickerMsg());

        // Assert
        $this->assertNull($cmd);
        $this->assertSame(Pane::Skills, $next->pane);
        $names = array_map(fn(Skill $s) => $s->name, $next->skillPickerOptions);
        $this->assertContains('palette-skill', $names);
        $this->assertNotContains('system-only-skill', $names);
    }

    public function testOpenSkillPickerMsgSetsStatusWhenNoSkillsAreUserInvocable(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $systemOnly = $this->skillFromYaml("description: System only\nuser-invocable: false", 'system-only-skill');
        $registry->register(['system-only-skill' => $systemOnly]);
        $app = App::new($this->provider, 'test-model')->withAvailableSkills($registry);

        // Act
        [$next] = $app->update(new OpenSkillPickerMsg());

        // Assert
        $this->assertSame([], $next->skillPickerOptions);
        $this->assertNotNull($next->status);
    }

    public function testSelectSkillMsgEnablesAUserInvocableSkillAndClosesThePicker(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $palette = $this->skillFromYaml("description: Palette skill\nuser-invocable: true", 'palette-skill');
        $registry->register(['palette-skill' => $palette]);
        $app = App::new($this->provider, 'test-model')
            ->withAvailableSkills($registry)
            ->withSkillPickerOptions([$palette]);

        // Act
        [$next, $cmd] = $app->update(new SelectSkillMsg('palette-skill'));

        // Assert
        $this->assertNull($cmd);
        $this->assertSame([], $next->skillPickerOptions);
        $names = array_map(fn(Skill $s) => $s->name, $next->enabledSkills);
        $this->assertContains('palette-skill', $names);
        $this->assertNull($next->error);
    }

    public function testSelectSkillMsgRejectsANonUserInvocableSkillEvenIfNamedDirectly(): void
    {
        // Arrange — defends against a caller bypassing the picker's own
        // options and naming a system-only skill directly.
        $registry = new SkillRegistry();
        $systemOnly = $this->skillFromYaml("description: System only\nuser-invocable: false", 'system-only-skill');
        $registry->register(['system-only-skill' => $systemOnly]);
        $app = App::new($this->provider, 'test-model')->withAvailableSkills($registry);

        // Act
        [$next, $cmd] = $app->update(new SelectSkillMsg('system-only-skill'));

        // Assert
        $this->assertNull($cmd);
        $this->assertNotNull($next->error);
        $names = array_map(fn(Skill $s) => $s->name, $next->enabledSkills);
        $this->assertNotContains('system-only-skill', $names);
    }

    public function testSelectSkillMsgDoesNotDuplicateAnAlreadyEnabledSkill(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $palette = $this->skillFromYaml("description: Palette skill\nuser-invocable: true", 'palette-skill');
        $registry->register(['palette-skill' => $palette]);
        $app = App::new($this->provider, 'test-model')
            ->withAvailableSkills($registry)
            ->withEnabledSkills([$palette]);

        // Act
        [$next] = $app->update(new SelectSkillMsg('palette-skill'));

        // Assert
        $this->assertCount(1, $next->enabledSkills);
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

    // -------------------------------------------------------------------------
    // dispatchSkill() environment orientation (crush_code.md Phase 5 item 3 /
    // section 12 finding 4).
    //
    // The path built its CompleteRequest by hand with `systemPrompt:
    // $skill->content`, so it constructed an Agent and then never called
    // Agent::systemPrompt() on it — a fork-context skill ran with no cwd, no
    // git state, no platform and no date, while AgentManager's sub-agents (the
    // sibling launch path) got all four.
    //
    // These reuse the mock-executor seam the two tests above already use; no
    // new scaffolding, and the CompleteRequest the pool is handed is captured
    // straight off the call.
    // -------------------------------------------------------------------------

    /**
     * Runs a fork-context skill through a mock executor and hands back the
     * CompleteRequest and SubAgent the pool was given.
     *
     * @return array{0: CompleteRequest, 1: SubAgent}
     */
    private function captureForkDispatch(App $app, Skill $skill): array
    {
        $captured = null;

        $executor = $this->createMock(ExecutorInterface::class);
        $executor->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function (SubAgent $subAgent, CompleteRequest $request) use (&$captured): AgentResult {
                $captured = [$request, $subAgent];

                return new AgentResult(agentId: $subAgent->id, status: AgentStatus::Completed, output: 'ok');
            });

        $result = $app->dispatchSkill($skill, new AgentWorkerPool(maxConcurrent: 1, executor: $executor), 'do the fork task');

        $this->assertNotNull($result, 'a fork-context skill must be dispatched, not fall through');
        $this->assertIsArray($captured);

        return $captured;
    }

    /**
     * The whole finding in one assertion: the request carries the skill body
     * AND the environment block, in that order. Reverting to `systemPrompt:
     * $skill->content` leaves the body and drops the block.
     */
    public function testDispatchSkillDeliversTheEnvironmentBlockAlongsideTheSkillBody(): void
    {
        $registry = new SkillRegistry();
        $forkSkill = $this->skillFromYaml("description: Fork skill\ncontext: fork", 'fork-skill');
        $registry->register(['fork-skill' => $forkSkill]);

        [$request] = $this->captureForkDispatch(
            App::new($this->provider, 'test-model')->withAvailableSkills($registry),
            $forkSkill,
        );

        $prompt = (string) $request->systemPrompt;

        $this->assertStringContainsString('Content for fork-skill.', $prompt, 'the skill body must still be delivered');
        $this->assertStringContainsString('<env>', $prompt);
        $this->assertStringContainsString('Working directory: ', $prompt);
        $this->assertStringContainsString('Current date: ', $prompt);
        $this->assertLessThan(
            strpos($prompt, '<env>'),
            strpos($prompt, 'Content for fork-skill.'),
            'Agent::systemPrompt() puts the prompt first and the block after it',
        );
    }

    /**
     * Half two of the fix, and the half Agent::systemPrompt()'s own fallback
     * gets wrong: the block orients at the App's configured `--root`, not at
     * the process directory. A `--root candy-shine` fork has to be told it is
     * standing in candy-shine.
     *
     * The temp directory is chosen precisely because it is NOT getcwd(), so the
     * two answers are distinguishable — which is the only way this test has any
     * power at all.
     */
    public function testDispatchSkillOrientsTheForkAtTheConfiguredRootNotTheProcessDirectory(): void
    {
        $root = sys_get_temp_dir() . '/crush_fork_root_' . uniqid('', true);
        mkdir($root);

        try {
            $this->assertNotSame(getcwd(), $root, 'the fixture is only meaningful if the two differ');

            $registry = new SkillRegistry();
            $forkSkill = $this->skillFromYaml("description: Fork skill\ncontext: fork", 'fork-skill');
            $registry->register(['fork-skill' => $forkSkill]);

            [$request] = $this->captureForkDispatch(
                App::new($this->provider, 'test-model')->withAvailableSkills($registry)->withRoot($root),
                $forkSkill,
            );

            $prompt = (string) $request->systemPrompt;

            $this->assertStringContainsString('Working directory: ' . $root, $prompt);
            $this->assertStringNotContainsString('Working directory: ' . getcwd(), $prompt);
        } finally {
            @rmdir($root);
        }
    }

    /**
     * And half three: captured at the FORK's model, not the session's. The
     * block renders a `Model:` line, so a skill declaring `model:` in its
     * frontmatter would otherwise be handed a prompt naming the session model
     * — the exact defect Bootstrap::agentManager()'s per-agent capture exists
     * to prevent, restated for this launch path.
     */
    public function testDispatchSkillStampsTheForksOwnModelIntoTheEnvironmentBlock(): void
    {
        $registry = new SkillRegistry();
        $forkSkill = $this->skillFromYaml(
            "description: Fork skill\ncontext: fork\nmodel: skill-declared-model",
            'fork-skill',
        );
        $registry->register(['fork-skill' => $forkSkill]);

        $this->assertSame('skill-declared-model', $forkSkill->model, 'the fixture must actually declare a model');

        [$request, $subAgent] = $this->captureForkDispatch(
            App::new($this->provider, 'session-model')->withAvailableSkills($registry),
            $forkSkill,
        );

        $prompt = (string) $request->systemPrompt;

        $this->assertStringContainsString('Model: skill-declared-model', $prompt);
        $this->assertStringNotContainsString('Model: session-model', $prompt);
        $this->assertSame('skill-declared-model', $subAgent->agent->model);
    }

    /**
     * The block has to be attached to the Agent, not merely folded into the
     * request: ProcessExecutor sends the request's systemPrompt AND, as a
     * separate field, `$agent->agent->systemPrompt()` — so an Agent carrying no
     * environment would send one oriented half and one unoriented half.
     */
    public function testDispatchSkillAttachesTheBlockToTheAgentTheExecutorAlsoReads(): void
    {
        $root = sys_get_temp_dir() . '/crush_fork_agent_' . uniqid('', true);
        mkdir($root);

        try {
            $registry = new SkillRegistry();
            $forkSkill = $this->skillFromYaml("description: Fork skill\ncontext: fork", 'fork-skill');
            $registry->register(['fork-skill' => $forkSkill]);

            [$request, $subAgent] = $this->captureForkDispatch(
                App::new($this->provider, 'test-model')->withAvailableSkills($registry)->withRoot($root),
                $forkSkill,
            );

            // The two fields ProcessExecutor sends must agree about where the
            // fork is standing.
            $this->assertStringContainsString('Working directory: ' . $root, $subAgent->agent->systemPrompt());
            $this->assertSame($subAgent->agent->systemPrompt(), (string) $request->systemPrompt);
        } finally {
            @rmdir($root);
        }
    }
}
