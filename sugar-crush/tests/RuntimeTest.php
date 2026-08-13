<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Context\EnvironmentBlock;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Permissions\PermissionReply;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\App\UserInputMsg;
use DateTimeImmutable;

/**
 * @see Runtime
 */
final class RuntimeTest extends TestCase
{
    private ProviderInterface $provider;
    private HookRegistry $hookRegistry;
    private HookManager $hookManager;
    private Runtime $runtime;

    /** @var list<string> */
    private array $tempRepos = [];

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('test-provider');

        $this->hookRegistry = new HookRegistry();
        $this->hookManager = new HookManager($this->hookRegistry);

        $this->runtime = new Runtime($this->provider, $this->hookManager);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempRepos as $dir) {
            $this->removeTree($dir);
        }
        $this->tempRepos = [];

        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }

        rmdir($dir);
    }

    // =========================================================================
    // run() Tests - Dispatches to streaming vs batch
    // =========================================================================

    public function testRunDispatchesToStreamingWhenSupported(): void
    {
        $this->provider->method('supportsStreaming')->willReturn(true);

        $streamResponse = new CompleteResponse(
            content: 'Hello',
            toolCalls: null,
            tokensUsed: 10,
        );

        $this->provider->method('completeStream')
            ->willReturnCallback(fn () => $this->streamOf([$streamResponse]));

        $app = App::new($this->provider, 'gpt-4');

        $results = iterator_to_array($this->runtime->run($app));

        $this->assertCount(1, $results);
        $this->assertInstanceOf(AssistantMessage::class, $results[0]);
        $this->assertSame('Hello', $results[0]->content());
    }

    public function testRunDispatchesToBatchWhenStreamingNotSupported(): void
    {
        $this->provider->method('supportsStreaming')->willReturn(false);

        $batchResponse = new CompleteResponse(
            content: 'Hello batch',
            toolCalls: null,
            tokensUsed: 15,
        );

        $this->provider->method('complete')
            ->willReturn($batchResponse);

        $app = App::new($this->provider, 'gpt-4');

        $results = iterator_to_array($this->runtime->run($app));

        $this->assertCount(1, $results);
        $this->assertInstanceOf(AssistantMessage::class, $results[0]);
        $this->assertSame('Hello batch', $results[0]->content());
    }

    // =========================================================================
    // runStreaming() Tests
    // =========================================================================

    public function testRunStreamingAccumulatesBuffer(): void
    {
        $this->provider->method('supportsStreaming')->willReturn(true);

        $responses = [
            new CompleteResponse(content: 'Hello ', toolCalls: null, tokensUsed: 0),
            new CompleteResponse(content: 'world!', toolCalls: null, tokensUsed: 0),
            new CompleteResponse(content: '', toolCalls: null, tokensUsed: 20),
        ];

        $this->provider->method('completeStream')
            ->willReturnCallback(fn () => $this->streamOf($responses));

        $app = App::new($this->provider, 'gpt-4');

        $results = iterator_to_array($this->runtime->run($app));

        $this->assertCount(1, $results);
        $this->assertInstanceOf(AssistantMessage::class, $results[0]);
        $this->assertSame('Hello world!', $results[0]->content());
    }

    public function testRunStreamingHandlesToolCalls(): void
    {
        $this->provider->method('supportsStreaming')->willReturn(true);

        $toolCall = new ToolCall('call_123', 'test_tool', ['arg' => 'value']);

        $responses = [
            new CompleteResponse(content: '', toolCalls: [$toolCall], tokensUsed: 0),
            new CompleteResponse(content: 'Tool result', toolCalls: null, tokensUsed: 25),
        ];

        $this->provider->method('completeStream')
            ->willReturnCallback(fn () => $this->streamOf($responses));

        // Mock tool that exists
        $tool = $this->createMockTool('test_tool', 'Tool executed successfully');

        $app = App::new($this->provider, 'gpt-4')->withTools([$tool]);

        $results = iterator_to_array($this->runtime->run($app));

        // Should have assistant message and tool result message
        $this->assertCount(2, $results);
        $this->assertInstanceOf(AssistantMessage::class, $results[0]);
        $this->assertInstanceOf(ToolResultMessage::class, $results[1]);
    }

    public function testRunStreamingYieldsEmptyWhenNoContent(): void
    {
        $this->provider->method('supportsStreaming')->willReturn(true);

        $responses = [
            new CompleteResponse(content: '', toolCalls: null, tokensUsed: 5),
        ];

        $this->provider->method('completeStream')
            ->willReturnCallback(fn () => $this->streamOf($responses));

        $app = App::new($this->provider, 'gpt-4');

        $results = iterator_to_array($this->runtime->run($app));

        $this->assertCount(1, $results);
        $this->assertInstanceOf(AssistantMessage::class, $results[0]);
        $this->assertSame('', $results[0]->content());
    }

    // =========================================================================
    // runBatch() Tests
    // =========================================================================

    public function testRunBatchReturnsCompleteResponse(): void
    {
        $this->provider->method('supportsStreaming')->willReturn(false);

        $batchResponse = new CompleteResponse(
            content: 'Batch response',
            reasoning: 'I thought about it',
            toolCalls: null,
            tokensUsed: 30,
        );

        $this->provider->method('complete')
            ->willReturn($batchResponse);

        $app = App::new($this->provider, 'gpt-4');

        $results = iterator_to_array($this->runtime->run($app));

        $this->assertCount(1, $results);
        $this->assertInstanceOf(AssistantMessage::class, $results[0]);
        $this->assertSame('Batch response', $results[0]->content());
        $this->assertSame('I thought about it', $results[0]->reasoning());
    }

    public function testRunBatchHandlesToolCalls(): void
    {
        $this->provider->method('supportsStreaming')->willReturn(false);

        $toolCall = new ToolCall('call_456', 'my_tool', []);

        $batchResponse = new CompleteResponse(
            content: 'Done',
            toolCalls: [$toolCall],
            tokensUsed: 20,
        );

        $this->provider->method('complete')
            ->willReturn($batchResponse);

        $tool = $this->createMockTool('my_tool', 'Result content');

        $app = App::new($this->provider, 'gpt-4')->withTools([$tool]);

        $results = iterator_to_array($this->runtime->run($app));

        $this->assertCount(2, $results);
        $this->assertInstanceOf(AssistantMessage::class, $results[0]);
        $this->assertInstanceOf(ToolResultMessage::class, $results[1]);
        $this->assertSame('call_456', $results[1]->toolCallId());
    }

    // =========================================================================
    // $onEvent tool-lifecycle plumbing (crush_feat.md §1 E1)
    // =========================================================================

    public function testRunEmitsToolStartedThenToolFinishedForEachToolCall(): void
    {
        $this->provider->method('supportsStreaming')->willReturn(false);

        $toolCall = new ToolCall('call_ev', 'ev_tool', ['arg' => 'value']);
        $this->provider->method('complete')
            ->willReturn(new CompleteResponse(content: 'calling', toolCalls: [$toolCall]));

        $app = App::new($this->provider, 'gpt-4')->withTools([$this->createMockTool('ev_tool', 'tool output')]);

        $events = [];
        iterator_to_array($this->runtime->run($app, function ($event) use (&$events): void {
            $events[] = $event;
        }));

        $this->assertCount(2, $events);
        $this->assertInstanceOf(ToolStarted::class, $events[0]);
        $this->assertSame('call_ev', $events[0]->toolCallId);
        $this->assertSame('ev_tool', $events[0]->toolName);
        $this->assertSame(['arg' => 'value'], $events[0]->arguments);

        $this->assertInstanceOf(ToolFinished::class, $events[1]);
        $this->assertSame('call_ev', $events[1]->toolCallId);
        $this->assertSame('ev_tool', $events[1]->toolName);
        $this->assertSame('tool output', $events[1]->result->content());
        $this->assertFalse($events[1]->result->isError());
    }

    public function testRunEmitsToolEventsOnTheStreamingPathToo(): void
    {
        $this->provider->method('supportsStreaming')->willReturn(true);

        $toolCall = new ToolCall('call_stream_ev', 'stream_tool', []);
        $this->provider->method('completeStream')->willReturnCallback(fn () => $this->streamOf([
            new CompleteResponse(content: 'thinking', toolCalls: [$toolCall]),
        ]));

        $app = App::new($this->provider, 'gpt-4')->withTools([$this->createMockTool('stream_tool', 'streamed')]);

        $events = [];
        iterator_to_array($this->runtime->run($app, function ($event) use (&$events): void {
            $events[] = $event;
        }));

        $this->assertSame(
            [ToolStarted::class, ToolFinished::class],
            array_map(static fn ($e) => $e::class, $events),
        );
        $this->assertSame('call_stream_ev', $events[1]->toolCallId);
    }

    /**
     * A tool never sees its own call id, so `createMockTool()` (like the real
     * built-ins) returns an invented one — the event must carry the id the
     * MODEL used, or a consumer cannot match a finish to its own placeholder.
     */
    public function testToolFinishedCarriesTheOriginalToolCallIdNotTheToolsOwn(): void
    {
        $toolCall = new ToolCall('call_original', 'id_tool', []);
        $app = App::new($this->provider, 'gpt-4')->withTools([$this->createMockTool('id_tool', 'out')]);

        $events = [];
        iterator_to_array($this->invokePrivateMethod($this->runtime, 'executeToolCalls', [
            [$toolCall],
            $app,
            function ($event) use (&$events): void { $events[] = $event; },
        ]));

        $this->assertSame('call_original', $events[1]->toolCallId);
        // The embedded result is handed over verbatim, so its own id is still
        // the tool's invented one - the EVENT's toolCallId is the correlation
        // key a consumer must key off, not result->toolCallId().
        $this->assertSame('call_id_tool', $events[1]->result->toolCallId());
    }

    public function testUnknownToolStillEmitsAStartAndAnErrorFinish(): void
    {
        $toolCall = new ToolCall('call_missing', 'nope', []);
        $app = App::new($this->provider, 'gpt-4');

        $events = [];
        iterator_to_array($this->invokePrivateMethod($this->runtime, 'executeToolCalls', [
            [$toolCall],
            $app,
            function ($event) use (&$events): void { $events[] = $event; },
        ]));

        $this->assertCount(2, $events);
        $this->assertInstanceOf(ToolStarted::class, $events[0]);
        $this->assertInstanceOf(ToolFinished::class, $events[1]);
        $this->assertTrue($events[1]->result->isError());
        $this->assertStringContainsString('Tool not found: nope', $events[1]->result->content());
    }

    public function testHookDenialEmitsAnErrorFinishWithTheDenialReason(): void
    {
        $tool = $this->createMockTool('denied_ev_tool', 'must not run');
        $this->hookRegistry->register(new class implements HookInterface {
            public function name(): string { return 'deny-ev'; }
            public function event(): HookEvent { return HookEvent::PreToolUse; }
            public function matcher(): string { return '.*'; }
            public function execute(HookContext $context): HookResult { return HookResult::deny('too risky'); }
        });

        $toolCall = new ToolCall('call_denied_ev', 'denied_ev_tool', []);
        $app = App::new($this->provider, 'gpt-4')->withTools([$tool]);

        $events = [];
        iterator_to_array($this->invokePrivateMethod($this->runtime, 'executeToolCalls', [
            [$toolCall],
            $app,
            function ($event) use (&$events): void { $events[] = $event; },
        ]));

        $this->assertCount(2, $events);
        $this->assertSame('call_denied_ev', $events[1]->toolCallId);
        $this->assertTrue($events[1]->result->isError());
        $this->assertStringContainsString('too risky', $events[1]->result->content());
    }

    /**
     * The renderer-side payloads (diff from W1.F1, image bytes from W1.G2) have
     * to ride on the event: re-deriving them downstream would mean scanning the
     * result's free text.
     */
    public function testToolFinishedCarriesTheWholeResultIncludingDiffAndImage(): void
    {
        $tool = $this->createMock(Tool::class);
        $tool->method('name')->willReturn('rich_tool');
        $tool->method('description')->willReturn('rich');
        $tool->method('inputSchema')->willReturn([]);
        $tool->method('execute')->willReturn(new ToolResult(
            toolCallId: 'ignored',
            content: 'File updated',
            isError: false,
            durationMs: 12,
            imageBytes: "\x89PNG\x00binary",
            imageProtocol: 'kitty',
            diff: "--- a/x.php\n+++ b/x.php\n",
        ));

        $toolCall = new ToolCall('call_rich', 'rich_tool', []);
        $app = App::new($this->provider, 'gpt-4')->withTools([$tool]);

        $events = [];
        iterator_to_array($this->invokePrivateMethod($this->runtime, 'executeToolCalls', [
            [$toolCall],
            $app,
            function ($event) use (&$events): void { $events[] = $event; },
        ]));

        $this->assertSame("--- a/x.php\n+++ b/x.php\n", $events[1]->result->diff());
        $this->assertSame("\x89PNG\x00binary", $events[1]->result->imageBytes());
        $this->assertSame('kitty', $events[1]->result->imageProtocol());
        $this->assertSame(12, $events[1]->result->durationMs());
    }

    public function testEveryToolCallInABatchGetsItsOwnStartFinishPair(): void
    {
        $app = App::new($this->provider, 'gpt-4')->withTools([
            $this->createMockTool('a_tool', 'A'),
            $this->createMockTool('b_tool', 'B'),
        ]);

        $events = [];
        iterator_to_array($this->invokePrivateMethod($this->runtime, 'executeToolCalls', [
            [new ToolCall('call_a', 'a_tool', []), new ToolCall('call_b', 'b_tool', [])],
            $app,
            function ($event) use (&$events): void { $events[] = $event; },
        ]));

        $this->assertSame(
            ['call_a', 'call_a', 'call_b', 'call_b'],
            array_map(static fn ($e) => $e->toolCallId, $events),
        );
        $this->assertSame(
            [ToolStarted::class, ToolFinished::class, ToolStarted::class, ToolFinished::class],
            array_map(static fn ($e) => $e::class, $events),
        );
    }

    public function testRunWithoutAnOnEventCallbackStillYieldsTheSameMessages(): void
    {
        $this->provider->method('supportsStreaming')->willReturn(false);
        $this->provider->method('complete')
            ->willReturn(new CompleteResponse(content: 'go', toolCalls: [new ToolCall('call_noev', 'noev_tool', [])]));

        $app = App::new($this->provider, 'gpt-4')->withTools([$this->createMockTool('noev_tool', 'silent')]);

        $results = iterator_to_array($this->runtime->run($app));

        $this->assertCount(2, $results);
        $this->assertInstanceOf(AssistantMessage::class, $results[0]);
        $this->assertInstanceOf(ToolResultMessage::class, $results[1]);
        $this->assertSame('silent', $results[1]->content());
    }

    // =========================================================================
    // executeToolCalls() Tests
    // =========================================================================

    public function testExecuteToolCallsYieldsErrorWhenToolNotFound(): void
    {
        $toolCall = new ToolCall('call_789', 'nonexistent_tool', []);

        $app = App::new($this->provider, 'gpt-4'); // No tools registered

        // Access private method via reflection
        $results = $this->invokePrivateMethod($this->runtime, 'executeToolCalls', [[$toolCall], $app]);

        $results = iterator_to_array($results);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(ToolResultMessage::class, $results[0]);
        $this->assertSame('call_789', $results[0]->toolCallId());
        $this->assertStringContainsString('Tool not found', $results[0]->content());
        $this->assertTrue($results[0]->isError());
    }

    public function testExecuteToolCallsYieldsErrorWhenHookDenies(): void
    {
        $tool = $this->createMockTool('denied_tool', 'Should not execute');

        $toolCall = new ToolCall('call_deny', 'denied_tool', []);

        // Register a denying hook
        $this->hookRegistry->register(new class implements HookInterface {
            public function name(): string { return 'deny_all'; }
            public function event(): HookEvent { return HookEvent::PreToolUse; }
            public function matcher(): string { return '.*'; }
            public function execute(HookContext $context): HookResult {
                return HookResult::deny('Hook denied this tool');
            }
        });

        $app = App::new($this->provider, 'gpt-4')->withTools([$tool]);

        $results = $this->invokePrivateMethod($this->runtime, 'executeToolCalls', [[$toolCall], $app]);

        $results = iterator_to_array($results);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(ToolResultMessage::class, $results[0]);
        $this->assertSame('call_deny', $results[0]->toolCallId());
        $this->assertStringContainsString('Hook denied', $results[0]->content());
        $this->assertTrue($results[0]->isError());
    }

    public function testExecuteToolCallsUsesModifiedInputFromHook(): void
    {
        $tool = $this->createMockTool('modifier_tool', 'Executed');

        $toolCall = new ToolCall('call_mod', 'modifier_tool', ['original' => 'value']);

        // Register a modifying hook
        $this->hookRegistry->register(new class implements HookInterface {
            public function name(): string { return 'modify_input'; }
            public function event(): HookEvent { return HookEvent::PreToolUse; }
            public function matcher(): string { return '.*'; }
            public function execute(HookContext $context): HookResult {
                return HookResult::modify('{"modified":"value"}', 'Input modified by hook');
            }
        });

        $app = App::new($this->provider, 'gpt-4')->withTools([$tool]);

        $results = $this->invokePrivateMethod($this->runtime, 'executeToolCalls', [[$toolCall], $app]);

        $results = iterator_to_array($results);

        $this->assertCount(1, $results);
        // The tool should have been called with modified input
        $this->assertSame('call_mod', $results[0]->toolCallId());
    }

    public function testExecuteToolCallsExecutesToolAndReturnsResult(): void
    {
        $tool = $this->createMockTool('exec_tool', 'Executed successfully');

        $toolCall = new ToolCall('call_exec', 'exec_tool', ['param' => 'test']);

        $app = App::new($this->provider, 'gpt-4')->withTools([$tool]);

        $results = $this->invokePrivateMethod($this->runtime, 'executeToolCalls', [[$toolCall], $app]);

        $results = iterator_to_array($results);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(ToolResultMessage::class, $results[0]);
        $this->assertSame('call_exec', $results[0]->toolCallId());
        $this->assertSame('Executed successfully', $results[0]->content());
        $this->assertFalse($results[0]->isError());
    }

    /**
     * W1.G2 reachability fix: an image-bearing Tools\ToolResult (e.g.
     * Doctor's capability swatch) must survive executeToolCalls() onto the
     * yielded ToolResultMessage instead of being silently dropped.
     */
    public function testExecuteToolCallsThreadsImageFieldsOntoToolResultMessage(): void
    {
        $tool = $this->createMock(Tool::class);
        $tool->method('name')->willReturn('doctor');
        $tool->method('description')->willReturn('doctor');
        $tool->method('inputSchema')->willReturn([]);
        $tool->method('execute')->willReturn(new ToolResult(
            toolCallId: 'call_doctor',
            content: 'Detected kitty',
            imageBytes: "\x89PNGfake",
            imageProtocol: 'kitty',
        ));

        $toolCall = new ToolCall('call_doctor', 'doctor', []);
        $app = App::new($this->provider, 'gpt-4')->withTools([$tool]);

        $results = iterator_to_array($this->invokePrivateMethod($this->runtime, 'executeToolCalls', [[$toolCall], $app]));

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->hasImage());
        $this->assertSame("\x89PNGfake", $results[0]->imageBytes());
        $this->assertSame('kitty', $results[0]->imageProtocol());
    }

    // =========================================================================
    // A throwing tool must cost its own call, not the whole turn
    // =========================================================================

    /**
     * Before the fix `$tool->execute()` ran bare. A \Throwable escaping a tool
     * propagated out of this generator, through Runtime::run(), and was only
     * stopped at EngineBackend::runCompleteInChild()'s outer boundary — which
     * reports a TURN-level failure, discarding every other tool result and all
     * assistant content already produced.
     *
     * Reproducible trigger in the wild: a model supplying a non-string
     * `command` to Bash, raising `TypeError: escapeshellarg(): Argument #1
     * ($arg) must be of type string, array given`.
     */
    public function testAThrowingToolDegradesToAnErrorResultInsteadOfEscaping(): void
    {
        $toolCall = new ToolCall('call_boom', 'boom_tool', ['command' => ['not', 'a', 'string']]);
        $app = App::new($this->provider, 'gpt-4')->withTools([
            $this->createThrowingTool('boom_tool', new \TypeError('escapeshellarg(): Argument #1 ($arg) must be of type string, array given')),
        ]);

        $results = iterator_to_array($this->invokePrivateMethod($this->runtime, 'executeToolCalls', [[$toolCall], $app]));

        $this->assertCount(1, $results);
        $this->assertInstanceOf(ToolResultMessage::class, $results[0]);
        $this->assertSame('call_boom', $results[0]->toolCallId());
        $this->assertTrue($results[0]->isError());
        $this->assertStringContainsString('escapeshellarg()', $results[0]->content());
        $this->assertStringContainsString('TypeError', $results[0]->content());
    }

    /**
     * The whole point of containing the throw: the REST of the batch has to
     * keep running. Against the old code the generator died on the first call
     * and the second tool never executed at all.
     */
    public function testATooThrowingMidBatchDoesNotStopLaterToolCalls(): void
    {
        $app = App::new($this->provider, 'gpt-4')->withTools([
            $this->createMockTool('first_tool', 'first ok'),
            $this->createThrowingTool('boom_tool', new \RuntimeException('exploded')),
            $this->createMockTool('third_tool', 'third ok'),
        ]);

        $results = iterator_to_array($this->invokePrivateMethod($this->runtime, 'executeToolCalls', [[
            new ToolCall('call_1', 'first_tool', []),
            new ToolCall('call_2', 'boom_tool', []),
            new ToolCall('call_3', 'third_tool', []),
        ], $app]));

        $this->assertCount(3, $results);
        $this->assertSame(['call_1', 'call_2', 'call_3'], array_map(static fn ($r) => $r->toolCallId(), $results));
        $this->assertSame([false, true, false], array_map(static fn ($r) => $r->isError(), $results));
        $this->assertSame('first ok', $results[0]->content());
        $this->assertStringContainsString('exploded', $results[1]->content());
        $this->assertSame('third ok', $results[2]->content());
    }

    /**
     * A contained throw is still a finished tool call, so the running→done
     * transition a renderer draws must complete rather than hang forever on
     * the ToolStarted it already painted.
     */
    public function testAThrowingToolStillEmitsItsToolFinishedEvent(): void
    {
        $app = App::new($this->provider, 'gpt-4')->withTools([
            $this->createThrowingTool('boom_tool', new \RuntimeException('exploded')),
        ]);

        $events = [];
        iterator_to_array($this->invokePrivateMethod($this->runtime, 'executeToolCalls', [
            [new ToolCall('call_boom', 'boom_tool', [])],
            $app,
            function ($event) use (&$events): void { $events[] = $event; },
        ]));

        $this->assertSame(
            [ToolStarted::class, ToolFinished::class],
            array_map(static fn ($e) => $e::class, $events),
        );
        $this->assertTrue($events[1]->result->isError());
        $this->assertStringContainsString('exploded', $events[1]->result->content());
    }

    /** A throw anywhere in the batch must not fail the whole run() either. */
    public function testRunSurvivesAThrowingToolAndKeepsTheAssistantContent(): void
    {
        $this->provider->method('supportsStreaming')->willReturn(false);
        $this->provider->method('complete')->willReturn(new CompleteResponse(
            content: 'assistant text that must survive',
            toolCalls: [new ToolCall('call_boom', 'boom_tool', [])],
        ));

        $app = App::new($this->provider, 'gpt-4')->withTools([
            $this->createThrowingTool('boom_tool', new \RuntimeException('exploded')),
        ]);

        $results = iterator_to_array($this->runtime->run($app));

        $this->assertCount(2, $results);
        $this->assertSame('assistant text that must survive', $results[0]->content());
        $this->assertTrue($results[1]->isError());
    }

    // =========================================================================
    // ...and neither must a throwing PostToolUse hook
    // =========================================================================

    /**
     * Containing only `$tool->execute()` left the turn just as easy to lose:
     * HookRegistry::executeHooks() calls `$hook->execute($context)` bare, so a
     * ScriptHook whose script is missing (or any PHP hook with a bug) threw
     * straight out of this generator and hit the same
     * EngineBackend::runCompleteInChild() boundary — discarding every other
     * tool result and all assistant content, because an OBSERVER failed after
     * the work was already done.
     */
    public function testAThrowingPostToolUseHookDoesNotCostTheToolResult(): void
    {
        $this->hookRegistry->register($this->throwingPostHook(new \RuntimeException('hook script missing')));

        $app = App::new($this->provider, 'gpt-4')->withTools([
            $this->createMockTool('good_tool', 'the real answer'),
        ]);

        $results = iterator_to_array($this->invokePrivateMethod($this->runtime, 'executeToolCalls', [
            [new ToolCall('call_hook', 'good_tool', [])],
            $app,
        ]));

        $this->assertCount(1, $results);
        $this->assertSame('call_hook', $results[0]->toolCallId());
        $this->assertStringContainsString('the real answer', $results[0]->content());
        $this->assertStringContainsString('PostToolUse hook failed', $results[0]->content());
        $this->assertStringContainsString('hook script missing', $results[0]->content());
        $this->assertFalse(
            $results[0]->isError(),
            'the tool succeeded — a broken observer must not tell the model to retry it',
        );
    }

    /** The rest of the batch has to survive a throwing post-hook too. */
    public function testAThrowingPostToolUseHookDoesNotStopLaterToolCalls(): void
    {
        $this->hookRegistry->register($this->throwingPostHook(new \LogicException('boom in hook')));

        $app = App::new($this->provider, 'gpt-4')->withTools([
            $this->createMockTool('first_tool', 'first ok'),
            $this->createMockTool('second_tool', 'second ok'),
        ]);

        $results = iterator_to_array($this->invokePrivateMethod($this->runtime, 'executeToolCalls', [[
            new ToolCall('call_1', 'first_tool', []),
            new ToolCall('call_2', 'second_tool', []),
        ], $app]));

        $this->assertCount(2, $results);
        $this->assertStringContainsString('first ok', $results[0]->content());
        $this->assertStringContainsString('second ok', $results[1]->content());
    }

    /** run() must not lose the assistant content either. */
    public function testRunSurvivesAThrowingPostToolUseHook(): void
    {
        $this->hookRegistry->register($this->throwingPostHook(new \RuntimeException('boom in hook')));
        $this->provider->method('supportsStreaming')->willReturn(false);
        $this->provider->method('complete')->willReturn(new CompleteResponse(
            content: 'assistant text that must survive',
            toolCalls: [new ToolCall('call_hook', 'good_tool', [])],
        ));

        $app = App::new($this->provider, 'gpt-4')->withTools([
            $this->createMockTool('good_tool', 'the real answer'),
        ]);

        $results = iterator_to_array($this->runtime->run($app));

        $this->assertCount(2, $results);
        $this->assertSame('assistant text that must survive', $results[0]->content());
        $this->assertStringContainsString('the real answer', $results[1]->content());
    }

    /**
     * The hook note has to reach the ToolFinished event as well, or a renderer
     * shows a clean result while the model is told a hook fell over.
     */
    public function testAThrowingPostToolUseHookIsAnnotatedOntoTheEventToo(): void
    {
        $this->hookRegistry->register($this->throwingPostHook(new \RuntimeException('boom in hook')));

        $app = App::new($this->provider, 'gpt-4')->withTools([
            $this->createMockTool('good_tool', 'the real answer'),
        ]);

        $events = [];
        iterator_to_array($this->invokePrivateMethod($this->runtime, 'executeToolCalls', [
            [new ToolCall('call_hook', 'good_tool', [])],
            $app,
            function ($event) use (&$events): void { $events[] = $event; },
        ]));

        $this->assertSame(
            [ToolStarted::class, ToolFinished::class],
            array_map(static fn ($e) => $e::class, $events),
        );
        $this->assertStringContainsString('PostToolUse hook failed', $events[1]->result->content());
    }

    /**
     * A throwing listener is a UI bug. It must not take the turn's other tool
     * results down with it, and the model still needs this result whether or
     * not anything managed to render it.
     */
    public function testAThrowingToolFinishedListenerStillDeliversTheResult(): void
    {
        $app = App::new($this->provider, 'gpt-4')->withTools([
            $this->createMockTool('good_tool', 'the real answer'),
            $this->createMockTool('other_tool', 'also fine'),
        ]);

        $results = iterator_to_array($this->invokePrivateMethod($this->runtime, 'executeToolCalls', [
            [new ToolCall('call_1', 'good_tool', []), new ToolCall('call_2', 'other_tool', [])],
            $app,
            static function ($event): void {
                if ($event instanceof ToolFinished) {
                    throw new \RuntimeException('renderer exploded');
                }
            },
        ]));

        $this->assertCount(2, $results);
        $this->assertStringContainsString('the real answer', $results[0]->content());
        $this->assertStringContainsString('ToolFinished listener failed', $results[0]->content());
        $this->assertStringContainsString('also fine', $results[1]->content());
    }

    /** A tool that ALSO threw keeps its own error, with the hook note added. */
    public function testAThrowingToolAndAThrowingHookBothSurfaceOnTheSameResult(): void
    {
        $this->hookRegistry->register($this->throwingPostHook(new \RuntimeException('boom in hook')));

        $app = App::new($this->provider, 'gpt-4')->withTools([
            $this->createThrowingTool('boom_tool', new \RuntimeException('exploded')),
        ]);

        $results = iterator_to_array($this->invokePrivateMethod($this->runtime, 'executeToolCalls', [
            [new ToolCall('call_boom', 'boom_tool', [])],
            $app,
        ]));

        $this->assertTrue($results[0]->isError(), 'the tool failure keeps the error flag');
        $this->assertStringContainsString('exploded', $results[0]->content());
        $this->assertStringContainsString('PostToolUse hook failed', $results[0]->content());
    }

    /**
     * `Runtime::annotate()` rebuilds a readonly {@see ToolResult} by copying
     * every constructor argument across by name. That is correct today and
     * unpinned: adding a ninth field to ToolResult drops it silently, and
     * only on the hook-failure path — the one path nobody exercises by hand.
     *
     * Reflecting over the real constructor is what makes it a tripwire. A new
     * parameter fails this test at the array_diff, whoever adds it has to
     * extend the map below, and the round-trip assertions then fail until
     * annotate() copies it too.
     */
    public function testAnnotateCopiesEveryToolResultConstructorField(): void
    {
        /** @var array<string, mixed> $fields ctor parameter name => a distinctive non-default value */
        $fields = [
            'toolCallId' => 'call_annotated',
            'content' => 'the real answer',
            'isError' => true,
            'durationMs' => 4242,
            'imageBytes' => 'RAW-PNG-BYTES',
            'imagePath' => '/tmp/screenshot.png',
            'imageProtocol' => 'kitty',
            'diff' => "--- a/x.php\n+++ b/x.php\n@@ -1 +1 @@\n-old\n+new\n",
        ];

        $constructor = (new \ReflectionClass(ToolResult::class))->getConstructor();
        $this->assertNotNull($constructor);
        $declared = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $constructor->getParameters(),
        );

        $this->assertSame(
            [],
            array_values(array_diff($declared, array_keys($fields))),
            'ToolResult grew a constructor field. Runtime::annotate() is a manual named-argument '
            . 'copy, so the new field is being dropped on the hook-failure path — add it to '
            . 'annotate() and to this map.',
        );

        $original = new ToolResult(...$fields);
        $annotated = $this->invokePrivateMethod($this->runtime, 'annotate', [$original, '[note]']);

        $this->assertInstanceOf(ToolResult::class, $annotated);
        $this->assertSame("the real answer\n\n[note]", $annotated->content());

        // Every field except the one the note is appended to survives.
        unset($fields['content']);
        foreach ($fields as $accessor => $expected) {
            $this->assertSame($expected, $annotated->{$accessor}(), $accessor);
        }
    }

    /** An empty content must not gain a leading blank-line pair. */
    public function testAnnotateOnAnEmptyResultIsJustTheNote(): void
    {
        $annotated = $this->invokePrivateMethod(
            $this->runtime,
            'annotate',
            [new ToolResult('call_empty', ''), '[note]'],
        );

        $this->assertSame('[note]', $annotated->content());
    }

    /**
     * The end-to-end version: a diff- and image-bearing result annotated by a
     * real failing PostToolUse hook still reaches the renderer with its
     * payloads. Those fields are what EngineBackend draws, so losing them
     * turns a rendered diff into plain text.
     */
    public function testADiffAndImageBearingResultSurvivesAFailingPostToolUseHook(): void
    {
        $this->hookRegistry->register($this->throwingPostHook(new \RuntimeException('hook script missing')));

        $rich = $this->createMock(Tool::class);
        $rich->method('name')->willReturn('rich_tool');
        $rich->method('description')->willReturn('rich');
        $rich->method('inputSchema')->willReturn([]);
        $rich->method('execute')->willReturn(new ToolResult(
            toolCallId: 'call_rich',
            content: 'edited x.php',
            isError: false,
            durationMs: 17,
            imageBytes: 'RAW-PNG-BYTES',
            imagePath: '/tmp/shot.png',
            imageProtocol: 'kitty',
            diff: "--- a/x.php\n+++ b/x.php\n",
        ));

        $app = App::new($this->provider, 'gpt-4')->withTools([$rich]);

        $events = [];
        iterator_to_array($this->invokePrivateMethod($this->runtime, 'executeToolCalls', [
            [new ToolCall('call_rich', 'rich_tool', [])],
            $app,
            function ($event) use (&$events): void { $events[] = $event; },
        ]));

        $delivered = $events[1]->result;
        $this->assertStringContainsString('PostToolUse hook failed', $delivered->content());
        $this->assertStringContainsString('edited x.php', $delivered->content());
        $this->assertTrue($delivered->hasDiff());
        $this->assertSame("--- a/x.php\n+++ b/x.php\n", $delivered->diff());
        $this->assertTrue($delivered->hasImage());
        $this->assertSame('RAW-PNG-BYTES', $delivered->imageBytes());
        $this->assertSame('/tmp/shot.png', $delivered->imagePath());
        $this->assertSame('kitty', $delivered->imageProtocol());
        $this->assertSame(17, $delivered->durationMs());
        $this->assertFalse($delivered->isError(), 'a broken observer must not flag the tool as failed');
    }

    private function throwingPostHook(\Throwable $throwable): HookInterface
    {
        return new class ($throwable) implements HookInterface {
            public function __construct(private \Throwable $throwable) {}
            public function name(): string { return 'throwing-post'; }
            public function event(): HookEvent { return HookEvent::PostToolUse; }
            public function matcher(): string { return '.*'; }
            public function execute(HookContext $context): HookResult { throw $this->throwable; }
        };
    }

    public function testExecuteToolCallsHandlesMultipleToolCalls(): void
    {
        $tool1 = $this->createMockTool('tool_one', 'Result 1');
        $tool2 = $this->createMockTool('tool_two', 'Result 2');

        $toolCall1 = new ToolCall('call_1', 'tool_one', []);
        $toolCall2 = new ToolCall('call_2', 'tool_two', []);

        $app = App::new($this->provider, 'gpt-4')->withTools([$tool1, $tool2]);

        $results = $this->invokePrivateMethod($this->runtime, 'executeToolCalls', [[$toolCall1, $toolCall2], $app]);

        $results = iterator_to_array($results);

        $this->assertCount(2, $results);
        $this->assertSame('call_1', $results[0]->toolCallId());
        $this->assertSame('Result 1', $results[0]->content());
        $this->assertSame('call_2', $results[1]->toolCallId());
        $this->assertSame('Result 2', $results[1]->content());
    }

    public function testExecuteToolCallsMeasuresDuration(): void
    {
        $tool = $this->createMockTool('slow_tool', 'Done', delayMs: 10);

        $toolCall = new ToolCall('call_dur', 'slow_tool', []);

        $app = App::new($this->provider, 'gpt-4')->withTools([$tool]);

        $results = $this->invokePrivateMethod($this->runtime, 'executeToolCalls', [[$toolCall], $app]);

        $results = iterator_to_array($results);

        $this->assertCount(1, $results);
        // Duration should be measured (at least 10ms for our mock tool)
        $this->assertSame('call_dur', $results[0]->toolCallId());
    }

    // =========================================================================
    // findTool() Tests
    // =========================================================================

    public function testFindToolReturnsToolWhenFound(): void
    {
        $tool = $this->createMockTool('findable', 'Found');

        $app = App::new($this->provider, 'gpt-4')->withTools([$tool]);

        $result = $this->invokePrivateMethod($this->runtime, 'findTool', ['findable', $app]);

        $this->assertNotNull($result);
        $this->assertSame('findable', $result->name());
    }

    public function testFindToolReturnsNullWhenNotFound(): void
    {
        $tool = $this->createMockTool('other_tool', 'Other');

        $app = App::new($this->provider, 'gpt-4')->withTools([$tool]);

        $result = $this->invokePrivateMethod($this->runtime, 'findTool', ['nonexistent', $app]);

        $this->assertNull($result);
    }

    public function testFindToolReturnsNullWhenNoTools(): void
    {
        $app = App::new($this->provider, 'gpt-4'); // No tools

        $result = $this->invokePrivateMethod($this->runtime, 'findTool', ['any_tool', $app]);

        $this->assertNull($result);
    }

    // =========================================================================
    // buildMessages() Tests
    // =========================================================================

    public function testBuildMessagesFiltersMessages(): void
    {
        $msg1 = new UserMessage('Hello');
        $msg2 = new AssistantMessage('Hi there');
        $nonMessage = 'not a message';

        $app = App::new($this->provider, 'gpt-4')
            ->withMessages([$msg1, $msg2, $nonMessage]);

        $result = $this->invokePrivateMethod($this->runtime, 'buildMessages', [$app]);

        $this->assertCount(2, $result);
        $this->assertSame($msg1, $result[0]);
        $this->assertSame($msg2, $result[1]);
    }

    public function testBuildMessagesReturnsEmptyArrayWhenNoMessages(): void
    {
        $app = App::new($this->provider, 'gpt-4');

        $result = $this->invokePrivateMethod($this->runtime, 'buildMessages', [$app]);

        $this->assertSame([], $result);
    }

    public function testBuildMessagesFiltersNonMessageInstances(): void
    {
        $msg = new UserMessage('Valid');
        $invalid = new \stdClass();

        $app = App::new($this->provider, 'gpt-4')
            ->withMessages([$msg, $invalid]);

        $result = $this->invokePrivateMethod($this->runtime, 'buildMessages', [$app]);

        $this->assertCount(1, $result);
        $this->assertSame($msg, $result[0]);
    }

    // =========================================================================
    // buildSystemPrompt() Tests
    // =========================================================================

    public function testBuildSystemPromptReturnsBasePrompt(): void
    {
        $app = App::new($this->provider, 'gpt-4');

        $result = $this->invokePrivateMethod($this->runtime, 'buildSystemPrompt', [$app]);

        $this->assertStringContainsString('SugarCrush', $result);
        $this->assertStringContainsString('AI coding assistant', $result);
    }

    public function testBuildSystemPromptIncludesSkillContributions(): void
    {
        $skill = new Skill(
            name: 'TestSkill',
            description: 'A test skill',
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: 'low',
            context: 'thread',
            paths: [],
            content: 'Skill content here',
            sourcePath: '/test/SKILL.md',
        );

        $app = App::new($this->provider, 'gpt-4')
            ->withEnabledSkills([$skill]);

        $result = $this->invokePrivateMethod($this->runtime, 'buildSystemPrompt', [$app]);

        $this->assertStringContainsString('SugarCrush', $result);
        $this->assertStringContainsString('## Skill: TestSkill', $result);
        $this->assertStringContainsString('Skill content here', $result);
    }

    public function testBuildSystemPromptIgnoresNonSkillEnabledSkills(): void
    {
        $nonSkill = 'not a skill object';

        $app = App::new($this->provider, 'gpt-4')
            ->withEnabledSkills([$nonSkill]);

        $result = $this->invokePrivateMethod($this->runtime, 'buildSystemPrompt', [$app]);

        $this->assertStringContainsString('SugarCrush', $result);
        $this->assertStringNotContainsString('## Skill', $result);
    }

    public function testBuildSystemPromptWithMultipleSkills(): void
    {
        $skill1 = new Skill(
            name: 'SkillOne',
            description: 'First skill',
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: 'low',
            context: 'thread',
            paths: [],
            content: 'Content one',
            sourcePath: '/test/SKILL.md',
        );

        $skill2 = new Skill(
            name: 'SkillTwo',
            description: 'Second skill',
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: 'medium',
            context: 'thread',
            paths: [],
            content: 'Content two',
            sourcePath: '/test/SKILL.md',
        );

        $app = App::new($this->provider, 'gpt-4')
            ->withEnabledSkills([$skill1, $skill2]);

        $result = $this->invokePrivateMethod($this->runtime, 'buildSystemPrompt', [$app]);

        $this->assertStringContainsString('## Skill: SkillOne', $result);
        $this->assertStringContainsString('Content one', $result);
        $this->assertStringContainsString('## Skill: SkillTwo', $result);
        $this->assertStringContainsString('Content two', $result);
    }

    // =========================================================================
    // Integration Tests
    // =========================================================================

    public function testFullRunWithStreamingAndToolCall(): void
    {
        $this->provider->method('supportsStreaming')->willReturn(true);

        $toolCall = new ToolCall('call_full', 'integrated_tool', ['input' => 'test']);
        $tool = $this->createMockTool('integrated_tool', 'Integrated result');

        $responses = [
            new CompleteResponse(content: 'Thinking...', toolCalls: [$toolCall], tokensUsed: 0),
            new CompleteResponse(content: 'Done', toolCalls: null, tokensUsed: 50),
        ];

        $this->provider->method('completeStream')
            ->willReturnCallback(fn () => $this->streamOf($responses));

        $app = App::new($this->provider, 'gpt-4')->withTools([$tool]);

        $results = iterator_to_array($this->runtime->run($app));

        $this->assertCount(2, $results);
        $this->assertInstanceOf(AssistantMessage::class, $results[0]);
        $this->assertInstanceOf(ToolResultMessage::class, $results[1]);
        $this->assertSame('call_full', $results[1]->toolCallId());
    }

    public function testFullRunWithBatchOnlyNoTools(): void
    {
        $this->provider->method('supportsStreaming')->willReturn(false);

        $this->provider->method('complete')
            ->willReturn(new CompleteResponse(
                content: 'Simple response',
                toolCalls: null,
                tokensUsed: 25,
            ));

        $app = App::new($this->provider, 'gpt-4');

        $results = iterator_to_array($this->runtime->run($app));

        $this->assertCount(1, $results);
        $this->assertInstanceOf(AssistantMessage::class, $results[0]);
        $this->assertSame('Simple response', $results[0]->content());
    }

    // =========================================================================
    // shouldPromptIdleCompaction() Tests
    // =========================================================================

    public function testShouldPromptIdleCompactionReturnsFalseWhenTokensBelowThreshold(): void
    {
        // App with recent activity but token count below 100K
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test');

        $app = App::new($provider, 'test-model')
            ->withLastActivity(new DateTimeImmutable('1 hour ago'));

        $result = $this->runtime->shouldPromptIdleCompaction($app, 50000);

        $this->assertFalse($result);
    }

    public function testShouldPromptIdleCompactionReturnsFalseWhenRecentlyActive(): void
    {
        // App with recent activity and high token count
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test');

        $app = App::new($provider, 'test-model')
            ->withLastActivity(new DateTimeImmutable('30 minutes ago'));

        $result = $this->runtime->shouldPromptIdleCompaction($app, 150000);

        $this->assertFalse($result);
    }

    public function testShouldPromptIdleCompactionReturnsTrueWhenIdleAndLarge(): void
    {
        // App with idle time > 1 hour and token count > 100K
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test');

        $app = App::new($provider, 'test-model')
            ->withLastActivity(new DateTimeImmutable('2 hours ago'));

        $result = $this->runtime->shouldPromptIdleCompaction($app, 150000);

        $this->assertTrue($result);
    }

    public function testShouldPromptIdleCompactionReturnsFalseWhenNoLastActivity(): void
    {
        // App with no lastActivityAt set
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test');

        $app = App::new($provider, 'test-model');

        // Even with high token count, should return false if we don't know idle time
        $result = $this->runtime->shouldPromptIdleCompaction($app, 150000);

        $this->assertFalse($result);
    }

    public function testShouldPromptIdleCompactionBoundaryAtExactlyOneHour(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test');

        // Exactly 3600 seconds ago (1 hour) - should be false (not MORE than 3600)
        $app = App::new($provider, 'test-model')
            ->withLastActivity(new DateTimeImmutable('3600 seconds ago'));

        $result = $this->runtime->shouldPromptIdleCompaction($app, 150000);

        $this->assertFalse($result);
    }

    public function testShouldPromptIdleCompactionBoundaryAtExactly100KTokens(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test');

        // Exactly 100000 tokens - should be false (not MORE than 100000)
        $app = App::new($provider, 'test-model')
            ->withLastActivity(new DateTimeImmutable('2 hours ago'));

        $result = $this->runtime->shouldPromptIdleCompaction($app, 100000);

        $this->assertFalse($result);
    }

    /**
     * App::$lastActivityAt was previously only ever set via withLastActivity()
     * from test code (see the tests above) - no real code path updated it on
     * an actual user prompt, so shouldPromptIdleCompaction() would see
     * lastActivityAt === null forever in production and never fire. A real
     * UserInputMsg dispatch through App::update() must now record activity.
     */
    public function testRealUserInputMsgUpdatesLastActivityAt(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test');

        $app = App::new($provider, 'test-model');
        $this->assertNull($app->lastActivityAt, 'fresh App has no recorded activity');

        [$next, ] = $app->update(new UserInputMsg('hello'));

        $this->assertNotNull($next->lastActivityAt);
        $this->assertGreaterThan(
            (new DateTimeImmutable('5 seconds ago'))->getTimestamp(),
            $next->lastActivityAt->getTimestamp(),
        );
    }

    public function testRealUserInputMsgResetsIdleClockSoOldSessionsStopLookingIdle(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test');

        // Simulate a session that had gone idle for 2 hours.
        $app = App::new($provider, 'test-model')
            ->withLastActivity(new DateTimeImmutable('2 hours ago'));

        [$next, ] = $app->update(new UserInputMsg('still here'));

        // A fresh prompt is real activity - the idle clock resets even
        // though the session had crossed the idle threshold before.
        $idleSeconds = time() - $next->lastActivityAt->getTimestamp();
        $this->assertLessThan(5, $idleSeconds);
    }

    // =========================================================================
    // buildSystemPrompt() root/forced instruction wiring
    // =========================================================================

    public function testBuildSystemPromptIncludesRootAgentsMdContent(): void
    {
        // The gap this closes: before the wiring, a repo-root AGENTS.md only
        // ever reached the model if the agent happened to touch a file in
        // that directory (loadForPath()); loadRoot() had no caller at all, so
        // this assertion fails against the old buildSystemPrompt().
        $root = $this->makeTempRepo();
        file_put_contents($root . '/AGENTS.md', 'ROOT AGENTS CONVENTION TEXT');

        $app = App::new($this->provider, 'gpt-4')
            ->withInstructionLoader(new InstructionFileLoader($root));

        $result = $this->invokePrivateMethod($this->runtime, 'buildSystemPrompt', [$app]);

        $this->assertStringContainsString('ROOT AGENTS CONVENTION TEXT', $result);
        $this->assertStringContainsString('<project-instructions>', $result);
        $this->assertStringContainsString('SugarCrush', $result);
    }

    public function testBuildSystemPromptIncludesRootClaudeMdWithExpandedImports(): void
    {
        $root = $this->makeTempRepo();
        file_put_contents($root . '/CLAUDE.md', "# Root\n@./AGENTS.md\n");
        file_put_contents($root . '/AGENTS.md', 'IMPORTED AGENTS BODY');

        $app = App::new($this->provider, 'gpt-4')
            ->withInstructionLoader(new InstructionFileLoader($root));

        $result = $this->invokePrivateMethod($this->runtime, 'buildSystemPrompt', [$app]);

        $this->assertStringContainsString('IMPORTED AGENTS BODY', $result);
        $this->assertStringNotContainsString('@./AGENTS.md', $result);
    }

    public function testBuildSystemPromptIncludesForcedInstructionGlobMatches(): void
    {
        $root = $this->makeTempRepo();
        mkdir($root . '/candy-shine');
        file_put_contents($root . '/candy-shine/CALIBER_LEARNINGS.md', 'FORCED LEARNINGS BODY');

        $app = App::new($this->provider, 'gpt-4')
            ->withInstructionLoader(new InstructionFileLoader($root, ['*/CALIBER_LEARNINGS.md']));

        $result = $this->invokePrivateMethod($this->runtime, 'buildSystemPrompt', [$app]);

        $this->assertStringContainsString('FORCED LEARNINGS BODY', $result);
    }

    public function testBuildSystemPromptOmitsProjectInstructionsWithoutLoader(): void
    {
        $app = App::new($this->provider, 'gpt-4');

        $result = $this->invokePrivateMethod($this->runtime, 'buildSystemPrompt', [$app]);

        $this->assertStringNotContainsString('<project-instructions>', $result);
    }

    public function testBuildSystemPromptKeepsSkillContributionsAlongsideRootInstructions(): void
    {
        $root = $this->makeTempRepo();
        file_put_contents($root . '/AGENTS.md', 'ROOT AGENTS CONVENTION TEXT');

        $skill = new Skill(
            name: 'TestSkill',
            description: 'A test skill',
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: 'low',
            context: 'thread',
            paths: [],
            content: 'Skill content here',
            sourcePath: '/test/SKILL.md',
        );

        $app = App::new($this->provider, 'gpt-4')
            ->withInstructionLoader(new InstructionFileLoader($root))
            ->withEnabledSkills([$skill]);

        $result = $this->invokePrivateMethod($this->runtime, 'buildSystemPrompt', [$app]);

        $this->assertStringContainsString('ROOT AGENTS CONVENTION TEXT', $result);
        $this->assertStringContainsString('## Skill: TestSkill', $result);
    }

    public function testBuildSystemPromptEmitsAtImportedRootFileOnlyOnce(): void
    {
        // Exactly this repo's own shape: root CLAUDE.md @-imports ./AGENTS.md.
        // Without de-duplication AGENTS.md lands twice on every single turn --
        // once inlined into the CLAUDE.md document by ImportResolver, once
        // again as loadRoot()'s own second document.
        $root = $this->makeTempRepo();
        file_put_contents($root . '/CLAUDE.md', "# Root\n@./AGENTS.md\n");
        file_put_contents($root . '/AGENTS.md', 'DISTINCTIVE AGENTS BODY MARKER');

        $app = App::new($this->provider, 'gpt-4')
            ->withInstructionLoader(new InstructionFileLoader($root));

        $result = $this->invokePrivateMethod($this->runtime, 'buildSystemPrompt', [$app]);

        $this->assertSame(1, substr_count($result, 'DISTINCTIVE AGENTS BODY MARKER'));
        // The import is expanded in place, not left as a literal reference.
        $this->assertStringNotContainsString('@./AGENTS.md', $result);
        $this->assertSame(1, substr_count($result, '<project-instructions>'));
    }

    public function testBuildSystemPromptEmitsForcedGlobMatchOfARootFileOnlyOnce(): void
    {
        // A forced pattern that happens to cover a root file must not buy a
        // second copy of it either -- loadRoot() drains first, loadForced()
        // sees the file as already emitted.
        $root = $this->makeTempRepo();
        file_put_contents($root . '/AGENTS.md', 'ROOT AND FORCED BODY MARKER');

        $app = App::new($this->provider, 'gpt-4')
            ->withInstructionLoader(new InstructionFileLoader($root, ['AGENTS.md']));

        $result = $this->invokePrivateMethod($this->runtime, 'buildSystemPrompt', [$app]);

        $this->assertSame(1, substr_count($result, 'ROOT AND FORCED BODY MARKER'));
    }

    public function testBuildSystemPromptSkipsWhitespaceOnlyInstructionFile(): void
    {
        // An empty (or whitespace-only) CLAUDE.md is common in a freshly
        // scaffolded repo. Emitting it would spend tokens on a bare
        // <project-instructions></project-instructions> wrapper around nothing.
        $root = $this->makeTempRepo();
        file_put_contents($root . '/CLAUDE.md', "   \n\t\n  ");

        $app = App::new($this->provider, 'gpt-4')
            ->withInstructionLoader(new InstructionFileLoader($root));

        $result = $this->invokePrivateMethod($this->runtime, 'buildSystemPrompt', [$app]);

        $this->assertStringNotContainsString('<project-instructions>', $result);
        $this->assertStringContainsString('SugarCrush', $result);
    }

    public function testBuildSystemPromptStillEmitsNonEmptyFileAlongsideAnEmptyOne(): void
    {
        // Guards the `continue` from turning into an early `break`/`return`:
        // a blank CLAUDE.md must not suppress a populated AGENTS.md.
        $root = $this->makeTempRepo();
        file_put_contents($root . '/CLAUDE.md', "\n\n");
        file_put_contents($root . '/AGENTS.md', 'NON EMPTY SIBLING BODY');

        $app = App::new($this->provider, 'gpt-4')
            ->withInstructionLoader(new InstructionFileLoader($root));

        $result = $this->invokePrivateMethod($this->runtime, 'buildSystemPrompt', [$app]);

        $this->assertSame(1, substr_count($result, '<project-instructions>'));
        $this->assertStringContainsString('NON EMPTY SIBLING BODY', $result);
    }

    public function testWithInstructionLoaderReturnsNewAppAndLeavesOriginalUntouched(): void
    {
        $loader = new InstructionFileLoader($this->makeTempRepo());
        $app = App::new($this->provider, 'gpt-4');

        $next = $app->withInstructionLoader($loader);

        $this->assertNotSame($app, $next);
        $this->assertNull($app->instructionLoader);
        $this->assertSame($loader, $next->instructionLoader);
        $this->assertNull($next->withInstructionLoader(null)->instructionLoader);
    }

    // =========================================================================
    // buildSystemPrompt() environment-block wiring
    // =========================================================================

    public function testBuildSystemPromptIncludesEnvironmentBlock(): void
    {
        // The gap this closes: no cwd, no git state, no platform, no model and
        // no date reached the model at all. EnvironmentBlock existed but had
        // no caller, so this assertion fails against the old
        // buildSystemPrompt().
        $app = App::new($this->provider, 'gpt-4');

        $result = $this->invokePrivateMethod($this->runtime, 'buildSystemPrompt', [$app]);

        $this->assertStringContainsString('<env>', $result);
        $this->assertStringContainsString('</env>', $result);
        $this->assertStringContainsString('Working directory: ' . getcwd(), $result);
        $this->assertStringContainsString('Model: gpt-4', $result);
        $this->assertStringContainsString('Platform: ' . strtolower(PHP_OS_FAMILY), $result);
        $this->assertStringContainsString('Current date: ' . date('Y-m-d'), $result);
    }

    public function testBuildSystemPromptUsesAnInjectedEnvironmentBlock(): void
    {
        $root = $this->makeTempRepo();
        $runtime = new Runtime(
            $this->provider,
            $this->hookManager,
            new EnvironmentBlock($root, 'injected-model', new DateTimeImmutable('2026-01-02 03:04:05')),
        );

        $result = $this->invokePrivateMethod($runtime, 'buildSystemPrompt', [App::new($this->provider, 'gpt-4')]);

        $this->assertStringContainsString('Working directory: ' . $root, $result);
        $this->assertStringContainsString('Model: injected-model', $result);
        $this->assertStringContainsString('Current date: 2026-01-02', $result);
    }

    public function testBuildSystemPromptReusesTheSameEnvironmentSnapshotAcrossTurns(): void
    {
        // The block documents itself as a point-in-time snapshot and shells out
        // to git three times to build one: re-capturing per turn would both
        // burn three subprocesses per step of the agentic loop and let the
        // rendered date/git state drift mid-session.
        $app = App::new($this->provider, 'gpt-4');

        $first = $this->invokePrivateMethod($this->runtime, 'environmentSnapshot', [$app]);
        $second = $this->invokePrivateMethod($this->runtime, 'environmentSnapshot', [$app]);

        $this->assertSame($first, $second);
    }

    public function testBuildSystemPromptOrdersEnvironmentBlockBeforeProjectInstructions(): void
    {
        $root = $this->makeTempRepo();
        file_put_contents($root . '/AGENTS.md', 'ROOT AGENTS CONVENTION TEXT');

        $app = App::new($this->provider, 'gpt-4')
            ->withInstructionLoader(new InstructionFileLoader($root));

        $result = $this->invokePrivateMethod($this->runtime, 'buildSystemPrompt', [$app]);

        $this->assertLessThan(
            strpos($result, '<project-instructions>'),
            strpos($result, '<env>'),
            'the model must learn where it is before it reads path-relative conventions',
        );
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Fresh on-disk repo root for the instruction-loader tests; removed in
     * tearDown() so each test sees only the files it wrote itself.
     */
    private function makeTempRepo(): string
    {
        $dir = sys_get_temp_dir() . '/crush-runtime-' . uniqid('', true);
        mkdir($dir, 0o777, true);
        $this->tempRepos[] = $dir;

        return $dir;
    }

    /**
     * Wrap fixture responses in a Generator. `completeStream()` is typed to
     * return \Generator, so a mock cannot ->willReturn() a plain array.
     *
     * @param list<CompleteResponse> $responses
     */
    private function streamOf(array $responses): \Generator
    {
        yield from $responses;
    }

    private function createMockTool(string $name, string $result, int $delayMs = 0): Tool
    {
        $tool = $this->createMock(Tool::class);
        $tool->method('name')->willReturn($name);
        $tool->method('description')->willReturn("Description for $name");
        $tool->method('inputSchema')->willReturn([]);
        $tool->method('execute')->willReturnCallback(function ($args) use ($name, $result, $delayMs) {
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
            return new ToolResult(
                toolCallId: $args['toolCallId'] ?? "call_$name",
                content: $result,
            );
        });

        return $tool;
    }

    /** A tool whose execute() throws rather than returning a ToolResult. */
    private function createThrowingTool(string $name, \Throwable $throwable): Tool
    {
        $tool = $this->createMock(Tool::class);
        $tool->method('name')->willReturn($name);
        $tool->method('description')->willReturn("Description for $name");
        $tool->method('inputSchema')->willReturn([]);
        $tool->method('execute')->willThrowException($throwable);

        return $tool;
    }

    // =========================================================================
    // Blocking permission requests (crush_feat.md 1 E2) - HookResult::ask()
    // =========================================================================

    /**
     * An ASK is a hook DEFERRING, not deciding. With nobody attached who can
     * put the question to a user, the call must fail closed - and say why in
     * those terms rather than reporting a denial the hook never made.
     */
    public function testAskWithNoApproverFailsClosedAndSaysPermissionWasRequired(): void
    {
        $tool = $this->createMockTool('ask_tool', 'must not run');
        $this->hookRegistry->register($this->askHook('Delete production data?'));

        $toolCall = new ToolCall('call_ask', 'ask_tool', []);
        $app = App::new($this->provider, 'gpt-4')->withTools([$tool]);

        $results = iterator_to_array($this->invokePrivateMethod($this->runtime, 'executeToolCalls', [[$toolCall], $app]));

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->isError());
        $this->assertStringContainsString('Permission required', $results[0]->content());
        $this->assertStringContainsString('Delete production data?', $results[0]->content());
    }

    /**
     * An approver answering yes settles the ASK into an ALLOW, so the tool
     * runs exactly as an allowed call does. Fails against the old code, where
     * an ASK fell into the deny branch with no way to answer it at all.
     */
    public function testApprovedAskRunsTheToolAndReportsTheRealResult(): void
    {
        $tool = $this->createMockTool('ask_tool', 'ran for real');
        $this->hookRegistry->register($this->askHook('Run it?'));

        $toolCall = new ToolCall('call_ask', 'ask_tool', []);
        $app = App::new($this->provider, 'gpt-4')->withTools([$tool]);

        $asked = [];
        $results = iterator_to_array($this->invokePrivateMethod($this->runtime, 'executeToolCalls', [
            [$toolCall],
            $app,
            null,
            function (ToolCall $call, HookResult $ask) use (&$asked): bool {
                $asked[] = [$call->name(), $ask->message];

                return true;
            },
        ]));

        $this->assertSame([['ask_tool', 'Run it?']], $asked);
        $this->assertFalse($results[0]->isError());
        $this->assertSame('ran for real', $results[0]->content());
    }

    public function testRejectedAskBlocksTheToolWithTheHooksOwnQuestionAsTheReason(): void
    {
        $tool = $this->createMockTool('ask_tool', 'must not run');
        $this->hookRegistry->register($this->askHook('Run it?'));

        $toolCall = new ToolCall('call_ask', 'ask_tool', []);
        $app = App::new($this->provider, 'gpt-4')->withTools([$tool]);

        $results = iterator_to_array($this->invokePrivateMethod($this->runtime, 'executeToolCalls', [
            [$toolCall],
            $app,
            null,
            static fn(ToolCall $call, HookResult $ask): bool => false,
        ]));

        $this->assertTrue($results[0]->isError());
        $this->assertStringContainsString('Run it?', $results[0]->content());
    }

    /**
     * Only a literal `true` is a grant. settleAsk() used to cast the
     * approver's answer with `(bool)`, which turns EVERY truthy value into
     * permission - and the natural approver to wire here is one returning a
     * {@see PermissionReply}, whose `Reject` case is a truthy object. Under
     * the old cast a user pressing "n" would have run the tool.
     *
     * This is the same fail-open shape as the tools-map bug earlier in this
     * build, where a truthy non-boolean silently granted tool access.
     */
    public function testATruthyNonBooleanApproverAnswerIsNotTreatedAsPermission(): void
    {
        $tool = $this->createMockTool('ask_tool', 'must not run');
        $this->hookRegistry->register($this->askHook('Run it?'));

        $toolCall = new ToolCall('call_ask', 'ask_tool', []);
        $app = App::new($this->provider, 'gpt-4')->withTools([$tool]);

        $results = iterator_to_array($this->invokePrivateMethod($this->runtime, 'executeToolCalls', [
            [$toolCall],
            $app,
            null,
            static fn(ToolCall $call, HookResult $ask): PermissionReply => PermissionReply::Reject,
        ]));

        $this->assertTrue($results[0]->isError(), 'a Reject reply must not run the tool');
        $this->assertStringNotContainsString('must not run', $results[0]->content());
    }

    /** A PreToolUse hook that always defers to the user. */
    private function askHook(string $question): HookInterface
    {
        return new class ($question) implements HookInterface {
            public function __construct(private readonly string $question) {}
            public function name(): string { return 'ask-hook'; }
            public function event(): HookEvent { return HookEvent::PreToolUse; }
            public function matcher(): string { return '.*'; }
            public function execute(HookContext $context): HookResult { return HookResult::ask($this->question); }
        };
    }

    private function invokePrivateMethod(object $object, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionClass($object);
        $reflectionMethod = $reflection->getMethod($method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invokeArgs($object, $args);
    }
}
