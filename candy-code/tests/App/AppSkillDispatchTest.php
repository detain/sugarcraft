<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentResult;
use SugarCraft\Code\Agents\AgentStatus;
use SugarCraft\Code\Agents\AgentWorkerPool;
use SugarCraft\Code\Agents\Effort;
use SugarCraft\Code\Agents\ExecutorInterface;
use SugarCraft\Code\Agents\ProcessExecutor;
use SugarCraft\Code\Agents\SubAgent;
use SugarCraft\Code\App\App;
use SugarCraft\Code\App\OpenSkillPickerMsg;
use SugarCraft\Code\App\SelectSkillMsg;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Skills\Skill;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tui\Pane;

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
        // ISS-035 review: this helper builds body-LOADED rows. That is the
        // right shape for launch-config skills, but it MASKED the picker
        // defect — the registry hands the picker stage-1 manifest rows whose
        // content is ''. The picker regressions below therefore use
        // manifestSkillOnDisk(), the production shape; this helper stays for
        // the filter/dispatch pins that only care about frontmatter.
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

    public function testSelectingASkillCarriesItOntoTheChatEngineBackendForTheNextTurn(): void
    {
        // ISS-035: the palette's enablement must reach the MODEL, not just the
        // shell's status line. sessionApp() splices the backend's prompt-skill
        // list into every turn, so selecting forwards the skill there.
        $registry = new SkillRegistry();
        $palette = $this->skillFromYaml("description: Palette skill\nuser-invocable: true", 'palette-skill');
        $registry->register(['palette-skill' => $palette]);
        $app = App::new($this->provider, 'test-model')
            ->withAvailableSkills($registry)
            ->withSkillPickerOptions([$palette])
            ->withChat(new Chat(backend: EngineBackend::new(new ScriptedProvider([]), 'm')));

        [$next] = $app->update(new SelectSkillMsg('palette-skill'));
        $carried = array_map(fn (Skill $s): string => $s->name, $next->chat->backend()->skills());
        $this->assertSame(['palette-skill'], $carried, 'the next turn ships the prompt skill');

        [$again] = $next->update(new SelectSkillMsg('palette-skill'));
        $twice = array_map(fn (Skill $s): string => $s->name, $again->chat->backend()->skills());
        $this->assertSame([], $twice, 'C-EXT-2.d: re-selecting an enabled skill toggles it off — the model drops it the same turn the shell does');
        $this->assertSame([], array_map(fn (Skill $s): string => $s->name, $again->enabledSkills));
    }

    public function testAChatlessAppKeepsSkillEnablementShellOnlyAndNeverFatals(): void
    {
        // The headless shell (no chat wired) still moves its enabled set —
        // the carry guard exits early where there is no backend to carry to.
        $registry = new SkillRegistry();
        $palette = $this->skillFromYaml("description: Palette skill\nuser-invocable: true", 'palette-skill');
        $registry->register(['palette-skill' => $palette]);
        $app = App::new($this->provider, 'test-model')
            ->withAvailableSkills($registry)
            ->withSkillPickerOptions([$palette]);

        [$next] = $app->update(new SelectSkillMsg('palette-skill'));

        $names = array_map(fn (Skill $s): string => $s->name, $next->enabledSkills);
        $this->assertContains('palette-skill', $names);
        $this->assertNull($next->chat);
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

    public function testReSelectingAnEnabledSkillTogglesItOffForTheSession(): void
    {
        // C-EXT-2.d: the picker is the session half of the switch. Selecting
        // an already-enabled skill now DISABLES it (through the one canonical
        // SkillManager::disable filter) and closes the picker — it never
        // duplicates a layer, and it never touches the persisted
        // `disabledSkills` list, which is `/skills disable`'s job.
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
        $this->assertCount(0, $next->enabledSkills);
        $this->assertSame([], $next->skillPickerOptions, 'the toggle answers inside the picker, closing it like the enable does');
    }

    // -------------------------------------------------------------------------
    // ISS-035 review fix — the picker's REAL fixture shape.
    //
    // skillFromYaml() builds body-loaded rows; the native tier registers
    // stage-1 manifests with `content: ''` (SkillRegistry::
    // registerFromManifest). The shipped carry spliced that empty row into
    // the prompt as a bare `## Skill:` heading AND the P7.S3 exclusion
    // dropped it from <available-skills> — invisible through both channels.
    // These pins use the production registration path, so the masking
    // fixture is now itself covered by its regression proof.
    // -------------------------------------------------------------------------

    /** @var list<string> */
    private array $skillTempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->skillTempDirs as $dir) {
            if (is_file($dir . '/demo/SKILL.md')) {
                unlink($dir . '/demo/SKILL.md');
            }
            if (is_dir($dir . '/demo')) {
                rmdir($dir . '/demo');
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
        $this->skillTempDirs = [];

        parent::tearDown();
    }

    /**
     * Registers a native-tier-shaped skill the way SkillManager's manifest
     * walker does — frontmatter only, `content: ''` — with the real SKILL.md
     * left on disk behind it.
     *
     * @return array{0: SkillRegistry, 1: string} the registry and the source path
     */
    private function manifestSkillOnDisk(): array
    {
        $dir = sys_get_temp_dir() . '/iss035-' . bin2hex(random_bytes(6));
        mkdir($dir . '/demo', 0o700, true);
        $this->skillTempDirs[] = $dir;
        $path = $dir . '/demo/SKILL.md';
        file_put_contents(
            $path,
            "---\ndescription: Demo skill\nuser-invocable: true\n---\n\nTHE-BODY-MARKER do the demo thing.\n"
        );

        $registry = new SkillRegistry();
        $registry->registerFromManifest([
            'name' => 'demo',
            'description' => 'Demo skill',
            'disableModelInvocation' => false,
            'userInvocable' => true,
            'context' => 'thread',
            'paths' => [],
            'sourcePath' => $path,
        ]);

        return [$registry, $path];
    }

    public function testThePickerEnablesTheContentlessManifestRowItself(): void
    {
        // Negative control for the masking fixture: the carry deliberately
        // keeps the stage-1 identity — the body is resolved when the prompt
        // is built, so a mid-session edit of the SKILL.md is never shadowed
        // by a stale snapshot.
        [$registry] = $this->manifestSkillOnDisk();
        $app = App::new($this->provider, 'test-model')
            ->withAvailableSkills($registry)
            ->withSkillPickerOptions(iterator_to_array($registry->getUserInvocable()));

        [$next] = $app->update(new SelectSkillMsg('demo'));

        $this->assertSame('demo', $next->enabledSkills[0]->name);
        $this->assertSame('', $next->enabledSkills[0]->content);
    }

    public function testAContentlessPickerSkillStillRendersItsBodyFromDisk(): void
    {
        [$registry] = $this->manifestSkillOnDisk();
        $app = App::new($this->provider, 'test-model')
            ->withAvailableSkills($registry)
            ->withSkillPickerOptions(iterator_to_array($registry->getUserInvocable()));

        [$next] = $app->update(new SelectSkillMsg('demo'));
        $prompt = $next->applySkillsToSystemPrompt('Base prompt.');

        $this->assertStringContainsString('## Skill:', $prompt);
        $this->assertStringContainsString(
            'THE-BODY-MARKER',
            $prompt,
            'the splice carries the picked instructions, not a bare heading'
        );
    }

    public function testAContentlessPickerSkillStaysInTheListingAndRendersItsBody(): void
    {
        // End-to-end through the engine path — the exact shape of the RV3
        // probe. Post-fix the model gets BOTH channels it used to lose:
        // the grafted body in the enabled section and the level-1 line in
        // <available-skills> (the P7.S3 exclusion fires only for rows that
        // were enabled body-loaded, as the launch path always is).
        [$registry] = $this->manifestSkillOnDisk();
        $provider = new ScriptedProvider([new CompleteResponse(content: 'ok')]);
        $app = App::new($provider, 'test-model')
            ->withAvailableSkills($registry)
            ->withSkillPickerOptions(iterator_to_array($registry->getUserInvocable()))
            ->withChat(new Chat(
                backend: EngineBackend::new($provider, 'test-model')->withSkillRegistry($registry)
            ));

        [$next] = $app->update(new SelectSkillMsg('demo'));
        $next->chat->backend()->complete([Message::user('hello')]);
        $prompt = (string) ($provider->requests[0]->systemPrompt ?? '');

        $this->assertStringContainsString('THE-BODY-MARKER', $prompt);
        $this->assertMatchesRegularExpression(
            '/<available-skills>.{0,}demo.{0,}<\/available-skills>/s',
            $prompt,
            'an enabled skill is never invisible through both channels'
        );
    }

    public function testABodylessSkillWithAVanishedSourceKeepsItsListingLine(): void
    {
        // Fail-soft pair: with no readable body there is no body to excuse
        // the listing drop — the row stays advertised and the bare heading
        // is the only thing spliced.
        [$registry, $path] = $this->manifestSkillOnDisk();
        unlink($path);
        $provider = new ScriptedProvider([new CompleteResponse(content: 'ok')]);
        $app = App::new($provider, 'test-model')
            ->withAvailableSkills($registry)
            ->withSkillPickerOptions(iterator_to_array($registry->getUserInvocable()))
            ->withChat(new Chat(
                backend: EngineBackend::new($provider, 'test-model')->withSkillRegistry($registry)
            ));

        [$next] = $app->update(new SelectSkillMsg('demo'));
        $next->chat->backend()->complete([Message::user('hello')]);
        $prompt = (string) ($provider->requests[0]->systemPrompt ?? '');

        $this->assertStringNotContainsString('THE-BODY-MARKER', $prompt);
        $this->assertMatchesRegularExpression('/<available-skills>.{0,}demo.{0,}<\/available-skills>/s', $prompt);
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

    /**
     * Audit AG-5 (App half): the fork's SubAgent took the constructor's
     * 300-second default, which the pool enforces since WF-1 (a) — so a wired
     * dispatch would have killed every fork-context skill at five minutes
     * whatever the configured timeout said. The budget is now the caller's,
     * and with none given there is no per-agent bound at all.
     */
    public function testDispatchSkillGivesTheForkTheCallersBudgetOrNone(): void
    {
        $registry = new SkillRegistry();
        $forkSkill = $this->skillFromYaml("description: Fork skill\ncontext: fork", 'fork-skill');
        $registry->register(['fork-skill' => $forkSkill]);
        $app = App::new($this->provider, 'test-model')->withAvailableSkills($registry);

        $timeouts = [];
        $executor = $this->createMock(ExecutorInterface::class);
        $executor->method('execute')->willReturnCallback(
            static function (SubAgent $agent) use (&$timeouts): AgentResult {
                $timeouts[] = $agent->timeout;

                return new AgentResult(agentId: $agent->id, status: AgentStatus::Completed, output: 'ok');
            },
        );
        $pool = new AgentWorkerPool(maxConcurrent: 1, executor: $executor);

        $app->dispatchSkill($forkSkill, $pool, 'no budget given');
        $app->dispatchSkill($forkSkill, $pool, 'the configured budget', 1_800);

        $this->assertSame([0, 1_800], $timeouts);
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
     * the process directory. A `--root candy-shine` fork has to be told it is @rename-keep historical provenance: real-defect narrative naming the then-live lib path
     * standing in candy-shine. @rename-keep historical provenance: real-defect narrative naming the then-live lib path
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
     * C-EXT-2.c: the shared SkillFork Agent builder carries the skill's
     * declared effort too — the pin above says the fork speaks at the skill's
     * model, this says it speaks at the skill's thinking budget. An
     * undeclared effort stays null: the inherited Agent's preset must not
     * masquerade as a declaration.
     */
    public function testDispatchSkillCarriesTheDeclaredEffortOntoTheForkAgent(): void
    {
        $registry = new SkillRegistry();
        $forkSkill = $this->skillFromYaml(
            "description: Fork skill\ncontext: fork\neffort: high",
            'fork-skill',
        );
        $registry->register(['fork-skill' => $forkSkill]);

        [, $subAgent] = $this->captureForkDispatch(
            App::new($this->provider, 'test-model')->withAvailableSkills($registry),
            $forkSkill,
        );
        $this->assertSame(Effort::High, $subAgent->agent->effort);

        $plain = $this->skillFromYaml("description: Plain fork\ncontext: fork", 'plain-fork');
        $registry->register([$plain]);
        [, $plainAgent] = $this->captureForkDispatch(
            App::new($this->provider, 'test-model')->withAvailableSkills($registry),
            $plain,
        );
        $this->assertNull($plainAgent->agent->effort);
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
    // C8 — the embedder seam (still caller-less), and the fork wording that
    // started honest and stayed honest: since C-EXT-2.c the model-driven fork
    // runs through SkillTool on the turn's bound Task, not through dispatchSkill
    // -------------------------------------------------------------------------

    /**
     * Selecting a `context: fork` skill must not claim it was applied.
     *
     * MEASURED before the branch this pins existed: a fork-context skill is
     * user-invocable, so it appears in the picker; selecting it reported
     * "Enabled skill 'x'."; and {@see App::applySkillsToSystemPrompt()} then
     * skipped it, because fork skills are excluded from the inline path by
     * design. At the time nothing dispatched it either and the status bar said
     * the opposite. Since C-EXT-2.c the enablement IS an effect — the Skill
     * tool's fork branch is reachable for an enabled fork skill and runs it
     * on the turn's bound Task — but the pin's subject still holds: the
     * picker never claims an inlining that does not happen, and the fork
     * body never reaches the system prompt.
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

        $expected = (new \SugarCraft\Code\Providers\EchoProvider())->complete(new CompleteRequest(
            model: 'test-model',
            messages: [new \SugarCraft\Code\Messages\UserMessage($task)],
        ))->content;

        $this->assertNotNull($result);
        $this->assertSame(AgentStatus::Completed, $result->status);
        $this->assertSame($expected, $result->output);
    }
}
