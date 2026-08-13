<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\ToolEventPumpMsg;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * crush_code.md Phase 0 item 13: streaming was fake end to end while still
 * paying its full SSE-parsing cost.
 *
 * Two independent breaks, both covered here.
 * {@see Runtime::runStreaming()} parsed the wire correctly and then
 * re-buffered the ENTIRE response before yielding a single
 * {@see \SugarCraft\Crush\Messages\AssistantMessage}, and
 * {@see Bootstrap::chat()} never turned streaming on at all — so a user saw
 * "assistant is thinking…" and then the whole reply at once, exactly as if
 * streaming were switched off.
 *
 * **Every assertion here is about ARRIVAL, not content.** A test that only
 * checked the assembled text would have passed against the broken code and
 * been worth nothing. The technique used throughout is an interleaving
 * witness: the fake provider records how many chunks it has PRODUCED at each
 * yield, and the observer records that count at each delivery. Real streaming
 * delivers chunk k while exactly k have been produced; the re-buffering
 * implementation delivers everything at the end, when all N exist. That is an
 * ordering proof with no sleeps and no timing tolerance — except in the fork
 * test, where a real wall-clock gap is the only thing that can distinguish
 * "arrived across the socket during the turn" from "arrived with the result".
 *
 * Nothing here makes a network call: {@see StreamRecordingProvider} is the
 * whole "SSE source". The one forking test is bounded by
 * {@see EngineBackendTest}'s established single-run()/stop() harness plus a
 * safety timer, and {@see EngineBackend::completeAsync()} reaps its own child.
 *
 * @see Runtime::runStreaming()
 * @see EngineBackend::completeAsync()
 * @see Chat::pumpLiveToolEvents()
 */
final class StreamingWiringTest extends TestCase
{
    /**
     * Wall-clock the fork test's fake provider spends producing its stream.
     * Long enough that "the first delta crossed the socket before the result
     * frame did" is measurable rather than inferred, short enough to be a
     * rounding error in the suite.
     */
    private const FORK_CHUNK_DELAY_SECONDS = 0.03;

    // =====================================================================
    // Runtime — the wire layer
    // =====================================================================

    /**
     * The core regression. Each delta must be handed over while the provider
     * is still mid-stream, not after the generator is exhausted.
     */
    public function testRuntimeForwardsEachChunkBeforeTheNextOneIsProduced(): void
    {
        $provider = new StreamRecordingProvider(['Hel', 'lo, ', 'world']);

        $seen = [];
        $runtime = new Runtime($provider, new HookManager(new HookRegistry()));
        $messages = iterator_to_array($runtime->run(
            \SugarCraft\Crush\App\App::new($provider, 'fake'),
            null,
            null,
            static function (string $delta) use ($provider, &$seen): void {
                // How much the provider had produced at the moment this
                // delta was delivered. 1,2,3 == live. 3,3,3 == re-buffered.
                $seen[] = [$delta, $provider->producedCount()];
            },
        ));

        $this->assertSame(
            [['Hel', 1], ['lo, ', 2], ['world', 3]],
            $seen,
            'each chunk must be forwarded as it is parsed; a count of 3 on every '
            . 'row means the whole response was re-buffered before anything was delivered',
        );
        $this->assertSame('Hello, world', $messages[0]->content(), 'the assembled turn must still be whole');
    }

    /**
     * A non-streaming provider still gets ONE delta carrying the whole reply,
     * so a consumer never has to ask whether the provider streams before
     * deciding whether to expect any text at all.
     */
    public function testRuntimeEmitsTheWholeReplyAsOneDeltaOnTheBatchPath(): void
    {
        $provider = new StreamRecordingProvider(['ignored'], streams: false);

        $seen = [];
        $runtime = new Runtime($provider, new HookManager(new HookRegistry()));
        iterator_to_array($runtime->run(
            \SugarCraft\Crush\App\App::new($provider, 'fake'),
            null,
            null,
            static function (string $delta) use (&$seen): void { $seen[] = $delta; },
        ));

        $this->assertSame(['batch reply'], $seen);
    }

    // =====================================================================
    // EngineBackend — in-process
    // =====================================================================

    public function testEngineBackendCompleteStreamsChunksInsteadOfOneShotAtTheEnd(): void
    {
        $provider = new StreamRecordingProvider(['one ', 'two ', 'three']);
        $backend = EngineBackend::new($provider, 'fake');

        $seen = [];
        $reply = $backend->complete(
            [Message::user('go')],
            static function (string $delta) use ($provider, &$seen): void {
                $seen[] = [$delta, $provider->producedCount()];
            },
        );

        $this->assertSame([['one ', 1], ['two ', 2], ['three', 3]], $seen);
        // The old end-of-turn one-shot must not ALSO fire, or the reply is
        // painted twice: once streamed, once whole.
        $this->assertSame($reply->content, implode('', array_column($seen, 0)));
    }

    // =====================================================================
    // EngineBackend — across the fork
    // =====================================================================

    /**
     * The path a real run actually takes. The completion runs in a forked
     * child ({@see EngineBackend::runCompleteInChild()}), so an in-process
     * closure is useless — a delta has to become a frame on the existing
     * length-prefixed channel or it dies with the child.
     *
     * Timing IS the assertion here, deliberately: with the child streaming
     * over ~90ms, "the first delta was in hand well before the promise
     * settled" is exactly the property under test, and it is the one thing an
     * ordering-only check could not distinguish from a batch of token frames
     * flushed alongside the result.
     */
    public function testCompleteAsyncDeliversTokensAcrossTheForkBeforeTheTurnSettles(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('stream_socket_pair')) {
            $this->markTestSkipped('pcntl/stream_socket_pair required for the forked completion path');
        }

        $backend = EngineBackend::new(
            new StreamRecordingProvider(['a', 'b', 'c', 'd'], delaySeconds: self::FORK_CHUNK_DELAY_SECONDS),
            'fake',
        );

        $deltas = [];
        $firstDeltaAt = null;
        $promise = $backend->completeAsync(
            [Message::user('go')],
            static function (string $delta) use (&$deltas, &$firstDeltaAt): void {
                $firstDeltaAt ??= \microtime(true);
                $deltas[] = $delta;
            },
        );

        $reply = $this->awaitPromise($promise);
        $resolvedAt = \microtime(true);

        $this->assertSame(['a', 'b', 'c', 'd'], $deltas, 'token frames must cross the socket individually');
        $this->assertNotNull($firstDeltaAt);
        $this->assertGreaterThan(
            self::FORK_CHUNK_DELAY_SECONDS,
            $resolvedAt - $firstDeltaAt,
            'the first token reached the parent at essentially the same instant as the result — '
            . 'the child batched instead of streaming',
        );
        // Streamed deltas suppress the result frame's one-shot, so the caller
        // is handed the reply exactly once.
        $this->assertSame('abcd', $reply->content);
        $this->assertSame('abcd', implode('', $deltas));
    }

    // =====================================================================
    // Chat — the recorded sequence of partial states
    // =====================================================================

    /**
     * The TUI-visible half: a recorded sequence of {@see Chat::streamingText()}
     * snapshots that GROWS. Before this wiring the sequence was `['', '', '']`
     * — the model had nowhere to put a partial reply.
     */
    public function testChatAccumulatesPartialRepliesIntoAGrowingSequence(): void
    {
        $chat = (new Chat(inFlight: true, streaming: true))->withSize(80, 24);

        $states = [$chat->streamingText()];
        foreach (['Por', 'ting ', 'a TUI'] as $delta) {
            $chat->enqueueToken($delta);
            [$chat] = $chat->update(new ToolEventPumpMsg());
            $states[] = $chat->streamingText();
        }

        $this->assertSame(['', 'Por', 'Porting ', 'Porting a TUI'], $states);
        $this->assertSame([], $chat->history, 'a half-written reply must never enter history');
    }

    /**
     * The delta queue is drained through the SAME pump the tool events use, so
     * a burst that lands between two ticks is folded in one repaint rather than
     * one repaint per token — a reply is thousands of tokens and the transcript
     * is re-rendered whole.
     */
    public function testChatCoalescesABurstOfDeltasIntoASingleUpdate(): void
    {
        $chat = new Chat(inFlight: true, streaming: true);
        foreach (['a', 'b', 'c', 'd'] as $delta) {
            $chat->enqueueToken($delta);
        }

        [$next, $more] = $chat->update(new ToolEventPumpMsg());

        $this->assertSame('abcd', $next->streamingText());
        $this->assertNull($more, 'the whole burst should have drained in one pass');
    }

    /**
     * Coalescing must stop at a tool event: text that preceded a call cannot
     * be allowed to jump ahead of it, or the transcript claims the model
     * explained itself after acting rather than before.
     */
    public function testCoalescingStopsAtAToolEventSoOrderIsPreserved(): void
    {
        $chat = new Chat(inFlight: true, streaming: true);
        $chat->enqueueToken('Let me look. ');
        $chat->enqueueToolEvent(new ToolStarted('call-1', 'Read'));
        $chat->enqueueToken('Found it.');

        [$afterText, $more] = $chat->update(new ToolEventPumpMsg());
        $this->assertSame('Let me look. ', $afterText->streamingText());
        $this->assertNotNull($more, 'the tool event behind the text must still be queued');

        // The tool call starts: the model has stopped talking and started
        // doing, so its introduction belongs to the step that just ended.
        [$afterTool] = $afterText->update(new ToolEventPumpMsg());
        $this->assertSame('', $afterTool->streamingText());
        $this->assertNotSame([], $afterTool->history, 'the running placeholder must have been appended');

        [$afterMore] = $afterTool->update(new ToolEventPumpMsg());
        $this->assertSame('Found it.', $afterMore->streamingText(), 'the next step streams from a blank partial');
    }

    /**
     * The coalescing loop tracks a consumed-prefix cursor and slices once
     * instead of `array_shift()`ing per delta, which was quadratic in queue
     * length. Correctness of that rewrite is entirely about where the prefix
     * ENDS, so this drives a burst big enough that an off-by-one cannot hide
     * and checks both halves of the boundary: everything before the tool event
     * is folded, everything from it onward is still queued in order.
     */
    public function testALargeBurstCoalescesWithoutLosingOrShiftingTheQueueBoundary(): void
    {
        $chat = new Chat(inFlight: true, streaming: true);
        $expected = '';
        for ($i = 0; $i < 2000; $i++) {
            $chat->enqueueToken('t' . $i . ' ');
            $expected .= 't' . $i . ' ';
        }
        $chat->enqueueToolEvent(new ToolStarted('call-1', 'Read'));
        $chat->enqueueToken('after the call');

        [$afterText, $more] = $chat->update(new ToolEventPumpMsg());
        $this->assertSame($expected, $afterText->streamingText(), 'the whole delta run must fold into one append');
        $this->assertNotNull($more, 'the tool event and the delta behind it must still be queued');

        [$afterTool] = $afterText->update(new ToolEventPumpMsg());
        $this->assertSame('', $afterTool->streamingText());
        $this->assertNotSame([], $afterTool->history, 'the entry after the folded run must be the ToolStarted');

        [$afterMore] = $afterTool->update(new ToolEventPumpMsg());
        $this->assertSame('after the call', $afterMore->streamingText(), 'nothing may be dropped past the boundary');
    }

    /**
     * A throwing `onToken` observer must not strand the turn. The exception
     * unwinds out of the `Cmd::promise()` factory, so before this was caught
     * the turn produced no promise at all: no later delta, no settlement, and
     * a Chat stuck `inFlight`. The observer is optional; the turn is not.
     */
    public function testAThrowingObserverLosesItsOwnDeltasButNotTheTurn(): void
    {
        $backend = new ChunkedBackend(['Hi', ' there', ' you']);
        $seen = [];
        $chat = (new Chat(inputBuf: 'hello', backend: $backend, streaming: true))
            ->onToken(static function (string $delta) use (&$seen): void {
                $seen[] = $delta;

                throw new \RuntimeException('embedder blew up on ' . $delta);
            });

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        // error_log is the reporting channel, so point it at a file both to
        // keep the suite's stderr clean and to assert the failure is not
        // swallowed silently.
        $log = \tempnam(\sys_get_temp_dir(), 'sugarcrush_ontoken_');
        $this->assertIsString($log);
        $previousLog = \ini_get('error_log');
        \ini_set('error_log', $log);

        try {
            $this->startAsyncCmd($cmd, $resolved);
        } finally {
            $previousLog === false ? \ini_restore('error_log') : \ini_set('error_log', $previousLog);
        }

        $this->assertSame(['Hi'], $seen, 'a sink that throws is detached rather than retried once per token');
        $this->assertStringContainsString('embedder blew up on Hi', (string) \file_get_contents($log));
        @\unlink($log);

        // The UI's own accumulation never depended on the observer.
        [$streaming] = $next->update(new ToolEventPumpMsg());
        $this->assertSame('Hi there you', $streaming->streamingText());

        [$done] = $streaming->update($this->settleAsyncCmd($backend, $resolved));
        $this->assertSame('', $done->streamingText());
        $this->assertFalse($done->inFlight, 'the turn must still settle');
        $this->assertSame('Hi there you', $done->history[array_key_last($done->history)]->content);
    }

    /**
     * End-to-end through the real Cmd {@see Chat::submit()} schedules: proves
     * `scheduleBackendCompletion()` actually hands the backend a live
     * `$onToken`. It used to compute `$next->streaming ? $next->onToken : null`,
     * which was null on every real run.
     */
    public function testSubmitHandsTheBackendALiveTokenSinkWhenStreamingIsOn(): void
    {
        $backend = new ChunkedBackend(['Hi', ' there']);
        $chat = new Chat(inputBuf: 'hello', backend: $backend, streaming: true);

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertInstanceOf(\Closure::class, $cmd);

        // Running the Cmd is the turn. Deltas land in the shared inbox WHILE
        // it runs, so the pump can walk them into partial states before the
        // reply is committed — which is the whole claim under test, and why
        // the turn is only settled afterwards.
        $this->startAsyncCmd($cmd, $resolved);

        $states = [];
        $streaming = $next;
        for ($i = 0; $i < 3; $i++) {
            [$streaming] = $streaming->update(new ToolEventPumpMsg());
            $states[] = $streaming->streamingText();
        }
        $this->assertSame('Hi there', $states[0], 'the backend never called $onToken — streaming is not wired');

        $settledMsg = $this->settleAsyncCmd($backend, $resolved);
        $this->assertInstanceOf(AssistantMsg::class, $settledMsg);
        [$done] = $streaming->update($settledMsg);
        $this->assertSame('', $done->streamingText(), 'the settled reply supersedes the partial');
        $this->assertSame('Hi there', $done->history[array_key_last($done->history)]->content);
    }

    public function testStreamingOffLeavesTheTokenSinkDetached(): void
    {
        $chat = new Chat(
            inputBuf: 'hello',
            backend: new ChunkedBackend(['Hi', ' there']),
            streaming: false,
        );

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->startAsyncCmd($cmd, $resolved);

        [$pumped] = $next->update(new ToolEventPumpMsg());
        $this->assertSame('', $pumped->streamingText(), 'an embedder that opted out must not get partials');
    }

    // =====================================================================
    // Cancel / error / tool-call mid-stream
    // =====================================================================

    public function testEscapeEscapeMidStreamDropsThePartialAndStrandsLaterDeltas(): void
    {
        $chat = new Chat(inFlight: true, streaming: true, inFlightCancellation: new CancellationToken());
        $chat->enqueueToken('half a sen');
        [$chat] = $chat->update(new ToolEventPumpMsg());
        $this->assertSame('half a sen', $chat->streamingText());

        [$first] = $chat->update(new KeyMsg(KeyType::Escape, ''));
        [$cancelled] = $first->update(new KeyMsg(KeyType::Escape, ''));

        $this->assertSame('', $cancelled->streamingText(), 'a half sentence must not sit under the cancellation notice');
        $this->assertFalse($cancelled->inFlight);

        // A child that has not noticed the cancel yet keeps writing frames.
        // Enqueued through the PRE-cancel instance, exactly as the backend's
        // still-live closure would: same shared inbox, the abandoned turn's
        // generation stamp.
        $chat->enqueueToken('tence more');
        [$after] = $cancelled->update(new ToolEventPumpMsg());
        $this->assertSame('', $after->streamingText(), 'a stale turn must not type into the transcript');
    }

    public function testProviderErrorMidStreamReplacesThePartialWithTheError(): void
    {
        $backend = new ChunkedBackend(['partial…'], failWith: 'connection reset');
        $chat = new Chat(inputBuf: 'hello', backend: $backend, streaming: true);

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->startAsyncCmd($cmd, $resolved);

        // The bytes that DID arrive before the provider died are on screen.
        [$streaming] = $next->update(new ToolEventPumpMsg());
        $this->assertSame('partial…', $streaming->streamingText());

        [$failed] = $streaming->update($this->settleAsyncCmd($backend, $resolved));

        $this->assertSame('', $failed->streamingText());
        $last = $failed->history[array_key_last($failed->history)];
        $this->assertStringContainsString('connection reset', $last->content);
        // The partial is dropped rather than committed: it was never a turn
        // the provider stood behind, and left in place it would read as an
        // answer that merely stopped short.
        foreach ($failed->history as $message) {
            $this->assertNotSame('partial…', $message->content);
        }
        $this->assertFalse($failed->inFlight);
    }

    public function testToolCallArrivingMidStreamKeepsBothTheProseAndThePlaceholderInOrder(): void
    {
        $chat = new Chat(inFlight: true, streaming: true);
        $chat->enqueueToken('Checking the clock. ');
        $chat->enqueueToolEvent(new ToolStarted('call-9', 'clock'));
        $chat->enqueueToolEvent(new ToolFinished('call-9', 'clock', new ToolResult('call-9', '12:00')));
        $chat->enqueueToken('It is noon.');

        $current = $chat;
        for ($i = 0; $i < 4; $i++) {
            [$current] = $current->update(new ToolEventPumpMsg());
        }

        $this->assertSame('It is noon.', $current->streamingText());
        $this->assertCount(1, $current->history, 'the finished call replaces its placeholder rather than stacking');
        $this->assertNotSame([], $current->history[0]->toolResults);
    }

    /**
     * Deltas the pump never reached before the turn settled are dropped, not
     * replayed into {@see \SugarCraft\Crush\BackendToolEventsMsg}: that queue
     * carries tool lifecycle states, and the settled Message beside them
     * already contains every byte those deltas described. Replaying them would
     * also crash the tool-event arm, which has no case for text.
     */
    public function testUndrainedDeltasAreDiscardedRatherThanReplayedAsToolEvents(): void
    {
        $backend = new ChunkedBackend(['never', ' pumped']);
        $chat = new Chat(inputBuf: 'hello', backend: $backend, streaming: true);

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->startAsyncCmd($cmd, $resolved);
        $settled = $this->settleAsyncCmd($backend, $resolved);

        $this->assertInstanceOf(AssistantMsg::class, $settled, 'text deltas must not be dressed up as tool events');

        [$done] = $next->update($settled);
        $this->assertSame('', $done->streamingText());
        $this->assertSame('never pumped', $done->history[array_key_last($done->history)]->content);
    }

    // =====================================================================
    // Renderer + Bootstrap
    // =====================================================================

    public function testRendererPaintsThePartialAboveTheThinkingPlaceholder(): void
    {
        $chat = (new Chat(inFlight: true, streaming: true))->withSize(100, 30);
        $chat->enqueueToken('Porting a Go TUI');
        [$chat] = $chat->update(new ToolEventPumpMsg());

        $frame = Renderer::render($chat);

        $this->assertStringContainsString('Porting a Go TUI', $frame, 'the partial reply never reached the screen');
        $this->assertStringContainsString('thinking', $frame, 'the turn is still running — the spinner must stay');
    }

    /**
     * A partial is a genuinely different input class from a settled reply — an
     * unterminated fence, a half-written table row — and every frame of a
     * streaming turn feeds one to the Markdown renderer. It must not be able
     * to take the TUI down mid-answer.
     */
    public function testRendererSurvivesAHalfWrittenMarkdownConstruct(): void
    {
        $chat = (new Chat(inFlight: true, streaming: true))->withSize(100, 30);
        $chat->enqueueToken("Here:\n\n```php\n<?php \$x = [");
        [$chat] = $chat->update(new ToolEventPumpMsg());

        $frame = Renderer::render($chat);

        $this->assertIsString($frame);
        $this->assertNotSame('', $frame);
    }

    /**
     * `bin/sugarcrush` runs {@see Bootstrap::app()} — the pane shell HOSTING
     * the Chat — not the bare Chat, so the partial has to survive two more
     * hops to reach a real screen: {@see \SugarCraft\Crush\App\App::update()}'s
     * default arm delegating {@see ToolEventPumpMsg} down, and the pane
     * compositor re-rendering the hosted model through
     * {@see Renderer::renderView()} after a `withSize()` clone.
     */
    public function testThePartialSurvivesTheAppShellThatTheBinaryActuallyRuns(): void
    {
        $chat = new Chat(inFlight: true, streaming: true);
        $app = \SugarCraft\Crush\App\App::new(new StreamRecordingProvider([]), 'fake')->withChat($chat);
        // Size the shell the way the real one is sized — WindowSizeMsg is the
        // size truth for both the App and the hosted Chat.
        [$app] = $app->update(new \SugarCraft\Core\Msg\WindowSizeMsg(120, 40));

        // Enqueued against the ORIGINAL instance: every clone shares the one
        // inbox, which is exactly the property that lets a backend append to a
        // Chat that has since been replaced several times over.
        $chat->enqueueToken('streaming through the shell');
        [$pumped] = $app->update(new ToolEventPumpMsg());

        $view = $pumped->view();
        $frame = $view instanceof \SugarCraft\Core\View ? $view->body : $view;

        $this->assertStringContainsString(
            'streaming through the shell',
            $frame,
            'the pane shell dropped the partial — it survives in the bare Chat but not on the live path',
        );
    }

    /**
     * The other half of the defect: everything above was already reachable in
     * principle, but {@see Bootstrap::chat()} never switched streaming on, so
     * no real run ever took the path.
     */
    public function testBootstrapChatEnablesStreaming(): void
    {
        $home = \sys_get_temp_dir() . '/sugarcrush_streaming_home_' . \uniqid('', true);
        $repo = $home . '/repo';
        \mkdir($repo, 0755, true);
        $originalHome = \getenv('HOME') ?: '';
        \putenv('HOME=' . $home);

        try {
            $this->assertTrue(
                Bootstrap::chat($repo)->isStreaming(),
                'a real run must ask the backend to stream, or the SSE parsing is paid for nothing',
            );
        } finally {
            $originalHome === '' ? \putenv('HOME') : \putenv('HOME=' . $originalHome);
            self::removeDirectory($home);
        }
    }

    // =====================================================================
    // Harness
    // =====================================================================

    /**
     * Pump the real ReactPHP loop until $promise settles. Copied in shape from
     * {@see \SugarCraft\Crush\Tests\Backend\EngineBackendTest::awaitPromise()}
     * — one long-lived run()/stop() pair rather than a re-entering poll, which
     * can miss a forked child's readability edge — with a safety timer so a
     * regression fails the test instead of hanging the suite.
     */
    private function awaitPromise(PromiseInterface $promise): mixed
    {
        $loop = \React\EventLoop\Loop::get();
        $settled = false;
        $value = null;
        $error = null;

        $promise->then(
            function ($v) use (&$settled, &$value, $loop): void { $settled = true; $value = $v; $loop->stop(); },
            function (\Throwable $e) use (&$settled, &$error, $loop): void { $settled = true; $error = $e; $loop->stop(); },
        );

        if (!$settled) {
            $safety = $loop->addTimer(15.0, static function () use ($loop): void { $loop->stop(); });
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

    /**
     * Run a Cmd built by Cmd::promise() and start watching its outcome,
     * WITHOUT ending the turn.
     *
     * Split from {@see settleAsyncCmd()} on purpose: settling is what drains
     * the shared inbox ({@see Chat::drainToolEventInbox()}), so a test that
     * wants to observe partial states has to look between the two — the same
     * window the live pump's tick occupies during a real turn. The `then()` is
     * attached here rather than at settle time because a promise resolved
     * before anything subscribes would run its chain with nobody listening.
     *
     * @param-out ?\SugarCraft\Core\Msg $resolved
     */
    private function startAsyncCmd(\Closure $cmd, mixed &$resolved = null): void
    {
        $asyncCmd = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $asyncCmd);
        $asyncCmd->promise->then(static function ($msg) use (&$resolved): void { $resolved = $msg; });
    }

    /** End the in-flight turn and hand back the Msg its Cmd dispatched. */
    private function settleAsyncCmd(ChunkedBackend $backend, mixed &$resolved): mixed
    {
        $backend->finish();
        $this->assertNotNull($resolved, 'the turn never dispatched a Msg');

        return $resolved;
    }

    private static function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() ? @\rmdir($entry->getPathname()) : @\unlink($entry->getPathname());
        }
        @\rmdir($dir);
    }
}

/**
 * A network-free stand-in for an SSE source that also WITNESSES its own
 * progress: {@see producedCount()} is how many chunks have left the generator,
 * so an observer can record what the provider had produced at the instant each
 * delta was delivered. That comparison is what separates real streaming from
 * a re-buffered replay — see the test class docblock.
 *
 * A concrete class rather than a PHPUnit mock because one test forks: mock
 * bookkeeping done in the child is lost on exit, and a plain object survives
 * the copy intact.
 */
final class StreamRecordingProvider implements ProviderInterface
{
    private int $produced = 0;

    /**
     * @param list<string> $chunks
     * @param bool         $streams        false makes this a batch-only provider
     * @param float        $delaySeconds   artificial gap between chunks, so a
     *                                     cross-process test can tell "arrived
     *                                     during the turn" from "arrived with
     *                                     the result"
     */
    public function __construct(
        private readonly array $chunks,
        private readonly bool $streams = true,
        private readonly float $delaySeconds = 0.0,
    ) {}

    public function producedCount(): int
    {
        return $this->produced;
    }

    public function name(): string
    {
        return 'stream-recording';
    }

    public function supportsStreaming(): bool
    {
        return $this->streams;
    }

    public function supportsFunctionCalling(): bool
    {
        return false;
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
        return 100000;
    }

    public function costPer1kTokens(string $model, string $direction): float
    {
        return 0.0;
    }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        return new CompleteResponse(content: 'batch reply');
    }

    public function completeStream(CompleteRequest $request): \Generator
    {
        foreach ($this->chunks as $chunk) {
            if ($this->delaySeconds > 0.0) {
                \usleep((int) ($this->delaySeconds * 1_000_000));
            }
            $this->produced++;

            yield new CompleteResponse(content: $chunk);
        }
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        return new EmbeddingsResponse(embeddings: []);
    }
}

/**
 * A {@see Backend} that reports its reply in pieces through `$onToken` and
 * then STAYS IN FLIGHT until {@see finish()} is called — the seam
 * {@see Chat::scheduleBackendCompletion()} is supposed to connect.
 *
 * Deliberately not resolving synchronously. A promise that is already
 * fulfilled when it is returned runs its whole `then()` chain inside the Cmd
 * factory, so the turn would settle — and
 * {@see Chat::drainToolEventInbox()} would empty the inbox — before a test
 * could ever look at a partial state. Holding a {@see Deferred} open reproduces
 * the real shape: text arrives during the turn, settlement comes after.
 *
 * No loop and no fork: these tests are about the Chat-side wiring, and the
 * cross-process delivery is covered separately against the real
 * {@see EngineBackend}.
 */
final class ChunkedBackend implements Backend
{
    private ?\React\Promise\Deferred $deferred = null;

    /**
     * @param list<string> $chunks
     * @param ?string      $failWith when set, the turn streams these chunks and
     *                               THEN fails — a provider dying mid-reply
     */
    public function __construct(
        private readonly array $chunks,
        private readonly ?string $failWith = null,
    ) {}

    public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
    {
        foreach ($this->chunks as $chunk) {
            if ($onToken !== null) {
                $onToken($chunk);
            }
        }

        if ($this->failWith !== null) {
            throw new \RuntimeException($this->failWith);
        }

        return Message::assistant(implode('', $this->chunks));
    }

    public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
    {
        // Text first, exactly as a streaming provider delivers it...
        foreach ($this->chunks as $chunk) {
            if ($onToken !== null) {
                $onToken($chunk);
            }
        }

        // ...settlement whenever the test says the turn ended.
        $this->deferred = new \React\Promise\Deferred();

        return $this->deferred->promise();
    }

    /** End the in-flight turn: resolve with the assembled reply, or fail it. */
    public function finish(): void
    {
        $deferred = $this->deferred;
        if ($deferred === null) {
            throw new \LogicException('finish() called with no turn in flight');
        }
        $this->deferred = null;

        $this->failWith === null
            ? $deferred->resolve(Message::assistant(implode('', $this->chunks)))
            : $deferred->reject(new \RuntimeException($this->failWith));
    }
}
