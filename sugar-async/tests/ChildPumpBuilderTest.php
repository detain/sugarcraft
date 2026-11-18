<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Async\ChildPump;
use SugarCraft\Async\DrainResult;
use SugarCraft\Async\WindowsPipes;

/**
 * The builder's knobs, each pinned against a real child. The static drain()
 * contract is pinned by ChildPumpTest; this file covers what the builder adds.
 */
final class ChildPumpBuilderTest extends TestCase
{
    /** @var list<resource> */
    private array $procs = [];

    protected function tearDown(): void
    {
        foreach ($this->procs as $p) {
            @proc_terminate($p);
            @proc_close($p);
        }
        $this->procs = [];
    }

    /**
     * @return array{proc: resource, pipes: array<int, resource>}
     */
    private function spawn(string $php, bool $withStdin = false): array
    {
        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        if ($withStdin) {
            $desc[0] = ['pipe', 'r'];
        }
        $pipes = [];
        $proc = proc_open([PHP_BINARY, '-r', $php], $desc, $pipes);
        $this->assertIsResource($proc);
        $this->procs[] = $proc;

        return ['proc' => $proc, 'pipes' => $pipes];
    }

    private function requirePosixPipes(): void
    {
        if (WindowsPipes::runtimeNeedsIt()) {
            $this->markTestSkipped('The select arm needs non-blocking pipes, which Windows proc_open pipes are not.');
        }
    }

    public function testSettersReturnANewInstanceAndTheCapTakesEffect(): void
    {
        $this->requirePosixPipes();
        $p = $this->spawn('fwrite(STDOUT, str_repeat("x", 100));');
        $base = ChildPump::new([1 => $p['pipes'][1]]);
        $capped = $base->withCap(7);

        $this->assertNotSame($base, $capped);
        $r = $capped->withDeadline(microtime(true) + 10)->pump();
        $this->assertSame(7, strlen($r->buffer(1)));
        $this->assertSame(93, $r->droppedBytes(1));
    }

    public function testDefaultCapApplies(): void
    {
        $this->requirePosixPipes();
        // Regression: the copies with no cap (candy-code CapturesProcessOutput)
        // grew without bound. A cap-less pump now keeps the default and counts the rest.
        $p = $this->spawn('fwrite(STDOUT, str_repeat("a", ' . (ChildPump::DEFAULT_MAX_BYTES + 1000) . '));');
        $r = ChildPump::new([1 => $p['pipes'][1]])
            ->withDeadline(microtime(true) + 30)
            ->pump();

        $this->assertSame(ChildPump::DEFAULT_MAX_BYTES, strlen($r->buffer(1)));
        $this->assertSame(1000, $r->droppedBytes(1));
        $this->assertTrue($r->truncated());
    }

    public function testDefaultDeadlineIsTheClockAtStartPlusTheDefaultTimeout(): void
    {
        $this->requirePosixPipes();
        // The clock reads 0 when the default deadline is computed, then 11 on
        // the first loop pass: 11 is past the default ten seconds, so EXPIRED.
        $seq = [0.0, 11.0];
        $clock = static function () use (&$seq): float {
            return array_shift($seq) ?? 11.0;
        };
        $p = $this->spawn('sleep(30);');
        $r = ChildPump::new([1 => $p['pipes'][1]])->withClock($clock)->pump();

        $this->assertSame(DrainResult::EXPIRED, $r->reason);
        $this->assertSame(10.0, ChildPump::DEFAULT_TIMEOUT_SECONDS);
    }

    /**
     * Pins the default's value, not just its sign: a clock reading inside the
     * ten-second window with the child still running must not expire the pump.
     * A default of 1 s, 5 s or 9.5 s would expire here, and a much longer one
     * would wait a second time, which the select stub refuses.
     */
    public function testDefaultDeadlineDoesNotExpireBeforeTenSecondsHavePassed(): void
    {
        $this->requirePosixPipes();
        // The read at 0.0 fixes the deadline at 10.0. 9.9 is inside it, so the
        // pump waits once; 11.0 is past it, so the pump stops with no second wait.
        $seq = [0.0, 9.9];
        $clock = static function () use (&$seq): float {
            return array_shift($seq) ?? 11.0;
        };
        $waits = 0;
        $select = static function (array $read, ?array $write, ?array $except, int $seconds, int $micros) use (&$waits): int {
            if (++$waits > 1) {
                throw new \RuntimeException('the pump waited again after the default deadline should have passed');
            }

            return 0;
        };
        $p = $this->spawn('sleep(30);');
        $r = ChildPump::new([1 => $p['pipes'][1]])->withClock($clock)->withSelect($select)->pump();

        $this->assertSame(1, $waits, 'at 9.9 s the default deadline has not passed, so the pump waits');
        $this->assertSame(DrainResult::EXPIRED, $r->reason);
    }

    public function testDefaultDeadlineLeavesAQuickChildAlone(): void
    {
        $this->requirePosixPipes();
        $p = $this->spawn('usleep(100000); fwrite(STDOUT, "ok");');
        $r = ChildPump::new([1 => $p['pipes'][1]])->withClock(static fn (): float => 1000.0)->pump();

        $this->assertSame(DrainResult::CLOSED, $r->reason);
        $this->assertSame('ok', $r->buffer(1));
    }

    public function testOnChunkSeesEveryChunkInOrder(): void
    {
        $this->requirePosixPipes();
        $p = $this->spawn('fwrite(STDOUT, "one"); usleep(50000); fwrite(STDERR, "two"); usleep(50000); fwrite(STDOUT, "three");');
        $seen = '';
        $r = ChildPump::new([1 => $p['pipes'][1], 2 => $p['pipes'][2]])
            ->withDeadline(microtime(true) + 10)
            ->withOnChunk(static function (int|string $slot, string $bytes) use (&$seen): void {
                $seen .= $slot . ':' . $bytes . ' ';
            })
            ->pump();

        $this->assertSame('onethree', $r->buffer(1));
        $this->assertSame('two', $r->buffer(2));
        $this->assertStringContainsString('1:one', $seen);
        $this->assertStringContainsString('2:two', $seen);
        $this->assertStringContainsString('1:three', $seen);
    }

    public function testOnChunkStillFiresForADiscardedSlot(): void
    {
        $this->requirePosixPipes();
        $p = $this->spawn('fwrite(STDERR, "noise"); fwrite(STDOUT, "kept");');
        $errSeen = '';
        $r = ChildPump::new([1 => $p['pipes'][1], 2 => $p['pipes'][2]])
            ->withDeadline(microtime(true) + 10)
            ->withDiscard([2])
            ->withOnChunk(static function (int|string $slot, string $bytes) use (&$errSeen): void {
                if ($slot === 2) {
                    $errSeen .= $bytes;
                }
            })
            ->pump();

        $this->assertSame('kept', $r->buffer(1));
        $this->assertSame('', $r->buffer(2), 'a discarded slot is never retained');
        $this->assertSame('noise', $errSeen, 'but the live feed still sees it');
    }

    public function testStdinPayloadIsWrittenAndThenEofReachesTheChild(): void
    {
        $this->requirePosixPipes();
        // 300 KiB is well past one pipe buffer, so the feed takes several writes
        // interleaved with the reads; the child only answers at EOF of stdin.
        $payload = str_repeat('a', 300_000);
        $p = $this->spawn('fwrite(STDOUT, strtoupper(stream_get_contents(STDIN)));', true);
        $r = ChildPump::new([1 => $p['pipes'][1]])
            ->withDeadline(microtime(true) + 20)
            ->withStdin($p['pipes'][0], $payload)
            ->pump();

        $this->assertSame(DrainResult::CLOSED, $r->reason);
        $this->assertSame(strtoupper($payload), $r->buffer(1));
    }

    public function testLineByLineStdinIsEchoedWhileTheFeedIsStillOpen(): void
    {
        $this->requirePosixPipes();
        // The child answers each line as it arrives, so the pump must read
        // stdout while it is still writing stdin, or the two would wait on each other.
        $p = $this->spawn('while (($l = fgets(STDIN)) !== false) { fwrite(STDOUT, $l); }', true);
        $lines = '';
        for ($i = 0; $i < 200; $i++) {
            $lines .= "line $i\n";
        }
        $r = ChildPump::new([1 => $p['pipes'][1]])
            ->withDeadline(microtime(true) + 20)
            ->withStdin($p['pipes'][0], $lines)
            ->pump();

        $this->assertSame($lines, $r->buffer(1));
    }

    public function testEmptyStdinPayloadGivesTheChildAnImmediateEof(): void
    {
        $this->requirePosixPipes();
        $p = $this->spawn('fwrite(STDOUT, "[" . stream_get_contents(STDIN) . "]");', true);
        $r = ChildPump::new([1 => $p['pipes'][1]])
            ->withDeadline(microtime(true) + 10)
            ->withStdin($p['pipes'][0], '')
            ->pump();

        $this->assertSame(DrainResult::CLOSED, $r->reason);
        $this->assertSame('[]', $r->buffer(1));
    }

    public function testStdinFeedIsRefusedOnTheWindowsPollArm(): void
    {
        // The poll arm is forced on any runtime; a stdin feed on it must refuse
        // loudly rather than park on a blocking write.
        $p = $this->spawn('usleep(10000);', true);
        $this->expectException(\LogicException::class);
        try {
            ChildPump::new([1 => $p['pipes'][1]])
                ->withDeadline(microtime(true) + 5)
                ->withPollPipes(true)
                ->withStdin($p['pipes'][0], 'x')
                ->pump();
        } finally {
            // The refusal closes the handed-over pipe itself, so only a pipe still open is ours to close.
            if (is_resource($p['pipes'][0])) {
                fclose($p['pipes'][0]);
            }
        }
    }

    public function testARefusedStdinFeedStillRunsTheReapAndClosesThePipe(): void
    {
        // The refusal happens before any read, so the reap and the pipe close are the
        // only cleanup the caller gets. Both must happen on the refusal path too.
        $p = $this->spawn('usleep(10000);', true);
        $stdin = $p['pipes'][0];
        $reaped = false;

        try {
            ChildPump::new([1 => $p['pipes'][1]])
                ->withDeadline(microtime(true) + 5)
                ->withPollPipes(true)
                ->withStdin($stdin, 'x')
                ->withReap(static function () use (&$reaped): void {
                    $reaped = true;
                })
                ->pump();
            $this->fail('a stdin feed on the poll arm must be refused');
        } catch (\LogicException) {
            // expected: the refusal under test
        }

        // Checked before any cleanup of ours, so a regression that leaves the pipe open shows here.
        $stillOpen = is_resource($stdin);
        if ($stillOpen) {
            fclose($stdin);
        }

        $this->assertTrue($reaped, 'the reap runs even when the stdin feed is refused');
        $this->assertFalse($stillOpen, 'the handed-over stdin pipe is closed even when the feed is refused');
    }

    public function testHeartbeatFiresWhileTheChildIsSilent(): void
    {
        $this->requirePosixPipes();
        $p = $this->spawn('usleep(400000); fwrite(STDOUT, "late");');
        $beats = 0;
        $r = ChildPump::new([1 => $p['pipes'][1]])
            ->withDeadline(microtime(true) + 10)
            ->withHeartbeat(0.05, static function () use (&$beats): void {
                $beats++;
            })
            ->pump();

        $this->assertSame('late', $r->buffer(1));
        $this->assertGreaterThanOrEqual(2, $beats, 'a silent child must still beat the heartbeat');
    }

    public function testHeartbeatDoesNotFireBeforeOneIntervalHasPassed(): void
    {
        $this->requirePosixPipes();
        $p = $this->spawn('fwrite(STDOUT, "quick");');
        $beats = 0;
        ChildPump::new([1 => $p['pipes'][1]])
            ->withDeadline(microtime(true) + 10)
            ->withHeartbeat(30.0, static function () use (&$beats): void {
                $beats++;
            })
            ->pump();

        $this->assertSame(0, $beats);
    }

    public function testReapRunsOnceAfterTheLastReadAndBeforeDrainReturns(): void
    {
        $this->requirePosixPipes();
        $p = $this->spawn('fwrite(STDOUT, "data");');
        $events = [];
        $r = ChildPump::new([1 => $p['pipes'][1]])
            ->withDeadline(microtime(true) + 10)
            ->withOnChunk(static function () use (&$events): void {
                $events[] = 'chunk';
            })
            ->withReap(static function () use (&$events): void {
                $events[] = 'reap';
            })
            ->pump();

        $this->assertSame('data', $r->buffer(1));
        $this->assertSame(['chunk', 'reap'], $events, 'reap comes after every read, exactly once');
    }

    public function testReapStillRunsWhenTheWalkThrows(): void
    {
        $reaped = 0;
        $p = $this->spawn('usleep(10000);');
        try {
            ChildPump::new([1 => $p['pipes'][1]])
                ->withDeadline(microtime(true) + 10)
                ->withSelect(static function (): never {
                    throw new \RuntimeException('boom');
                })
                ->withPollPipes(false)
                ->withReap(static function () use (&$reaped): void {
                    $reaped++;
                })
                ->pump();
            $this->fail('the select failure must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(1, $reaped, 'the caller reaps the child even when the walk throws');
    }

    public function testRejectsBadArgumentsAtTheSetter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ChildPump::new([])->withCap(-1);
    }

    /** @return iterable<string, array{0: callable(ChildPump): ChildPump}> */
    public static function badSetters(): iterable
    {
        yield 'NaN deadline' => [static fn (ChildPump $p): ChildPump => $p->withDeadline(NAN)];
        yield 'zero slice' => [static fn (ChildPump $p): ChildPump => $p->withSlice(0.0)];
        yield 'zero heartbeat' => [static fn (ChildPump $p): ChildPump => $p->withHeartbeat(0.0, static fn () => null)];
        yield 'non-resource stdin' => [static fn (ChildPump $p): ChildPump => $p->withStdin('not a pipe', 'x')];
    }

    #[DataProvider('badSetters')]
    public function testRejectsBadValuesAtTheirSetters(callable $apply): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $apply(ChildPump::new([]));
    }

    public function testStaticDrainKeepsItsBehaviourOverTheBuilder(): void
    {
        $this->requirePosixPipes();
        // The compatibility entry must still be the builder under the hood.
        $p = $this->spawn('fwrite(STDOUT, "same");');
        $r = ChildPump::drain([1 => $p['pipes'][1]], microtime(true) + 10, 1024, running: null);

        $this->assertSame('same', $r->buffer(1));
        $this->assertSame(DrainResult::CLOSED, $r->reason);
    }
}
