<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Diagnostics;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Diagnostics\NoticeSink;
use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tests\Support\ReapsForkedChildrenTrait;

/**
 * O-2a: the runtime-notice inbox is per SESSION, not per process.
 *
 * `RuntimeNoticeSink` was one static queue, so two sessions in one host
 * process — the server Appendix O designs — would each drain the other's
 * warnings. The state now lives on {@see NoticeSink} instances; the static
 * surface routes to whichever is current, a host selects one per turn with
 * {@see RuntimeNoticeSink::using()}, and `EngineBackend::completeAsync()`'s
 * child pins the sink it was forked under. These cases hold each half of that,
 * on the real transport and a real forked turn.
 */
final class NoticeSinkIsolationTest extends TestCase
{
    use HomeSandboxTrait;
    use ReapsForkedChildrenTrait;

    /** @var list<NoticeSink> */
    private array $sinks = [];

    private string $home = '';

    protected function setUp(): void
    {
        parent::setUp();
        RuntimeNoticeSink::reset();
    }

    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();
        foreach ($this->sinks as $sink) {
            $sink->reset();
        }
        RuntimeNoticeSink::reset();
        if ($this->home !== '') {
            $this->restoreHomeSandbox();
            @rmdir($this->home);
        }
        parent::tearDown();
    }

    public function testTwoSessionsInOneProcessEachDrainOnlyTheirOwnRows(): void
    {
        $a = $this->sink(crossFork: false);
        $b = $this->sink(crossFork: false);

        RuntimeNoticeSink::using($a, static fn (): bool => RuntimeNoticeSink::record('for session a'));
        RuntimeNoticeSink::using($b, static fn (): bool => RuntimeNoticeSink::record('for session b'));

        self::assertSame(['for session a'], $a->drain());
        self::assertSame(['for session b'], $b->drain());
        self::assertFalse(
            RuntimeNoticeSink::process()->isArmed(),
            'neither row may have touched the process sink — it was never armed, so it would have dropped them',
        );
    }

    public function testUsingRestoresTheRouteEvenWhenTheBodyThrows(): void
    {
        $outer = $this->sink(crossFork: false);
        $inner = $this->sink(crossFork: false);
        RuntimeNoticeSink::routeTo($outer);

        try {
            RuntimeNoticeSink::using($inner, static function (): never {
                throw new \RuntimeException('turn setup failed');
            });
            self::fail('using() swallowed the body\'s exception');
        } catch (\RuntimeException $e) {
            self::assertSame('turn setup failed', $e->getMessage());
        }

        self::assertSame($outer, RuntimeNoticeSink::current(), 'a throwing body must not leave the route on its sink');

        RuntimeNoticeSink::routeTo(null);
        self::assertSame(RuntimeNoticeSink::process(), RuntimeNoticeSink::current());
    }

    public function testResetDropsTheRouteAndEmptiesTheProcessSinkInPlace(): void
    {
        $process = RuntimeNoticeSink::process();
        RuntimeNoticeSink::arm(false);
        RuntimeNoticeSink::record('left over');
        RuntimeNoticeSink::routeTo($this->sink(crossFork: false));

        RuntimeNoticeSink::reset();

        self::assertSame($process, RuntimeNoticeSink::current(), 'reset() must route back to the process sink');
        self::assertSame($process, RuntimeNoticeSink::process(), 'the process sink is emptied in place, never replaced');
        self::assertFalse($process->isArmed());
        self::assertSame([], $process->drain());
    }

    public function testChildrenForkedUnderTwoSessionsWriteOnlyToTheirOwn(): void
    {
        self::requirePcntl();

        $a = $this->sink(crossFork: true);
        $b = $this->sink(crossFork: true);
        self::assertTrue($a->hasTransport() && $b->hasTransport(), 'both sinks need the cross-fork transport');

        $pids = [];
        foreach (['a' => $a, 'b' => $b] as $name => $sink) {
            $pids[] = RuntimeNoticeSink::using($sink, function () use ($name): int {
                $pid = $this->forkTracked();
                if ($pid === 0) {
                    RuntimeNoticeSink::record("child of session {$name}");
                    ForkedChild::exitNow(0);
                }

                return $pid;
            });
        }
        foreach ($pids as $pid) {
            $status = 0;
            pcntl_waitpid($pid, $status);
        }

        self::assertSame(['child of session a'], $a->drain());
        self::assertSame(['child of session b'], $b->drain());
    }

    /**
     * The read end is inherited by every child forked after the arm. A child
     * that drained it would take rows out of the PARENT's transcript — so the
     * reading methods answer nothing outside the process that armed the sink.
     */
    public function testAForkedChildCannotReadTheParentsInboxDry(): void
    {
        self::requirePcntl();

        $sink = $this->sink(crossFork: true);
        $sink->record('the parent\'s row');

        // The child reports what it could see over a pipe of its own: its exit
        // status is not a channel, because ForkedChild::exitNow() SIGKILLs.
        $report = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($report);
        [$parentEnd, $childEnd] = $report;

        $pid = $this->forkTracked();
        if ($pid === 0) {
            fclose($parentEnd);
            fwrite($childEnd, json_encode(['pending' => $sink->hasPending(), 'drained' => $sink->drain()]) ?: '');
            fclose($childEnd);
            ForkedChild::exitNow(0);
        }
        fclose($childEnd);
        $status = 0;
        pcntl_waitpid($pid, $status);
        $seen = json_decode((string) stream_get_contents($parentEnd), true);
        fclose($parentEnd);

        self::assertSame(
            ['pending' => false, 'drained' => []],
            $seen,
            'the child saw (or took) the parent\'s pending rows',
        );
        self::assertSame(['the parent\'s row'], $sink->drain(), 'the row must still be the parent\'s to drain');
    }

    /**
     * A REAL forked turn: the provider runs in `completeAsync()`'s child and
     * reports, through the facade every emitter uses, what that child sees.
     * Started under session A's sink with A holding a read watcher on the
     * parent's loop, the row must land in A — not in the process sink, which
     * is armed with a transport of its own — and the child must have forgotten
     * the inherited watcher (`EngineBackend`'s child-branch arm).
     */
    public function testAForkedTurnWritesToTheSessionItWasStartedUnder(): void
    {
        self::requirePcntl();
        $this->home = sys_get_temp_dir() . '/sc_notice_iso_' . bin2hex(random_bytes(4));
        $this->useHomeSandbox($this->home);

        RuntimeNoticeSink::arm();
        $a = $this->sink(crossFork: true);
        $woke = false;
        self::assertTrue($a->notifyOnceWhenPending(static function () use (&$woke): void {
            $woke = true;
        }));

        $backend = EngineBackend::new(self::reportingProvider(), 'notice-probe');
        $promise = RuntimeNoticeSink::using($a, static fn (): PromiseInterface => $backend->completeAsync([Message::user('go')]));
        self::assertSame(
            RuntimeNoticeSink::process(),
            RuntimeNoticeSink::current(),
            'the route must be back on the process sink as soon as the turn is started',
        );

        $this->awaitForkedTurn($promise);

        self::assertTrue($woke, 'the parent\'s watcher on session A never fired');
        self::assertSame(['child: watcher forgotten'], $a->drain());
        self::assertSame([], RuntimeNoticeSink::process()->drain(), 'the turn\'s notice leaked into the process sink');
    }

    private function sink(bool $crossFork): NoticeSink
    {
        $sink = NoticeSink::new();
        $sink->arm($crossFork);
        $this->sinks[] = $sink;

        return $sink;
    }

    private static function requirePcntl(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            self::markTestSkipped('ext-pcntl is required to exercise the fork boundary');
        }
    }

    /** Pump the shared loop until the forked turn settles, bounded by one safety timer. */
    private function awaitForkedTurn(PromiseInterface $promise): void
    {
        $loop = Loop::get();
        $outcome = null;
        $settle = static function (mixed $v) use (&$outcome, $loop): void {
            $outcome = $v;
            $loop->stop();
        };
        $promise->then($settle, $settle);

        if ($outcome === null) {
            $guard = $loop->addTimer(30.0, static function () use (&$outcome, $loop): void {
                $outcome = new \RuntimeException('the forked turn never settled');
                $loop->stop();
            });
            $loop->run();
            $loop->cancelTimer($guard);
        }

        if ($outcome instanceof \Throwable) {
            self::fail('forked turn failed: ' . $outcome->getMessage());
        }
        self::assertInstanceOf(Message::class, $outcome);
    }

    /** A provider that records, from inside the turn, whether the inherited watcher is still there. */
    private static function reportingProvider(): ProviderInterface
    {
        return new class () implements ProviderInterface {
            public function name(): string { return 'notice-probe'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return true; }
            public function supportsVision(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 100_000; }
            public function costPer1kTokens(string $model, string $direction): float { return 0.0; }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                RuntimeNoticeSink::record(
                    'child: watcher ' . (RuntimeNoticeSink::isNotificationArmed() ? 'still armed' : 'forgotten'),
                );

                return new CompleteResponse(content: 'done');
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                yield $this->complete($request);
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                return new EmbeddingsResponse([]);
            }
        };
    }
}
