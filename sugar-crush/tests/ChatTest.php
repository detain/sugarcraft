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

    public function testEscDoesNotQuitWhenIdle(): void
    {
        $chat = new Chat();
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertNull($cmd);
        $this->assertInstanceOf(Chat::class, $next);
    }

    public function testSingleEscWhileInFlightDoesNotAbort(): void
    {
        $chat = (new Chat(backend: new EchoBackend(), inputBuf: 'hi'))->update(new KeyMsg(KeyType::Enter, ''))[0];
        $this->assertTrue($chat->inFlight);

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertTrue($next->inFlight);
        $this->assertNull($cmd);
    }

    public function testDoubleEscWithinWindowAbortsInFlightRequest(): void
    {
        $chat = (new Chat(backend: new EchoBackend(), inputBuf: 'hi'))->update(new KeyMsg(KeyType::Enter, ''))[0];
        $this->assertTrue($chat->inFlight);

        [$afterFirst] = $chat->update(new KeyMsg(KeyType::Escape, ''));
        [$afterSecond, $cmd] = $afterFirst->update(new KeyMsg(KeyType::Escape, ''));

        $this->assertFalse($afterSecond->inFlight);
        $this->assertNull($cmd);
        $history = $afterSecond->history;
        $this->assertStringContainsString('cancelled', end($history)->content);
    }

    public function testStaleAssistantMsgAfterAbortIsDropped(): void
    {
        $chat = (new Chat(backend: new EchoBackend(), inputBuf: 'hi'))->update(new KeyMsg(KeyType::Enter, ''))[0];
        [$afterFirst] = $chat->update(new KeyMsg(KeyType::Escape, ''));
        [$aborted] = $afterFirst->update(new KeyMsg(KeyType::Escape, ''));

        $historyBeforeStaleReply = $aborted->history;

        // A reply for the aborted turn arrives late, stamped with the
        // generation it was scheduled under - a fresh Chat starts at
        // generation 0, and submit() bumps to 1 for this turn's Cmd (the
        // subsequent abort bumps again to 2, which is why this is stale).
        [$afterStaleReply] = $aborted->update(new AssistantMsg(Message::assistant('late reply'), 1));

        $this->assertSame($historyBeforeStaleReply, $afterStaleReply->history);
    }

    public function testCtrlWDeletesLastWordFromInput(): void
    {
        $chat = new Chat(inputBuf: 'hello there world');
        [$next] = $chat->update(new KeyMsg(KeyType::Char, 'w', ctrl: true));
        $this->assertSame('hello there ', $next->inputBuf);
    }

    public function testAltBackspaceDeletesLastWordFromInput(): void
    {
        $chat = new Chat(inputBuf: 'hello there world');
        [$next] = $chat->update(new KeyMsg(KeyType::Backspace, '', alt: true));
        $this->assertSame('hello there ', $next->inputBuf);
    }

    public function testPlainBackspaceStillDeletesOneCharacter(): void
    {
        $chat = new Chat(inputBuf: 'hello');
        [$next] = $chat->update(new KeyMsg(KeyType::Backspace, ''));
        $this->assertSame('hell', $next->inputBuf);
    }

    public function testAltEnterInsertsNewlineInsteadOfSubmitting(): void
    {
        $chat = new Chat(inputBuf: 'line one');
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, '', alt: true));
        $this->assertNull($cmd);
        $this->assertSame("line one\n", $next->inputBuf);
        $this->assertFalse($next->inFlight);
    }

    public function testShiftEnterInsertsNewlineInsteadOfSubmitting(): void
    {
        $chat = new Chat(inputBuf: 'line one');
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, '', shift: true));
        $this->assertNull($cmd);
        $this->assertSame("line one\n", $next->inputBuf);
    }

    public function testUpArrowRecallsLastSentUserMessageWhenInputEmpty(): void
    {
        $chat = new Chat(history: [
            Message::user('first message'),
            Message::assistant('a reply'),
            Message::user('second message'),
        ]);

        [$next] = $chat->update(new KeyMsg(KeyType::Up, ''));

        $this->assertSame('second message', $next->inputBuf);
    }

    public function testUpArrowDoesNothingWhenNoUserMessageInHistory(): void
    {
        $chat = new Chat();
        [$next] = $chat->update(new KeyMsg(KeyType::Up, ''));
        $this->assertSame('', $next->inputBuf);
    }

    public function testUpArrowNavigatesSlashMenuInsteadOfRecallingWhenPopupShowing(): void
    {
        $chat = new Chat(
            history: [Message::user('should not be recalled')],
            inputBuf: '/th',
        );

        [$next] = $chat->update(new KeyMsg(KeyType::Up, ''));

        // The "/" popup claimed this Up press - inputBuf is untouched
        // (only slashMenuIndex moves), not overwritten with history recall.
        $this->assertSame('/th', $next->inputBuf);
    }

    public function testExitCommandQuits(): void
    {
        $chat = new Chat(inputBuf: '/exit');
        [, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertInstanceOf(\SugarCraft\Core\Msg\QuitMsg::class, $cmd());
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
                public function completeAsync(array $history, callable $onToken = null, ?\SugarCraft\Crush\Backend\CancellationToken $cancellation = null): PromiseInterface
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
                public function completeAsync(array $history, callable $onToken = null, ?\SugarCraft\Crush\Backend\CancellationToken $cancellation = null): PromiseInterface
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

        [$afterPlaceholders, $next] = $this->runToolCallsToCompletion($chat, $message);

        // "running" placeholder was visible before the result arrived.
        $this->assertCount(3, $afterPlaceholders->history);
        $this->assertNotNull($afterPlaceholders->history[2]->pendingToolCallId);

        // Tool executed in a forked child (see runToolCallsToCompletion()) -
        // $executedArgs, captured by reference, can't cross that boundary
        // (same reasoning documented on forkToolCalls()); the real args
        // ARE observable via the finished result's own content instead.
        $this->assertNull($executedArgs);

        // A follow-up backend call should be scheduled
        $this->assertTrue($next->inFlight);
        // History: user msg + assistant msg + tool result msg
        $this->assertCount(3, $next->history);
        $this->assertStringContainsString('total 0', $next->history[2]->content);
    }

    public function testToolResultAddedToHistoryAfterExecution(): void
    {
        $toolCall = new \SugarCraft\Crush\ToolCall('echo', ['text' => 'hello']);
        $message = Message::assistant('Echoing...')->withToolCalls([$toolCall]);

        $chat = (new Chat(
            history: [Message::user('say hello')],
            inFlight: true,
        ))->registerTool('echo', static fn(array $args) => $args['text'] ?? '');

        [, $next] = $this->runToolCallsToCompletion($chat, $message);

        // After tool execution, history should have 3 items:
        // user msg, assistant msg with tool call, tool result
        $this->assertCount(3, $next->history);
        // Message::withToolResults() used to discard both the passed-in
        // ToolResult array AND the message's own content, so every tool
        // result rendered as a blank assistant bubble - the actual bug
        // behind "tool calls are silent in the chat window". Fixed to
        // preserve content and actually carry the results.
        $this->assertSame('hello', $next->history[2]->content);
        $this->assertCount(1, $next->history[2]->toolResults);
        $this->assertSame('echo', $next->history[2]->toolResults[0]->name);
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

        [, $next] = $this->runToolCallsToCompletion($chat, $message);

        // History should have user msg, assistant msg, and error result
        $this->assertCount(3, $next->history);
        $this->assertStringContainsString('Tool failed intentionally', $next->history[2]->content);
        $this->assertTrue($next->history[2]->toolResults[0]->isError());
    }

    public function testMultipleToolCallsWithPoolConfiguredExecuteRealTools(): void
    {
        // R14b.fix: with 2+ tool calls AND a non-null pool configured,
        // execution now goes through executeToolsParallel()'s direct
        // pcntl_fork() fan-out (see its docblock) rather than the old
        // AgentWorkerPool/SubAgent/ProcessExecutor detour that could only
        // fabricate output. A stub AgentWorkerPool executor is still wired
        // here (unused by the new code path) purely to prove that: even
        // configuring one has no bearing on tool output correctness anymore -
        // real registered-tool closures run and their real return values are
        // what the onToolCall listener observes, never anything the executor
        // would have fabricated.
        $executor = new class implements ExecutorInterface {
            public function execute(\SugarCraft\Crush\Agents\SubAgent $agent, CompleteRequest $request): AgentResult
            {
                return new AgentResult($agent->id, \SugarCraft\Crush\Agents\AgentStatus::Completed, 'FABRICATED-NOT-REAL-OUTPUT');
            }

            public function executeStream(\SugarCraft\Crush\Agents\SubAgent $agent, CompleteRequest $request): \Generator
            {
                yield new AgentResult($agent->id, \SugarCraft\Crush\Agents\AgentStatus::Completed, 'FABRICATED-NOT-REAL-OUTPUT');
            }

            public function cancel(string $agentId): void {}
            public function cancelAll(): void {}
        };
        $pool = new AgentWorkerPool(maxConcurrent: 2, executor: $executor);

        $toolCallA = new \SugarCraft\Crush\ToolCall('toolA', ['x' => 1]);
        $toolCallB = new \SugarCraft\Crush\ToolCall('toolB', ['y' => 2]);
        $message = Message::assistant('Calling two tools...')->withToolCalls([$toolCallA, $toolCallB]);

        $observed = [];
        $chat = (new Chat(
            history: [Message::user('do two things')],
            inFlight: true,
        ))
            ->withWorkerPool($pool)
            ->onToolCall(function (string $name, array $args, mixed $result) use (&$observed): void {
                $observed[$name] = $result;
            })
            ->registerTool('toolA', static fn(array $args) => 'REAL-TOOL-A-OUTPUT')
            ->registerTool('toolB', static fn(array $args) => 'REAL-TOOL-B-OUTPUT');

        [, $next] = $this->runToolCallsToCompletion($chat, $message);

        // Both real tool closures ran (whether via a forked child or the
        // pcntl-unavailable sequential fallback) and their real output was
        // observed, keyed correctly by name despite running concurrently.
        $this->assertSame(
            ['toolA' => 'REAL-TOOL-A-OUTPUT', 'toolB' => 'REAL-TOOL-B-OUTPUT'],
            $observed
        );
        $this->assertNotContains('FABRICATED-NOT-REAL-OUTPUT', $observed);

        // History: user msg + assistant msg + tool result A + tool result B
        $this->assertCount(4, $next->history);
        $this->assertTrue($next->inFlight);
    }

    public function testParallelToolCallsActuallyRunInSeparateForkedProcesses(): void
    {
        // Deterministic proxy for "this really is parallel, not just
        // sequential-with-extra-steps": each tool closure reports its own
        // getmypid(). If executeToolsParallel() forked one child per call (as
        // designed), every reported PID differs both from each other and
        // from this test's own process - the only way that's possible given
        // invokeTool() is a pure, synchronous call with no other source of a
        // different PID. A single shared PID (or this test's own PID) would
        // mean execution silently fell back to running in-process.
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available in this environment.');
        }

        $toolCallA = new \SugarCraft\Crush\ToolCall('pidA', []);
        $toolCallB = new \SugarCraft\Crush\ToolCall('pidB', []);
        $message = Message::assistant('Calling two tools...')->withToolCalls([$toolCallA, $toolCallB]);

        $pool = new AgentWorkerPool(maxConcurrent: 2);
        $observedPids = [];
        $chat = (new Chat(history: [Message::user('report pids')], inFlight: true))
            ->withWorkerPool($pool)
            ->onToolCall(function (string $name, array $args, mixed $result) use (&$observedPids): void {
                $observedPids[$name] = $result;
            })
            ->registerTool('pidA', static fn(array $args) => getmypid())
            ->registerTool('pidB', static fn(array $args) => getmypid());

        $this->runToolCallsToCompletion($chat, $message);

        $this->assertCount(2, $observedPids);
        $this->assertNotSame($observedPids['pidA'], getmypid());
        $this->assertNotSame($observedPids['pidB'], getmypid());
        $this->assertNotSame($observedPids['pidA'], $observedPids['pidB']);
    }

    public function testSingleToolCallAlsoRunsInAForkedChildForLiveVisibility(): void
    {
        // Every tool call forks now, single or not - beginToolCalls()'s
        // "running" placeholder (shown the instant the call is dispatched,
        // before it finishes - see Message::toolRunning()) needs the render
        // loop to keep ticking while even a lone, potentially slow tool call
        // runs, the same reason EngineBackend::completeAsync() forks the
        // provider call. A shared PID here would mean it silently fell back
        // to blocking in-process, defeating that visibility.
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available in this environment.');
        }

        $toolCall = new \SugarCraft\Crush\ToolCall('solo', []);
        $message = Message::assistant('Calling one tool...')->withToolCalls([$toolCall]);

        $observedPid = null;
        $chat = (new Chat(history: [Message::user('report pid')], inFlight: true))
            ->onToolCall(function (string $name, array $args, mixed $result) use (&$observedPid): void {
                $observedPid = $result;
            })
            ->registerTool('solo', static fn(array $args) => getmypid());

        $this->runToolCallsToCompletion($chat, $message);

        $this->assertNotSame(getmypid(), $observedPid);
    }

    public function testSlashMenuFiltersAsUserTypes(): void
    {
        $chat = new Chat(inputBuf: '/');
        $this->assertGreaterThan(1, count($chat->slashMenuMatches()));

        [$narrowed] = $chat->update(new KeyMsg(KeyType::Char, 'b'));
        $names = array_map(static fn($spec) => $spec->name, $narrowed->slashMenuMatches());
        $this->assertSame(['branch'], $names);
    }

    public function testSlashMenuHiddenOnceArgumentsStart(): void
    {
        $chat = new Chat(inputBuf: '/rename');
        [$next] = $chat->update(new KeyMsg(KeyType::Space, ''));
        $this->assertSame([], $next->slashMenuMatches());
    }

    public function testSlashMenuUpDownWrapsSelection(): void
    {
        $chat = new Chat(inputBuf: '/re'); // matches: rename, rewind
        $this->assertSame(0, $chat->slashMenuIndex());

        [$down] = $chat->update(new KeyMsg(KeyType::Down, ''));
        $this->assertSame(1, $down->slashMenuIndex());

        [$wrapped] = $down->update(new KeyMsg(KeyType::Down, ''));
        $this->assertSame(0, $wrapped->slashMenuIndex());

        [$up] = $wrapped->update(new KeyMsg(KeyType::Up, ''));
        $this->assertSame(1, $up->slashMenuIndex());
    }

    public function testEnterCompletesAmbiguousMatchInsteadOfSubmitting(): void
    {
        $chat = new Chat(inputBuf: '/re'); // matches: rename, rewind — ambiguous
        [$next] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame('/rename ', $next->inputBuf);
        $this->assertSame([], $next->history); // not submitted
    }

    public function testEnterSubmitsExactCommandMatchInsteadOfRefilling(): void
    {
        // Only one command starts with "sessions" and the typed text is an
        // exact match for it — Enter must submit, not re-fill the same text.
        $chat = new Chat(inputBuf: '/sessions');
        [$next] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNotSame('/sessions ', $next->inputBuf);
        $this->assertNotSame([], $next->history);
    }

    public function testContextUsagePercentIsZeroForEmptyHistory(): void
    {
        $chat = new Chat();
        $this->assertSame(0.0, $chat->contextUsagePercent());
    }

    public function testContextUsagePercentGrowsWithHistorySize(): void
    {
        $short = new Chat(history: [Message::user('hi')]);
        $long = new Chat(history: [Message::user(str_repeat('a', 40000))]);

        $this->assertGreaterThan($short->contextUsagePercent(), $long->contextUsagePercent());
    }

    public function testDownArrowIsNoOpOutsideSlashMenu(): void
    {
        $chat = new Chat(inputBuf: 'hello');
        [$next] = $chat->update(new KeyMsg(KeyType::Down, ''));
        $this->assertSame($chat, $next);
    }

    public function testThemeCommandWithNoArgsShowsCurrentAndAvailable(): void
    {
        $chat = new Chat(inputBuf: '/theme');
        [$next] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame('dark', $next->theme()->name);
        $this->assertStringContainsString('dark', $next->history[1]->content);
        $this->assertStringContainsString('dracula', $next->history[1]->content);
    }

    public function testThemeCommandSwitchesTheme(): void
    {
        $chat = new Chat(inputBuf: '/theme dracula');
        [$next] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame('dracula', $next->theme()->name);
        $this->assertSame('', $next->inputBuf);
    }

    public function testThemeCommandFiresOnConfigChangeForPersistence(): void
    {
        $observed = [];
        $chat = (new Chat(inputBuf: '/theme dracula'))
            ->withOnConfigChange(function (string $key, string $value) use (&$observed): void {
                $observed[] = [$key, $value];
            });

        $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame([['theme', 'dracula']], $observed);
    }

    public function testPaletteSwitchThemeFiresOnConfigChangeForPersistence(): void
    {
        $observed = [];
        $chat = (new Chat())->withOnConfigChange(function (string $key, string $value) use (&$observed): void {
            $observed[] = [$key, $value];
        });

        [$opened] = $chat->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $current = $opened;
        foreach (str_split('switch theme') as $ch) {
            [$current] = $current->update(new KeyMsg(KeyType::Char, $ch === ' ' ? ' ' : $ch));
        }
        [$inThemes] = $current->update(new KeyMsg(KeyType::Enter, ''));
        [$inThemes] = $inThemes->update(new KeyMsg(KeyType::Enter, '')); // selects the top match ('dark')

        $this->assertSame([['theme', 'dark']], $observed);
    }

    public function testThemeCommandWithUnknownNameLeavesThemeUnchanged(): void
    {
        $chat = new Chat(inputBuf: '/theme nonexistent');
        [$next] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame('dark', $next->theme()->name);
        $this->assertStringContainsString('Unknown theme', $next->history[1]->content);
    }

    public function testCtrlPOpensPaletteAndEscapeCloses(): void
    {
        $chat = new Chat();
        $this->assertNull($chat->palette());

        [$opened] = $chat->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $this->assertNotNull($opened->palette());
        $this->assertSame('root', $opened->palette()->mode);

        [$closed] = $opened->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertNull($closed->palette());
    }

    public function testSecondCtrlPClosesAnOpenPalette(): void
    {
        $chat = new Chat();
        [$opened] = $chat->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        [$closed] = $opened->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $this->assertNull($closed->palette());
    }

    public function testPaletteQueryFiltersActionsAndResetsSelection(): void
    {
        $chat = new Chat();
        [$opened] = $chat->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        [$down] = $opened->update(new KeyMsg(KeyType::Down, ''));
        $this->assertSame(1, $down->palette()->selectedIndex);

        $current = $down;
        foreach (str_split('theme') as $ch) {
            [$current] = $current->update(new KeyMsg(KeyType::Char, $ch));
        }

        $this->assertSame(0, $current->palette()->selectedIndex);
        $this->assertSame('Switch theme', $current->paletteMatches()[0]);
    }

    public function testPaletteUpDownWraps(): void
    {
        $chat = new Chat();
        [$opened] = $chat->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $count = count($opened->paletteMatches());

        [$up] = $opened->update(new KeyMsg(KeyType::Up, ''));
        $this->assertSame($count - 1, $up->palette()->selectedIndex);
    }

    public function testPaletteEnterOnExitQuits(): void
    {
        $chat = new Chat();
        [$opened] = $chat->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));

        $exitIndex = array_search('Exit', $opened->paletteMatches(), true);
        $current = $opened;
        for ($i = 0; $i < $exitIndex; $i++) {
            [$current] = $current->update(new KeyMsg(KeyType::Down, ''));
        }

        [$next, $cmd] = $current->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNull($next->palette());
        $this->assertNotNull($cmd);
    }

    public function testPaletteEnterOnShareSessionDispatchesRealHandlerAndCloses(): void
    {
        $chat = new Chat();
        [$opened] = $chat->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));

        $current = $opened;
        foreach (str_split('share') as $ch) {
            [$current] = $current->update(new KeyMsg(KeyType::Char, $ch));
        }
        $this->assertSame('Share session', $current->paletteMatches()[0]);

        // A bare Chat() has no session store, so the real ShareCommand
        // handler this dispatches through legitimately has nothing to
        // share and reports an error via the print-closure path rather
        // than history - the point proven here is that dispatch reached
        // the real handler (and closed the palette) at all, not that it
        // necessarily succeeded.
        [$next, $cmd] = $current->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNull($next->palette());
        $this->assertNotNull($cmd);
    }

    public function testPaletteSwitchModelTransitionsToProviderListWithoutClosing(): void
    {
        $chat = new Chat();
        [$opened] = $chat->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));

        $current = $opened;
        foreach (str_split('switch model') as $ch) {
            [$current] = $current->update(new KeyMsg(KeyType::Char, $ch === ' ' ? ' ' : $ch));
        }
        $this->assertSame('Switch model', $current->paletteMatches()[0]);

        [$next] = $current->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNotNull($next->palette());
        $this->assertSame('providers', $next->palette()->mode);
        $this->assertContains('sglang', $next->paletteMatches());
    }

    public function testPaletteSwitchThemeTransitionsAndSelectingAThemeAppliesIt(): void
    {
        $chat = new Chat();
        [$opened] = $chat->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));

        $current = $opened;
        foreach (str_split('switch theme') as $ch) {
            [$current] = $current->update(new KeyMsg(KeyType::Char, $ch === ' ' ? ' ' : $ch));
        }
        [$inThemes] = $current->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame('themes', $inThemes->palette()->mode);
        $this->assertSame(['dark', 'light', 'dracula', 'tokyoNight', 'ansi'], $inThemes->paletteMatches());

        $draculaIndex = array_search('dracula', $inThemes->paletteMatches(), true);
        $current = $inThemes;
        for ($i = 0; $i < $draculaIndex; $i++) {
            [$current] = $current->update(new KeyMsg(KeyType::Down, ''));
        }

        [$next] = $current->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNull($next->palette());
        $this->assertSame('dracula', $next->theme()->name);
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
    /**
     * view() used to compute its own cell-level diff and return only the
     * changed bytes for a repeat frame - but Program's own Renderer ALSO
     * diffs whatever a Model's view() returns, so that pre-diffed byte
     * soup was being diffed a second time against unrelated bytes from the
     * previous call, producing wrong cursor placement (visible as typed
     * text/replies landing in the wrong row - e.g. the status bar - once a
     * conversation grew past a single frame). view() now always returns
     * the full literal frame; the real diffing happens once, correctly, in
     * candy-core's Program/Renderer.
     */
    public function testViewReturnsTheFullFrameOnEveryCall(): void
    {
        $chat = new Chat(history: [Message::user('hello'), Message::assistant('Hi there!')]);

        $out1 = $chat->view();
        [$chat2] = $chat->update(new KeyMsg(KeyType::Char, '!'));
        $out2 = $chat2->view();

        $this->assertStringContainsString('hello', $out1);
        $this->assertStringContainsString('hello', $out2);
        $this->assertStringContainsString('!', $out2);
        // Both are full frames of the same conversation at the same terminal
        // size - comparable magnitude, not a shrinking delta.
        $this->assertGreaterThan(0.5 * \strlen($out1), \strlen($out2));
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
        // Use a custom executor that immediately returns a result. AgentResult
        // has no ok() factory — use the real constructor with an explicit
        // AgentStatus, mirroring the pattern in
        // testMultipleToolCallsWithPoolConfiguredExecuteRealToolsSequentially().
        $executor = new class implements ExecutorInterface {
            public function execute(\SugarCraft\Crush\Agents\SubAgent $agent, CompleteRequest $request): AgentResult
            {
                return new AgentResult($agent->id, \SugarCraft\Crush\Agents\AgentStatus::Completed, 'done');
            }

            public function executeStream(\SugarCraft\Crush\Agents\SubAgent $agent, CompleteRequest $request): \Generator
            {
                yield new AgentResult($agent->id, \SugarCraft\Crush\Agents\AgentStatus::Completed, 'done');
            }

            public function cancel(string $agentId): void {}
            public function cancelAll(): void {}
        };

        $pool = new AgentWorkerPool(maxConcurrent: 1, executor: $executor);
        $chat = (new Chat())->withWorkerPool($pool);

        // Non-empty agents array so this actually dispatches through the pool's
        // happy path rather than short-circuiting on executeAll()'s `$agents === []`
        // early return.
        $subAgent = new \SugarCraft\Crush\Agents\SubAgent(
            id: 'explicit-pool-agent',
            agent: new Agent(
                name: 'ExplicitPoolAgent',
                description: 'Agent for explicit-pool dispatch test',
                prompt: 'You are a test agent.',
                model: 'test-model',
                provider: 'test',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ),
            task: 'do the thing',
        );

        $results = $chat->executeAgents([$subAgent], new CompleteRequest(
            model: 'test',
            messages: [],
        ));
        $this->assertInstanceOf(\Generator::class, $results);

        $collected = iterator_to_array($results, false);
        $this->assertCount(1, $collected);
        $this->assertSame('explicit-pool-agent', $collected[0]->agentId);
        $this->assertTrue($collected[0]->isSuccess());
        $this->assertSame('done', $collected[0]->output);
    }

    public function testExecuteAgentsBuildsPoolFromConfig(): void
    {
        $config = new AgentPoolConfig(maxConcurrent: 2);
        $chat = (new Chat())->withAgentPoolConfig($config);

        // Accessor confirms config is set
        $this->assertSame($config, $chat->agentPoolConfig());

        // Pool is built from config when executeAgents is called with no pool set.
        // Use a non-empty agents array so this actually exercises the built pool's
        // default ProcessExecutor happy path (a self-contained inline worker
        // simulation with no real network calls — see ProcessExecutorTest) rather
        // than short-circuiting on an empty array.
        $subAgent = new \SugarCraft\Crush\Agents\SubAgent(
            id: 'config-built-pool-agent',
            agent: new Agent(
                name: 'ConfigBuiltPoolAgent',
                description: 'Agent for config-built-pool dispatch test',
                prompt: 'You are a test agent.',
                model: 'test-model',
                provider: 'test',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ),
            task: 'Say hello',
        );

        $results = $chat->executeAgents([$subAgent], new CompleteRequest(
            model: 'test-model',
            messages: [],
        ));
        $this->assertInstanceOf(\Generator::class, $results);

        $collected = iterator_to_array($results, false);
        $this->assertCount(1, $collected);
        $this->assertSame('config-built-pool-agent', $collected[0]->agentId);
        $this->assertTrue($collected[0]->isSuccess());
        $this->assertSame(
            '[ConfigBuiltPoolAgent] Task finished: Say hello',
            $collected[0]->output,
        );
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

    public function testWorkflowHelpHonestlyDescribesPauseResumeLimitation(): void
    {
        $engine = $this->createFakeWorkflowEngine();
        $chat = new Chat(inputBuf: '/workflow help', workflowEngine: $engine);

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $response = $next->history[1]->content;
        $this->assertStringContainsString('per-whole-stage only', $response);
        $this->assertStringContainsString('parallel', $response);
        $this->assertStringContainsString('partial-credit resume', $response);
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

    // ─── Ctrl+A keyboard shortcut (R20) ──────────────────────────────────────

    /**
     * R20: Ctrl+A re-runs the exact real /agents dispatch (handleAgentsCommand())
     * that a typed "/agents" already uses, rather than a separate/duplicated
     * implementation — see Chat::update()'s KeyMsg match arm docblock.
     */
    public function testCtrlAKeyRunsRealAgentsCommandDispatch(): void
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
        $chat = new Chat(inputBuf: 'unsubmitted draft', agentManager: $agentManager);

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Char, rune: 'a', ctrl: true));

        $this->assertNull($cmd);
        $this->assertSame('', $next->inputBuf);
        $this->assertCount(2, $next->history);
        $this->assertSame(Role::User, $next->history[0]->role);
        $this->assertSame('/agents', $next->history[0]->content);
        $this->assertSame(Role::Assistant, $next->history[1]->role);
        $this->assertStringContainsString('Active Agents', $next->history[1]->content);
        $this->assertStringContainsString('reviewer', $next->history[1]->content);
    }

    /**
     * Guard against the fix over-reaching: plain "a" (no ctrl) must still be
     * typed into the input buffer like any other character.
     */
    public function testPlainAKeyIsTypedNotDispatched(): void
    {
        $chat = new Chat(inputBuf: 'h');

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Char, rune: 'a', ctrl: false));

        $this->assertNull($cmd);
        $this->assertSame('ha', $next->inputBuf);
        $this->assertCount(0, $next->history);
    }

    /**
     * R20.fix regression: `Bootstrap::chat()` (the construction path
     * `bin/sugarcrush` actually runs) never passes an `agentManager:`, so a
     * typed "/agents" with no `agentManager` configured used to throw an
     * uncaught `RuntimeException('AgentManager not set')` straight out of
     * `Chat::update()` — candy-core's `Program` has no try/catch around its
     * synchronous update() dispatch, so this crashed the whole live CLI
     * instead of degrading like every other optional collaborator here
     * (workflow engine / session store / memory store).
     */
    public function testAgentsCommandDegradesGracefullyWithoutAgentManagerInsteadOfThrowing(): void
    {
        $chat = new Chat(inputBuf: '/agents');

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($cmd);
        $this->assertCount(2, $next->history);
        $this->assertSame(Role::User, $next->history[0]->role);
        $this->assertSame(Role::Assistant, $next->history[1]->role);
        $this->assertStringContainsString('Agent manager not configured', $next->history[1]->content);
    }

    /**
     * Same regression as above, but via the new Ctrl+A shortcut this item
     * added — a single accidental keystroke used to trigger the crash
     * rather than requiring a user to type out "/agents" by hand.
     */
    public function testCtrlAKeyDegradesGracefullyWithoutAgentManagerInsteadOfThrowing(): void
    {
        $chat = new Chat(inputBuf: 'unsubmitted draft');

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Char, rune: 'a', ctrl: true));

        $this->assertNull($cmd);
        $this->assertSame('', $next->inputBuf);
        $this->assertCount(2, $next->history);
        $this->assertSame('/agents', $next->history[0]->content);
        $this->assertStringContainsString('Agent manager not configured', $next->history[1]->content);
    }

    // =========================================================================
    // Idle-compaction wiring (R-idle-compaction): shouldPromptIdleCompaction()
    // must be exercised through the real submit() dispatch path, not only
    // called directly as a standalone predicate.
    // =========================================================================

    public function testSubmitPromptsIdleCompactionInsteadOfCallingBackendWhenIdleAndOversized(): void
    {
        // ~500,000 chars ≈ 125,010 estimated tokens (1 token ≈ 4 chars + 10
        // overhead), comfortably over the 100,000-token threshold.
        $bigMessage = Message::user(str_repeat('x', 500_000));
        $chat = (new Chat(history: [$bigMessage], inputBuf: 'hello again'))
            ->withLastActivity(new \DateTimeImmutable('2 hours ago'));

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        // Short-circuited locally, same shape as a slash command: no Cmd
        // dispatched to the backend for this turn.
        $this->assertNull($cmd);
        $this->assertFalse($next->inFlight);
        $this->assertSame('', $next->inputBuf);

        $this->assertCount(3, $next->history);
        $this->assertSame(Role::User, $next->history[1]->role);
        $this->assertSame('hello again', $next->history[1]->content);
        $this->assertSame(Role::Assistant, $next->history[2]->role);
        $this->assertStringContainsString('/compact', $next->history[2]->content);

        // The nudge itself counts as fresh activity, resetting the idle
        // clock so it doesn't repeat on the very next message.
        $this->assertNotNull($next->lastActivityAt());
        $this->assertGreaterThan(
            (new \DateTimeImmutable('5 seconds ago'))->getTimestamp(),
            $next->lastActivityAt()->getTimestamp(),
        );
    }

    public function testSubmitDispatchesToBackendNormallyWhenRecentlyActiveDespiteOversizedHistory(): void
    {
        $bigMessage = Message::user(str_repeat('x', 500_000));
        $chat = (new Chat(history: [$bigMessage], inputBuf: 'hello'))
            ->withLastActivity(new \DateTimeImmutable('5 minutes ago'));

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertTrue($next->inFlight);
        // History is also well over the 70% reminder tier here (see
        // R-reminder-consumer tests below), so a Role::System notice rides
        // along after the user turn — a soft, non-blocking addition, unlike
        // the hard idle-compaction short-circuit this test is guarding.
        $this->assertCount(3, $next->history);
        $this->assertSame('hello', $next->history[1]->content);
        $this->assertSame(Role::System, $next->history[2]->role);
    }

    public function testSubmitDispatchesToBackendNormallyWhenIdleButHistorySmall(): void
    {
        $chat = (new Chat(history: [Message::user('hi')], inputBuf: 'hello'))
            ->withLastActivity(new \DateTimeImmutable('2 hours ago'));

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertTrue($next->inFlight);
        $this->assertCount(2, $next->history);
    }

    public function testFreshChatHasNoLastActivityAndSubmitRecordsOneOnRealPrompt(): void
    {
        $chat = new Chat(inputBuf: 'hello');
        $this->assertNull($chat->lastActivityAt());

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        // No lastActivityAt yet means shouldPromptIdleCompaction() can never
        // fire regardless of token count, so this still dispatches normally.
        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertNotNull($next->lastActivityAt());
    }

    public function testWithLastActivityIsFluentAndImmutable(): void
    {
        $chat = new Chat();
        $t = new \DateTimeImmutable('1 hour ago');
        $next = $chat->withLastActivity($t);

        $this->assertNull($chat->lastActivityAt());
        $this->assertSame($t, $next->lastActivityAt());
        $this->assertNotSame($chat, $next);
    }

    // =========================================================================
    // Reminder-tier wiring (R-reminder-consumer): ContextCompactor's 70%
    // shouldSendReminder() (added by R21) must be exercised through the real
    // submit() dispatch path, not only called directly as a standalone
    // predicate — and it must surface as a soft, non-blocking notice distinct
    // from the hard idle-compaction prompt above (which short-circuits the
    // turn instead of calling the backend).
    // =========================================================================

    public function testSubmitSurfacesReminderMessageAlongsideRealPromptWhenOverReminderThreshold(): void
    {
        // ~280,000 chars ≈ 70,010 estimated tokens (1 token≈4 chars + 10
        // overhead) — over ContextCompactor's default 70% reminder tier of
        // Chat's 100,000-token proxy limit (70,000), but comfortably under
        // the 100,000-token hard idle-compaction threshold.
        $bigMessage = Message::user(str_repeat('x', 280_000));
        $chat = new Chat(history: [$bigMessage], inputBuf: 'hello');

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        // Unlike the hard idle-compaction prompt, this does NOT short-circuit
        // the turn: a real backend Cmd is still scheduled.
        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertTrue($next->inFlight);
        $this->assertSame('', $next->inputBuf);

        $this->assertCount(3, $next->history);
        $this->assertSame(Role::User, $next->history[1]->role);
        $this->assertSame('hello', $next->history[1]->content);

        // Distinct from the hard prompt's Role::Assistant bubble: the
        // reminder rides along as a Role::System notice.
        $this->assertSame(Role::System, $next->history[2]->role);
        $this->assertStringContainsString('/compact', $next->history[2]->content);
        $this->assertStringContainsString('70010', $next->history[2]->content);
    }

    public function testSubmitOmitsReminderMessageWhenUnderReminderThreshold(): void
    {
        $chat = new Chat(history: [Message::user('hi')], inputBuf: 'hello');

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertCount(2, $next->history);
        $this->assertSame(Role::User, $next->history[1]->role);
        $this->assertSame('hello', $next->history[1]->content);
    }

    public function testReminderMessageResolvesThroughEchoBackendWithoutBreakingTheTurn(): void
    {
        $bigMessage = Message::user(str_repeat('x', 280_000));
        $chat = new Chat(history: [$bigMessage], inputBuf: 'hello', backend: new EchoBackend());

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertInstanceOf(\Closure::class, $cmd);

        // Drive the scheduled Cmd exactly like testEchoBackendRoundTrip: the
        // AsyncCmd wraps a promise that resolves synchronously for EchoBackend.
        $asyncCmd = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $asyncCmd);
        $resolvedMsg = null;
        $asyncCmd->promise->then(function ($msg) use (&$resolvedMsg): void {
            $resolvedMsg = $msg;
        });
        $this->assertInstanceOf(AssistantMsg::class, $resolvedMsg);

        [$final] = $next->update($resolvedMsg);
        $this->assertCount(4, $final->history);
        $this->assertSame(Role::System, $final->history[2]->role);
        $this->assertSame(Role::Assistant, $final->history[3]->role);
        $this->assertFalse($final->inFlight);
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

    /**
     * Drive an assistant message with tool calls through the full async
     * two-step flow: update(AssistantMsg) shows "running" placeholders and
     * returns a Cmd; running that Cmd forks the tool calls and returns a
     * Promise (AsyncCmd) that only settles once every forked child has
     * exited (see Chat::waitForToolChildrenAsync()) - so, unlike the old
     * fully-synchronous handleToolCalls(), the real results (and any
     * onToolCall side effects) aren't observable until the resulting
     * ToolResultsMsg is actually dispatched back into update(). Returns
     * [$afterPlaceholders, $final].
     *
     * @return array{0: Chat, 1: Chat}
     */
    private function runToolCallsToCompletion(Chat $chat, Message $assistantMessage): array
    {
        [$afterPlaceholders, $cmd] = $chat->update(new AssistantMsg($assistantMessage));
        $this->assertInstanceOf(\Closure::class, $cmd);

        $asyncCmd = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $asyncCmd);

        // A single run()/stop() pair - see EngineBackendTest::awaitPromise()'s
        // docblock for why a repeated add-short-timer-then-run() polling
        // dance is fragile against real fork/WNOHANG timing.
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

        $this->assertInstanceOf(\SugarCraft\Crush\ToolResultsMsg::class, $resolved, 'tool execution did not complete within the test timeout');

        [$final] = $afterPlaceholders->update($resolved);

        return [$afterPlaceholders, $final];
    }
}
