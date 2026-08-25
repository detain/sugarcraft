<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Backend\Support\ScaledClockLoop;
use SugarCraft\Crush\Tests\Backend\Support\StreamingDouble;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Pty\Libc;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\Posix\PosixTermios;
use SugarCraft\Core\Util\Tty;

/**
 * @see EngineBackend
 */
final class EngineBackendTest extends TestCase
{
    public function testIsABackend(): void
    {
        $this->assertInstanceOf(Backend::class, EngineBackend::new(new EchoProvider(), 'echo'));
    }

    public function testCompleteEchoesThroughTheEngine(): void
    {
        $backend = EngineBackend::new(new EchoProvider(), 'echo');

        $reply = $backend->complete([Message::user('hello world')]);

        $this->assertInstanceOf(Message::class, $reply);
        $this->assertStringContainsString('> hello world', $reply->content);
    }

    public function testCompleteAsyncResolvesToReply(): void
    {
        $backend = EngineBackend::new(new EchoProvider(), 'echo');

        $promise = $backend->completeAsync([Message::user('ping')]);
        $this->assertInstanceOf(PromiseInterface::class, $promise);

        // completeAsync() forks the real work off-process (see its docblock)
        // and only settles once the parent's ReactPHP loop observes the
        // child's result over a socket - genuinely async, so the promise is
        // NOT resolved synchronously right after then() the way every other
        // Backend's fake-async completeAsync() is. Pump the loop until it
        // settles instead of asserting immediately.
        $resolved = $this->awaitPromise($promise);

        $this->assertInstanceOf(Message::class, $resolved);
        $this->assertStringContainsString('> ping', $resolved->content);
    }

    /**
     * Regression for crush_feat.md §12 D3's final wiring gap: `complete()`
     * used to build the root {@see Message} from `$lastAssistant->content()`
     * only, silently dropping `$lastAssistant->reasoning()` even though
     * {@see \SugarCraft\Crush\Runtime} already populates it correctly on
     * every real completion.
     */
    public function testCompleteThreadsReasoningOntoTheRootMessage(): void
    {
        $backend = EngineBackend::new($this->reasoningProvider(), 'reasoner');

        $reply = $backend->complete([Message::user('why?')]);

        $this->assertSame('the answer', $reply->content);
        $this->assertSame('thinking it through', $reply->reasoning);
    }

    /**
     * Same regression as {@see testCompleteThreadsReasoningOntoTheRootMessage()}
     * but through the forked {@see EngineBackend::completeAsync()} path,
     * which serializes the child's result over a socket - reasoning has to
     * survive that round-trip too, not just the in-process one.
     */
    public function testCompleteAsyncThreadsReasoningOntoTheRootMessage(): void
    {
        $backend = EngineBackend::new($this->reasoningProvider(), 'reasoner');

        $resolved = $this->awaitPromise($backend->completeAsync([Message::user('why?')]));

        $this->assertSame('the answer', $resolved->content);
        $this->assertSame('thinking it through', $resolved->reasoning);
    }

    /**
     * W1.G2 reachability fix: an image-bearing tool result (e.g. Doctor's
     * capability swatch) produced by the real Runtime/App agentic loop must
     * reach the root Message complete() returns, not be dropped alongside
     * every other ToolResultMessage field.
     */
    public function testCompleteThreadsImageOntoTheRootMessage(): void
    {
        $backend = EngineBackend::new($this->imageThenAnswerProvider(), 'img')
            ->withTools([$this->imageTool()]);

        $reply = $backend->complete([Message::user('check image support')]);

        $this->assertTrue($reply->hasImage());
        $this->assertSame("\x89PNGfake", $reply->imageBytes);
        $this->assertSame('kitty', $reply->imageProtocol);
    }

    /**
     * Same as {@see testCompleteThreadsImageOntoTheRootMessage()} but
     * through the forked completeAsync() path - the image has to survive
     * the child's serialize()/unserialize() socket round-trip too.
     */
    public function testCompleteAsyncThreadsImageOntoTheRootMessage(): void
    {
        $backend = EngineBackend::new($this->imageThenAnswerProvider(), 'img')
            ->withTools([$this->imageTool()]);

        $resolved = $this->awaitPromise($backend->completeAsync([Message::user('check image support')]));

        $this->assertTrue($resolved->hasImage());
        $this->assertSame("\x89PNGfake", $resolved->imageBytes);
        $this->assertSame('kitty', $resolved->imageProtocol);
    }

    private function imageThenAnswerProvider(): ProviderInterface
    {
        return new class implements ProviderInterface {
            public int $calls = 0;
            public function name(): string { return 'img'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return true; }
            public function supportsVision(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 1000; }
            public function costPer1kTokens(string $m, string $d): float { return 0.0; }
            public function complete(CompleteRequest $r): CompleteResponse
            {
                $this->calls++;

                return $this->calls === 1
                    ? new CompleteResponse(content: 'checking', toolCalls: [new ToolCall('call_1', 'doctor', [])])
                    : new CompleteResponse(content: 'here is what I found');
            }
            public function completeStream(CompleteRequest $r): \Generator { yield new CompleteResponse(content: ''); }
            public function embeddings(EmbeddingsRequest $r): EmbeddingsResponse { return new EmbeddingsResponse([]); }
        };
    }

    private function imageTool(): Tool
    {
        return new class implements Tool {
            public function name(): string { return 'doctor'; }
            public function description(): string { return 'test image tool'; }
            public function inputSchema(): array { return []; }
            public function execute(array $args): ToolResult
            {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: 'kitty detected',
                    imageBytes: "\x89PNGfake",
                    imageProtocol: 'kitty',
                );
            }
        };
    }

    private function reasoningProvider(): ProviderInterface
    {
        return new class implements ProviderInterface {
            public function name(): string { return 'reasoner'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return false; }
            public function supportsVision(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 1000; }
            public function costPer1kTokens(string $m, string $d): float { return 0.0; }
            public function complete(CompleteRequest $r): CompleteResponse
            {
                return new CompleteResponse(content: 'the answer', reasoning: 'thinking it through');
            }
            public function completeStream(CompleteRequest $r): \Generator { yield new CompleteResponse(content: ''); }
            public function embeddings(EmbeddingsRequest $r): EmbeddingsResponse { return new EmbeddingsResponse([]); }
        };
    }

    public function testCompleteAsyncDoesNotBlockTheEventLoop(): void
    {
        // The whole point of the fork-based rewrite: while a completion is
        // in flight, the caller's event loop must keep ticking (spinner
        // animation, keystroke handling) instead of freezing until the
        // provider call returns. Prove it by counting periodic-timer fires
        // that happen concurrently with an in-flight completeAsync() call.
        $provider = new class implements ProviderInterface {
            public function name(): string { return 'slow'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return false; }
            public function supportsVision(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 1000; }
            public function costPer1kTokens(string $m, string $d): float { return 0.0; }
            public function complete(CompleteRequest $r): CompleteResponse
            {
                usleep(150_000);

                return new CompleteResponse(content: 'slow reply');
            }
            public function completeStream(CompleteRequest $r): \Generator { yield new CompleteResponse(content: ''); }
            public function embeddings(EmbeddingsRequest $r): EmbeddingsResponse { return new EmbeddingsResponse([]); }
        };

        $backend = EngineBackend::new($provider, 'slow');
        $loop = \React\EventLoop\Loop::get();

        $ticks = 0;
        $timer = $loop->addPeriodicTimer(0.02, static function () use (&$ticks): void {
            $ticks++;
        });

        $resolved = $this->awaitPromise($backend->completeAsync([Message::user('go')]));

        $loop->cancelTimer($timer);

        $this->assertInstanceOf(Message::class, $resolved);
        $this->assertSame('slow reply', $resolved->content);
        $this->assertGreaterThan(
            0,
            $ticks,
            'periodic timer never fired while completeAsync() was in flight - the event loop was blocked',
        );
    }

    public function testCancellationTokenAbortsAnInFlightCompletion(): void
    {
        // Backs Chat's double-Escape-to-abort feature: a provider stuck for
        // longer than any reasonable test timeout, cancelled shortly after
        // starting - the promise must reject quickly (well under the
        // provider's own delay) rather than waiting for it to finish.
        $provider = new class implements ProviderInterface {
            public function name(): string { return 'stuck'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return false; }
            public function supportsVision(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 1000; }
            public function costPer1kTokens(string $m, string $d): float { return 0.0; }
            public function complete(CompleteRequest $r): CompleteResponse
            {
                sleep(5);

                return new CompleteResponse(content: 'too late');
            }
            public function completeStream(CompleteRequest $r): \Generator { yield new CompleteResponse(content: ''); }
            public function embeddings(EmbeddingsRequest $r): EmbeddingsResponse { return new EmbeddingsResponse([]); }
        };

        $backend = EngineBackend::new($provider, 'stuck');
        $loop = \React\EventLoop\Loop::get();
        $cancellation = new \SugarCraft\Crush\Backend\CancellationToken();

        $loop->addTimer(0.2, static function () use ($cancellation): void {
            $cancellation->cancel();
        });

        $start = microtime(true);
        $error = null;
        try {
            $this->awaitPromise($backend->completeAsync([Message::user('go')], null, $cancellation));
        } catch (\Throwable $e) {
            $error = $e;
        }
        $elapsed = microtime(true) - $start;

        $this->assertNotNull($error, 'cancelled completion must reject, not resolve');
        $this->assertStringContainsString('cancelled', $error->getMessage());
        $this->assertLessThan(5.0, $elapsed, 'cancellation must not wait for the provider to finish on its own');
    }

    public function testAlreadyCancelledTokenRejectsImmediatelyWithoutForking(): void
    {
        $backend = EngineBackend::new(new EchoProvider(), 'echo');
        $cancellation = new \SugarCraft\Crush\Backend\CancellationToken();
        $cancellation->cancel();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/cancelled/');
        $this->awaitPromise($backend->completeAsync([Message::user('go')], null, $cancellation));
    }

    /**
     * Regression for the actual bug behind "typed text ends up in the status
     * bar / Enter and Ctrl+P stop working after the first message":
     * completeAsync() forks a child (see runCompleteInChild()) that inherits
     * a copy of whatever raw-mode Tty a real Program has already set up. If
     * that child ends with a plain exit(), its inherited Tty's destructor
     * fires during PHP's shutdown sequence and restores the ORIGINAL
     * (cooked/echo) termios onto the REAL, shared terminal device - which is
     * exactly what a user watched happen right after sending their first
     * message. Drives the real completeAsync() (real fork, real child exit)
     * against a real PTY and asserts the terminal is still in raw mode
     * afterwards. See ForkedChildTest for the isolated mechanism.
     */
    public function testCompleteAsyncDoesNotResetTheRealTerminalsRawMode(): void
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

        $pair = (new PosixPtySystem())->open();
        $slavePath = $pair->slave()->path();

        $libc = Libc::lib();
        $slaveFd = $libc->open($slavePath, 0x0002 /* O_RDWR */);
        if ($slaveFd < 0) {
            $this->markTestSkipped('Could not open slave PTY path: ' . $slavePath);
        }

        // Injected Termios test seam (see Tty's constructor docblock) -
        // exercises the real PosixBackend raw-mode/restore machinery against
        // a real fd without depending on candy-core's separate (int)-cast fd
        // resolution, which only coincides with the real OS fd for a
        // process's original STDIN/STDOUT (irrelevant to production, which
        // only ever wraps the real STDIN).
        //
        // THE STREAM ARGUMENT IS EXPLICIT, AND USED TO BE `null` (round 49).
        // WHAT THAT DID: `Tty::__construct()` is `self::backend($stream ??
        // STDIN, $termios)`, so `null` here wrapped THIS PROCESS's descriptor
        // 0 - and the injected-Termios branch of
        // `PosixBackend::enableRawMode()` skips its own `isTty()` guard, so
        // its trailing `@stream_set_blocking($this->stream, false)` and
        // `restore()`'s matching `(…, true)` both landed on the runner's fd 0.
        // MEASURED, PHP 8.3.6, three takes: with `null`, fd 0's `blocked` flag
        // goes true -> false across this seam (3/3); with an explicit stream it
        // stays true (3/3). That side effect is not cosmetic: the descriptor-0
        // repair in `tests/bootstrap.php` IS an `O_NONBLOCK` flag on fd 0, and
        // `restore()` here was clearing it back for every later test in the
        // run. See that file's write-up; it cost a full run to find.
        //
        // A SOCKET PAIR rather than `php://memory`, and that is forced: PHP
        // reports a memory stream as blocked whatever you set, so it cannot
        // tell "the seam wrote the flag here" from "the seam wrote it
        // somewhere else". The pair's flag is observable in both directions -
        // asserted below in both, which is what makes a revert to `null` red
        // rather than merely undetected.
        $flagSink = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, 0);
        $this->assertIsArray($flagSink, 'no socket pair: this test cannot observe where the seam writes');
        $this->assertTrue(
            stream_get_meta_data($flagSink[0])['blocked'],
            'control: a stream nobody has touched must report blocked, or the probe below reads nothing',
        );

        $tty = new Tty($flagSink[0], new PosixTermios($slaveFd));
        $tty->enableRawMode();

        try {
            $this->assertFalse(
                stream_get_meta_data($flagSink[0])['blocked'],
                "enableRawMode() did not clear O_NONBLOCK on the stream it was GIVEN, so it wrote the flag "
                    . "somewhere else - on a null stream that somewhere else is the runner's descriptor 0",
            );
            $this->assertTrue($this->isRaw($slavePath), 'setup: raw mode must be active before completing');

            $backend = EngineBackend::new(new EchoProvider(), 'echo');
            $message = $this->awaitPromise($backend->completeAsync([Message::user('hello')]));

            $this->assertInstanceOf(Message::class, $message, 'completion must still resolve normally');
            $this->assertTrue(
                $this->isRaw($slavePath),
                'the real terminal was knocked out of raw mode by completeAsync()\'s forked child exiting',
            );
        } finally {
            $tty->restore();
            $this->assertTrue(
                stream_get_meta_data($flagSink[0])['blocked'],
                'restore() did not put O_NONBLOCK back on the stream it was given',
            );
            fclose($flagSink[0]);
            fclose($flagSink[1]);
            $libc->close($slaveFd);
            $pair->master()->close();
        }
    }

    private function isRaw(string $slavePath): bool
    {
        // BSD/macOS stty takes the device flag lowercase (-f); GNU/Linux
        // coreutils uses uppercase (-F). Using the wrong one fails with an
        // EMPTY stdout, which is indistinguishable from "the terminal is
        // cooked" - so the message on fd 2 is the only thing that tells those
        // two apart, and it used to go to /dev/null.
        //
        // It cannot be folded into stdout with `2>&1` either: the return
        // value below is a substring match for `-icanon`/`-echo`, and stty's
        // diagnostic would be searched as if it were flag output. So fd 2
        // goes to a file this helper reads back and fails on.
        //
        // THE SAME FIX AS {@see \SugarCraft\Crush\Tests\Support\ForkedChildTest::isRaw()},
        // which is where this helper was copied from. That copy was fixed in
        // round 47 and this one was in no lane's file list, so the two sat one
        // directory apart with opposite behaviour until `Backend/` joined
        // {@see \SugarCraft\Crush\Tests\Support\ChildStderrCaptureTest::SCOPE}
        // and the guard said so.
        $flag = PHP_OS_FAMILY === 'Darwin' ? '-f' : '-F';
        $stderrFile = (string) tempnam(sys_get_temp_dir(), 'sc_enginebackend_stty_');

        $out = trim((string) shell_exec(
            'stty ' . $flag . ' ' . escapeshellarg($slavePath) . ' -a 2>' . escapeshellarg($stderrFile),
        ));

        $stderr = trim((string) @file_get_contents($stderrFile));
        @unlink($stderrFile);

        self::assertSame(
            '',
            $stderr,
            'stty could not read the pty, so this helper cannot answer whether raw mode is set '
                . 'and a bare false would be read as "cooked": ' . $stderr,
        );

        return str_contains($out, '-icanon') && str_contains($out, '-echo');
    }

    /**
     * Pump the real ReactPHP loop until $promise settles, returning its
     * resolved value (or rethrowing its rejection). Bounded so a genuine
     * regression (the promise never settling) fails the test instead of
     * hanging the suite forever.
     */
    private function awaitPromise(PromiseInterface $promise): mixed
    {
        // A single run()/stop() pair, exactly like Program::run()'s own
        // one-shot $this->loop->run() - NOT a repeated add-short-timer-then-
        // run() polling dance. That looks equivalent but isn't: re-entering
        // run() over and over via a freshly re-added timer raced against a
        // forked child's real (curl/HTTPS) I/O and could miss the socket's
        // readability edge between iterations, hanging the test. A single
        // long-lived run(), stopped only once by whichever settles first
        // (the promise's own callback or the safety timer), matches how the
        // real Program drives this and is what completeAsync() is verified
        // against.
        $loop = \React\EventLoop\Loop::get();
        $settled = false;
        $value = null;
        $error = null;

        $promise->then(
            function ($v) use (&$settled, &$value, $loop): void { $settled = true; $value = $v; $loop->stop(); },
            function (\Throwable $e) use (&$settled, &$error, $loop): void { $settled = true; $error = $e; $loop->stop(); },
        );

        // then() on an already-settled promise (e.g. completeAsync()'s
        // already-cancelled fast path) invokes its callback synchronously,
        // before run() is even called - stop() on a not-yet-running loop is
        // a no-op, so without this check run() would sit idle until the
        // 10s safety timer, not return immediately.
        if (!$settled) {
            $safety = $loop->addTimer(10.0, static function () use ($loop): void { $loop->stop(); });
            $loop->run();
            $loop->cancelTimer($safety);
        }

        if (!$settled) {
            $this->fail('Promise did not settle within the test timeout');
        }

        if ($error !== null) {
            throw $error;
        }

        return $value;
    }

    public function testAgenticLoopExecutesToolThenAnswers(): void
    {
        $provider = $this->toolThenAnswerProvider();
        $backend = EngineBackend::new($provider, 'tc')->withTools([$this->clockTool()]);

        $reply = $backend->complete([Message::user('what time is it?')]);

        // Two provider round-trips: one that requested the tool, one that
        // answered after seeing the tool result.
        $this->assertSame(2, $provider->calls);
        $this->assertStringContainsString('NOON', $reply->content);
    }

    /**
     * crush_feat.md §1 E1: before the $onEvent seam every tool call the bounded
     * agentic loop made was swallowed inside complete() — only $lastAssistant's
     * text escaped — so a caller had no way to observe them. This test fails
     * against that older code because there was no third argument to pass.
     */
    public function testCompleteThreadsToolEventsToTheOnEventCallback(): void
    {
        $backend = EngineBackend::new($this->toolThenAnswerProvider(), 'tc')->withTools([$this->clockTool()]);

        $events = [];
        $reply = $backend->complete([Message::user('what time is it?')], null, function ($event) use (&$events): void {
            $events[] = $event;
        });

        $this->assertStringContainsString('NOON', $reply->content);
        $this->assertSame(
            [ToolStarted::class, ToolFinished::class],
            array_map(static fn ($e) => $e::class, $events),
        );
        $this->assertSame('c1', $events[0]->toolCallId);
        $this->assertSame('clock', $events[0]->toolName);
        $this->assertSame('c1', $events[1]->toolCallId);
        $this->assertSame('NOON', $events[1]->result->content());
        $this->assertFalse($events[1]->result->isError());
    }

    /**
     * A hook DENY inside the loop is a tool-call outcome the caller must be
     * able to see; it used to be entirely internal to the engine.
     */
    public function testCompleteReportsAHookDeniedToolCallAsAnErrorEvent(): void
    {
        $backend = EngineBackend::new($this->bashThenAnswerProvider(), 'bash')
            ->withTools([$this->bashSpyTool()]);

        $events = [];
        $backend->complete([Message::user('nuke it')], null, function ($event) use (&$events): void {
            $events[] = $event;
        });

        $this->assertCount(2, $events);
        $this->assertInstanceOf(ToolFinished::class, $events[1]);
        $this->assertTrue($events[1]->result->isError());
        $this->assertStringContainsString('Hook denied', $events[1]->result->content());
    }

    public function testCompleteStillWorksWithoutAnOnEventCallback(): void
    {
        $backend = EngineBackend::new($this->toolThenAnswerProvider(), 'tc')->withTools([$this->clockTool()]);

        $this->assertStringContainsString('NOON', $backend->complete([Message::user('time?')])->content);
    }

    /**
     * completeAsync() runs the engine in a forked child, where invoking the
     * caller's callback would write into a copy of its state and vanish — so
     * the events ride back in the payload and are replayed in the PARENT.
     */
    public function testCompleteAsyncReplaysTheChildsToolEventsInTheParent(): void
    {
        $backend = EngineBackend::new($this->toolThenAnswerProvider(), 'tc')->withTools([$this->clockTool()]);

        $events = [];
        $reply = $this->awaitPromise($backend->completeAsync(
            [Message::user('what time is it?')],
            null,
            null,
            function ($event) use (&$events): void { $events[] = $event; },
        ));

        $this->assertStringContainsString('NOON', $reply->content);
        $this->assertSame(
            [ToolStarted::class, ToolFinished::class],
            array_map(static fn ($e) => $e::class, $events),
        );
        $this->assertSame('c1', $events[1]->toolCallId);
        $this->assertSame('clock', $events[1]->toolName);
        $this->assertSame('NOON', $events[1]->result->content());
    }

    /**
     * The diff (W1.F1) and image bytes (W1.G2) a renderer needs must survive
     * the fork's serialize/allowed_classes=false seam, not just the in-process
     * call — they are the whole reason the event carries the ToolResult.
     */
    public function testCompleteAsyncPreservesDiffAndImagePayloadsAcrossTheFork(): void
    {
        $backend = EngineBackend::new($this->toolThenAnswerProvider(), 'tc')
            ->withTools([$this->richClockTool()]);

        $events = [];
        $this->awaitPromise($backend->completeAsync(
            [Message::user('what time is it?')],
            null,
            null,
            function ($event) use (&$events): void { $events[] = $event; },
        ));

        $finished = $events[1];
        $this->assertInstanceOf(ToolFinished::class, $finished);
        $this->assertSame("--- a/clock\n+++ b/clock\n", $finished->result->diff());
        $this->assertSame("\x89PNG\x00raw", $finished->result->imageBytes());
        $this->assertSame('kitty', $finished->result->imageProtocol());
        $this->assertSame(7, $finished->result->durationMs());
    }

    /**
     * The defect §1 E1 describes: the child used to buffer every tool event
     * and write ONE payload at the very end, so a turn running real
     * multi-step tool work showed nothing but a "thinking" spinner until it
     * finished (and its 120s ceiling was a single wall-clock timer for the
     * whole turn rather than an idle one).
     *
     * The proof is a handshake, not a stopwatch: the child's SECOND provider
     * call blocks until the parent creates a gate file, and the parent only
     * creates it from inside the $onEvent callback. If events are delivered
     * live the child is unblocked and answers "NOON"; with the old
     * end-of-turn batching the parent cannot possibly have seen the event
     * yet, the gate never opens, and the child reports the timeout instead.
     */
    public function testCompleteAsyncDeliversToolEventsWhileTheChildIsStillWorking(): void
    {
        $gate = sys_get_temp_dir() . '/crush-live-events-' . bin2hex(random_bytes(6));
        $backend = EngineBackend::new($this->gatedToolThenAnswerProvider($gate), 'tc')
            ->withTools([$this->clockTool()]);

        $seen = [];
        try {
            $reply = $this->awaitPromise($backend->completeAsync(
                [Message::user('what time is it?')],
                null,
                null,
                function ($event) use (&$seen, $gate): void {
                    $seen[] = $event;
                    if ($event instanceof ToolFinished) {
                        touch($gate);
                    }
                },
            ));
        } finally {
            @unlink($gate);
        }

        $this->assertStringContainsString(
            'NOON',
            $reply->content,
            'the child never saw the gate open - tool events did not reach the parent until the turn was already over',
        );
        $this->assertSame(
            [ToolStarted::class, ToolFinished::class],
            array_map(static fn ($e) => $e::class, $seen),
        );
    }

    /**
     * A frame can and does span two reads: the parent reads 64KB at a time,
     * and one image-bearing tool result is bigger than that on its own. This
     * drives a ~256KB event payload through the real fork, so a framing
     * implementation that assumed one read == one frame would hand the
     * consumer a corrupt (or missing) event.
     */
    public function testCompleteAsyncReassemblesAToolEventSplitAcrossReads(): void
    {
        $bytes = str_repeat("\x89PNG\x00\xff", 45000); // ~270KB, >> the 64KB read size
        $backend = EngineBackend::new($this->toolThenAnswerProvider(), 'tc')
            ->withTools([$this->bulkyTool($bytes)]);

        $events = [];
        $reply = $this->awaitPromise($backend->completeAsync(
            [Message::user('what time is it?')],
            null,
            null,
            function ($event) use (&$events): void { $events[] = $event; },
        ));

        $this->assertStringContainsString('NOON', $reply->content);
        $this->assertCount(2, $events);
        $this->assertInstanceOf(ToolFinished::class, $events[1]);
        $this->assertSame($bytes, $events[1]->result->imageBytes());
    }

    /**
     * Frame reassembly in isolation: bytes handed over one at a time must
     * yield nothing until a frame is whole, then exactly that frame, and the
     * unconsumed tail must stay in the buffer for the next readable edge.
     */
    public function testDrainFramesYieldsOnlyWholeFramesAndKeepsThePartialTail(): void
    {
        $drain = new \ReflectionMethod(EngineBackend::class, 'drainFrames');
        $body = serialize(['kind' => 'started', 'id' => 'c1', 'name' => 'clock', 'arguments' => []]);
        $wire = pack('N', strlen($body)) . $body;

        $buffer = '';
        $collected = [];
        // Feed the wire bytes one at a time, plus a truncated second frame.
        foreach (str_split($wire . substr($wire, 0, 5), 1) as $byte) {
            $buffer .= $byte;
            foreach ($drain->invokeArgs(null, [&$buffer]) as $frame) {
                $collected[] = $frame;
            }
        }

        $this->assertCount(1, $collected);
        $this->assertSame('started', $collected[0]['kind']);
        $this->assertSame('c1', $collected[0]['id']);
        $this->assertSame(5, strlen($buffer), 'the partial frame must stay buffered for the next read');
    }

    /**
     * A nonsensical declared length means the stream is no longer parseable;
     * the buffer is dropped rather than misinterpreted, so the turn fails via
     * the missing-result path instead of resolving with garbage.
     */
    public function testDrainFramesDiscardsAnImpossiblyLongFrame(): void
    {
        $drain = new \ReflectionMethod(EngineBackend::class, 'drainFrames');
        $buffer = pack('N', 0x7fffffff) . 'nonsense';

        $frames = $drain->invokeArgs(null, [&$buffer]);

        $this->assertSame([], $frames);
        $this->assertSame('', $buffer);
    }

    /**
     * The second half of the §1 E1 defect: COMPLETE_TIMEOUT_SECONDS used to be
     * armed exactly once for the whole forked turn, so real multi-step tool
     * work got SIGKILLed mid-flight. It is now an IDLE ceiling, re-armed on
     * every frame the child streams.
     *
     * WHAT THIS TEST SAID (E496): "which cannot be exercised in a unit test
     * without waiting out the real 120s, so pin the wiring instead" — and it
     * then scanned `completeAsync()`'s SOURCE TEXT with a `ReflectionMethod`,
     * counting `addTimer(self::COMPLETE_TIMEOUT_SECONDS` occurrences and
     * looking for the string `$resetTimeout()` after `addReadStream(`.
     *
     * WHAT IS TRUE NOW: the premise stopped being true in round 56, which built
     * {@see ScaledClockLoop} — real streams, real fork, a scaled timer clock —
     * so 120 virtual seconds now cost 240 ms of wall time. And the source scan
     * was not merely superseded, it was WEAK IN A SPECIFIC WAY: move the
     * `$resetTimeout()` call from the top of the drain loop down into the
     * `reasoning` branch and BOTH of its assertions still hold — one arming
     * site, `$resetTimeout()` still lexically inside the read handler — while a
     * turn whose progress is assistant TEXT rather than thinking now dies at
     * the ceiling. A scan for the presence of a string cannot see where the
     * string is.
     *
     * WHY THE CLAIM STILL EARNS A TEST: it is the difference between a ceiling
     * that bounds SILENCE and one that bounds a turn's total length, and the
     * latter kills real work. So the claim is now driven rather than read: a
     * provider that streams nothing but content, in gaps far under the ceiling,
     * for a total far over it.
     *
     * The `reasoning` half of this family, and the known-positive control that
     * a silent provider still DIES on the same clock, live in
     * {@see ReasoningProgressTest}. This is the `token`-frame member, and it is
     * here because it is what replaced the scan.
     */
    public function testTheIdleCeilingIsReArmedByATokenFrameAndNotOnlyByAThought(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            // Both were checked on this host (PHP 8.3.6) and both exist, so
            // this gate does not fire here and the test really runs. Named
            // rather than copied from a neighbouring gate.
            self::markTestSkipped('completeAsync() falls back to a blocking, timer-less path without pcntl');
        }

        $tokens = [];
        $run = $this->runOnScaledClock(new StreamingDouble(40, 20_000, 'content', 'the answer'), $tokens);

        $this->assertFalse(
            $run['realCeiling'],
            'the harness ran out of REAL time - nothing below is a verdict about the idle ceiling',
        );
        if ($run['error'] !== null) {
            $this->fail('a turn streaming assistant text was killed as hung: ' . $run['error']->getMessage());
        }
        // The reply is the whole accumulated stream, not just the final
        // chunk: content deltas ARE the assistant's words, unlike thoughts.
        $this->assertStringStartsWith('word 0 ', (string) $run['message']?->content);
        $this->assertStringEndsWith('the answer', (string) $run['message']?->content);
        $this->assertGreaterThan(
            $this->ceilingSeconds(),
            $run['virtualSeconds'],
            'the clock never reached the idle ceiling, so surviving it proves nothing',
        );
        // The frames really were token frames: 40 content chunks plus the
        // answer, none of it routed through the reasoning channel.
        $this->assertGreaterThan(40, count($tokens), 'the turn did not actually stream on the token channel');
        $this->assertSame('word 0 ', $tokens[0]);
    }

    private function ceilingSeconds(): float
    {
        return (float) (new \ReflectionClass(EngineBackend::class))
            ->getReflectionConstant('COMPLETE_TIMEOUT_SECONDS')
            ->getValue();
    }

    /**
     * Run one forked completion against {@see ScaledClockLoop}.
     *
     * A near-twin of {@see ReasoningProgressTest}'s private helper of the same
     * name, deliberately not shared: that one collects a reasoning channel this
     * test has no use for, and folding the two would mean restructuring a file
     * whose whole suite is about a different claim. If a third caller appears,
     * promote it to `Support/` rather than growing a third copy.
     *
     * @param array<int, string> $tokens
     * @return array{settled: bool, message: ?Message, error: ?\Throwable, virtualSeconds: float, realCeiling: bool}
     */
    private function runOnScaledClock(ProviderInterface $provider, array &$tokens): array
    {
        $backend = EngineBackend::new($provider, 'scaled');
        $loop = new ScaledClockLoop();
        $previous = Loop::get();
        Loop::set($loop);

        $settled = false;
        $value = null;
        $error = null;

        try {
            $promise = $backend->completeAsync(
                [Message::user('say something slowly')],
                static function (string $t) use (&$tokens): void { $tokens[] = $t; },
            );
            $promise->then(
                static function ($v) use (&$settled, &$value, $loop): void { $settled = true; $value = $v; $loop->stop(); },
                static function (\Throwable $e) use (&$settled, &$error, $loop): void { $settled = true; $error = $e; $loop->stop(); },
            );
            if (!$settled) {
                $loop->run();
            }
        } finally {
            // The SUITE's loop object, not a fresh one: tests/bootstrap.php
            // pins one instance for the whole run and other files registered
            // on it.
            Loop::set($previous);
        }

        return [
            'settled' => $settled,
            'message' => $value instanceof Message ? $value : null,
            'error' => $error,
            'virtualSeconds' => $loop->highWaterVirtualSeconds(),
            'realCeiling' => $loop->hitRealCeiling(),
        ];
    }

    /**
     * Requests a tool on the first turn, then blocks the second turn until
     * $gate exists - the handshake
     * {@see testCompleteAsyncDeliversToolEventsWhileTheChildIsStillWorking()}
     * uses to prove the parent saw the tool event mid-turn.
     */
    private function gatedToolThenAnswerProvider(string $gate): ProviderInterface
    {
        return new class($gate) implements ProviderInterface {
            public int $calls = 0;
            public function __construct(private string $gate) {}
            public function name(): string { return 'gated'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return true; }
            public function supportsVision(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 1000; }
            public function costPer1kTokens(string $m, string $d): float { return 0.0; }
            public function complete(CompleteRequest $r): CompleteResponse
            {
                $this->calls++;
                if ($this->calls === 1) {
                    return new CompleteResponse(content: 'checking', toolCalls: [new ToolCall('c1', 'clock', [])]);
                }

                // Bounded so a regression fails the assertion instead of
                // hanging the suite.
                $deadline = microtime(true) + 3.0;
                while (microtime(true) < $deadline) {
                    clearstatcache(true, $this->gate);
                    if (file_exists($this->gate)) {
                        return new CompleteResponse(content: 'The time is NOON.');
                    }
                    usleep(5000);
                }

                return new CompleteResponse(content: 'gate never opened');
            }
            public function completeStream(CompleteRequest $r): \Generator { yield new CompleteResponse(content: ''); }
            public function embeddings(EmbeddingsRequest $r): EmbeddingsResponse { return new EmbeddingsResponse([]); }
        };
    }

    /** A clock tool whose result carries a payload far larger than one read. */
    private function bulkyTool(string $bytes): Tool
    {
        return new class($bytes) implements Tool {
            public function __construct(private string $bytes) {}
            public function name(): string { return 'clock'; }
            public function description(): string { return 'test tool'; }
            public function inputSchema(): array { return []; }
            public function execute(array $args): ToolResult
            {
                return new ToolResult(
                    toolCallId: '',
                    content: 'NOON',
                    isError: false,
                    imageBytes: $this->bytes,
                    imageProtocol: 'kitty',
                );
            }
        };
    }

    /** An image/diff-bearing variant of {@see clockTool()}. */
    private function richClockTool(): Tool
    {
        return new class implements Tool {
            public function name(): string { return 'clock'; }
            public function description(): string { return 'test tool'; }
            public function inputSchema(): array { return []; }
            public function execute(array $args): ToolResult
            {
                return new ToolResult(
                    toolCallId: '',
                    content: 'NOON',
                    isError: false,
                    durationMs: 7,
                    imageBytes: "\x89PNG\x00raw",
                    imageProtocol: 'kitty',
                    diff: "--- a/clock\n+++ b/clock\n",
                );
            }
        };
    }

    public function testMaxStepsGuardsAgainstRunawayToolLoops(): void
    {
        // A provider that calls a tool forever — the loop must stop at the cap.
        $provider = new class implements ProviderInterface {
            public int $calls = 0;
            public function name(): string { return 'loop'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return true; }
            public function supportsVision(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 1000; }
            public function costPer1kTokens(string $m, string $d): float { return 0.0; }
            public function complete(CompleteRequest $r): CompleteResponse
            {
                $this->calls++;
                return new CompleteResponse(content: "step {$this->calls}", toolCalls: [new ToolCall('c', 'noop', [])]);
            }
            public function completeStream(CompleteRequest $r): \Generator { yield new CompleteResponse(content: ''); }
            public function embeddings(EmbeddingsRequest $r): EmbeddingsResponse { return new EmbeddingsResponse([]); }
        };
        $noop = $this->namedTool('noop', 'done');

        $backend = EngineBackend::new($provider, 'loop')->withTools([$noop])->withMaxSteps(3);

        $reply = $backend->complete([Message::user('go')]);

        $this->assertSame(3, $provider->calls, 'loop must stop at maxSteps');
        $this->assertStringContainsString('step 3', $reply->content);
    }

    public function testWithersReturnNewInstances(): void
    {
        $base = EngineBackend::new(new EchoProvider(), 'echo');

        $this->assertNotSame($base, $base->withTools([$this->clockTool()]));
        $this->assertNotSame($base, $base->withMaxSteps(2));
        $this->assertNotSame($base, $base->withoutHooks());
    }

    public function testWithInstructionLoaderAttachesTheLoaderWithoutMutatingTheOriginal(): void
    {
        $loader = new InstructionFileLoader(sys_get_temp_dir());
        $base = EngineBackend::new(new EchoProvider(), 'echo');

        $withLoader = $base->withInstructionLoader($loader);

        $this->assertNotSame($base, $withLoader);
        $this->assertNull($this->instructionLoaderOf($base));
        $this->assertSame($loader, $this->instructionLoaderOf($withLoader));
    }

    /**
     * Every sibling wither rebuilds the backend through `new self(...)` with
     * nine positional arguments, and the ninth is the instruction loader that
     * carries a repo-root CLAUDE.md/AGENTS.md into the system prompt. Dropping
     * that argument from any one of them would silently return
     * {@see \SugarCraft\Crush\Context\InstructionFileLoader::loadRoot()} to
     * dead code with the rest of the suite still green - Bootstrap happens to
     * call withInstructionLoader() LAST today, so no other test would notice.
     * Pin the invariant across all of them here.
     *
     * @dataProvider witherProvider
     * @param list<mixed> $args
     */
    public function testEveryWitherPreservesTheInstructionLoader(string $method, array $args): void
    {
        $loader = new InstructionFileLoader(sys_get_temp_dir());
        $base = EngineBackend::new(new EchoProvider(), 'echo')->withInstructionLoader($loader);

        $derived = $base->{$method}(...$args);

        $this->assertNotSame($base, $derived, "{$method}() must return a new instance");
        $this->assertSame(
            $loader,
            $this->instructionLoaderOf($derived),
            "{$method}() must carry the instruction loader through to the new instance",
        );
    }

    /**
     * @return iterable<string, array{string, list<mixed>}>
     */
    public static function witherProvider(): iterable
    {
        yield 'withTools' => ['withTools', [[]]];
        yield 'withSkills' => ['withSkills', [[]]];
        yield 'withSkillRegistry' => ['withSkillRegistry', [new SkillRegistry()]];
        yield 'withHooks' => ['withHooks', [new HookManager(new HookRegistry())]];
        yield 'withoutHooks' => ['withoutHooks', []];
        yield 'withMaxSteps' => ['withMaxSteps', [3]];
        yield 'withWorktreeRoot' => ['withWorktreeRoot', [sys_get_temp_dir()]];
    }

    private function instructionLoaderOf(EngineBackend $backend): ?InstructionFileLoader
    {
        $property = new \ReflectionProperty($backend, 'instructionLoader');
        $property->setAccessible(true);

        return $property->getValue($backend);
    }

    /**
     * Safe-by-default: a backend constructed WITHOUT a withHooks() call must
     * still register the built-in hooks, so a dangerous Bash tool call is
     * denied before the tool ever executes.
     */
    public function testDefaultBackendDeniesDangerousBashToolCall(): void
    {
        $spy = $this->bashSpyTool();
        $backend = EngineBackend::new($this->bashThenAnswerProvider(), 'm')
            ->withTools([$spy]);

        $backend->complete([Message::user('clean up')]);

        $this->assertFalse($spy->executed, 'rm -rf must be denied by the safe-by-default hooks');
    }

    /**
     * The withoutHooks() escape hatch removes the safe-by-default guard, so the
     * same dangerous Bash tool call now reaches the tool.
     */
    public function testWithoutHooksRunsToolUnguarded(): void
    {
        $spy = $this->bashSpyTool();
        $backend = EngineBackend::new($this->bashThenAnswerProvider(), 'm')
            ->withTools([$spy])
            ->withoutHooks();

        $backend->complete([Message::user('clean up')]);

        $this->assertTrue($spy->executed, 'withoutHooks() must leave the tool unguarded');
    }

    // --- helpers -----------------------------------------------------------

    /**
     * A provider that requests a dangerous Bash tool call on the first turn,
     * then answers plainly once it sees the (denied) tool result.
     */
    private function bashThenAnswerProvider(): ProviderInterface
    {
        return new class implements ProviderInterface {
            public int $calls = 0;
            public function name(): string { return 'bash-tc'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return true; }
            public function supportsVision(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 1000; }
            public function costPer1kTokens(string $m, string $d): float { return 0.0; }
            public function complete(CompleteRequest $r): CompleteResponse
            {
                $this->calls++;
                return $this->calls === 1
                    ? new CompleteResponse(content: 'cleaning', toolCalls: [new ToolCall('c1', 'Bash', ['command' => 'rm -rf ./build'])])
                    : new CompleteResponse(content: 'done');
            }
            public function completeStream(CompleteRequest $r): \Generator { yield new CompleteResponse(content: ''); }
            public function embeddings(EmbeddingsRequest $r): EmbeddingsResponse { return new EmbeddingsResponse([]); }
        };
    }

    /**
     * A tool named "Bash" that records whether it executed but never runs a
     * real shell — so the assertion is purely about the hook gate.
     */
    private function bashSpyTool(): Tool
    {
        return new class implements Tool {
            public bool $executed = false;
            public function name(): string { return 'Bash'; }
            public function description(): string { return 'spy bash'; }
            public function inputSchema(): array { return []; }
            public function execute(array $args): ToolResult
            {
                $this->executed = true;
                return new ToolResult(toolCallId: '', content: 'ran');
            }
        };
    }


    private function toolThenAnswerProvider(): ProviderInterface
    {
        return new class implements ProviderInterface {
            public int $calls = 0;
            public function name(): string { return 'tc'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return true; }
            public function supportsVision(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 1000; }
            public function costPer1kTokens(string $m, string $d): float { return 0.0; }
            public function complete(CompleteRequest $r): CompleteResponse
            {
                $this->calls++;
                return $this->calls === 1
                    ? new CompleteResponse(content: 'checking', toolCalls: [new ToolCall('c1', 'clock', [])])
                    : new CompleteResponse(content: 'The time is NOON.');
            }
            public function completeStream(CompleteRequest $r): \Generator { yield new CompleteResponse(content: ''); }
            public function embeddings(EmbeddingsRequest $r): EmbeddingsResponse { return new EmbeddingsResponse([]); }
        };
    }

    private function clockTool(): Tool
    {
        return $this->namedTool('clock', 'NOON');
    }

    private function namedTool(string $name, string $result): Tool
    {
        return new class($name, $result) implements Tool {
            public function __construct(private string $toolName, private string $result) {}
            public function name(): string { return $this->toolName; }
            public function description(): string { return 'test tool'; }
            public function inputSchema(): array { return []; }
            public function execute(array $args): ToolResult { return new ToolResult(toolCallId: '', content: $this->result); }
        };
    }
}
