<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Tests\Support\BackendSelectionEnvSandboxTrait;
use SugarCraft\Crush\Tests\Tools\BuiltInToolCorpus;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\BuiltIn\Glob;
use SugarCraft\Crush\Tools\BuiltIn\Read;

/**
 * R19: bin/sugarcrush previously built `new Chat(backend: $backend)` with no
 * SessionStore/MemoryStore/InstructionFileLoader ever constructed, and built
 * Read/Edit/Glob with no InstructionFileLoader either -- leaving the
 * already-built /branch, /rewind, /memory, and nested-instruction-loading
 * features (P6.S9/S11/S12/S15) unreachable through the real CLI binary.
 *
 * This exercises SugarCraft\Crush\Cli\Bootstrap -- the construction logic
 * extracted out of bin/sugarcrush's IIFE -- directly, rather than shelling
 * out to bin/sugarcrush itself: the bin script ends in Program::run(), which
 * attaches to a real TTY and blocks, so it cannot be driven from a
 * deterministic, CI-safe test.
 *
 * HOME and the whole backend-selection chain
 * ({@see BackendSelectionEnvSandboxTrait}) are sandboxed per test. The chain
 * matters because these tests assert on the ENGINE backend `Bootstrap::backend()`
 * builds — its tool set, its loaders — and either shell-out variable merely
 * exported in the developer's shell selects a `CommandBackend` instead, which
 * has none of them. Measured before the clearing was added: one error here in a
 * full run.
 */
final class BinSugarcrushWiringTest extends TestCase
{
    use BackendSelectionEnvSandboxTrait;

    private string $tempDir;
    private string $originalHome;
    private mixed $originalServerHome;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sugarcrush_bin_wiring_' . uniqid('', true);
        mkdir($this->tempDir . '/home', 0700, true);
        mkdir($this->tempDir . '/repo', 0755, true);

        // Isolate the real ~/.sugar-crush/ from this test's session db and
        // memory directory, same convention as SessionTest/WorkflowEngineTest.
        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=' . $this->tempDir . '/home');

        // BOTH SPELLINGS, and the REASON has changed since this comment was
        // written even though the action has not. It said "the code under test
        // does not agree with itself about where HOME lives: Bootstrap reads
        // getenv('HOME'), while ForeignSkillDiscovery reads $_SERVER['HOME']" —
        // that disagreement is gone, every `~` reader in src/ now resolves
        // through HomeDirectory, which prefers getenv(). The superglobal is set
        // anyway for the reason {@see HomeSandboxTrait} states: half a sandbox is
        // not a sandbox, and it costs nothing to keep it honest for anything that
        // reads $_SERVER directly. Redirecting only one of them once left this
        // scanning the DEVELOPER's ~/.claude/skills.
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->tempDir . '/home';

        $this->clearBackendSelectionEnv();
    }

    protected function tearDown(): void
    {
        $this->restoreBackendSelectionEnv();

        if ($this->originalHome !== '') {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
        }

        if ($this->originalServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->originalServerHome;
        }

        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testChatIsWiredWithANonNullSessionStore(): void
    {
        $chat = Bootstrap::chat($this->tempDir . '/repo');

        $this->assertInstanceOf(EnhancedSessionStore::class, $chat->sessionStore());
    }

    public function testChatIsWiredWithANonNullMemoryStore(): void
    {
        $chat = Bootstrap::chat($this->tempDir . '/repo');

        $this->assertInstanceOf(MemoryStore::class, $chat->memoryStore());
    }

    public function testSessionStoreDatabaseIsCreatedUnderTheUserConfigDir(): void
    {
        Bootstrap::chat($this->tempDir . '/repo');

        $this->assertFileExists($this->tempDir . '/home/.sugar-crush/session.db');
    }

    public function testMemoryStoreDirectoryIsCreatedUnderTheUserConfigDir(): void
    {
        Bootstrap::chat($this->tempDir . '/repo');

        $this->assertDirectoryExists($this->tempDir . '/home/.sugar-crush/memory');
    }

    /**
     * Same reachability class as the Doctor pin below, and the same cause: the
     * tool existed, was tested, and was never listed in Bootstrap::tools(), so
     * the live EngineBackend/Runtime loop — which resolves every model tool
     * call against exactly that array — could not advertise or dispatch it.
     * With Write absent, Edit's `file_exists()` precondition left `Bash` as the
     * model's only way to create a file.
     *
     * THE EXPECTED SET IS SCANNED, NOT WRITTEN DOWN, and that is the whole point
     * of this revision. The previous version asserted `assertCount(10, …)` plus a
     * literal name list, which pins only the direction where a tool is ADDED to
     * `Bootstrap::tools()`.
     *
     * MEASURED, in a scratch copy of the lib with an eleventh `Tool` implementor
     * (`src/Tools/BuiltIn/Notify.php`) present and deliberately NOT listed in
     * `Bootstrap::tools()`:
     *
     *   - with the old literal assertions: this file `OK (298 tests, 1692
     *     assertions)`, the whole Integration tier `OK (467 tests, 2681
     *     assertions)` — the omitted-from-the-array direction, the one that
     *     actually happened to `Write`, was invisible;
     *   - with the scanned set below: `1 failure` in this file, naming the class.
     *
     * `Bootstrap::tools()`' doc-block claimed the two halves "agree by
     * construction"; nothing constructs either from the other, so this assertion
     * is the mechanism that makes them agree, and it is named as such there now.
     */
    public function testBootstrapToolsShipsAWriteToolAndTheWholeBuiltInSet(): void
    {
        $byClass = $this->toolsByClass();

        $this->assertArrayHasKey(\SugarCraft\Crush\Tools\BuiltIn\Write::class, $byClass);

        // MINUS the dynamically-constructed tools, and the subtraction is the
        // whole of the exemption — see
        // {@see BuiltInToolCorpus::DYNAMIC_TOOL_CLASSES} for why it had to exist
        // and how narrow it is kept. `array_values` because `array_diff`
        // preserves keys and `assertSame` compares them.
        $expected = array_values(array_diff(
            BuiltInToolCorpus::classNames(),
            BuiltInToolCorpus::dynamicToolClasses(),
        ));
        $wired = array_keys($byClass);
        sort($wired);

        $this->assertSame(
            $expected,
            $wired,
            'every concrete Tool under src/Tools/BuiltIn/ must be wired into Bootstrap::tools(), and nothing '
            . 'else may be: a class in that directory that no run can dispatch is the Write defect again',
        );

        // The exemption may not become a hiding place: this repo has no
        // `.mcp.json`, so the fixture repo Bootstrap::tools() was called against
        // has none either, and every exempted class must therefore be ABSENT
        // here. If one ever appears, it is constructible as a literal after all
        // and belongs in the array rather than on the list.
        foreach (BuiltInToolCorpus::dynamicToolClasses() as $dynamic) {
            $this->assertArrayNotHasKey($dynamic, $byClass);
        }

        $names = array_map(static fn (object $t): string => $t->name(), array_values($byClass));
        sort($names);
        $this->assertSame(
            // Still a literal, and deliberately: this asserts the WIRE NAMES the
            // provider schema advertises, which the class names do not determine
            // (`SkillTool` announces itself as `Skill`) and which the model has
            // learned. `doctor` is lower-case where the other nine are TitleCase —
            // asserted as it actually is rather than as it ought to be, since
            // renaming a tool the model already knows is not this test's business.
            // A NEW tool fails the scanned assertion above before it reaches here,
            // so this list cannot silently go stale.
            ['Bash', 'Edit', 'Glob', 'Grep', 'Read', 'Skill', 'WebFetch', 'WebSearch', 'Write', 'doctor'],
            $names,
        );

        // The same loader instance Read/Edit/Glob hold, not merely a non-null
        // one: loadForPath()'s "already injected this session" map is
        // per-instance, so a Write on its own loader would re-announce a nested
        // CLAUDE.md the model had already been shown.
        $this->assertSame(
            $this->instructionLoaderOf($byClass[Read::class]),
            $this->instructionLoaderOf($byClass[\SugarCraft\Crush\Tools\BuiltIn\Write::class]),
        );
    }

    public function testReadEditGlobEachReceiveANonNullInstructionLoader(): void
    {
        $byClass = $this->toolsByClass();

        foreach ([Read::class, Edit::class, Glob::class] as $class) {
            $this->assertArrayHasKey($class, $byClass, "Expected {$class} among the built-in tools");
            $this->assertInstanceOf(
                InstructionFileLoader::class,
                $this->instructionLoaderOf($byClass[$class]),
                "{$class} must be wired with a non-null InstructionFileLoader",
            );
        }
    }

    public function testReadEditGlobShareTheSameInstructionLoaderInstance(): void
    {
        // loadForPath() tracks "already injected this session" per loader
        // instance (InstructionFileLoader::$emittedPaths) -- a shared
        // instance across the three tools is what makes the nested
        // CLAUDE.md/AGENTS.md dedup semantics apply CLI-wide instead of
        // once per tool.
        $byClass = $this->toolsByClass();

        $readLoader = $this->instructionLoaderOf($byClass[Read::class]);
        $editLoader = $this->instructionLoaderOf($byClass[Edit::class]);
        $globLoader = $this->instructionLoaderOf($byClass[Glob::class]);

        $this->assertSame($readLoader, $editLoader);
        $this->assertSame($editLoader, $globLoader);
    }

    /**
     * W1.B3a: EngineBackend::withInstructionLoader() exists so the engine's
     * loadRoot()/loadForced() reads and the tools' on-touch loadForPath()
     * reads run against ONE loader. Both docblocks assert that invariant; a
     * Bootstrap that built a second loader for the engine would still pass
     * every "non-null" check above while silently splitting
     * InstructionFileLoader::$emittedPaths in two, so the same-instance
     * claim is asserted against a single real Bootstrap::backend() here.
     */
    public function testBackendSharesItsInstructionLoaderWithTheReadEditGlobTools(): void
    {
        $backend = Bootstrap::backend($this->tempDir . '/repo');

        $backendLoader = $this->privateValue($backend, 'instructionLoader');
        $this->assertInstanceOf(InstructionFileLoader::class, $backendLoader);

        /** @var list<object> $tools */
        $tools = $this->privateValue($backend, 'tools');
        $byClass = [];
        foreach ($tools as $tool) {
            $byClass[$tool::class] = $tool;
        }

        foreach ([Read::class, Edit::class, Glob::class] as $class) {
            $this->assertArrayHasKey($class, $byClass, "Expected {$class} among the backend's tools");
            $this->assertSame(
                $backendLoader,
                $this->instructionLoaderOf($byClass[$class]),
                "{$class} must share the backend's InstructionFileLoader instance",
            );
        }
    }

    /**
     * The inverse of what this test used to assert. It previously pinned the
     * R20.fix GAP -- `Bootstrap::chat()` never constructs/passes an
     * `agentManager:` -- documenting it where a reader would see it rather
     * than only in a docblock. crush_code.md Phase 1 item 1 closed the gap, so
     * the pin flips: a launch must now carry a real manager, because
     * everything downstream of it (`/agents`, Ctrl+A, the transcript's agent
     * strip, `AgentDashboardPane`'s agent rows, `PermissionGate`) is reachable
     * only through this reference.
     */
    public function testChatIsWiredWithARealAgentManager(): void
    {
        $chat = Bootstrap::chat($this->tempDir . '/repo');

        $this->assertNotNull($chat->agentManager());
    }

    /**
     * R20.fix regression: typing "/agents" (or pressing Ctrl+A) against a real
     * `Bootstrap::chat()`-constructed Chat used to throw an uncaught
     * `RuntimeException('AgentManager not set')` straight out of
     * `Chat::update()` -- candy-core's `Program` has no try/catch around its
     * synchronous update() dispatch, so this crashed the live CLI outright
     * (and skipped `teardownTerminal()`). It was then made to degrade to a
     * "not configured" response; with Phase 1 item 1's wiring it must answer
     * from the real roster instead, so neither the crash nor the degradation
     * is what a CLI user sees.
     */
    public function testAgentsCommandAnswersFromTheRealRosterOnABootstrapConstructedChat(): void
    {
        $chat = Bootstrap::chat($this->tempDir . '/repo');
        $ref = new \ReflectionMethod($chat, 'withInputBuf');
        $ref->setAccessible(true);
        $chat = $ref->invoke($chat, '/agents');

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $reply = $next->history[array_key_last($next->history)]->content;

        $this->assertStringNotContainsString('Agent manager not configured', $reply);
        $this->assertStringContainsString('agent(s) registered and idle', $reply);
    }

    /**
     * W1.G2 reachability fix (reviewer-reported): the previous 'doctor'
     * wiring only ever reached Chat's own registerTool()/beginToolCalls()/
     * forkToolCalls() dispatch, which never fires in production -- every
     * real completion goes through EngineBackend, which resolves tool
     * calls internally against the Tool[] array Bootstrap::tools() builds.
     * A real Bootstrap::tools($root) call must now include a Doctor tool,
     * so the live LLM tool-calling schema actually advertises it.
     */
    public function testBootstrapToolsIncludesARealDoctorTool(): void
    {
        $byClass = $this->toolsByClass();

        $this->assertArrayHasKey(\SugarCraft\Crush\Tools\BuiltIn\Doctor::class, $byClass);
    }

    /**
     * Calling the real Doctor::execute() (the method
     * SugarCraft\Crush\Runtime::executeToolCalls() calls for every 'doctor'
     * ToolCall the live EngineBackend/Runtime/App loop resolves) must
     * produce a genuinely image-bearing Tools\ToolResult.
     */
    public function testDoctorToolProducesAnImageBearingToolResult(): void
    {
        $result = (new \SugarCraft\Crush\Tools\BuiltIn\Doctor())->execute([]);

        $this->assertInstanceOf(\SugarCraft\Crush\Tools\ToolResult::class, $result);
        $this->assertTrue($result->hasImage());
        $this->assertNotNull($result->imageBytes());
        $this->assertStringStartsWith("\x89PNG", (string) $result->imageBytes());
        $this->assertNotNull($result->imageProtocol());
    }

    /**
     * End-to-end through the REAL production pipeline a model-issued
     * "doctor" tool call takes: Chat::submit() -> EngineBackend::completeAsync()
     * (pcntl_fork() when available, the same fork boundary every real
     * bin/sugarcrush completion crosses) -> Runtime::run()/executeToolCalls()
     * resolving the call against Bootstrap::tools($root)'s real Doctor
     * instance -> EngineBackend::complete() threading the image onto the
     * root Message -> Chat::update(AssistantMsg) appending it to history.
     *
     * Only the provider's HTTP layer is a stub (a ProviderInterface returning
     * a canned tool_call then a canned answer, exactly like
     * EngineBackendTest's own agentic-loop tests) -- unlike the fake
     * reachability test this replaces, the assistant Message carrying the
     * toolCalls is never hand-constructed; Chat drives the whole loop
     * itself via a real submit()/backend round-trip.
     */
    public function testDoctorToolIsReachableEndToEndThroughARealChatTurn(): void
    {
        $root = $this->tempDir . '/repo';
        $provider = new class implements \SugarCraft\Crush\Providers\ProviderInterface {
            public int $calls = 0;
            public function name(): string { return 'stub-doctor'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return true; }
            public function supportsVision(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 1000; }
            public function costPer1kTokens(string $m, string $d): float { return 0.0; }
            public function complete(\SugarCraft\Crush\Providers\CompleteRequest $r): \SugarCraft\Crush\Providers\CompleteResponse
            {
                $this->calls++;

                return $this->calls === 1
                    ? new \SugarCraft\Crush\Providers\CompleteResponse(
                        content: 'checking terminal capability',
                        toolCalls: [new \SugarCraft\Crush\Tools\ToolCall('call_doctor_1', 'doctor', [])],
                    )
                    : new \SugarCraft\Crush\Providers\CompleteResponse(content: 'done checking');
            }
            public function completeStream(\SugarCraft\Crush\Providers\CompleteRequest $r): \Generator
            {
                yield new \SugarCraft\Crush\Providers\CompleteResponse(content: '');
            }
            public function embeddings(\SugarCraft\Crush\Providers\EmbeddingsRequest $r): \SugarCraft\Crush\Providers\EmbeddingsResponse
            {
                return new \SugarCraft\Crush\Providers\EmbeddingsResponse([]);
            }
        };

        $backend = \SugarCraft\Crush\Backend\EngineBackend::new($provider, 'stub-doctor')
            ->withTools(Bootstrap::tools($root));
        $chat = new \SugarCraft\Crush\Chat(backend: $backend, mosaic: \SugarCraft\Crush\ToolResult::mosaic());

        $inputBufRef = new \ReflectionMethod($chat, 'withInputBuf');
        $inputBufRef->setAccessible(true);
        $chat = $inputBufRef->invoke($chat, "check my terminal's image support");

        [$afterSubmit, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertInstanceOf(\Closure::class, $cmd);

        $asyncCmd = $cmd();
        $this->assertInstanceOf(\SugarCraft\Core\AsyncCmd::class, $asyncCmd);

        $loop = \React\EventLoop\Loop::get();
        $resolved = null;
        $asyncCmd->promise->then(function ($msg) use (&$resolved, $loop): void {
            $resolved = $msg;
            $loop->stop();
        });

        if ($resolved === null) {
            $safety = $loop->addTimer(10.0, static function () use ($loop): void { $loop->stop(); });
            $loop->run();
            $loop->cancelTimer($safety);
        }

        // Since W2.S1c a turn whose backend ran tools resolves to a
        // BackendToolEventsMsg queue (the ToolStarted/ToolFinished pair for the
        // doctor call) that hands off to the AssistantMsg once drained - so
        // follow the Cmd chain instead of applying a single Msg.
        $this->assertInstanceOf(
            \SugarCraft\Crush\BackendToolEventsMsg::class,
            $resolved,
            'doctor tool call did not complete within the test timeout',
        );

        $final = $afterSubmit;
        $msg = $resolved;
        $steps = 0;
        while ($msg !== null && $steps++ < 20) {
            [$final, $nextCmd] = $final->update($msg);
            $msg = $nextCmd === null ? null : $nextCmd();
        }

        $lastMessage = $final->history[array_key_last($final->history)];

        $this->assertTrue($lastMessage->hasImage(), 'image captured by the real Doctor tool must survive EngineBackend + the completeAsync() fork boundary and reach Chat history');
        $this->assertStringStartsWith("\x89PNG", (string) $lastMessage->imageBytes);
        $this->assertNotNull($lastMessage->imageProtocol);
    }

    // =========================================================================
    // Skills subsystem wiring (crush_feat.md section 7 E1)
    // =========================================================================

    /**
     * section 7 D's finding: `bin/sugarcrush` contained zero references to
     * Skill, so the whole BuiltIn/ roster plus anything a user dropped under
     * .sugar-crush/skills was invisible to a real run. Bootstrap::app() is
     * the binary's entry point (bin/sugarcrush -> Bootstrap::app() -> App),
     * so the registry it hands the App must actually carry the on-disk skill.
     */
    public function testBootstrapAppPopulatesAvailableSkillsFromDisk(): void
    {
        $this->writeProjectSkill('bin-wiring-marker', 'Marker skill for the bin wiring test.');

        $app = Bootstrap::app($this->tempDir . '/repo');

        $this->assertNotNull($app->availableSkills->get('bin-wiring-marker'));
        $this->assertNotNull($app->availableSkills->get('security-audit'));
    }

    /**
     * Populating the registry is only half of "auto-triggerable": the model
     * reaches a skill through the `Skill` tool, which is resolved out of the
     * Tool[] EngineBackend/Runtime hold, so Bootstrap::tools() has to ship it.
     */
    public function testBootstrapToolsIncludesTheModelFacingSkillTool(): void
    {
        $byClass = $this->toolsByClass();

        $this->assertArrayHasKey(\SugarCraft\Crush\Tools\BuiltIn\SkillTool::class, $byClass);
        $this->assertSame('Skill', $byClass[\SugarCraft\Crush\Tools\BuiltIn\SkillTool::class]->name());
    }

    /**
     * The shell's Skills pane and the model's Skill tool must read ONE
     * registry instance — two independent scans could disagree about which
     * skills exist or which are disabled.
     */
    public function testSkillToolSharesTheAppsRegistryInstance(): void
    {
        $app = Bootstrap::app($this->tempDir . '/repo');

        $skillTool = null;
        foreach ($app->tools as $tool) {
            if ($tool instanceof \SugarCraft\Crush\Tools\BuiltIn\SkillTool) {
                $skillTool = $tool;
            }
        }

        $this->assertNotNull($skillTool);
        $this->assertSame($app->availableSkills, $this->privateValue($skillTool, 'registry'));
    }

    /**
     * End of the chain: the tool Bootstrap built must return the on-disk
     * SKILL.md body when the model invokes it by name.
     */
    public function testSkillToolInvocationReturnsTheOnDiskSkillBody(): void
    {
        $this->writeProjectSkill('bin-invoke-marker', 'Marker skill invoked through the tool.');

        $tool = $this->skillToolFromBootstrap();
        $result = $tool->execute(['name' => 'bin-invoke-marker']);

        $this->assertFalse($result->isError());
        $this->assertStringContainsString('BIN INVOKE MARKER BODY', $result->content());
    }

    /**
     * section 7 E1's `disableFromConfig()` step: a name listed under
     * `disabledSkills` in the persisted user config must be unreachable
     * through the tool, not merely hidden from the picker.
     */
    public function testDisabledSkillsInUserConfigAreNotInvocable(): void
    {
        $this->writeProjectSkill('bin-disabled-marker', 'Marker skill turned off by config.');
        mkdir($this->tempDir . '/home/.sugar-crush', 0700, true);
        file_put_contents(
            $this->tempDir . '/home/.sugar-crush/config.json',
            json_encode(['disabledSkills' => ['bin-disabled-marker']]),
        );

        $result = $this->skillToolFromBootstrap()->execute(['name' => 'bin-disabled-marker']);

        $this->assertTrue($result->isError());
    }

    // =========================================================================
    // `--root` propagation (crush_code.md Phase 0 item 6)
    //
    // Every test below points --root at $this->tempDir . '/repo', which is
    // NEVER the process cwd (PHPUnit runs from the package directory). That
    // divergence IS the bug: `--root candy-shine` correctly jailed the tools
    // to candy-shine/ while telling the model — through the environment block
    // and through every HookContext — that it was standing in the enclosing
    // monorepo. A test where the configured root and getcwd() coincide cannot
    // observe that at all.
    // =========================================================================

    public function testBootstrapAppCarriesTheConfiguredRootRatherThanTheProcessDirectory(): void
    {
        $root = $this->tempDir . '/repo';
        $this->assertNotSame(getcwd(), $root, 'the fixture root must diverge from the process cwd');

        $app = Bootstrap::app($root);

        $this->assertSame($root, $app->root);
    }

    public function testAnAppConstructedWithoutARootReportsNullRatherThanGuessing(): void
    {
        // Null, not getcwd(): App::$root has to stay distinguishable from
        // "explicitly rooted at the process directory" so each consumer can
        // spell its own fallback. Bootstrap::app() with no argument does
        // resolve one, so this exercises the App seam directly.
        $this->assertNull(\SugarCraft\Crush\App\App::new(new \SugarCraft\Crush\Providers\EchoProvider(), 'echo')->root);
    }

    public function testBootstrapChatResolvesTheConfiguredRootRatherThanTheProcessDirectory(): void
    {
        $root = $this->tempDir . '/repo';

        $chat = Bootstrap::chat($root);

        $this->assertSame($root, $chat->projectRoot());
        $this->assertNotSame(getcwd(), $chat->projectRoot());
    }

    /**
     * The Settings sidebar is the one place a user can SEE which root the
     * session is on. It read `getcwd()` because App carried no root to read
     * back, so it agreed with the (wrong) environment block rather than with
     * the tools.
     */
    public function testSettingsPaneReportsTheConfiguredRootNotTheProcessDirectory(): void
    {
        $root = $this->tempDir . '/repo';

        $settings = [];
        foreach (\SugarCraft\Crush\Tui\Components\SettingsPane::settings(Bootstrap::app($root)) as [$label, $value]) {
            $settings[$label] = $value;
        }

        $this->assertSame($root, $settings['Root'] ?? null);
    }

    /**
     * The engine pipeline, end to end through the real production seam
     * `Bootstrap::backend($root)` builds: a model-issued tool call reaches
     * `Runtime::executeToolCalls()`, which builds the `HookContext` every
     * PreToolUse/PostToolUse hook gates on. That context's `projectRoot` used
     * to be `getcwd()`, so a `protect-files`-style guard on a `--root` run
     * would have resolved paths against the wrong tree.
     *
     * `complete()` (not `completeAsync()`) on purpose: the async path forks,
     * and a hook recording into the child's memory is unobservable here.
     */
    public function testEngineHookContextsReportTheConfiguredRoot(): void
    {
        $root = $this->tempDir . '/repo';
        $recorder = $this->recordingHook();

        $hooks = new \SugarCraft\Crush\Hooks\HookManager(new \SugarCraft\Crush\Hooks\HookRegistry());
        $hooks->register($recorder);

        $backend = \SugarCraft\Crush\Backend\EngineBackend::new($this->toolCallingProvider(), 'stub')
            ->withTools([$this->noopTool()])
            ->withHooks($hooks)
            ->withRoot($root);

        $backend->complete([\SugarCraft\Crush\Message::user('go')]);

        $this->assertNotSame([], $recorder->roots, 'the recording hook never saw a PreToolUse call');
        $this->assertSame([$root], array_values(array_unique($recorder->roots)));
    }

    /**
     * Chat's OWN tool pipeline (crush_feat.md §1 D's second dispatcher) builds
     * its own `HookContext`, with no App or Runtime in reach — which is why it
     * needed its own copy of the root rather than reading the engine's.
     */
    public function testChatsOwnHookContextsReportTheConfiguredRoot(): void
    {
        $root = $this->tempDir . '/repo';
        $recorder = $this->recordingHook();

        $hooks = new \SugarCraft\Crush\Hooks\HookManager(new \SugarCraft\Crush\Hooks\HookRegistry());
        $hooks->register($recorder);

        $chat = (new \SugarCraft\Crush\Chat(hooks: $hooks, projectRoot: $root))
            ->registerTool('noop', static fn(array $args): string => 'ok');

        $gate = new \ReflectionMethod($chat, 'gateToolCall');
        $gate->setAccessible(true);
        $gate->invoke($chat, new \SugarCraft\Crush\ToolCall('noop', [], 'call_1'));

        $this->assertSame([$root], $recorder->roots);
    }

    /**
     * Bootstrap is what actually hands Chat that root on a real run, so the
     * two halves above are only joined once this holds — and it is asserted
     * on the CONTEXT a hook receives, not on the stored field, because the
     * field being right while the context is not is exactly the failure the
     * neighbouring tests exist to catch.
     */
    public function testBootstrapChatDeliversTheConfiguredRootAllTheWayIntoAHookContext(): void
    {
        $root = $this->tempDir . '/repo';
        // registerTool() only adds the callable gateToolCall() dispatches on;
        // the root, the guard chain and everything else still come from
        // Bootstrap, and registerTool() returns a NEW Chat sharing the same
        // HookManager instance.
        $chat = Bootstrap::chat($root)->registerTool('noop', static fn(array $args): string => 'ok');

        $hooks = $this->privateValue($chat, 'hooks');
        $this->assertInstanceOf(\SugarCraft\Crush\Hooks\HookManager::class, $hooks, 'Bootstrap::chat() must wire the guard chain');

        // Registered onto the guard chain Bootstrap already built, so nothing
        // about the construction path under test is replaced by the fixture.
        $recorder = $this->recordingHook();
        $hooks->register($recorder);

        $gate = new \ReflectionMethod($chat, 'gateToolCall');
        $gate->setAccessible(true);
        $gate->invoke($chat, new \SugarCraft\Crush\ToolCall('noop', [], 'call_1'));

        $this->assertSame([$root], $recorder->roots);
    }

    /**
     * The one `bin/sugarcrush` path that can be executed end-to-end without
     * blocking: the root check runs before either dispatch, so the process
     * exits before `Program::run()` ever attaches to a TTY.
     *
     * Worth an actual subprocess rather than a call to
     * {@see \SugarCraft\Crush\Cli\ArgvParser::rootError()} alone, because the
     * defect being pinned is a WIRING one — rootError() existing but never
     * being consulted would leave `--root /typo` reaching App::$root, every
     * HookContext, and every ScriptHook's proc_open() cwd exactly as before.
     */
    public function testBinRejectsARootThatNamesNoDirectory(): void
    {
        $missing = $this->tempDir . '/no_such_root';
        $bin = \dirname(__DIR__, 2) . '/bin/sugarcrush';

        $process = proc_open(
            [PHP_BINARY, $bin, '--root', $missing],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(2, $exitCode, 'a --root naming no directory is a usage error');
        $this->assertStringContainsString($missing, $stderr);
        $this->assertSame('', $stdout, 'the TUI must not have started');
    }

    public function testBinAcceptsARootThatExists(): void
    {
        // The complement: proving the check is not simply rejecting every
        // --root. Paired with `--help` so the process still terminates.
        $bin = \dirname(__DIR__, 2) . '/bin/sugarcrush';

        $process = proc_open(
            [PHP_BINARY, $bin, '--root', $this->tempDir . '/repo', '--help'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process));
        $this->assertNotSame('', $stdout);
    }

    /**
     * A dead working directory (deleted out from under the process) must
     * produce a clear error naming --root, not a TypeError from whichever
     * `string`-typed constructor `false` happened to reach first.
     */
    public function testBootstrapReportsAMissingRootRatherThanHandingAPathJailAFalse(): void
    {
        $dead = $this->tempDir . '/dead_cwd';
        mkdir($dead, 0755, true);

        $original = getcwd();
        $this->assertIsString($original);

        try {
            chdir($dead);
            rmdir($dead);
            $this->assertFalse(getcwd(), 'this test is meaningless unless getcwd() actually fails');

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/--root/');
            Bootstrap::tools(null);
        } finally {
            chdir($original);
        }
    }

    // -------------------------------------------------------------------------
    // Scrape-and-pin: a NEW root-resolving site must not be able to skip this
    // -------------------------------------------------------------------------

    /**
     * Positions where a bare `getcwd()` means "this site silently ignores
     * `--root`". Spelled several ways rather than one, for the same reason
     * ProviderConnectTimeoutTest scrapes several timeout spellings: a
     * single-pattern scrape is trivially evadable and would then read as
     * proof of something it never checked.
     *
     * Both the named-argument form (`projectRoot: getcwd()`) and the local
     * form (`$workingDirectory = getcwd()`) are covered — the two original
     * offenders were spelled one each way. `$root ??= getcwd()` is
     * deliberately NOT matched: that is Bootstrap resolving the DEFAULT root
     * for a run that named none, which is the one correct use.
     */
    private const BARE_GETCWD_ROOT_SPELLINGS = [
        '/projectRoot:\s*(\(string\)\s*)?getcwd\(\)/',
        '/workingDirectory:\s*(\(string\)\s*)?getcwd\(\)/',
        self::ROOT_CAPTURE_EXEMPT_PATTERN,
        '/\$projectRoot\s*=\s*(\(string\)\s*)?getcwd\(\)/',
        '/\$workingDirectory\s*=\s*(\(string\)\s*)?getcwd\(\)/',
        '/\$root\s*=\s*(\(string\)\s*)?getcwd\(\)/',
    ];

    /**
     * The one file allowed to capture an environment block at `getcwd()`.
     *
     * {@see \SugarCraft\Crush\Agents\Agent} is a PERSISTED config value
     * object — it has no root of its own and must not grow one, since a root
     * written into an agent definition file would outlive the session that
     * captured it. Its rooted path is the `?EnvironmentBlock $environment`
     * parameter/field a caller holding a session snapshot passes in; the
     * capture is the documented last resort for the callers that hold none
     * (AgentManager, ProcessExecutor, WorkflowEngine). The pin below asserts
     * that rooted path still exists, so the exemption cannot quietly become
     * a licence to ignore the root.
     *
     * The exemption is per-PATTERN, not per-file: only
     * {@see ROOT_CAPTURE_EXEMPT_PATTERN} is skipped for this file, and the
     * other five spellings still apply to it. A whole-file `return` would
     * have disarmed the guard for the one file most likely to grow a new
     * root-resolving site — a `$projectRoot = getcwd();` added here would
     * have slid through the check that exists to catch precisely that.
     */
    private const ROOT_CAPTURE_EXEMPT = 'Agents/Agent.php';

    /** The single spelling {@see ROOT_CAPTURE_EXEMPT} is excused from. */
    private const ROOT_CAPTURE_EXEMPT_PATTERN = '/EnvironmentBlock::capture\(\s*(\(string\)\s*)?getcwd\(\)/';

    /**
     * Every PHP source the guard below scans: all of `src/`, plus the
     * `bin/sugarcrush` entry point — which is `src/`-adjacent, carries no
     * `.php` extension, and is exactly where a "resolve the root here"
     * shortcut would be most tempting to add.
     *
     * Comments are stripped before the patterns run. The scrape is
     * source-TEXT based, so without this a future docblock quoting
     * `$root = getcwd()` as the anti-pattern it warns against would fail the
     * guard spuriously — and the natural "fix" for that is to weaken the
     * pattern, which is the outcome worth avoiding.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function crushSourceFiles(): array
    {
        $lib = (string) realpath(\dirname(__DIR__, 2));
        $root = $lib . '/src';
        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        ) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = (string) $file->getPathname();
            $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
            $files[$relative] = [$relative, self::codeWithoutComments((string) file_get_contents($path))];
        }

        ksort($files);

        $bin = $lib . '/bin/sugarcrush';
        if (is_file($bin)) {
            $files['bin/sugarcrush'] = ['bin/sugarcrush', self::codeWithoutComments((string) file_get_contents($bin))];
        }

        return $files;
    }

    /**
     * The PHP source with every comment and docblock removed, so the scrape
     * matches real code rather than prose that quotes it.
     */
    private static function codeWithoutComments(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (\is_array($token)) {
                if ($token[0] === \T_COMMENT || $token[0] === \T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }

        return $code;
    }

    /**
     * @dataProvider crushSourceFiles
     */
    public function testNoRootResolvingSiteFallsBackToBareGetcwd(string $name, string $source): void
    {
        $exempt = $name === self::ROOT_CAPTURE_EXEMPT;
        if ($exempt) {
            $this->assertStringContainsString(
                '?EnvironmentBlock $environment',
                $source,
                'the exemption is only defensible while the rooted path (an injected snapshot) still exists',
            );
        }

        foreach (self::BARE_GETCWD_ROOT_SPELLINGS as $pattern) {
            // One pattern is excused for one file — never the whole list.
            if ($exempt && $pattern === self::ROOT_CAPTURE_EXEMPT_PATTERN) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                $pattern,
                $source,
                $name . ' resolves a project root with a bare getcwd(). Read the configured root '
                    . '(App::$root / Chat::$projectRoot / EngineBackend::withRoot()) and fall back to '
                    . 'getcwd() only when it is null, or `--root` stops here (crush_code.md Phase 0 item 6).',
            );
        }
    }

    public function testTheRootScrapeActuallyFoundTheKnownRootResolvingFiles(): void
    {
        // Guards the scrape above from silently passing on an empty or
        // mis-rooted file set.
        $found = array_keys(self::crushSourceFiles());

        foreach ([
            'Runtime.php',
            'Chat.php',
            'Cli/Bootstrap.php',
            'App/App.php',
            'Agents/TaskList.php',
            'bin/sugarcrush',
            self::ROOT_CAPTURE_EXEMPT,
        ] as $expected) {
            $this->assertContains($expected, $found);
        }
    }

    /**
     * A PreToolUse hook that records the `projectRoot` of every context it is
     * handed, and permits the call so the pipeline runs to completion.
     */
    private function recordingHook(): \SugarCraft\Crush\Hooks\HookInterface
    {
        return new class implements \SugarCraft\Crush\Hooks\HookInterface {
            /** @var list<string> */
            public array $roots = [];

            public function name(): string { return 'root-recorder'; }

            public function event(): \SugarCraft\Crush\Hooks\HookEvent
            {
                return \SugarCraft\Crush\Hooks\HookEvent::PreToolUse;
            }

            public function matcher(): string { return '.*'; }

            public function execute(\SugarCraft\Crush\Hooks\HookContext $context): \SugarCraft\Crush\Hooks\HookResult
            {
                $this->roots[] = $context->projectRoot;

                return \SugarCraft\Crush\Hooks\HookResult::allow();
            }
        };
    }

    /** A tool that does nothing but exist, so the gate has something to resolve. */
    private function noopTool(): \SugarCraft\Crush\Tools\Tool
    {
        return new class implements \SugarCraft\Crush\Tools\Tool {
            public function name(): string { return 'noop'; }

            public function description(): string { return 'Does nothing.'; }

            public function inputSchema(): array { return ['type' => 'object', 'properties' => []]; }

            public function execute(array $args): \SugarCraft\Crush\Tools\ToolResult
            {
                return \SugarCraft\Crush\Tools\ToolResult::success('noop', 'ok', 'call_noop_1');
            }
        };
    }

    /** Issues one `noop` tool call, then answers plainly. No network. */
    private function toolCallingProvider(): \SugarCraft\Crush\Providers\ProviderInterface
    {
        return new class implements \SugarCraft\Crush\Providers\ProviderInterface {
            private int $calls = 0;

            public function name(): string { return 'stub-root'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return true; }
            public function supportsVision(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 1000; }
            public function costPer1kTokens(string $m, string $d): float { return 0.0; }

            public function complete(\SugarCraft\Crush\Providers\CompleteRequest $r): \SugarCraft\Crush\Providers\CompleteResponse
            {
                $this->calls++;

                return $this->calls === 1
                    ? new \SugarCraft\Crush\Providers\CompleteResponse(
                        content: 'calling noop',
                        toolCalls: [new \SugarCraft\Crush\Tools\ToolCall('call_noop_1', 'noop', [])],
                    )
                    : new \SugarCraft\Crush\Providers\CompleteResponse(content: 'done');
            }

            public function completeStream(\SugarCraft\Crush\Providers\CompleteRequest $r): \Generator
            {
                yield new \SugarCraft\Crush\Providers\CompleteResponse(content: '');
            }

            public function embeddings(\SugarCraft\Crush\Providers\EmbeddingsRequest $r): \SugarCraft\Crush\Providers\EmbeddingsResponse
            {
                return new \SugarCraft\Crush\Providers\EmbeddingsResponse([]);
            }
        };
    }

    private function skillToolFromBootstrap(): \SugarCraft\Crush\Tools\BuiltIn\SkillTool
    {
        $tool = $this->toolsByClass()[\SugarCraft\Crush\Tools\BuiltIn\SkillTool::class] ?? null;
        $this->assertInstanceOf(\SugarCraft\Crush\Tools\BuiltIn\SkillTool::class, $tool);

        return $tool;
    }

    private function writeProjectSkill(string $name, string $description): void
    {
        $dir = $this->tempDir . '/repo/.sugar-crush/skills/' . $name;
        mkdir($dir, 0755, true);
        file_put_contents(
            $dir . '/SKILL.md',
            "---\ndescription: {$description}\nuser-invocable: true\ndisable-model-invocation: false\n---\n"
            . "# {$name}\n\nBIN INVOKE MARKER BODY\n",
        );
    }

    /**
     * @return array<class-string, object>
     */
    private function toolsByClass(): array
    {
        $byClass = [];
        foreach (Bootstrap::tools($this->tempDir . '/repo') as $tool) {
            $byClass[$tool::class] = $tool;
        }

        return $byClass;
    }

    private function privateValue(object $target, string $property): mixed
    {
        $ref = new \ReflectionProperty($target, $property);
        $ref->setAccessible(true);

        return $ref->getValue($target);
    }

    private function instructionLoaderOf(object $tool): ?InstructionFileLoader
    {
        $property = new \ReflectionProperty($tool, 'instructionLoader');
        $property->setAccessible(true);

        /** @var InstructionFileLoader|null $value */
        $value = $property->getValue($tool);

        return $value;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
