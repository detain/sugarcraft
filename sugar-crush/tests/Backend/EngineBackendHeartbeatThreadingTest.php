<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Tests\Support\ReapsForkedChildrenTrait;

/**
 * **E493's consumer half: the heartbeat closure must survive the trip from
 * `EngineBackend` through `Runtime` onto the provider request — and, on the
 * forked path, from the CHILD's frame-writer back across the socket.**
 *
 * Round 70 (lane gc) shipped the carrier: `CompleteRequest::$onHeartbeat` plus
 * the two plain-Guzzle providers that honour it by wiring it into libcurl's
 * progress callback. What it could not ship was the thread — the provider
 * field existed and NOTHING in-tree wrote it, so no batch turn ever beat.
 * This file pins the thread at the two points where it can silently vanish:
 *
 *  - the sync `EngineBackend::complete()` → `Runtime::run()` → request chain
 *    (a dropped argument there compiles green and ships the same dead field
 *    gc shipped);
 *  - the forked child's frame-writer in `runCompleteInChild()` (the child is
 *    the only production caller that passes a heartbeat at all, and the frame
 *    it writes is the whole point — the parent resets its idle deadline on
 *    every frame, so a beat that never crosses the socket saves nothing).
 *
 * The wire shape is deliberately NOT new: each beat rides the SAME bare
 * `reasoning` frame with an empty `text` that E456 established for "a chunk
 * with nothing to show", so the parent's existing drop-empty-before-the-
 * painter rule covers it and no consumer learns a new kind. These tests read
 * raw frames off a real socket rather than asserting that equivalence in
 * prose.
 *
 * Fail-soft is pinned in both polarities: a caller that passes no heartbeat
 * gets `onHeartbeat === null` on the request exactly as before E493 landed,
 * and a provider that never fires the closure produces ZERO extra frames —
 * the presence of a thread nobody pulls must cost the wire nothing.
 */
final class EngineBackendHeartbeatThreadingTest extends TestCase
{
    use ReapsForkedChildrenTrait;

    /**
     * Ledger first (rule: the fork census reaps the tracked set before any
     * test-side teardown can race it); every child this file forks is already
     * collected by `collectRawChildFrames()`'s own `pcntl_waitpid()` by the
     * time a test ends, so on the green path the reaper answers ECHILD for
     * each recorded pid and reports nothing. It earns its place on the ABORT
     * path: a child pinned inside a forked `runCompleteInChild()` would
     * otherwise outlive the per-test alarm, which fires in the parent only.
     */
    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();
    }
    /**
     * The channel, end to end, in one process: a heartbeat passed to
     * `complete()` reaches the provider's `CompleteRequest` as a live
     * Closure, and calling it there fires THIS caller's sink.
     */
    public function testTheSyncPathForwardsTheHeartbeatOntoTheProviderRequest(): void
    {
        $beats = 0;
        $provider = new HeartbeatObservingBatchProvider(fireBeats: true);

        EngineBackend::new($provider, 'scripted')
            ->withoutHooks()
            ->complete(
                [Message::user('go')],
                null,
                null,
                null,
                static function () use (&$beats): void {
                    $beats++;
                },
            );

        $this->assertNotNull($provider->lastRequest, 'fixture: the batch provider really ran');
        $this->assertInstanceOf(
            \Closure::class,
            $provider->lastRequest->onHeartbeat,
            'the heartbeat did not survive complete() -> Runtime::run() -> CompleteRequest',
        );
        $this->assertSame(2, $beats, 'every fire inside the provider must reach the caller sink');

        // Identity, not just shape: the closure ON THE REQUEST is the one the
        // caller passed (modulo Closure::fromCallable's pass-through), so a
        // future wrapper that re-throttles here would show up as a lost count.
        ($provider->lastRequest->onHeartbeat)();
        $this->assertSame(3, $beats, 'the request closure is not the caller closure');
    }

    /**
     * The other polarity, and the whole fail-soft law in one assertion: with
     * no heartbeat passed, the provider reads EXACTLY what it read before
     * E493's consumer half existed — `null`, which every honouring provider's
     * `heartbeatOptions(null)` already maps to `[]` — no request options,
     * byte-identical wire behaviour.
     */
    public function testAHeartbeatAbsentFromTheCallArrivesAtTheProviderAsNull(): void
    {
        $provider = new HeartbeatObservingBatchProvider(fireBeats: true);

        EngineBackend::new($provider, 'scripted')->withoutHooks()->complete([Message::user('go')]);

        $this->assertNotNull($provider->lastRequest, 'fixture: the batch provider really ran');
        $this->assertNull(
            $provider->lastRequest->onHeartbeat,
            'an absent heartbeat must stay absent — threading it must not manufacture a default',
        );
        $this->assertSame(
            0,
            $provider->beatsFired,
            'the fixture fires only a non-null heartbeat; a fire here means the null guard broke',
        );
    }

    /**
     * The fork child's half, on a REAL socket: `runCompleteInChild()` must
     * hand `complete()` a heartbeat whose every call writes one bare
     * `reasoning` frame, and those frames must cross BEFORE the reply. This
     * is the exact shape the parent's frame loop already resets its idle
     * deadline on — see `EngineBackendTest`/`ReasoningProgressTest` for the
     * parent side of that rule; here the question is only whether the beat
     * ever reaches the wire at all.
     *
     * Driven by reflection rather than through `completeAsync()` because the
     * parent's own reader consumes every frame it hands out; a raw socket of
     * our own is the only way to SEE the empty-`text` frames, which by
     * contract no caller callback ever receives. The child dies via
     * {@see ForkedChild::exitNow()} on the same path production does.
     */
    public function testTheForkedChildWritesOneBareReasoningFramePerBeat(): void
    {
        $frames = $this->collectRawChildFrames(new HeartbeatObservingBatchProvider(fireBeats: true));

        $kinds = array_map(static fn (array $frame): string => (string) ($frame['kind'] ?? '?'), $frames);
        $this->assertSame(
            ['reasoning', 'reasoning', 'token', 'result'],
            $kinds,
            'the two beats the provider fired must cross as two bare reasoning frames, ahead of the reply',
        );
        $this->assertSame(
            ['', ''],
            array_map(static fn (array $frame): string => (string) ($frame['text'] ?? 'x'), array_slice($frames, 0, 2)),
            'a heartbeat frame carries the EMPTY text — E456\'s nothing-to-show shape, so the parent '
                . 'painter rule already drops it and the deadline reset already covers it',
        );
        $this->assertTrue($frames[3]['ok'] ?? false, 'the turn itself must still resolve');
    }

    /**
     * ...and a provider that CANNOT beat (here: one instructed not to) must
     * produce zero heartbeat traffic — the child always passes the closure,
     * so the only thing that puts a frame on the wire is the provider's
     * transport actually firing it. A third frame kind appearing here, or an
     * empty reasoning frame without a fire, would mean the presence of the
     * thread changed the wire for turns that gain nothing from it.
     */
    public function testAForkedTurnWhoseProviderNeverBeatsCrossesNoHeartbeatFrames(): void
    {
        $frames = $this->collectRawChildFrames(new HeartbeatObservingBatchProvider(fireBeats: false));

        $kinds = array_map(static fn (array $frame): string => (string) ($frame['kind'] ?? '?'), $frames);
        $this->assertSame(
            ['token', 'result'],
            $kinds,
            'a batch turn whose provider never fires the heartbeat must look EXACTLY like one from before E493',
        );
    }

    /**
     * Forks a real child running `runCompleteInChild()` against a private
     * socket pair and returns every frame it wrote, in wire order, decoded
     * through the SAME `drainFrames()` the production parent uses (a private
     * static reached by reflection so the parse under test is the real one).
     *
     * @return list<array<string, mixed>>
     */
    private function collectRawChildFrames(HeartbeatObservingBatchProvider $provider): array
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid') || !\function_exists('stream_socket_pair')) {
            $this::markTestSkipped('this seam is only real across a fork; without pcntl there is no socket to read');
        }

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this::assertIsArray($sockets, 'fixture: the socket pair must build on a host that passed the fork gate');
        [$parentSocket, $childSocket] = $sockets;

        $backend = EngineBackend::new($provider, 'scripted')->withoutHooks();
        $writer = new \ReflectionMethod(EngineBackend::class, 'runCompleteInChild');

        // forkTracked(), not a bare pcntl_fork(): the per-test time limit fires
        // in the PARENT only, so a child pinned inside the forked complete()
        // would survive an abort with no clock on it unless the tearDown
        // ledger owns its pid. The reaper only acts on pids the child did not
        // already collect below.
        $pid = $this->forkTracked();
        if ($pid === -1) {
            fclose($parentSocket);
            fclose($childSocket);
            $this->fail('pcntl_fork() failed on a host that advertises it');
        }

        if ($pid === 0) {
            // The child never returns: runCompleteInChild writes its frames
            // and dies via ForkedChild::exitNow() exactly as production does.
            // The catch below is a paranoia leash so a failure BEFORE that
            // method's own try cannot fall out into phpunit's inherited
            // shutdown (double-reported tests are the classic fork accident).
            try {
                $writer->invoke($backend, $childSocket, [Message::user('go')]);
            } catch (\Throwable) {
                // fall through to the same silent death production uses
            }
            ForkedChild::exitNow(1);
        }

        fclose($childSocket);
        $raw = '';
        while (($chunk = fread($parentSocket, 65536)) !== '' && $chunk !== false) {
            $raw .= $chunk;
        }
        fclose($parentSocket);
        pcntl_waitpid($pid, $status);

        $drain = new \ReflectionMethod(EngineBackend::class, 'drainFrames');

        // invokeArgs, not invoke: drainFrames takes its buffer BY REFERENCE (it
        // consumes whole frames and leaves the remainder), and invoke() forwards
        // its arguments by value - which PHP reports as "must be passed by
        // reference, value given" rather than quietly mis-parsing.
        return $drain->invokeArgs(null, [&$raw]);
    }
}

/**
 * A batch provider that records the request it was handed and, when told to,
 * fires `CompleteRequest::$onHeartbeat` twice mid-call — the same thing the
 * real Sglang/Custom transports do from inside libcurl's progress callback,
 * minus the wire.
 *
 * Named and shaped apart from UsageWiringTest's ScriptedUsageProvider on
 * purpose: this one's subject is the callback's SURVIVAL, and the fork tests
 * rely on the firing happening in whichever process runs complete().
 */
final class HeartbeatObservingBatchProvider implements ProviderInterface
{
    public ?CompleteRequest $lastRequest = null;
    public int $beatsFired = 0;

    public function __construct(private readonly bool $fireBeats) {}

    public function name(): string
    {
        return 'heartbeat-observing';
    }

    public function supportsStreaming(): bool
    {
        return false;
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
        return 8192;
    }

    public function costPer1kTokens(string $model, string $direction): float
    {
        return 0.0;
    }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        $this->lastRequest = $request;

        if ($this->fireBeats && $request->onHeartbeat !== null) {
            ($request->onHeartbeat)();
            ($request->onHeartbeat)();
            $this->beatsFired = 2;
        }

        return new CompleteResponse(content: 'threaded reply');
    }

    public function completeStream(CompleteRequest $request): \Generator
    {
        yield $this->complete($request);
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        return new EmbeddingsResponse(embeddings: []);
    }
}
