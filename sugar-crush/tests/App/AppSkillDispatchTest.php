<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\ExecutorInterface;
use SugarCraft\Crush\Agents\ProcessExecutor;
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
    // -------------------------------------------------------------------------
    // C8 — the dormant seam, and the one thing about it that was NOT dormant
    // -------------------------------------------------------------------------

    /**
     * Selecting a `context: fork` skill must not claim it was applied.
     *
     * MEASURED before the branch this pins existed: a fork-context skill is
     * user-invocable, so it appears in the picker; selecting it reported
     * "Enabled skill 'x'."; and {@see App::applySkillsToSystemPrompt()} then
     * skipped it, because fork skills are excluded from the inline path by
     * design. Nothing dispatched it either — {@see App::dispatchSkill()} has no
     * production caller. So the skill had no effect of any kind and the status
     * bar said the opposite.
     *
     * The two halves are asserted together on purpose. The status text alone
     * would be a string test; pairing it with the unchanged system prompt is
     * what makes it a statement about the outcome the user was told about.
     */
    public function testSelectingAForkContextSkillDoesNotClaimItWasInlined(): void
    {
        $registry = new SkillRegistry();
        $forkSkill = $this->skillFromYaml(
            "description: Fork skill\nuser-invocable: true\ncontext: fork",
            'forky',
        );
        $registry->register([$forkSkill]);

        $app = App::new($this->provider, 'test-model')->withAvailableSkills($registry);
        [$opened] = $app->update(new OpenSkillPickerMsg());
        [$next, $cmd] = $opened->update(new SelectSkillMsg('forky'));

        $this->assertNull($cmd);
        $this->assertSame([$forkSkill], $next->enabledSkills);
        $this->assertStringContainsString('context: fork', (string) $next->status);
        $this->assertStringNotContainsString('Enabled skill', (string) $next->status);
        $this->assertSame('BASE', $next->applySkillsToSystemPrompt('BASE'));
    }

    /**
     * The other polarity: a normal skill still reports plain enablement and
     * still reaches the prompt. Without this, narrowing the message to fork
     * skills could be "fixed" by giving every skill the fork wording.
     */
    public function testSelectingANonForkSkillStillReportsPlainEnablementAndIsInlined(): void
    {
        $registry = new SkillRegistry();
        $inlineSkill = $this->skillFromYaml("description: Inline skill\nuser-invocable: true", 'inliney');
        $registry->register([$inlineSkill]);

        $app = App::new($this->provider, 'test-model')->withAvailableSkills($registry);
        [$opened] = $app->update(new OpenSkillPickerMsg());
        [$next] = $opened->update(new SelectSkillMsg('inliney'));

        $this->assertSame("Enabled skill 'inliney'.", $next->status);
        $this->assertNotSame('BASE', $next->applySkillsToSystemPrompt('BASE'));
    }

    /**
     * The dormancy itself, pinned.
     *
     * {@see App::dispatchSkill()} is documented as having no production caller
     * and three named mechanisms that keep it that way. That documentation is
     * prose, and prose rots. This is the part a reader can trust: a token-stream
     * scan of `src/` and `bin/` for a CALL to `dispatchSkill`, which must find
     * none. The declaration is a `T_FUNCTION` followed by the name, so it does
     * not count; a `{@see}` in a doc-block is a `T_DOC_COMMENT`, so it does not
     * count either.
     *
     * If a production caller is ever added, this test goes red — which is the
     * point. It is not a prohibition, it is a tripwire: the person adding one
     * must come here, delete this test, and in doing so read the three blockers
     * on `dispatchSkill()` before deciding they have answered them.
     *
     * The known-positive fixture is what stops this being a green nothing:
     * an assertion of "no occurrences" is satisfied just as well by a scanner
     * that has stopped working.
     */
    public function testDispatchSkillStillHasNoProductionCaller(): void
    {
        $scan = static function (string $source): int {
            $tokens = token_get_all($source);
            $calls = 0;

            foreach ($tokens as $i => $token) {
                if (!\is_array($token) || $token[0] !== T_STRING || $token[1] !== 'dispatchSkill') {
                    continue;
                }

                // Backwards over whitespace: `function dispatchSkill` is the
                // declaration, not a call.
                $j = $i - 1;
                while ($j >= 0 && \is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    --$j;
                }
                if (\is_array($tokens[$j] ?? null) && $tokens[$j][0] === T_FUNCTION) {
                    continue;
                }

                // Forwards over whitespace: a call has `(` next.
                $k = $i + 1;
                while ($k < \count($tokens) && \is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                    ++$k;
                }
                if (($tokens[$k] ?? null) === '(') {
                    ++$calls;
                }
            }

            return $calls;
        };

        $positive = "<?php\n\$r = \$app->dispatchSkill(\$skill, \$pool, 'do the thing');\n";
        $this->assertSame(1, $scan($positive), 'the scanner is dead — it cannot see a real call');
        $declaration = "<?php\nclass X { public function dispatchSkill(\$a, \$b, \$c) {} }\n";
        $this->assertSame(0, $scan($declaration), 'a declaration is not a call');
        $mention = "<?php\n/** {@see dispatchSkill()} does the thing. */\n\$x = 1;\n";
        $this->assertSame(0, $scan($mention), 'a docblock mention is not a call');

        $root = \dirname(__DIR__, 2);
        $callers = [];
        $scanned = 0;

        foreach (['src', 'bin'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $source = file_get_contents($file->getPathname());
                $this->assertIsString($source);
                if (!str_contains($source, '<?php')) {
                    continue;
                }
                ++$scanned;
                if ($scan($source) > 0) {
                    $callers[] = substr($file->getPathname(), \strlen($root) + 1);
                }
            }
        }

        $this->assertGreaterThan(200, $scanned, 'the walk found almost nothing — the scan is not running');
        $this->assertSame([], $callers, 'dispatchSkill() gained a production caller; read its three blockers first');
    }

    /**
     * End to end through the real chain, and it REFUSES.
     *
     * App -> AgentWorkerPool::executeOne() -> ProcessExecutor -> a spawned PHP
     * child. The pool is constructed with NO arguments, so the executor comes
     * from {@see AgentWorkerPool::createDefaultExecutor()} — this is the shipped
     * default in full, not an executor this test built and then described as
     * one. (It said "a default-constructed executor" while injecting one, which
     * also set the pool's `$customExecutor` flag; immaterial to the outcome
     * here, since `executeOne()` runs in-parent either way, but the distinction
     * is load-bearing three comments away and should not be blurred in a
     * fourth.) The result is a FAILED AgentResult naming the absent provider,
     * and — the assertion that matters — a null output.
     *
     * This is the acceptance test for the C4/C8 pair together. Before the live
     * worker existed, this same call returned a Completed result carrying a
     * sentence the worker made up, and no test anywhere could have told the
     * difference between that and a real answer.
     */
    public function testDispatchSkillThroughTheDefaultExecutorRefusesRatherThanFabricating(): void
    {
        $registry = new SkillRegistry();
        $forkSkill = $this->skillFromYaml(
            "description: Fork skill\nuser-invocable: true\ncontext: fork",
            'refusing-fork',
        );
        $registry->register([$forkSkill]);

        $app = App::new($this->provider, 'test-model')->withAvailableSkills($registry);
        $pool = new AgentWorkerPool();

        $result = $app->dispatchSkill($forkSkill, $pool, 'summarise the changelog');

        $this->assertInstanceOf(AgentResult::class, $result);
        $this->assertSame(AgentStatus::Failed, $result->status);
        $this->assertNull($result->output);
        $this->assertStringContainsString('Refusing to fabricate', (string) $result->error?->getMessage());
    }

    /**
     * And the same chain with a provider configured produces that PROVIDER's
     * answer to this skill's task.
     *
     * The expectation is computed by running the same provider in-process, so
     * a worker that invented a plausible sentence fails here. This is what
     * makes the claim "a fork-context skill's task reached a model" testable
     * at all — the thing that was impossible while the worker was a simulation.
     */
    public function testDispatchSkillRelaysARealProvidersAnswer(): void
    {
        $registry = new SkillRegistry();
        $forkSkill = $this->skillFromYaml(
            "description: Fork skill\nuser-invocable: true\ncontext: fork",
            'relaying-fork',
        );
        $registry->register([$forkSkill]);

        $task = 'summarise the changelog';
        $app = App::new($this->provider, 'test-model')->withAvailableSkills($registry);
        $pool = new AgentWorkerPool(executor: new ProcessExecutor(
            timeoutSeconds: 30,
            workerProvider: ['type' => 'echo'],
        ));

        $result = $app->dispatchSkill($forkSkill, $pool, $task);

        $expected = (new \SugarCraft\Crush\Providers\EchoProvider())->complete(new CompleteRequest(
            model: 'test-model',
            messages: [new \SugarCraft\Crush\Messages\UserMessage($task)],
        ))->content;

        $this->assertNotNull($result);
        $this->assertSame(AgentStatus::Completed, $result->status);
        $this->assertSame($expected, $result->output);
    }
}
