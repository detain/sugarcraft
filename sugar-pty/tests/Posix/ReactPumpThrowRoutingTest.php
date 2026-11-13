<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\StreamSelectLoop;
use React\EventLoop\TimerInterface;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Posix\ReactPump;
use SugarCraft\Pty\PumpOptions;
use SugarCraft\Pty\PtyException;
use SugarCraft\Pty\Tests\Support\SharedLoopResidue;

/**
 * A callback throwing mid-loop must not eat the loop.
 *
 * Before the teardown guard, any throwable escaping a pump handler
 * (master read EIO, a user onData, a sink write race) propagated out of
 * StreamSelectLoop::run() through ReactPHP's rethrow path — which skips
 * this pump's cleanup entirely: read/write watchers and the poll timer
 * stayed registered on the loop, the start() Deferred settled never, and
 * whoever owned the shared Loop::get() facade lost it mid-program.
 *
 * These pins drive the throw on the SHARED loop (the only loop whose
 * loss actually matters) with a co-tenant periodic: the co-tenant must
 * keep ticking past the pump's death (survival), the promise must reject
 * with the cause (not a fake exit code), and the residue census must
 * read zero the moment run() returns.
 */
final class ReactPumpThrowRoutingTest extends TestCase
{
    private ?TimerInterface $coTenant = null;

    protected function tearDown(): void
    {
        if ($this->coTenant !== null) {
            Loop::get()->cancelTimer($this->coTenant);
            $this->coTenant = null;
        }
    }

    public function testThrowingMasterReadRejectsSavesTheSharedLoopAndLeavesNoResidue(): void
    {
        $loop = Loop::get();
        $before = SharedLoopResidue::census();

        [$masterEnd, $peerEnd] = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $master = new ThrowingReadMasterPty($masterEnd);
        $pump = new ReactPump();

        $coTenantTicks = 0;
        $this->coTenant = $loop->addPeriodicTimer(0.005, function () use ($loop, &$coTenantTicks): void {
            $coTenantTicks++;
            if ($coTenantTicks >= 3) {
                $loop->stop();
            }
        });

        $settled = null;
        $failure = null;
        $pump->start($master, null, null, null, (new PumpOptions())->withPumpDeadlineUs(5_000_000))
            ->then(
                static function (int $code) use (&$settled, &$failure): void {
                    $settled = $code;
                },
                static function (\Throwable $e) use (&$failure): void {
                    $failure = $e;
                },
            );

        \fwrite($peerEnd, 'wakes the read watcher');
        $loop->run();
        \fclose($peerEnd);
        \fclose($masterEnd);

        $this->assertNotNull($failure, 'the start() promise settled with a code — a loop-callback throw must REJECT with the cause');
        $this->assertNull($settled);
        $this->assertInstanceOf(PtyException::class, $failure);
        $this->assertStringContainsString('simulated master read failure', $failure->getMessage());
        $this->assertFalse($pump->isRunning(), 'the pump must be dead after the guard routes its throw to finish()');
        $this->assertGreaterThanOrEqual(2, $coTenantTicks, 'the co-tenant stopped ticking when the pump died — it did not survive the error path');

        $loop->cancelTimer($this->coTenant);
        $this->coTenant = null;
        $residue = SharedLoopResidue::census();
        $this->assertSame(
            $before,
            $residue,
            'the threw-out pump changed what is armed on the shared loop (before: '
            . \json_encode($before) . ' after: ' . SharedLoopResidue::describe()
            . ') — its error path must hand the loop back exactly as it found it.',
        );
        $this->assertSame(
            ['timers' => 0, 'readStreams' => 0, 'writeStreams' => 0, 'signals' => 0, 'futureTicks' => 0],
            $before,
            'the census must run against an empty shared loop for this pin to measure the pump; suite-order drift changed the baseline',
        );
    }

    public function testThrowingUserDataCallbackRejectsThePromiseOnAnIsolatedLoop(): void
    {
        $loop = new StreamSelectLoop();
        [$masterEnd, $peerEnd] = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $master = new ThrowingReadMasterPty($masterEnd, throwAfterReads: 1, firstPayload: 'hello');

        $pump = new ReactPump($loop);
        $failure = null;
        $settled = null;
        $pump->start(
            $master,
            null,
            null,
            null,
            (new PumpOptions())->withPumpDeadlineUs(5_000_000),
            static function (string $bytes): never {
                throw new \LogicException("user onData broke on '{$bytes}'");
            },
        )->then(
            static function (int $code) use (&$settled): void {
                $settled = $code;
            },
            static function (\Throwable $e) use (&$failure, $loop): void {
                $failure = $e;
                // The guard's teardown already disarmed the pump; the loop
                // may return now instead of idling to the watchdog.
                $loop->stop();
            },
        );

        $watchdog = $loop->addTimer(3.0, static function () use ($loop): void {
            $loop->stop();
        });
        \fwrite($peerEnd, 'go');
        $loop->run();
        $loop->cancelTimer($watchdog);
        \fclose($peerEnd);
        \fclose($masterEnd);

        $this->assertNotNull($failure, 'a throwing onData must reject, not resolve nor vanish');
        $this->assertNull($settled);
        $this->assertStringContainsString('user onData broke', $failure->getMessage());
        $this->assertFalse($pump->isRunning());
    }
}

/**
 * MasterPty double: read() throws $message after $throwAfterReads normal
 * returns — the EIO-on-dead-child shape every real master can produce
 * mid-loop.
 */
final class ThrowingReadMasterPty implements MasterPty
{
    private int $readsDone = 0;

    /** @param resource $stream */
    public function __construct(
        private $stream,
        private int $throwAfterReads = 0,
        private string $firstPayload = '',
    ) {
    }

    public function read(int $len = 8192, ?float $timeout = null): ?string
    {
        if ($this->readsDone >= $this->throwAfterReads) {
            throw new PtyException('simulated master read failure');
        }
        $this->readsDone++;
        return $this->firstPayload;
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
