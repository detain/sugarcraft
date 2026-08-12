<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\SkillLoader;
use SugarCraft\Crush\Skills\SkillManager;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * W1.B3c (crush_feat.md section 6 recommendation #5): integration-level proof
 * that BOTH halves of the section-6 gap actually reach the model.
 *
 * `tests/RuntimeTest.php` (W1.B3a) covers the same two features by reflecting
 * into the private `Runtime::buildSystemPrompt()`. That proves the method
 * assembles the right string, not that any production caller ever receives it:
 * `loadRoot()`/`loadForced()` and `EnvironmentBlock` were each individually
 * correct-and-unit-tested *and* completely unreachable before this wave, which
 * is precisely the failure mode a reflection test cannot catch.
 *
 * So every assertion here reads the `CompleteRequest::$systemPrompt` a real
 * provider is handed, driven from a production entry point:
 * `EngineBackend::complete()` (what `Chat::submit()` calls every turn) and, in
 * the last test, `Chat::update(Enter)` itself, across the real
 * `completeAsync()` fork boundary. Construction mirrors `Bootstrap::backend()`
 * -- one shared `Bootstrap::instructionLoader($root)` threaded into both the
 * engine and `Bootstrap::tools()` -- with only the provider's HTTP layer
 * stubbed, the same seam `BinSugarcrushWiringTest` stubs.
 */
final class SystemPromptWiringTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sugarcrush_sysprompt_' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    /**
     * The headline gap: a repo-root AGENTS.md had zero effect on a session
     * unless the agent happened to touch a file in the root directory, because
     * `loadRoot()` had no caller. Asserted against the request the provider is
     * actually given.
     */
    public function testRootAgentsMdReachesTheProviderSystemPrompt(): void
    {
        file_put_contents($this->tempDir . '/AGENTS.md', 'ROOT AGENTS INTEGRATION MARKER');

        $provider = $this->completeOneTurn();

        $prompt = $this->soleSystemPrompt($provider);
        $this->assertStringContainsString('ROOT AGENTS INTEGRATION MARKER', $prompt);
        $this->assertStringContainsString('<project-instructions>', $prompt);
    }

    /**
     * Root CLAUDE.md is `loadRoot()`'s other half, and its `@import` expansion
     * has to have happened before the wire: an unexpanded `@./AGENTS.md` would
     * reach the model as literal dead text.
     */
    public function testRootClaudeMdArrivesWithItsAtImportsAlreadyExpanded(): void
    {
        file_put_contents($this->tempDir . '/CLAUDE.md', "# Root\n@./AGENTS.md\n");
        file_put_contents($this->tempDir . '/AGENTS.md', 'IMPORTED BODY INTEGRATION MARKER');

        $provider = $this->completeOneTurn();

        $prompt = $this->soleSystemPrompt($provider);
        $this->assertStringContainsString('IMPORTED BODY INTEGRATION MARKER', $prompt);
        $this->assertStringNotContainsString('@./AGENTS.md', $prompt);
        $this->assertSame(1, substr_count($prompt, 'IMPORTED BODY INTEGRATION MARKER'));
    }

    /**
     * The environment half: cwd, git flag, platform, PHP version, model and
     * date existed as `EnvironmentBlock` with no caller anywhere in `src/`, so
     * none of it reached the model at all.
     */
    public function testEnvironmentBlockReachesTheProviderSystemPrompt(): void
    {
        $provider = $this->completeOneTurn();

        $prompt = $this->soleSystemPrompt($provider);
        $this->assertStringContainsString('<env>', $prompt);
        $this->assertStringContainsString('</env>', $prompt);
        $this->assertStringContainsString('Working directory: ' . getcwd(), $prompt);
        $this->assertStringContainsString('Platform: ' . strtolower(PHP_OS_FAMILY), $prompt);
        $this->assertStringContainsString('PHP version: ' . PHP_VERSION, $prompt);
        $this->assertStringContainsString('Model: stub-sysprompt', $prompt);
        $this->assertStringContainsString('Current date: ' . date('Y-m-d'), $prompt);
    }

    /**
     * Both features must land in the SAME prompt, environment first: a model
     * has to know which directory it is in before it reads conventions phrased
     * relative to that directory. A wiring that let one half displace the other
     * would still pass both single-feature tests above.
     */
    public function testBothHalvesLandInOneSystemPromptWithEnvironmentFirst(): void
    {
        file_put_contents($this->tempDir . '/AGENTS.md', 'BOTH HALVES INTEGRATION MARKER');

        $provider = $this->completeOneTurn();

        $prompt = $this->soleSystemPrompt($provider);
        $this->assertStringContainsString('<env>', $prompt);
        $this->assertStringContainsString('BOTH HALVES INTEGRATION MARKER', $prompt);
        $this->assertLessThan(
            strpos($prompt, '<project-instructions>'),
            strpos($prompt, '<env>'),
        );
    }

    /**
     * `EngineBackend::complete()` runs a bounded agentic loop, calling
     * `Runtime::run()` once per step. The environment block documents itself as
     * a point-in-time snapshot and shells out to git three times to build one,
     * so every step of a turn must be handed a byte-identical prompt: a
     * per-step re-capture would burn subprocesses and let the reported date and
     * git state drift inside a single turn. Only a real multi-step loop can
     * demonstrate that -- a single `buildSystemPrompt()` call cannot.
     */
    public function testEveryStepOfOneTurnGetsTheIdenticalSystemPrompt(): void
    {
        file_put_contents($this->tempDir . '/AGENTS.md', 'MULTI STEP INTEGRATION MARKER');

        $provider = $this->completeOneTurn(toolCallOnFirstStep: true);

        $this->assertCount(2, $provider->requests, 'expected a tool-calling step followed by an answering step');
        $this->assertSame($provider->requests[0]->systemPrompt, $provider->requests[1]->systemPrompt);
        $this->assertStringContainsString('MULTI STEP INTEGRATION MARKER', (string) $provider->requests[1]->systemPrompt);
    }

    /**
     * Top of the production chain: a keystroke. `Chat::update(Enter)` ->
     * `Chat::submit()` -> `EngineBackend::completeAsync()` (pcntl_fork(), the
     * same boundary every live bin/sugarcrush turn crosses) ->
     * `Runtime::run()`. The stub provider echoes the system prompt back as its
     * answer because only the returned Message survives the fork -- state
     * recorded on a provider inside the child dies with the child.
     */
    public function testARealChatKeystrokeTurnDeliversBothHalves(): void
    {
        file_put_contents($this->tempDir . '/AGENTS.md', 'CHAT TURN INTEGRATION MARKER');

        $chat = new Chat(backend: $this->backend($this->echoingProvider()));

        $withInput = new \ReflectionMethod($chat, 'withInputBuf');
        $withInput->setAccessible(true);
        $chat = $withInput->invoke($chat, 'what are this project conventions?');

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

        $this->assertInstanceOf(\SugarCraft\Crush\AssistantMsg::class, $resolved, 'the completion did not finish within the test timeout');

        [$final] = $afterSubmit->update($resolved);
        $answer = $final->history[array_key_last($final->history)]->content;

        $this->assertStringContainsString('CHAT TURN INTEGRATION MARKER', $answer);
        $this->assertStringContainsString('<env>', $answer);
        $this->assertStringContainsString('Model: echo-sysprompt', $answer);
    }

    /**
     * W3.S8 (crush_feat.md section 7 E1/E2): a populated skill registry is
     * only half the wiring — the model needs the Level-1 listing in its
     * system prompt, or the `Skill` tool is one it has no reason to call.
     * Asserted on the request a provider is actually handed, for the same
     * reason as the tests above: `SkillMatcher` was unit-tested and had no
     * production caller at all before this step.
     */
    public function testDiscoveredSkillsAreListedInTheProviderSystemPrompt(): void
    {
        $registry = $this->registryWithProjectSkill('sysprompt-marker-skill', 'Marker skill for the system-prompt listing.');

        $provider = $this->capturingProvider(false);
        $this->backend($provider)->withSkillRegistry($registry)->complete([Message::user('hello')]);

        $prompt = $this->soleSystemPrompt($provider);
        $this->assertStringContainsString('Available skills (invoke via Skill tool):', $prompt);
        $this->assertStringContainsString(
            '- sysprompt-marker-skill: Marker skill for the system-prompt listing.',
            $prompt,
        );
    }

    /**
     * A session that discovered nothing must be byte-identical to before the
     * listing existed — an empty registry may not leave a dangling header.
     */
    public function testAnEmptyRegistryAddsNothingToTheSystemPrompt(): void
    {
        $provider = $this->completeOneTurn();

        $this->assertStringNotContainsString('Available skills', $this->soleSystemPrompt($provider));
    }

    /**
     * Discover one project-scoped SKILL.md written under this test's temp
     * root, the same way `Bootstrap::skillRegistry()` does (that method is
     * private, so the SkillManager pair it uses is constructed here).
     */
    private function registryWithProjectSkill(string $name, string $description): SkillRegistry
    {
        $dir = $this->tempDir . '/.sugar-crush/skills/' . $name;
        mkdir($dir, 0755, true);
        file_put_contents(
            $dir . '/SKILL.md',
            "---\ndescription: {$description}\nuser-invocable: true\ndisable-model-invocation: false\n---\n# {$name}\n\nBody.\n",
        );

        $registry = new SkillRegistry();
        (new SkillManager(new SkillLoader(), $registry))->loadAll($this->tempDir);

        return $registry;
    }

    /**
     * Drive one real `EngineBackend::complete()` turn against a capturing
     * provider and hand the provider back for assertions.
     */
    private function completeOneTurn(bool $toolCallOnFirstStep = false): object
    {
        $provider = $this->capturingProvider($toolCallOnFirstStep);

        $this->backend($provider)->complete([Message::user('hello')]);

        return $provider;
    }

    /**
     * Construct the backend the way `Bootstrap::backend()` does -- one shared
     * `InstructionFileLoader` threaded into both the engine and the
     * Read/Edit/Glob tools -- swapping only the provider.
     *
     * `Bootstrap::hooks()`/`::skillRegistry()` are private, so they cannot be
     * called from here; `EngineBackend` falls back to the equivalent defaults
     * (a `HookManager` with `registerBuiltIns()`, an empty `SkillRegistry`),
     * neither of which touches system-prompt assembly.
     */
    private function backend(ProviderInterface $provider): EngineBackend
    {
        $loader = Bootstrap::instructionLoader($this->tempDir);

        return (new EngineBackend($provider, $provider->name()))
            ->withTools(Bootstrap::tools($this->tempDir, $loader))
            ->withInstructionLoader($loader);
    }

    /**
     * Records every {@see CompleteRequest} the engine builds so the system
     * prompt can be asserted on exactly as the provider receives it.
     *
     * Non-streaming on purpose: `Runtime::run()` picks `runBatch()` over
     * `runStreaming()` from `supportsStreaming()`, and only `runBatch()`
     * hands back a single deterministic response per step.
     *
     * @param bool $toolCallOnFirstStep Emit an unresolvable tool call on the
     *        first step so `EngineBackend::complete()` feeds the error result
     *        back and takes a genuine second lap of its agentic loop.
     */
    private function capturingProvider(bool $toolCallOnFirstStep): object
    {
        return new class($toolCallOnFirstStep) implements ProviderInterface {
            /** @var list<CompleteRequest> */
            public array $requests = [];

            public function __construct(private readonly bool $toolCallOnFirstStep) {}

            public function name(): string
            {
                return 'stub-sysprompt';
            }

            public function supportsStreaming(): bool
            {
                return false;
            }

            public function supportsFunctionCalling(): bool
            {
                return true;
            }

            public function supportsVision(): bool
            {
                return false;
            }

            public function supportsJsonSchema(): bool
            {
                return false;
            }

            public function contextWindow(): int
            {
                return 1000;
            }

            public function costPer1kTokens(string $model, string $direction): float
            {
                return 0.0;
            }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                $this->requests[] = $request;

                return $this->toolCallOnFirstStep && count($this->requests) === 1
                    ? new CompleteResponse(
                        content: 'looking that up',
                        toolCalls: [new ToolCall('call_sysprompt_1', 'no_such_tool', [])],
                    )
                    : new CompleteResponse(content: 'answered');
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                yield new CompleteResponse(content: '');
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                return new EmbeddingsResponse([]);
            }
        };
    }

    /**
     * Answers with the system prompt it was given.
     *
     * `EngineBackend::completeAsync()` forks, so a provider that merely
     * recorded requests would record them in a child whose memory is thrown
     * away. Echoing the prompt into the response content routes it back over
     * the result socket as ordinary message text -- the only channel that
     * survives that boundary.
     */
    private function echoingProvider(): ProviderInterface
    {
        return new class implements ProviderInterface {
            public function name(): string
            {
                return 'echo-sysprompt';
            }

            public function supportsStreaming(): bool
            {
                return false;
            }

            public function supportsFunctionCalling(): bool
            {
                return true;
            }

            public function supportsVision(): bool
            {
                return false;
            }

            public function supportsJsonSchema(): bool
            {
                return false;
            }

            public function contextWindow(): int
            {
                return 1000;
            }

            public function costPer1kTokens(string $model, string $direction): float
            {
                return 0.0;
            }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                return new CompleteResponse(content: (string) $request->systemPrompt);
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                yield new CompleteResponse(content: '');
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                return new EmbeddingsResponse([]);
            }
        };
    }

    private function soleSystemPrompt(object $provider): string
    {
        $this->assertCount(1, $provider->requests, 'expected exactly one provider round-trip');

        $prompt = $provider->requests[0]->systemPrompt;
        $this->assertIsString($prompt);

        return $prompt;
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
