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
use SugarCraft\Crush\BackendToolEventsMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Tools\ToolCall as EngineToolCall;
use SugarCraft\Crush\Tools\ToolResult as EngineToolResult;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\PermissionReplyMsg;
use SugarCraft\Crush\Permissions\PermissionReply;
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

    /**
     * W1.G2/E2 fix (reviewer-reported): crush_feat.md section 9's E2
     * literally specifies `new Chat(..., mosaic: $mosaic)` exposing the
     * probe-once candy-mosaic capability instance through Chat's
     * constructor, rather than leaving it a standalone static cache only
     * SugarCraft\Crush\ToolResult can reach.
     */
    public function testMosaicDefaultsToNullWhenNotWired(): void
    {
        $chat = new Chat();

        $this->assertNull($chat->mosaic());
    }

    public function testMosaicIsExposedWhenWiredThroughTheConstructor(): void
    {
        $mosaic = \SugarCraft\Mosaic\Mosaic::halfBlock();
        $chat = new Chat(mosaic: $mosaic);

        $this->assertSame($mosaic, $chat->mosaic());
    }

    public function testMosaicSurvivesMutateViaWithInputBuf(): void
    {
        $mosaic = \SugarCraft\Mosaic\Mosaic::halfBlock();
        $chat = new Chat(mosaic: $mosaic);

        [$next] = $chat->update(new KeyMsg(KeyType::Char, 'a'));

        $this->assertSame($mosaic, $next->mosaic());
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
        $msg = new \SugarCraft\Core\Msg\EnvMsg([]);
        [$next, $cmd] = $chat->update($msg);
        $this->assertSame($chat, $next);
        $this->assertNull($cmd);
    }

    /**
     * Regression: Renderer must lay out against the REAL terminal size, and
     * the only place that size can come from without silently disagreeing
     * with what candy-core's Program itself detected (or missing a live
     * resize) is the WindowSizeMsg Program dispatches at startup and on
     * every SIGWINCH - see rows()/cols()'s docblock.
     */
    public function testWindowSizeMsgUpdatesRowsAndCols(): void
    {
        $chat = new Chat();
        [$next, $cmd] = $chat->update(new \SugarCraft\Core\Msg\WindowSizeMsg(80, 24));

        $this->assertNull($cmd);
        $this->assertSame(24, $next->rows());
        $this->assertSame(80, $next->cols());
    }

    public function testRowsAndColsFallBackToTerminalDetectionBeforeAnyWindowSizeMsg(): void
    {
        $chat = new Chat();
        $this->assertSame(
            \SugarCraft\Crush\Tui\Renderer::getTerminalSize()['rows'],
            $chat->rows(),
        );
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
                public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
                {
                    if ($onToken !== null) {
                        $onToken('token1');
                        $onToken('token2');
                        $onToken('token3');
                    }
                    return \SugarCraft\Crush\Message::assistant('streaming reply');
                }
                public function completeAsync(array $history, callable $onToken = null, ?\SugarCraft\Crush\Backend\CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
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
                public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
                {
                    return \SugarCraft\Crush\Message::assistant('reply');
                }
                public function completeAsync(array $history, callable $onToken = null, ?\SugarCraft\Crush\Backend\CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
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

    /**
     * Regression for the same bug as EngineBackendTest::
     * testCompleteAsyncDoesNotResetTheRealTerminalsRawMode() - forkToolCalls()
     * forks a real child per tool call (see that method's docblock). If the
     * child ends with a plain exit(), its inherited Tty's destructor fires
     * during PHP's shutdown sequence and restores the ORIGINAL (cooked/echo)
     * termios onto the REAL, shared terminal device. Drives a real tool call
     * through the real fork path against a real PTY and asserts the
     * terminal is still in raw mode afterwards.
     */
    public function testToolExecutionDoesNotResetTheRealTerminalsRawMode(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required for termios FFI.');
        }
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl is required to exercise the real fork path.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }

        $pair = (new \SugarCraft\Pty\Posix\PosixPtySystem())->open();
        $slavePath = $pair->slave()->path();

        $libc = \SugarCraft\Pty\Libc::lib();
        $slaveFd = $libc->open($slavePath, 0x0002 /* O_RDWR */);
        if ($slaveFd < 0) {
            $this->markTestSkipped('Could not open slave PTY path: ' . $slavePath);
        }

        $isRaw = static function (string $path): bool {
            // BSD/macOS stty takes the device flag lowercase (-f); GNU/Linux
            // coreutils uses uppercase (-F).
            $flag = PHP_OS_FAMILY === 'Darwin' ? '-f' : '-F';
            $out = trim((string) shell_exec('stty ' . $flag . ' ' . escapeshellarg($path) . ' -a 2>/dev/null'));

            return str_contains($out, '-icanon') && str_contains($out, '-echo');
        };

        // Injected Termios test seam - see EngineBackendTest's matching test
        // for why this bypasses candy-core's (int)-cast fd resolution.
        $tty = new \SugarCraft\Core\Util\Tty(null, new \SugarCraft\Pty\Posix\PosixTermios($slaveFd));
        $tty->enableRawMode();

        try {
            $this->assertTrue($isRaw($slavePath), 'setup: raw mode must be active before running the tool');

            $toolCall = new \SugarCraft\Crush\ToolCall('bash', ['cmd' => 'ls -la']);
            $message = Message::assistant('Running command...')->withToolCalls([$toolCall]);
            $chat = (new Chat(history: [Message::user('list files')], inFlight: true))
                ->registerTool('bash', static fn(array $args) => 'total 0');

            [, $next] = $this->runToolCallsToCompletion($chat, $message);

            $this->assertTrue($next->inFlight, 'sanity: the tool call must actually have run');
            $this->assertTrue(
                $isRaw($slavePath),
                'the real terminal was knocked out of raw mode by forkToolCalls()\'s forked child exiting',
            );
        } finally {
            $tty->restore();
            $libc->close($slaveFd);
            $pair->master()->close();
        }
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

    /**
     * W2.S1b: ToolResult's reconciled $diff/$durationMs (the fields that
     * previously existed only on the engine-side Tools\ToolResult) have to
     * survive Chat's pcntl_fork()/JSON-temp-file seam, or a diff computed by
     * a tool would be silently dropped before Renderer ever sees it - the
     * same class of loss the base64 image fix (W1.G2) closed.
     */
    public function testDiffAndDurationSurviveTheForkedToolResultSeam(): void
    {
        $toolCall = new \SugarCraft\Crush\ToolCall('edit', ['path' => 'a.php'], 'call_diff_1');
        $message = Message::assistant('Editing...')->withToolCalls([$toolCall]);
        $diff = "--- a/a.php\n+++ b/a.php\n@@ -1 +1 @@\n-x\n+y\n";

        $chat = (new Chat(
            history: [Message::user('edit a.php')],
            inFlight: true,
        ))->registerTool('edit', static fn(array $args) => new \SugarCraft\Crush\ToolResult(
            name: 'edit',
            result: 'File updated: a.php',
            id: 'call_diff_1',
            diff: $diff,
            durationMs: 5,
        ));

        [, $next] = $this->runToolCallsToCompletion($chat, $message);

        $result = $next->history[2]->toolResults[0];
        $this->assertSame($diff, $result->diff);
        $this->assertTrue($result->hasDiff());
        $this->assertSame(5, $result->durationMs);
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

    // ---------------------------------------------------------------
    // Backend tool-lifecycle events (crush_feat.md §1 E1, W2.S1c)
    // ---------------------------------------------------------------

    public function testBackendToolEventsSurviveAsAQueuedMsgInsteadOfBeingDropped(): void
    {
        $call = new EngineToolCall('call_1', 'bash', ['command' => 'ls']);
        $chat = new Chat(
            backend: $this->eventEmittingBackend([
                ToolStarted::fromCall($call),
                ToolFinished::fromResult($call, new EngineToolResult('call_1', 'a.txt')),
            ], Message::assistant('there is one file')),
            inputBuf: 'list files',
        );

        // Against the pre-W2.S1c Chat this resolved to a plain AssistantMsg:
        // the backend's $onEvent was never passed, so both tool events were
        // dropped and the turn rendered as a bare "thinking…" spinner.
        $resolved = $this->resolveBackendCmd($chat);
        $this->assertInstanceOf(BackendToolEventsMsg::class, $resolved);
        $this->assertCount(2, $resolved->events);
        $this->assertSame('there is one file', $resolved->message->content);
    }

    public function testToolStartedRendersARunningPlaceholderBeforeTheResultArrives(): void
    {
        $call = new EngineToolCall('call_1', 'bash', ['command' => 'ls']);
        $chat = new Chat(
            backend: $this->eventEmittingBackend([
                ToolStarted::fromCall($call),
                ToolFinished::fromResult($call, new EngineToolResult('call_1', 'a.txt')),
            ], Message::assistant('done')),
            inputBuf: 'list files',
        );

        [$afterSubmit] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        [$running, $cmd] = $afterSubmit->update($this->resolveBackendCmd($chat));

        $placeholder = $running->history[count($running->history) - 1];
        $this->assertSame(Role::System, $placeholder->role);
        $this->assertSame('call_1', $placeholder->pendingToolCallId);
        $this->assertSame('bash(command: "ls")', $placeholder->content);
        // The running state is a real, rendered frame - not folded away in the
        // same update() as its result.
        $this->assertStringContainsString('running: bash(command: "ls")', \SugarCraft\Crush\Renderer::render($running));

        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertInstanceOf(BackendToolEventsMsg::class, $cmd());
    }

    public function testToolFinishedReplacesThePlaceholderAndThenHandsOffTheReply(): void
    {
        $call = new EngineToolCall('call_1', 'bash', ['command' => 'ls']);
        $chat = new Chat(
            backend: $this->eventEmittingBackend([
                ToolStarted::fromCall($call),
                ToolFinished::fromResult($call, new EngineToolResult('call_1', 'a.txt', durationMs: 12, diff: '--- a\n+++ b\n')),
            ], Message::assistant('there is one file')),
            inputBuf: 'list files',
        );

        [$afterSubmit] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $final = $this->drainBackendEvents($afterSubmit, $this->resolveBackendCmd($chat));

        $pending = array_filter($final->history, static fn(Message $m): bool => $m->pendingToolCallId !== null);
        $this->assertSame([], $pending, 'the running placeholder was never replaced');

        $withResults = array_values(array_filter($final->history, static fn(Message $m): bool => $m->toolResults !== []));
        $this->assertCount(1, $withResults);
        $result = $withResults[0]->toolResults[0];
        $this->assertSame('bash', $result->name);
        $this->assertSame('a.txt', $result->result);
        $this->assertSame(12, $result->durationMs);
        $this->assertSame('--- a\n+++ b\n', $result->diff);
        $this->assertSame('a.txt', $withResults[0]->content);

        // The turn's own reply still lands through the ordinary AssistantMsg
        // arm, after the tool calls that produced it.
        $last = $final->history[count($final->history) - 1];
        $this->assertSame('there is one file', $last->content);
        $this->assertFalse($final->inFlight);
    }

    public function testToolFinishedCorrelatesOnTheEventIdNotTheToolsInventedResultId(): void
    {
        // A tool never sees its own call id, so built-ins routinely return an
        // invented one; only ToolFinished::$toolCallId carries the id the
        // placeholder was keyed with.
        $call = new EngineToolCall('call_1', 'read', ['path' => 'x']);
        $chat = new Chat(
            backend: $this->eventEmittingBackend([
                ToolStarted::fromCall($call),
                ToolFinished::fromResult($call, new EngineToolResult('', 'contents')),
            ], Message::assistant('read it')),
            inputBuf: 'read x',
        );

        [$afterSubmit] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $final = $this->drainBackendEvents($afterSubmit, $this->resolveBackendCmd($chat));

        $this->assertSame(
            [],
            array_filter($final->history, static fn(Message $m): bool => $m->pendingToolCallId !== null),
        );
        // user turn + the replaced placeholder + the reply. A 4th entry would
        // mean the result was appended alongside an orphaned placeholder.
        $this->assertCount(3, $final->history, 'the result was appended instead of replacing the placeholder');
    }

    public function testFailedToolCallRendersAsAnErrorResult(): void
    {
        $call = new EngineToolCall('call_1', 'bash', ['command' => 'boom']);
        $chat = new Chat(
            backend: $this->eventEmittingBackend([
                ToolStarted::fromCall($call),
                ToolFinished::fromResult($call, new EngineToolResult('call_1', 'command not found', isError: true)),
            ], Message::assistant('that failed')),
            inputBuf: 'run boom',
        );

        [$afterSubmit] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $final = $this->drainBackendEvents($afterSubmit, $this->resolveBackendCmd($chat));

        $withResults = array_values(array_filter($final->history, static fn(Message $m): bool => $m->toolResults !== []));
        $this->assertCount(1, $withResults);
        $this->assertTrue($withResults[0]->toolResults[0]->isError());
        $this->assertSame('command not found', $withResults[0]->toolResults[0]->error);
        $this->assertSame('Tool error: command not found', $withResults[0]->content);
    }

    public function testTurnWithoutToolEventsStillResolvesToAPlainAssistantMsg(): void
    {
        $chat = new Chat(backend: new EchoBackend(), inputBuf: 'hello');
        $this->assertInstanceOf(AssistantMsg::class, $this->resolveBackendCmd($chat));
    }

    public function testStaleBackendToolEventsAreDropped(): void
    {
        $chat = new Chat(history: [Message::user('hi')], generation: 7);
        $msg = new BackendToolEventsMsg(
            [ToolStarted::fromCall(new EngineToolCall('call_1', 'bash', []))],
            Message::assistant('late'),
            3,
        );

        [$next, $cmd] = $chat->update($msg);
        $this->assertSame($chat, $next);
        $this->assertNull($cmd);
    }

    public function testBackendFailureAfterToolCallsStillShowsWhatTheToolsDid(): void
    {
        $call = new EngineToolCall('call_1', 'bash', ['command' => 'ls']);
        $chat = new Chat(
            backend: $this->eventEmittingBackend(
                [ToolStarted::fromCall($call), ToolFinished::fromResult($call, new EngineToolResult('call_1', 'a.txt'))],
                null,
                new \RuntimeException('provider exploded'),
            ),
            inputBuf: 'list files',
        );

        [$afterSubmit] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $resolved = $this->resolveBackendCmd($chat);
        $this->assertInstanceOf(BackendToolEventsMsg::class, $resolved);

        $final = $this->drainBackendEvents($afterSubmit, $resolved);
        $this->assertNotSame([], array_filter($final->history, static fn(Message $m): bool => $m->toolResults !== []));
        $this->assertStringContainsString('provider exploded', $final->history[count($final->history) - 1]->content);
    }

    /**
     * A Backend that reports $events through the $onEvent seam and then either
     * resolves with $reply or rejects with $failure.
     *
     * @param list<ToolStarted|ToolFinished> $events
     */
    private function eventEmittingBackend(array $events, ?Message $reply, ?\Throwable $failure = null): \SugarCraft\Crush\Backend
    {
        return new class ($events, $reply, $failure) implements \SugarCraft\Crush\Backend {
            /** @param list<ToolStarted|ToolFinished> $events */
            public function __construct(
                private array $events,
                private ?Message $reply,
                private ?\Throwable $failure,
            ) {}

            public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
            {
                foreach ($this->events as $event) {
                    if ($onEvent !== null) {
                        $onEvent($event);
                    }
                }
                if ($this->failure !== null) {
                    throw $this->failure;
                }

                return $this->reply ?? Message::assistant('');
            }

            public function completeAsync(array $history, callable $onToken = null, ?\SugarCraft\Crush\Backend\CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                return new Promise(function (callable $resolve, callable $reject) use ($history, $onToken, $onEvent): void {
                    try {
                        $resolve($this->complete($history, $onToken, $onEvent));
                    } catch (\Throwable $e) {
                        $reject($e);
                    }
                });
            }
        };
    }

    /**
     * Submit $chat's input buffer and run the scheduled backend Cmd to the Msg
     * it resolves to (synchronous - every backend used here settles inline).
     */
    private function resolveBackendCmd(Chat $chat): mixed
    {
        [, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertInstanceOf(\Closure::class, $cmd);
        $asyncCmd = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $asyncCmd);

        $resolved = null;
        $asyncCmd->promise->then(function ($msg) use (&$resolved): void {
            $resolved = $msg;
        });

        return $resolved;
    }

    /**
     * Feed $msg into $chat and keep following the Cmd it returns until the
     * event queue drains and the final AssistantMsg has been applied.
     */
    private function drainBackendEvents(Chat $chat, mixed $msg): Chat
    {
        $steps = 0;
        while ($msg !== null) {
            [$chat, $cmd] = $chat->update($msg);
            if ($cmd === null || ++$steps > 20) {
                break;
            }
            $msg = $cmd();
        }

        return $chat;
    }

    // ---------------------------------------------------------------
    // Hook gating on the Chat-native tool path (crush_feat.md §1 E1, W2.S1d)
    // ---------------------------------------------------------------

    /**
     * A hook that delegates to $handler, matching every tool name.
     *
     * The matcher is `.*`, not `*`: HookRegistry compiles matchers as
     * regexes, and a bare `*` is invalid and would silently never match.
     */
    private function spyHook(HookEvent $event, \Closure $handler): HookInterface
    {
        return new class ($event, $handler) implements HookInterface {
            public function __construct(
                private readonly HookEvent $hookEvent,
                private readonly \Closure $handler,
            ) {}

            public function name(): string
            {
                return 'spy-' . $this->hookEvent->value;
            }

            public function event(): HookEvent
            {
                return $this->hookEvent;
            }

            public function matcher(): string
            {
                return '.*';
            }

            public function execute(HookContext $context): HookResult
            {
                return ($this->handler)($context);
            }
        };
    }

    private function hookManagerWith(HookInterface ...$hooks): HookManager
    {
        $manager = new HookManager(new HookRegistry());
        foreach ($hooks as $hook) {
            $manager->register($hook);
        }

        return $manager;
    }

    public function testWithHooksReturnsANewChatAndLeavesTheOriginalUngated(): void
    {
        $chat = new Chat();
        $hooks = $this->hookManagerWith();

        $gated = $chat->withHooks($hooks);

        $this->assertNull($chat->hooks());
        $this->assertSame($hooks, $gated->hooks());
        $this->assertNotSame($chat, $gated);
    }

    /**
     * The Chat-native (registerTool) pipeline used to invoke its callback
     * with zero gating while the engine pipeline ran the very same call
     * through HookManager - crush_feat.md §1 D's "two independent,
     * non-unified tool-calling pipelines". Fails against the old Chat: no
     * PreToolUse hook ever fired for a registerTool() call.
     */
    public function testChatNativeToolCallRunsThroughThePreToolUseHook(): void
    {
        $seen = [];
        $hooks = $this->hookManagerWith($this->spyHook(
            HookEvent::PreToolUse,
            static function (HookContext $context) use (&$seen): HookResult {
                $seen[] = [$context->toolName, $context->toolArgs];

                return HookResult::allow();
            },
        ));

        $chat = (new Chat(history: [Message::user('list files')]))
            ->registerTool('bash', static fn(array $args) => 'total 0')
            ->withHooks($hooks);

        $call = new \SugarCraft\Crush\ToolCall('bash', ['cmd' => 'ls'], 'call_1');
        [, $final] = $this->runToolCallsToCompletion($chat, Message::assistant('running')->withToolCalls([$call]));

        // Recorded in THIS process: hooks run parent-side, before the fork.
        $this->assertSame([['bash', ['cmd' => 'ls']]], $seen);
        $this->assertStringContainsString('total 0', $final->history[2]->content);
    }

    /**
     * A PreToolUse DENY must block the callback outright and still resolve
     * the running placeholder - the ToolFinished half of the pair - with an
     * honest error result rather than leaving a spinner forever.
     */
    public function testPreToolUseDenyBlocksTheToolAndStillResolvesThePlaceholder(): void
    {
        $sentinel = sys_get_temp_dir() . '/sc_hook_deny_' . bin2hex(random_bytes(8));
        $hooks = $this->hookManagerWith($this->spyHook(
            HookEvent::PreToolUse,
            static fn(HookContext $context): HookResult => HookResult::deny('rm -rf is not allowed'),
        ));

        $chat = (new Chat())
            ->registerTool('bash', static function (array $args) use ($sentinel): string {
                // Written from the forked child if the deny leaks through:
                // an on-disk sentinel is the only side effect that survives
                // the fork boundary back to this process.
                file_put_contents($sentinel, 'ran');

                return 'deleted everything';
            })
            ->withHooks($hooks);

        $call = new \SugarCraft\Crush\ToolCall('bash', ['cmd' => 'rm -rf /'], 'call_1');
        [$afterPlaceholders, $final] = $this->runToolCallsToCompletion($chat, Message::assistant('running')->withToolCalls([$call]));

        $this->assertFileDoesNotExist($sentinel, 'a denied tool call still reached its callback');

        $this->assertSame('call_1', $afterPlaceholders->history[1]->pendingToolCallId);
        $this->assertNull($final->history[1]->pendingToolCallId);
        $this->assertSame('Tool error: Hook denied: rm -rf is not allowed', $final->history[1]->content);
        $this->assertTrue($final->history[1]->toolResults[0]->isError());
        $this->assertSame('call_1', $final->history[1]->toolResults[0]->id);
    }

    /**
     * PostToolUse has to observe the real output in the PARENT: run inside
     * the forked child, every effect of the hook chain (audit trail,
     * accumulated state) would die with that child's memory.
     */
    public function testPostToolUseHookObservesTheToolOutputInTheParentProcess(): void
    {
        $outputs = [];
        $hooks = $this->hookManagerWith($this->spyHook(
            HookEvent::PostToolUse,
            static function (HookContext $context) use (&$outputs): HookResult {
                $outputs[] = $context->toolOutput;

                return HookResult::allow();
            },
        ));

        $chat = (new Chat())
            ->registerTool('bash', static fn(array $args) => 'total 0')
            ->withHooks($hooks);

        $call = new \SugarCraft\Crush\ToolCall('bash', ['cmd' => 'ls'], 'call_1');
        $this->runToolCallsToCompletion($chat, Message::assistant('running')->withToolCalls([$call]));

        $this->assertSame(['total 0'], $outputs);
    }

    /**
     * A call that never ran has no output to observe - Runtime skips its own
     * postToolUse on the deny branch, and so must Chat.
     */
    public function testDeniedToolCallSkipsThePostToolUseHook(): void
    {
        $postCalls = 0;
        $hooks = $this->hookManagerWith(
            $this->spyHook(HookEvent::PreToolUse, static fn(HookContext $c): HookResult => HookResult::deny('nope')),
            $this->spyHook(HookEvent::PostToolUse, static function (HookContext $c) use (&$postCalls): HookResult {
                ++$postCalls;

                return HookResult::allow();
            }),
        );

        $chat = (new Chat())
            ->registerTool('bash', static fn(array $args) => 'total 0')
            ->withHooks($hooks);

        $call = new \SugarCraft\Crush\ToolCall('bash', ['cmd' => 'ls'], 'call_1');
        $this->runToolCallsToCompletion($chat, Message::assistant('running')->withToolCalls([$call]));

        $this->assertSame(0, $postCalls);
    }

    /**
     * MODIFY is "allowed, with rewritten input" on both pipelines - the
     * rewritten arguments are what the callback must actually receive.
     */
    public function testModifyHookRewritesTheArgumentsBeforeTheToolRuns(): void
    {
        $hooks = $this->hookManagerWith($this->spyHook(
            HookEvent::PreToolUse,
            static fn(HookContext $c): HookResult => HookResult::modify((string) json_encode(['cmd' => 'ls -la'])),
        ));

        $chat = (new Chat())
            ->registerTool('bash', static fn(array $args): string => 'ran: ' . ($args['cmd'] ?? 'nothing'))
            ->withHooks($hooks);

        $call = new \SugarCraft\Crush\ToolCall('bash', ['cmd' => 'rm -rf /'], 'call_1');
        [, $final] = $this->runToolCallsToCompletion($chat, Message::assistant('running')->withToolCalls([$call]));

        $this->assertSame('ran: ls -la', $final->history[1]->content);
    }

    /**
     * Runtime resolves the tool first and only builds a HookContext once it
     * has one, so an unknown name never reaches the hook chain. Chat matches
     * that ordering rather than inventing a second convention.
     */
    public function testUnknownToolIsReportedWithoutConsultingTheHooks(): void
    {
        $preCalls = 0;
        $hooks = $this->hookManagerWith($this->spyHook(
            HookEvent::PreToolUse,
            static function (HookContext $c) use (&$preCalls): HookResult {
                ++$preCalls;

                return HookResult::allow();
            },
        ));

        // Some tool must be registered for update() to enter the tool path
        // at all - just not the one the assistant asked for.
        $chat = (new Chat())
            ->registerTool('bash', static fn(array $args) => 'total 0')
            ->withHooks($hooks);

        $call = new \SugarCraft\Crush\ToolCall('nope', [], 'call_1');
        [, $final] = $this->runToolCallsToCompletion($chat, Message::assistant('running')->withToolCalls([$call]));

        $this->assertSame(0, $preCalls);
        $this->assertSame('Tool error: Unknown tool: nope', $final->history[1]->content);
    }

    /**
     * A Chat with no HookManager keeps its pre-gating behaviour exactly:
     * embedders and tests that never wire hooks must be unaffected.
     */
    public function testToolCallsStillRunUngatedWhenNoHookManagerIsWired(): void
    {
        $chat = (new Chat())->registerTool('bash', static fn(array $args) => 'total 0');

        $call = new \SugarCraft\Crush\ToolCall('bash', ['cmd' => 'ls'], 'call_1');
        [, $final] = $this->runToolCallsToCompletion($chat, Message::assistant('running')->withToolCalls([$call]));

        $this->assertNull($chat->hooks());
        $this->assertSame('total 0', $final->history[1]->content);
    }

    // ---------------------------------------------------------------
    // Blocking permission requests (crush_feat.md 1 E2, W2.S3b)
    // ---------------------------------------------------------------

    /** A PreToolUse hook that always defers to the user, counting its runs. */
    private function askHook(string $question, ?int &$calls = null): HookInterface
    {
        $calls = 0;

        return $this->spyHook(
            HookEvent::PreToolUse,
            static function (HookContext $context) use ($question, &$calls): HookResult {
                ++$calls;

                return HookResult::ask($question);
            },
        );
    }

    /**
     * A Chat whose only tool records that it ran by writing $sentinel - the
     * one side effect that survives the fork boundary back to this process.
     */
    private function chatAwaitingPermission(string $sentinel, HookInterface $hook): Chat
    {
        return (new Chat())
            ->registerTool('bash', static function (array $args) use ($sentinel): string {
                file_put_contents($sentinel, 'ran');

                return 'total 0';
            })
            ->withHooks($this->hookManagerWith($hook));
    }

    private function askingToolCall(): Message
    {
        return Message::assistant('running')->withToolCalls([
            new \SugarCraft\Crush\ToolCall('bash', ['cmd' => 'rm -rf /'], 'call_1'),
        ]);
    }

    /** Await a dispatched tool batch and fold its results back into $model. */
    private function awaitToolResults(Chat $model, \Closure $cmd): Chat
    {
        $asyncCmd = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $asyncCmd);

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

        [$final] = $model->update($resolved);

        return $final;
    }

    /**
     * An ASK is the hook deferring to the user, not denying. Against the old
     * code it fell into the deny branch and the call was reported as "Hook
     * denied" with nobody ever asked; now the whole batch suspends, nothing
     * is forked, and not even a "running" placeholder is shown - a spinner
     * for a call that has not been permitted would be a lie.
     */
    public function testAskHookSuspendsTheTurnInsteadOfRunningOrDenyingTheCall(): void
    {
        $sentinel = sys_get_temp_dir() . '/sc_perm_' . bin2hex(random_bytes(8));
        $chat = $this->chatAwaitingPermission($sentinel, $this->askHook('Really run rm -rf /?'));

        [$suspended, $cmd] = $chat->update(new AssistantMsg($this->askingToolCall()));

        $this->assertNotNull($suspended->pendingPermission());
        $this->assertSame('Really run rm -rf /?', $suspended->pendingPermission()->prompt);
        $this->assertSame('bash', $suspended->pendingPermission()->toolCall->name);
        $this->assertTrue($suspended->inFlight);
        $this->assertSame([], $suspended->history);
        $this->assertFileDoesNotExist($sentinel, 'a tool call awaiting permission was executed anyway');
        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    /**
     * The scheduled Cmd is the block: its promise stays pending for as long
     * as the prompt is up, and settles the moment the user answers.
     */
    public function testTheSuspendingCmdStaysPendingUntilTheUserAnswers(): void
    {
        $sentinel = sys_get_temp_dir() . '/sc_perm_' . bin2hex(random_bytes(8));
        $chat = $this->chatAwaitingPermission($sentinel, $this->askHook('Run it?'));

        [$suspended, $cmd] = $chat->update(new AssistantMsg($this->askingToolCall()));

        $settled = false;
        $cmd()->promise->then(function () use (&$settled): void { $settled = true; });
        $this->assertFalse($settled, 'the turn was not actually blocked on the decision');

        $suspended->update(new PermissionReplyMsg(PermissionReply::Reject));

        $this->assertTrue($settled);
    }

    /**
     * A "once" reply resumes the SAME gated batch, so the hook chain runs
     * exactly once per call - re-gating on resume would re-fire every hook's
     * side effects and re-ask the question just answered.
     */
    public function testOnceReplyRunsTheToolAndGatesItExactlyOnce(): void
    {
        $sentinel = sys_get_temp_dir() . '/sc_perm_' . bin2hex(random_bytes(8));
        $calls = 0;
        $chat = $this->chatAwaitingPermission($sentinel, $this->askHook('Run it?', $calls));

        [$suspended] = $chat->update(new AssistantMsg($this->askingToolCall()));
        [$resumed, $resumeCmd] = $suspended->update(new PermissionReplyMsg(PermissionReply::Once));

        $this->assertNull($resumed->pendingPermission());
        $this->assertSame([], $resumed->permissionGrants(), 'a once reply must not grant anything beyond this call');
        $this->assertSame('call_1', $resumed->history[1]->pendingToolCallId, 'the running placeholder appears only once permitted');
        $this->assertInstanceOf(\Closure::class, $resumeCmd);

        $final = $this->awaitToolResults($resumed, $resumeCmd);

        $this->assertSame('total 0', $final->history[1]->content);
        $this->assertSame(1, $calls, 'the PreToolUse chain ran a second time on resume');
    }

    /**
     * "Always" is the only reply that outlives the call it answers: the tool
     * is granted for the rest of the session, so a later ASK for the same
     * tool resolves without prompting again (opencode's `approved: Rule[]`).
     */
    public function testAlwaysReplyGrantsTheToolForTheRestOfTheSession(): void
    {
        $sentinel = sys_get_temp_dir() . '/sc_perm_' . bin2hex(random_bytes(8));
        $calls = 0;
        $chat = $this->chatAwaitingPermission($sentinel, $this->askHook('Run it?', $calls));

        [$suspended] = $chat->update(new AssistantMsg($this->askingToolCall()));
        [$granted, $resumeCmd] = $suspended->update(new PermissionReplyMsg(PermissionReply::Always));

        $this->assertSame(['bash' => true], $granted->permissionGrants());

        $afterFirst = $this->awaitToolResults($granted, $resumeCmd);
        $this->assertSame('total 0', $afterFirst->history[1]->content);

        // Second turn, same tool, same asking hook: no prompt this time.
        [$secondTurn, $secondCmd] = $afterFirst->update(new AssistantMsg($this->askingToolCall()));

        $this->assertNull($secondTurn->pendingPermission(), 'an always-granted tool asked again');
        $this->assertInstanceOf(\Closure::class, $secondCmd);

        $final = $this->awaitToolResults($secondTurn, $secondCmd);
        $this->assertSame('total 0', $final->history[count($final->history) - 1]->content);
    }

    /**
     * A rejection ends the turn honestly: the tool never runs, the turn stops
     * being in flight, and the transcript shows both what was proposed and
     * that it was refused.
     */
    public function testRejectReplyRefusesTheCallAndEndsTheTurn(): void
    {
        $sentinel = sys_get_temp_dir() . '/sc_perm_' . bin2hex(random_bytes(8));
        $chat = $this->chatAwaitingPermission($sentinel, $this->askHook('Run it?'));

        [$suspended] = $chat->update(new AssistantMsg($this->askingToolCall()));
        [$rejected, $cmd] = $suspended->update(new PermissionReplyMsg(PermissionReply::Reject));

        $this->assertNull($cmd);
        $this->assertNull($rejected->pendingPermission());
        $this->assertFalse($rejected->inFlight);
        $this->assertFileDoesNotExist($sentinel, 'a refused tool call ran anyway');
        $this->assertSame('running', $rejected->history[0]->content);
        $this->assertSame('_Permission denied: bash was not run._', $rejected->history[1]->content);
    }

    /**
     * The prompt owns the keyboard while it is up: the turn is inFlight by
     * definition, so without its own arm every reply keystroke would hit the
     * inFlight blanket-swallow and the prompt could never be answered.
     *
     * @dataProvider permissionKeyProvider
     */
    public function testPermissionKeysDecideThePrompt(KeyMsg $key, PermissionReply $expected): void
    {
        $sentinel = sys_get_temp_dir() . '/sc_perm_' . bin2hex(random_bytes(8));
        $chat = $this->chatAwaitingPermission($sentinel, $this->askHook('Run it?'));

        [$suspended] = $chat->update(new AssistantMsg($this->askingToolCall()));
        [$answered] = $suspended->update($key);

        $this->assertNull($answered->pendingPermission());
        $this->assertSame(
            $expected === PermissionReply::Always ? ['bash' => true] : [],
            $answered->permissionGrants(),
        );
        $this->assertSame($expected === PermissionReply::Reject, !$answered->inFlight);
    }

    public static function permissionKeyProvider(): array
    {
        return [
            'y approves once' => [new KeyMsg(KeyType::Char, 'y'), PermissionReply::Once],
            'a approves always' => [new KeyMsg(KeyType::Char, 'a'), PermissionReply::Always],
            'n refuses' => [new KeyMsg(KeyType::Char, 'n'), PermissionReply::Reject],
            'escape refuses' => [new KeyMsg(KeyType::Escape, ''), PermissionReply::Reject],
        ];
    }

    /**
     * This prompt gates tool execution, so "the user pressed something" must
     * never read as consent: an unmapped key leaves the prompt exactly as it
     * was.
     */
    public function testUnmappedKeyLeavesThePermissionPromptUp(): void
    {
        $sentinel = sys_get_temp_dir() . '/sc_perm_' . bin2hex(random_bytes(8));
        $chat = $this->chatAwaitingPermission($sentinel, $this->askHook('Run it?'));

        [$suspended] = $chat->update(new AssistantMsg($this->askingToolCall()));
        [$unchanged, $cmd] = $suspended->update(new KeyMsg(KeyType::Char, 'q'));

        $this->assertSame($suspended, $unchanged);
        $this->assertNull($cmd);
        $this->assertNotNull($unchanged->pendingPermission());
    }

    /**
     * A Chat with two asking tools, each recording that it ran by writing its
     * own sentinel - so a call released by somebody else's answer is visible.
     */
    private function chatAwaitingTwoPermissions(string $alphaSentinel, string $betaSentinel): Chat
    {
        return (new Chat())
            ->registerTool('alpha', static function (array $args) use ($alphaSentinel): string {
                file_put_contents($alphaSentinel, 'ran');

                return 'alpha ok';
            })
            ->registerTool('beta', static function (array $args) use ($betaSentinel): string {
                file_put_contents($betaSentinel, 'ran');

                return 'beta ok';
            })
            ->withHooks($this->hookManagerWith($this->askHook('Approve?')));
    }

    private function twoAskingToolCalls(): Message
    {
        return Message::assistant('running')->withToolCalls([
            new \SugarCraft\Crush\ToolCall('alpha', [], 'call_a'),
            new \SugarCraft\Crush\ToolCall('beta', [], 'call_b'),
        ]);
    }

    /**
     * Consent for one call is not consent for the batch. Against the old code
     * the answer for `alpha` dispatched the whole parked batch, so `beta` ran
     * on the strength of an approval the user gave for a different call and
     * was never even shown - the exact fail-open this prompt exists to stop.
     */
    public function testAnsweringOneAskDoesNotReleaseTheOtherCallsInTheBatch(): void
    {
        $alpha = sys_get_temp_dir() . '/sc_perm_a_' . bin2hex(random_bytes(8));
        $beta = sys_get_temp_dir() . '/sc_perm_b_' . bin2hex(random_bytes(8));
        $chat = $this->chatAwaitingTwoPermissions($alpha, $beta);

        [$suspended] = $chat->update(new AssistantMsg($this->twoAskingToolCalls()));
        $this->assertSame('alpha', $suspended->pendingPermission()->toolCall->name);

        [$afterFirst, $cmd] = $suspended->update(new PermissionReplyMsg(PermissionReply::Once));

        $this->assertNotNull($afterFirst->pendingPermission(), 'the second ask was dropped instead of being raised');
        $this->assertSame('beta', $afterFirst->pendingPermission()->toolCall->name);
        $this->assertSame([], $afterFirst->history, 'the batch was dispatched with an ask outstanding');
        $this->assertFileDoesNotExist($beta, 'a call nobody approved was executed');
        $this->assertFileDoesNotExist($alpha, 'the batch ran before every call was decided');
        $this->assertInstanceOf(\Closure::class, $cmd);

        // Answering the last outstanding ask releases the whole batch.
        [$resumed, $resumeCmd] = $afterFirst->update(new PermissionReplyMsg(PermissionReply::Once));

        $this->assertNull($resumed->pendingPermission());

        $final = $this->awaitToolResults($resumed, $resumeCmd);

        $this->assertFileExists($alpha);
        $this->assertFileExists($beta);
        $this->assertSame('alpha ok', $final->history[1]->content);
        $this->assertSame('beta ok', $final->history[2]->content);
    }

    /**
     * "Always" is scoped to the tool it was answered for: it clears that
     * tool's queued asks and nothing else, so a different tool in the same
     * batch still has to be decided on its own.
     */
    public function testAlwaysForOneToolDoesNotReleaseAnAskForAnother(): void
    {
        $alpha = sys_get_temp_dir() . '/sc_perm_a_' . bin2hex(random_bytes(8));
        $beta = sys_get_temp_dir() . '/sc_perm_b_' . bin2hex(random_bytes(8));
        $chat = $this->chatAwaitingTwoPermissions($alpha, $beta);

        [$suspended] = $chat->update(new AssistantMsg($this->twoAskingToolCalls()));
        [$granted, $cmd] = $suspended->update(new PermissionReplyMsg(PermissionReply::Always));

        $this->assertSame(['alpha' => true], $granted->permissionGrants());
        $this->assertNotNull($granted->pendingPermission(), 'an always for alpha released beta');
        $this->assertSame('beta', $granted->pendingPermission()->toolCall->name);
        $this->assertSame([], $granted->history);
        $this->assertFileDoesNotExist($beta);
        $this->assertFileDoesNotExist($alpha);
        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    public function testPermissionReplyWithNothingPendingIsANoOp(): void
    {
        $chat = new Chat();

        [$same, $cmd] = $chat->update(new PermissionReplyMsg(PermissionReply::Always));

        $this->assertSame($chat, $same);
        $this->assertNull($cmd);
        $this->assertSame([], $same->permissionGrants());
    }
}
