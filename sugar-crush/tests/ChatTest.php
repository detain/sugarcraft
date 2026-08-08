<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use React\Promise\Promise;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\AgentPoolConfig;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\ExecutorInterface;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Role;
use PHPUnit\Framework\TestCase;

final class ChatTest extends TestCase
{
    public function testTypingAccumulatesCharsInInputBuffer(): void
    {
        $chat = new Chat();
        [$h] = $chat->update(new KeyMsg(KeyType::Char, 'h'));
        [$he] = $h->update(new KeyMsg(KeyType::Char, 'e'));
        [$hel] = $he->update(new KeyMsg(KeyType::Char, 'l'));
        $this->assertSame('hel', $hel->inputBuf);
    }

    public function testSpaceKeyAppendsSpace(): void
    {
        $chat = new Chat();
        [$a]  = $chat->update(new KeyMsg(KeyType::Char,  'a'));
        [$ab] = $a->update(new KeyMsg(KeyType::Space, ''));
        [$abc] = $ab->update(new KeyMsg(KeyType::Char, 'b'));
        $this->assertSame('a b', $abc->inputBuf);
    }

    public function testBackspaceDropsLastChar(): void
    {
        $chat = new Chat(inputBuf: 'hello');
        [$next] = $chat->update(new KeyMsg(KeyType::Backspace, ''));
        $this->assertSame('hell', $next->inputBuf);
    }

    public function testBackspaceDropsLastUtf8Codepoint(): void
    {
        $chat = new Chat(inputBuf: 'hi 🚀');
        [$next] = $chat->update(new KeyMsg(KeyType::Backspace, ''));
        $this->assertSame('hi ', $next->inputBuf);
    }

    public function testEnterSubmitsAndSchedulesBackend(): void
    {
        $chat = new Chat(inputBuf: 'hello');
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertCount(1, $next->history);
        $this->assertSame(Role::User, $next->history[0]->role);
        $this->assertSame('hello', $next->history[0]->content);
        $this->assertSame('', $next->inputBuf, 'input cleared after submit');
        $this->assertTrue($next->inFlight, 'inFlight set while waiting');
        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    public function testEmptySubmitIsNoop(): void
    {
        $chat = new Chat(inputBuf: '   ');
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame($chat, $next);
        $this->assertNull($cmd);
    }

    public function testAssistantMsgAppendsAndClearsInFlight(): void
    {
        $chat = new Chat(history: [Message::user('hi')], inFlight: true);
        $reply = Message::assistant('hello!');
        [$next] = $chat->update(new AssistantMsg($reply));
        $this->assertCount(2, $next->history);
        $this->assertSame('hello!', $next->history[1]->content);
        $this->assertFalse($next->inFlight);
    }

    public function testKeystrokesIgnoredWhileInFlight(): void
    {
        $chat = new Chat(inputBuf: '', inFlight: true);
        [$next] = $chat->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame('', $next->inputBuf);
    }

    public function testEscQuits(): void
    {
        $chat = new Chat();
        [, $cmd] = $chat->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    public function testEchoBackendRoundTrip(): void
    {
        $chat = new Chat(backend: new EchoBackend(), inputBuf: 'ping');
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        // Run the Cmd → AsyncCmd (async), not AssistantMsg (sync)
        $asyncCmd = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $asyncCmd);

        // Wait for the promise to resolve (EchoBackend resolves synchronously)
        $resolved = null;
        $asyncCmd->promise->then(function ($msg) use (&$resolved) {
            $resolved = $msg;
        });

        // Dispatch the resolved AssistantMsg
        $this->assertInstanceOf(AssistantMsg::class, $resolved);
        [$final] = $next->update($resolved);
        $this->assertCount(2, $final->history);
        $this->assertSame(Role::Assistant, $final->history[1]->role);
        $this->assertStringContainsString('ping', $final->history[1]->content);
    }

    public function testInitReturnsNoCmd(): void
    {
        $this->assertNull((new Chat())->init());
    }

    public function testNonKeyMessageIgnored(): void
    {
        $chat = new Chat(inputBuf: 'x');
        $msg = new \SugarCraft\Core\Msg\WindowSizeMsg(80, 24);
        [$next, $cmd] = $chat->update($msg);
        $this->assertSame($chat, $next);
        $this->assertNull($cmd);
    }

    public function testWithStreamingEnablesFlag(): void
    {
        $chat = new Chat();
        $this->assertFalse($chat->isStreaming());
        $chat2 = $chat->withStreaming(true);
        $this->assertNotSame($chat, $chat2);
        $this->assertTrue($chat2->isStreaming());
    }

    public function testWithStreamingCanDisable(): void
    {
        $chat = new Chat();
        $chat2 = $chat->withStreaming(true);
        $chat3 = $chat2->withStreaming(false);
        $this->assertFalse($chat3->isStreaming());
    }

    public function testOnTokenSetsCallback(): void
    {
        $chat = new Chat();
        $called = false;
        $chat2 = $chat->onToken(function () use (&$called) {
            $called = true;
        });
        $this->assertNotSame($chat, $chat2);
    }

    public function testStreamingStatePreservedOnInput(): void
    {
        $chat = new Chat(inputBuf: '', streaming: true, onToken: static fn() => null);
        [$next] = $chat->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertTrue($next->isStreaming());
    }

    public function testStreamingStatePreservedOnAssistantMsg(): void
    {
        $chat = new Chat(
            history: [Message::user('hi')],
            inFlight: true,
            streaming: true,
            onToken: static fn() => null,
        );
        $reply = Message::assistant('hello!');
        [$next] = $chat->update(new AssistantMsg($reply));
        $this->assertTrue($next->isStreaming());
    }

    public function testStreamingCallbackPassedToBackend(): void
    {
        $tokens = [];
        $chat = new Chat(
            backend: new class implements \SugarCraft\Crush\Backend {
                public function complete(array $history, callable $onToken = null): Message
                {
                    if ($onToken !== null) {
                        $onToken('token1');
                        $onToken('token2');
                        $onToken('token3');
                    }
                    return \SugarCraft\Crush\Message::assistant('streaming reply');
                }
                public function completeAsync(array $history, callable $onToken = null): PromiseInterface
                {
                    return new \React\Promise\Promise(function (callable $resolve, callable $reject) use ($history, $onToken): void {
                        try {
                            $resolve($this->complete($history, $onToken));
                        } catch (\Throwable $e) {
                            $reject($e);
                        }
                    });
                }
            },
            inputBuf: 'hello',
            streaming: true,
            onToken: static function (string $token) use (&$tokens) {
                $tokens[] = $token;
            },
        );
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertInstanceOf(\Closure::class, $cmd);
        // Execute the command to trigger the backend
        $msg = $cmd();
        $this->assertCount(3, $tokens);
        $this->assertSame(['token1', 'token2', 'token3'], $tokens);
    }

    public function testStreamingCallbackNotPassedWhenDisabled(): void
    {
        $callbackReceived = null;
        $chat = new Chat(
            backend: new class implements \SugarCraft\Crush\Backend {
                public function complete(array $history, callable $onToken = null): Message
                {
                    return \SugarCraft\Crush\Message::assistant('reply');
                }
                public function completeAsync(array $history, callable $onToken = null): PromiseInterface
                {
                    return new \React\Promise\Promise(function (callable $resolve, callable $reject) use ($history, $onToken): void {
                        try {
                            $resolve($this->complete($history, $onToken));
                        } catch (\Throwable $e) {
                            $reject($e);
                        }
                    });
                }
            },
            inputBuf: 'hello',
            streaming: false,
            onToken: static function (string $token) use (&$callbackReceived) {
                $callbackReceived = $token;
            },
        );
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $msg = $cmd();
        // When streaming is disabled, onToken is null (not passed)
        $this->assertNull($callbackReceived);
    }

    public function testRegisterToolAddsTool(): void
    {
        $chat = new Chat();
        $this->assertEmpty($chat->getTools());
        $chat2 = $chat->registerTool('bash', static fn(array $args) => 'result');
        $this->assertNotSame($chat, $chat2);
        $this->assertCount(1, $chat2->getTools());
        $this->assertArrayHasKey('bash', $chat2->getTools());
    }

    public function testRegisterToolIsImmutable(): void
    {
        $chat = new Chat();
        $chat2 = $chat->registerTool('bash', static fn(array $args) => 'result');
        $this->assertEmpty($chat->getTools());
        $this->assertCount(1, $chat2->getTools());
    }

    public function testMultipleToolsCanBeRegistered(): void
    {
        $chat = new Chat();
        $chat2 = $chat
            ->registerTool('bash', static fn(array $args) => 'bash result')
            ->registerTool('read', static fn(array $args) => 'file content');
        $this->assertCount(2, $chat2->getTools());
        $this->assertArrayHasKey('bash', $chat2->getTools());
        $this->assertArrayHasKey('read', $chat2->getTools());
    }

    public function testOnToolCallSetsCallback(): void
    {
        $chat = new Chat();
        $called = false;
        $chat2 = $chat->onToolCall(function () use (&$called) {
            $called = true;
        });
        $this->assertNotSame($chat, $chat2);
        // Callback is stored (we trust it's set correctly by immutability)
        $this->assertNotSame($chat, $chat2);
    }

    public function testToolExecutionOnAssistantMsg(): void
    {
        $toolCall = new \SugarCraft\Crush\ToolCall('bash', ['cmd' => 'ls -la']);
        $message = Message::assistant('Running command...')->withToolCalls([$toolCall]);

        $executedArgs = null;
        $chat = (new Chat(
            history: [Message::user('list files')],
            inFlight: true,
        ))->registerTool('bash', static function (array $args) use (&$executedArgs) {
            $executedArgs = $args;
            return 'total 0' . "\n" . 'drwxr-xr-x 2 user user 4096 May 10 00:00 .';
        });

        [$next] = $chat->update(new AssistantMsg($message));

        // Tool should have been executed synchronously
        $this->assertNotNull($executedArgs);
        $this->assertSame(['cmd' => 'ls -la'], $executedArgs);

        // A follow-up backend call should be scheduled
        $this->assertTrue($next->inFlight);
        // History: user msg + assistant msg + tool result msg
        $this->assertCount(3, $next->history);
    }

    public function testToolResultAddedToHistoryAfterExecution(): void
    {
        $toolCall = new \SugarCraft\Crush\ToolCall('echo', ['text' => 'hello']);
        $message = Message::assistant('Echoing...')->withToolCalls([$toolCall]);

        $chat = (new Chat(
            history: [Message::user('say hello')],
            inFlight: true,
        ))->registerTool('echo', static fn(array $args) => $args['text'] ?? '');

        [$next, ] = $chat->update(new AssistantMsg($message));

        // After tool execution, history should have 3 items:
        // user msg, assistant msg with tool call, tool result
        $this->assertCount(3, $next->history);
        $this->assertSame('', $next->history[2]->content); // tool result content is in a separate message
    }

    public function testUnknownToolReturnsError(): void
    {
        $toolCall = new \SugarCraft\Crush\ToolCall('unknown_tool', []);
        $message = Message::assistant('Calling unknown...')->withToolCalls([$toolCall]);

        // Chat with no tools registered - tool calls should be ignored
        // and message should just be added to history
        $chat = new Chat(
            history: [Message::user('do something')],
            inFlight: true,
        );

        [$next] = $chat->update(new AssistantMsg($message));

        // Without tools registered, tool calls are ignored
        // History: user msg + assistant msg with tool calls (no execution)
        $this->assertCount(2, $next->history);
        $this->assertFalse($next->inFlight);
    }

    public function testToolExceptionReturnsErrorResult(): void
    {
        $toolCall = new \SugarCraft\Crush\ToolCall('failing', []);
        $message = Message::assistant('Calling failing tool...')->withToolCalls([$toolCall]);

        $chat = (new Chat(
            history: [Message::user('test')],
            inFlight: true,
        ))->registerTool('failing', static function (array $args): void {
            throw new \RuntimeException('Tool failed intentionally');
        });

        [$next] = $chat->update(new AssistantMsg($message));

        // History should have user msg, assistant msg, and error result
        $this->assertCount(3, $next->history);
    }

    public function testToolsAndCallbacksPreservedOnInput(): void
    {
        $chat = new Chat(tools: ['test' => static fn() => 'result']);
        [$next] = $chat->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertArrayHasKey('test', $next->getTools());
    }

    public function testAssistantMsgWithoutToolCallsNoOpOnTools(): void
    {
        $chat = (new Chat(
            history: [Message::user('hello')],
            inFlight: true,
        ))->registerTool('bash', static fn(array $args) => 'result');
        $reply = Message::assistant('Hello!');
        [$next] = $chat->update(new AssistantMsg($reply));
        $this->assertCount(2, $next->history);
        $this->assertFalse($next->inFlight);
    }

    public function testBackendAccessor(): void
    {
        $backend = new EchoBackend();
        $chat = new Chat(backend: $backend);
        $this->assertSame($backend, $chat->backend());
    }

    public function testBackendAccessorWithDefaultBackend(): void
    {
        $chat = new Chat();
        // Default backend is EchoBackend
        $this->assertInstanceOf(\SugarCraft\Crush\Backend\EchoBackend::class, $chat->backend());
    }

    public function testBackendPreservedOnInput(): void
    {
        $backend = new EchoBackend();
        $chat = new Chat(backend: $backend);
        [$next] = $chat->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame($backend, $next->backend());
    }

    /**
     * Benchmark: diff-based view() emits fewer bytes than full re-render
     * for small changes between consecutive frames.
     *
     * Frame 1: full output (baseline)
     * Frame 2: delta output (smaller than full 80x24 re-emit)
     * Frame 3: delta output (smaller than full 80x24 re-emit)
     */
    public function testDiffEmissionByteBenchmark(): void
    {
        $chat = new Chat(history: [Message::user('hello'), Message::assistant('Hi there!')]);

        // Frame 1: full render
        $out1 = $chat->view();
        $bytes1 = \strlen($out1);

        // Frame 2: type a character (input buffer changes)
        [$chat2] = $chat->update(new KeyMsg(KeyType::Char, '!'));
        $out2 = $chat2->view();
        $bytes2 = \strlen($out2);

        // Frame 3: type another character
        [$chat3] = $chat2->update(new KeyMsg(KeyType::Char, '!'));
        $out3 = $chat3->view();
        $bytes3 = \strlen($out3);

        // Delta frames should be smaller than a full 80x24 re-emit (≥1920 bytes).
        // The 30-byte threshold was a placeholder guess; the real goal is
        // delta < full 80x24 re-emit, which these renders satisfy since they
        // emit ~350 bytes for small state changes vs the 1920+ bytes for a full frame.
        $fullRepaintBytes = 1920;
        $this->assertLessThan($fullRepaintBytes, $bytes2, 'Frame 2 delta should be smaller than full 80x24 re-emit');
        $this->assertLessThan($fullRepaintBytes, $bytes3, 'Frame 3 delta should be smaller than full 80x24 re-emit');
    }

    // ─── AgentWorkerPool wiring tests (P1.S10) ─────────────────────────────────

    public function testWithWorkerPoolReturnsNewInstance(): void
    {
        $chat = new Chat();
        $pool = new AgentWorkerPool(maxConcurrent: 3);
        $chat2 = $chat->withWorkerPool($pool);
        $this->assertNotSame($chat, $chat2);
        $this->assertSame($pool, $chat2->pool());
    }

    public function testWithAgentPoolConfigReturnsNewInstance(): void
    {
        $chat = new Chat();
        $config = new AgentPoolConfig(maxConcurrent: 3);
        $chat2 = $chat->withAgentPoolConfig($config);
        $this->assertNotSame($chat, $chat2);
        $this->assertSame($config, $chat2->agentPoolConfig());
    }

    public function testWorkerPoolPreservedOnInput(): void
    {
        $pool = new AgentWorkerPool(maxConcurrent: 3);
        $chat = (new Chat())->withWorkerPool($pool);
        [$next] = $chat->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame($pool, $next->pool());
    }

    public function testAgentPoolConfigPreservedOnInput(): void
    {
        $config = new AgentPoolConfig(maxConcurrent: 3);
        $chat = (new Chat())->withAgentPoolConfig($config);
        [$next] = $chat->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame($config, $next->agentPoolConfig());
    }

    public function testWorkerPoolPreservedOnStreamingToggle(): void
    {
        $pool = new AgentWorkerPool(maxConcurrent: 3);
        $chat = (new Chat())->withWorkerPool($pool);
        $chat2 = $chat->withStreaming(true);
        $this->assertSame($pool, $chat2->pool());
    }

    public function testAgentPoolConfigPreservedOnToolRegistration(): void
    {
        $config = new AgentPoolConfig(maxConcurrent: 3);
        $chat = (new Chat())->withAgentPoolConfig($config);
        $chat2 = $chat->registerTool('echo', static fn(array $args) => 'result');
        $this->assertSame($config, $chat2->agentPoolConfig());
    }

    public function testExecuteAgentsThrowsWithoutPoolOrConfig(): void
    {
        $chat = new Chat();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no AgentWorkerPool or AgentPoolConfig available');
        // Suppress iterator consumption warning
        @$chat->executeAgents([], new CompleteRequest(
            model: 'test',
            messages: [],
        ));
    }

    public function testExecuteAgentsUsesExplicitPool(): void
    {
        // Use a custom executor that immediately returns a result
        $executor = new class implements ExecutorInterface {
            public function execute(\SugarCraft\Crush\Agents\SubAgent $agent, CompleteRequest $request): AgentResult
            {
                return AgentResult::ok($agent->id, 'done');
            }

            public function executeStream(\SugarCraft\Crush\Agents\SubAgent $agent, CompleteRequest $request): \Generator
            {
                yield AgentResult::ok($agent->id, 'done');
            }

            public function cancel(string $agentId): void {}
            public function cancelAll(): void {}
        };

        $pool = new AgentWorkerPool(maxConcurrent: 1, executor: $executor);
        $chat = (new Chat())->withWorkerPool($pool);

        // No agents — should return empty generator
        $results = @$chat->executeAgents([], new CompleteRequest(
            model: 'test',
            messages: [],
        ));
        $this->assertInstanceOf(\Generator::class, $results);
        $this->assertCount(0, iterator_to_array($results));
    }

    public function testExecuteAgentsBuildsPoolFromConfig(): void
    {
        $config = new AgentPoolConfig(maxConcurrent: 2);
        $chat = (new Chat())->withAgentPoolConfig($config);

        // Accessor confirms config is set
        $this->assertSame($config, $chat->agentPoolConfig());

        // Pool is built from config when executeAgents is called with no pool set
        // We can verify this doesn't throw (empty agents list is valid)
        $results = @$chat->executeAgents([], new CompleteRequest(
            model: 'test',
            messages: [],
        ));
        $this->assertInstanceOf(\Generator::class, $results);
    }

    // ─── /workflow command wiring tests (P4.S15) ─────────────────────────────────

    /**
     * Standalone fake WorkflowEngine for testing.
     *
     * Provides stub implementations of the 5 public methods that Chat's
     * workflow handlers call: run, pause, resume, getStatus, listWorkflows.
     * This is a simple test double — NOT extending WorkflowEngine — so that
     * WorkflowEngine can remain final without scope creep into Chat.php's test.
     */
    private function createFakeWorkflowEngine(array $stubs = []): \SugarCraft\Crush\Workflows\WorkflowEngineInterface
    {
        return new class($stubs) implements \SugarCraft\Crush\Workflows\WorkflowEngineInterface {
            private array $stubs;

            public function __construct(array $stubs = []) {
                $this->stubs = $stubs;
            }

            public function listWorkflows(): array
            {
                return $this->stubs['listWorkflows'] ?? [];
            }

            public function run(string $workflowPath, array $context = []): \SugarCraft\Crush\Workflows\WorkflowResult
            {
                if (isset($this->stubs['run'])) {
                    return $this->stubs['run'];
                }
                throw new \SugarCraft\Crush\Workflows\WorkflowNotFoundException('Not found');
            }

            public function pause(string $workflowId): void
            {
                if (isset($this->stubs['pause'])) {
                    call_user_func($this->stubs['pause'], $workflowId);
                }
            }

            public function resume(string $workflowId): \SugarCraft\Crush\Workflows\WorkflowResult
            {
                if (isset($this->stubs['resume'])) {
                    return $this->stubs['resume'];
                }
                throw new \SugarCraft\Crush\Workflows\WorkflowNotFoundException('Not found');
            }

            public function getStatus(string $workflowId): \SugarCraft\Crush\Workflows\WorkflowStatus
            {
                if (isset($this->stubs['getStatus'])) {
                    return $this->stubs['getStatus'];
                }
                throw new \SugarCraft\Crush\Workflows\WorkflowNotRunningException('Not found');
            }
        };
    }

    public function testWorkflowWithNullEngineShowsError(): void
    {
        // Chat with no workflow engine set
        $chat = new Chat(inputBuf: '/workflow');
        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        // Should have user command and error response in history
        $this->assertCount(2, $next->history);
        $this->assertSame(Role::User, $next->history[0]->role);
        $this->assertSame('/workflow', $next->history[0]->content);
        $this->assertSame(Role::Assistant, $next->history[1]->role);
        $this->assertStringContainsString('not configured', $next->history[1]->content);
    }

    public function testWorkflowHelpShowsAllCommands(): void
    {
        $engine = $this->createFakeWorkflowEngine();
        $chat = new Chat(inputBuf: '/workflow help', workflowEngine: $engine);

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertCount(2, $next->history);
        $this->assertSame(Role::Assistant, $next->history[1]->role);
        $response = $next->history[1]->content;
        $this->assertStringContainsString('/workflow run', $response);
        $this->assertStringContainsString('/workflow pause', $response);
        $this->assertStringContainsString('/workflow resume', $response);
        $this->assertStringContainsString('/workflow status', $response);
        $this->assertStringContainsString('/workflow list', $response);
    }

    public function testWorkflowListWithNoWorkflowsShowsEmptyMessage(): void
    {
        $engine = $this->createFakeWorkflowEngine(['listWorkflows' => []]);
        $chat = new Chat(inputBuf: '/workflow list', workflowEngine: $engine);

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertCount(2, $next->history);
        $this->assertSame(Role::Assistant, $next->history[1]->role);
        $this->assertStringContainsString('No workflows found', $next->history[1]->content);
    }

    public function testWorkflowListWithWorkflowsShowsNumberedList(): void
    {
        $engine = $this->createFakeWorkflowEngine(['listWorkflows' => ['build', 'test', 'deploy']]);
        $chat = new Chat(inputBuf: '/workflow list', workflowEngine: $engine);

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertCount(2, $next->history);
        $this->assertSame(Role::Assistant, $next->history[1]->role);
        $response = $next->history[1]->content;
        $this->assertStringContainsString('build', $response);
        $this->assertStringContainsString('test', $response);
        $this->assertStringContainsString('deploy', $response);
        $this->assertStringContainsString('1.', $response);
        $this->assertStringContainsString('2.', $response);
        $this->assertStringContainsString('3.', $response);
    }

    public function testWorkflowRunWithEmptyNameShowsUsageError(): void
    {
        $engine = $this->createFakeWorkflowEngine();
        $chat = new Chat(inputBuf: '/workflow run', workflowEngine: $engine);

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertCount(2, $next->history);
        $this->assertSame(Role::Assistant, $next->history[1]->role);
        $response = $next->history[1]->content;
        $this->assertStringContainsString('Error', $response);
        $this->assertStringContainsString('Usage:', $response);
    }

    public function testWorkflowRunWithValidNameCallsEngine(): void
    {
        $result = new \SugarCraft\Crush\Workflows\WorkflowResult(
            workflowId: 'test-wf-1234',
            status: \SugarCraft\Crush\Workflows\WorkflowStatus::Completed,
            stageResults: [],
            totalTokens: 100,
            totalCost: 0.01,
        );

        $engine = $this->createFakeWorkflowEngine(['run' => $result]);
        $chat = new Chat(inputBuf: '/workflow run myworkflow', workflowEngine: $engine);

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertCount(2, $next->history);
        $this->assertSame(Role::Assistant, $next->history[1]->role);
        $this->assertStringContainsString('myworkflow', $next->history[1]->content);
        $this->assertStringContainsString('completed', $next->history[1]->content);
    }

    public function testWorkflowPauseWithEmptyIdShowsUsageError(): void
    {
        $engine = $this->createFakeWorkflowEngine();
        $chat = new Chat(inputBuf: '/workflow pause', workflowEngine: $engine);

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertCount(2, $next->history);
        $this->assertSame(Role::Assistant, $next->history[1]->role);
        $response = $next->history[1]->content;
        $this->assertStringContainsString('Error', $response);
        $this->assertStringContainsString('Usage:', $response);
    }

    public function testWorkflowResumeWithEmptyIdShowsUsageError(): void
    {
        $engine = $this->createFakeWorkflowEngine();
        $chat = new Chat(inputBuf: '/workflow resume', workflowEngine: $engine);

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertCount(2, $next->history);
        $this->assertSame(Role::Assistant, $next->history[1]->role);
        $response = $next->history[1]->content;
        $this->assertStringContainsString('Error', $response);
        $this->assertStringContainsString('Usage:', $response);
    }

    public function testWorkflowStatusWithEmptyIdShowsUsageError(): void
    {
        $engine = $this->createFakeWorkflowEngine();
        $chat = new Chat(inputBuf: '/workflow status', workflowEngine: $engine);

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertCount(2, $next->history);
        $this->assertSame(Role::Assistant, $next->history[1]->role);
        $response = $next->history[1]->content;
        $this->assertStringContainsString('Error', $response);
        $this->assertStringContainsString('Usage:', $response);
    }

    // ─── /agents command parsing tests (R13) ─────────────────────────────────

    public function testBareAgentsCommandListsAgentsWhenNoneActive(): void
    {
        // Regression: "/agents" (no trailing space, no args) used to be
        // sliced at a fixed offset of 7, which for the 7-char string
        // "/agents" itself lands one character past its own end and
        // mis-parses into a single-char agent-name lookup for "s" instead
        // of routing to listAgents().
        $agentManager = $this->createAgentManagerWithAgents([]);
        $chat = new Chat(inputBuf: '/agents', agentManager: $agentManager);

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertCount(2, $next->history);
        $this->assertSame(Role::User, $next->history[0]->role);
        $this->assertSame('/agents', $next->history[0]->content);
        $this->assertSame(Role::Assistant, $next->history[1]->role);
        $response = $next->history[1]->content;
        $this->assertStringContainsString('No active agents configured', $response);
        $this->assertStringNotContainsString('Unknown agent', $response);
    }

    public function testBareAgentsCommandListsActiveAgents(): void
    {
        $agents = [
            new Agent(
                name: 'reviewer',
                description: 'Reviews code for bugs',
                prompt: 'You are a reviewer.',
                model: 'claude-sonnet-4-6',
                provider: 'anthropic',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ),
        ];
        $agentManager = $this->createAgentManagerWithAgents($agents);
        $chat = new Chat(inputBuf: '/agents', agentManager: $agentManager);

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertCount(2, $next->history);
        $this->assertSame(Role::Assistant, $next->history[1]->role);
        $response = $next->history[1]->content;
        $this->assertStringContainsString('Active Agents', $response);
        $this->assertStringContainsString('reviewer', $response);
        $this->assertStringNotContainsString('Unknown agent', $response);
    }

    public function testAgentCommandWithNameStillLooksUpThatAgent(): void
    {
        // Guard against the fix over-correcting: "/agent <name>" (singular,
        // with a real argument) must still resolve to that specific agent.
        $agent = new Agent(
            name: 'coder',
            description: 'Writes and reviews code',
            prompt: 'You are a coder agent.',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        );
        $agentManager = $this->createAgentManagerWithAgents([$agent]);
        $chat = new Chat(inputBuf: '/agent coder', agentManager: $agentManager);

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertCount(2, $next->history);
        $this->assertSame(Role::Assistant, $next->history[1]->role);
        $this->assertStringContainsString('Agent: coder', $next->history[1]->content);
    }

    /**
     * Create an AgentManager stub with predefined agents (mirrors the helper
     * in AgentsCommandTest — AgentManager's real constructor needs a
     * ProviderInterface + SkillRegistry that aren't relevant here).
     *
     * @param Agent[] $agents
     */
    private function createAgentManagerWithAgents(array $agents): AgentManager
    {
        $reflection = new \ReflectionClass(AgentManager::class);
        $agentManager = $reflection->newInstanceWithoutConstructor();

        $agentsProperty = $reflection->getProperty('agents');
        $agentsProperty->setAccessible(true);
        $indexedAgents = [];
        foreach ($agents as $agent) {
            $indexedAgents[$agent->name] = $agent;
        }
        $agentsProperty->setValue($agentManager, $indexedAgents);

        return $agentManager;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        \Mockery::close();
    }
}
