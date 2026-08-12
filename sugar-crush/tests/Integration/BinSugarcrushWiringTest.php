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
 */
final class BinSugarcrushWiringTest extends TestCase
{
    private string $tempDir;
    private string $originalHome;

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
    }

    protected function tearDown(): void
    {
        if ($this->originalHome !== '') {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
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
     * R20.fix regression (reviewer-reported): `Bootstrap::chat()` never
     * constructs/passes an `agentManager:` -- confirming that here in the
     * same test file that already exercises `Bootstrap::chat()` directly
     * documents the gap where a future reader will actually see it, rather
     * than only in a docblock. See `Renderer.php`'s "R20.fix" note.
     */
    public function testChatHasNoAgentManagerSinceBootstrapDoesNotConstructOne(): void
    {
        $chat = Bootstrap::chat($this->tempDir . '/repo');

        $this->assertNull($chat->agentManager());
    }

    /**
     * R20.fix regression: with the gap above in place, typing "/agents" (or
     * pressing Ctrl+A) against a real `Bootstrap::chat()`-constructed Chat
     * used to throw an uncaught `RuntimeException('AgentManager not set')`
     * straight out of `Chat::update()` -- candy-core's `Program` has no
     * try/catch around its synchronous update() dispatch, so this crashed
     * the live CLI outright (and skipped `teardownTerminal()`). It must now
     * degrade to a plain "not configured" response instead.
     */
    public function testAgentsCommandDoesNotCrashARealBootstrapConstructedChat(): void
    {
        $chat = Bootstrap::chat($this->tempDir . '/repo');
        $ref = new \ReflectionMethod($chat, 'withInputBuf');
        $ref->setAccessible(true);
        $chat = $ref->invoke($chat, '/agents');

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertStringContainsString('Agent manager not configured', $next->history[array_key_last($next->history)]->content);
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
