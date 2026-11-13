<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Posix\MultiPump;
use SugarCraft\Pty\Posix\PosixPump;
use SugarCraft\Pty\Posix\ReactPump;
use SugarCraft\Pty\PumpOptions;

/**
 * The pump hook contract, one law across three implementations:
 * an idle tick fires onIdle, then the legacy keepalive alias —
 * PosixPump (select → 0), ReactPump (poll tick), MultiPump (sync
 * tick()'s idle arm). MultiPump used to fire keepalive only, silently
 * no-op'ing options that carried onIdle while every other pump fired
 * them; this pin is the law now enforced on all three paths.
 *
 * Every drive uses a quiet master (a socket pair nobody writes to, read()
 * would-blocking forever) so the only loop events are selects expiring —
 * the idle arm is the subject, and no child process is needed to prove
 * ordering.
 */
final class PumpHooksContractTest extends TestCase
{
    use \SugarCraft\Pty\Tests\Support\RequiresPtyCapability;

    /** @param resource $stream */
    private function quietMaster($stream): MasterPty
    {
        return new QuietMasterPty($stream);
    }

    public function testPosixPumpIdleTickFiresOnIdleThenKeepalive(): void
    {
        $seq = [];
        $opts = (new PumpOptions())
            ->withSelectTimeoutUs(1_000)
            ->withPumpDeadlineUs(150_000)
            ->withOnIdle(static function () use (&$seq): void {
                $seq[] = 'idle';
            })
            ->withKeepalive(static function () use (&$seq): void {
                $seq[] = 'ka';
            });

        [$a, $b] = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $stdin = \fopen('php://temp', 'r+b');
        \fwrite($stdin, 'x');
        \rewind($stdin);
        $stdout = \fopen('php://temp', 'r+b');

        try {
            (new PosixPump())->run($this->quietMaster($a), $stdin, $stdout, null, $opts);
        } finally {
            \fclose($a);
            \fclose($b);
            \fclose($stdin);
            \fclose($stdout);
        }

        $this->assertPairOrder($seq, 'PosixPump');
    }

    public function testReactPumpPollTickFiresOnIdleThenKeepalive(): void
    {
        $seq = [];
        $opts = (new PumpOptions())
            ->withSelectTimeoutUs(1_000)
            ->withPumpDeadlineUs(120_000)
            ->withOnIdle(static function () use (&$seq): void {
                $seq[] = 'idle';
            })
            ->withKeepalive(static function () use (&$seq): void {
                $seq[] = 'ka';
            });

        [$a, $b] = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $loop = new StreamSelectLoop();
        $pump = new ReactPump($loop);
        $settled = false;
        $pump->start($this->quietMaster($a), null, null, null, $opts)
            ->then(static function () use (&$settled): void {
                $settled = true;
            });

        $loop->run();

        \fclose($a);
        \fclose($b);

        $this->assertTrue($settled, 'the deadline-bounded ReactPump never resolved — the loop ran on a dead pump');
        $this->assertPairOrder($seq, 'ReactPump');
    }

    public function testMultiPumpTickTreatsSelectFalseAsLoudErrorNotIdleTick(): void
    {
        $seq = [];
        $opts = (new PumpOptions())
            ->withOnIdle(static function () use (&$seq): void {
                $seq[] = 'idle';
            })
            ->withKeepalive(static function () use (&$seq): void {
                $seq[] = 'ka';
            });

        [$a, $b] = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $sink = \fopen('php://temp', 'r+b');
        $pump = new MultiPump();
        // Hold the resource in a local AND close it out from under the
        // session: a stream the caller closed mid-tick is the classic
        // EBADF/stream_select-false shape.
        $master = $this->quietMaster($a);
        $pump->add($master, $sink, null, $opts);
        \fclose($a);

        // "Loud" has two honest shapes on PHP 8: a rejected stream set
        // raises ValueError from inside stream_select() itself, while a
        // genuine select() false reaches our PtyException. Either must
        // escape the tick — the regression being pinned is the SILENT one
        // (laundering the failure into an idle tick + hook spin).
        $loud = null;
        try {
            $pump->tick();
        } catch (\SugarCraft\Pty\PtyException|\ValueError $e) {
            $loud = $e;
        } finally {
            \fclose($b);
            \fclose($sink);
        }

        $this->assertNotNull($loud, 'a failed select must throw out of tick(), not launder into an idle tick');
        if ($loud instanceof \SugarCraft\Pty\PtyException) {
            $this->assertMatchesRegularExpression('/stream_select\(\) failed inside MultiPump::tick/', $loud->getMessage());
        }
        $this->assertSame([], $seq, 'the failed select fired idle hooks — the silent-laundering regression');
    }

    public function testMultiPumpSyncTickIdleArmFiresOnIdleThenKeepalive(): void
    {
        $seq = [];
        $opts = (new PumpOptions())
            ->withOnIdle(static function () use (&$seq): void {
                $seq[] = 'idle';
            })
            ->withKeepalive(static function () use (&$seq): void {
                $seq[] = 'ka';
            });

        [$a, $b] = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $sink = \fopen('php://temp', 'r+b');
        $pump = new MultiPump();
        $pump->add($this->quietMaster($a), $sink, null, $opts);

        try {
            $this->assertSame(0, $pump->tick(), 'a quiet session must report zero drained');
            $this->assertSame(0, $pump->tick());
        } finally {
            \fclose($a);
            \fclose($b);
            \fclose($sink);
        }

        $this->assertPairOrder($seq, 'MultiPump tick()');
    }

    /**
     * Both hooks fired and the first pair is idle-then-ka; per-tick
     * cadence interleaves them exactly.
     *
     * @param list<string> $seq
     */
    private function assertPairOrder(array $seq, string $pumpLabel): void
    {
        $idle = \count(\array_filter($seq, static fn (string $s): bool => $s === 'idle'));
        $ka = \count(\array_filter($seq, static fn (string $s): bool => $s === 'ka'));
        $this->assertGreaterThanOrEqual(2, $idle, "{$pumpLabel}: onIdle fired {$idle} times over the idle run");
        $this->assertGreaterThanOrEqual(2, $ka, "{$pumpLabel}: keepalive fired {$ka} times over the idle run");
        $this->assertSame(
            ['idle', 'ka', 'idle', 'ka'],
            \array_slice($seq, 0, 4),
            "{$pumpLabel}: the idle arm must deliver onIdle BEFORE keepalive, interleaved per tick; got "
            . \implode(',', \array_slice($seq, 0, 8)),
        );
    }
}

/**
 * A master that exists but never speaks: read() would-blocks forever
 * (null), write() accepts everything (input pins are not the subject),
 * stream() is a real socket-pair end so stream_select has something
 * honest to watch.
 */
final class QuietMasterPty implements MasterPty
{
    /** @param resource $stream */
    public function __construct(private $stream)
    {
    }

    public function read(int $len = 8192, ?float $timeout = null): ?string
    {
        return null;
    }

    public function write(string $bytes): int
    {
        return \strlen($bytes);
    }

    public function resize(int $cols, int $rows): void
    {
    }

    public function size(): array
    {
        return ['cols' => 80, 'rows' => 24, 'xpix' => 0, 'ypix' => 0];
    }

    public function stream(): mixed
    {
        return $this->stream;
    }
    /**
     * MasterPty contract member (PTY-13). Scripted fakes hold no real
     * blocking mode — nothing they return depends on it.
     */
    public function setBlocking(bool $blocking): void
    {
    }


    public function fd(): int
    {
        return -1;
    }

    public function close(): void
    {
    }

    public function isClosed(): bool
    {
        return false;
    }
}
