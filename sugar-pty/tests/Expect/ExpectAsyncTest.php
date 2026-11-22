<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Expect;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;
use SugarCraft\Pty\Contract\Child;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Exception\ExpectEofException;
use SugarCraft\Pty\Exception\ExpectTimeoutException;
use SugarCraft\Pty\Expect;
use SugarCraft\Pty\Posix\PosixChild;
use SugarCraft\Pty\PtyException;
use SugarCraft\Pty\PtySystemFactory;
use SugarCraft\Pty\Tests\Support\RecordingLoop;

/**
 * P1-8c: Expect::expectAsync() reads on the Expect's loop through
 * addReadStream(), settles through a promise, and arms no periodic timer.
 *
 * Every loop here is the test's own (never the shared Loop::get()), and every
 * run sits behind a safety timer so a promise that never settles fails
 * instead of hanging the runner.
 */
final class ExpectAsyncTest extends TestCase
{
    use \SugarCraft\Pty\Tests\Support\RequiresPtyCapability;

    private const SAFETY_SECONDS = 5.0;

    /** @var list<PosixChild> */
    private array $spawned = [];

    /** @var list<resource> */
    private array $sockets = [];

    protected function tearDown(): void
    {
        foreach ($this->spawned as $child) {
            if (!$child->exited()) {
                $child->kill(9);
            }
            $child->wait();
        }
        $this->spawned = [];
        foreach ($this->sockets as $socket) {
            if (\is_resource($socket)) {
                \fclose($socket);
            }
        }
        $this->sockets = [];
        if (\function_exists('pcntl_signal') && \defined('SIGCHLD')) {
            \pcntl_signal(\SIGCHLD, \SIG_DFL);
        }
    }

    public function testResolvesWithTheNeedleWhenARealPtyChildPrintsIt(): void
    {
        $this->requirePtySyscalls();

        $pair = PtySystemFactory::new()->open(80, 24);
        $master = $pair->master();
        $child = $pair->slave()->spawn(
            ['/bin/sh', '-c', 'sleep 0.1; printf "hello READY now"; sleep 1'],
            ['PATH' => \getenv('PATH') ?: '/usr/bin:/bin', 'TERM' => 'dumb'],
        );

        try {
            $loop = new StreamSelectLoop();
            $promise = Expect::on($master, loop: $loop)->expectAsync('READY', 3.0);
            [$value, $error] = $this->settle($loop, $promise);

            $this->assertNull($error);
            $this->assertSame('READY', $value);
        } finally {
            if (!$child->exited()) {
                $child->kill(9);
            }
            $child->wait();
            $master->close();
        }
    }

    public function testResolvesWithTheMatchedTextOfAPattern(): void
    {
        [$master, $peer] = $this->socketMaster();
        $loop = new StreamSelectLoop();
        $loop->addTimer(0.02, static function () use ($peer): void {
            \fwrite($peer, 'build 1234 finished');
        });

        $promise = Expect::on($master, loop: $loop)->expectAsync('/build \d+/', 2.0, isPattern: true);
        [$value, $error] = $this->settle($loop, $promise);

        $this->assertNull($error);
        $this->assertSame('build 1234', $value);
    }

    public function testFindsANeedleSplitAcrossTwoReads(): void
    {
        [$master, $peer] = $this->socketMaster();
        $loop = new StreamSelectLoop();
        $loop->addTimer(0.02, static fn () => \fwrite($peer, 'aaaSLE'));
        $loop->addTimer(0.08, static fn () => \fwrite($peer, 'UTHbbb'));

        $promise = Expect::on($master, loop: $loop)->expectAsync(['NEVER', 'SLEUTH'], 2.0);
        [$value, $error] = $this->settle($loop, $promise);

        $this->assertNull($error);
        $this->assertSame('SLEUTH', $value);
    }

    public function testOneReadinessEventDrainsEveryByteAlreadyBuffered(): void
    {
        // A loop that polls the fd (ext-uv, ext-ev) never reports bytes PHP's
        // stream layer already pulled into its own buffer, so one readiness
        // callback must read until the master answers nothing. The callback
        // is fired by hand once: a listener that reads a single chunk leaves
        // the needle unread.
        [$master, $peer] = $this->socketMaster();
        \fwrite($peer, \str_repeat('x', 3000) . 'PROMPT');
        $loop = new ListenerCapturingLoop();

        $promise = Expect::on($master, readChunk: 16, loop: $loop)->expectAsync('PROMPT', 1.0);
        $this->assertCount(1, $loop->readListeners);
        ($loop->readListeners[0])();
        // The loop holds no read stream: only the drain's own continuation
        // ticks can reach the needle, 188 chunks of 16 bytes in.
        [$value, $error] = $this->settle($loop, $promise);

        $this->assertNull($error, 'one readiness event must drain what is already buffered');
        $this->assertSame('PROMPT', $value);
    }

    public function testReadinessEventsDuringACappedDrainKeepOneContinuationPending(): void
    {
        // A child still writing keeps every drain pass at DRAIN_MAX_CHUNKS, and
        // each readiness event lands while the last pass's continuation is
        // still queued. Each event must not start a chain of its own: one
        // per event grows the per-tick work every iteration of the wait.
        [$master, $peer] = $this->socketMaster();
        \fwrite($peer, \str_repeat('x', 20000));
        $loop = new ListenerCapturingLoop();

        $promise = Expect::on($master, readChunk: 16, loop: $loop)->expectAsync('NEVER', 0.2);
        $this->assertCount(1, $loop->readListeners);
        for ($i = 0; $i < 5; $i++) {
            ($loop->readListeners[0])();
        }

        $this->assertSame(1, $loop->ticksScheduled, 'five capped passes must leave one continuation pending, not five');
        [, $error] = $this->settle($loop, $promise);
        $this->assertInstanceOf(ExpectTimeoutException::class, $error);
    }

    public function testASecondPendingCallOnTheSameMasterThrowsInsteadOfStarving(): void
    {
        [$master, $peer] = $this->socketMaster();
        $loop = new StreamSelectLoop();
        $expect = Expect::on($master, loop: $loop);

        $first = $expect->expectAsync('AAA', 1.0);
        try {
            $expect->expectAsync('BBB', 3.0);
            $this->fail('a second pending expectAsync() on the same master must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('still pending', $e->getMessage());
        }

        // Settling the first frees the master for the next call.
        $first->then(null, static fn () => null);
        $first->cancel();
        $loop->addTimer(0.02, static fn () => \fwrite($peer, 'xx BBB yy'));
        [$value, $error] = $this->settle($loop, $expect->expectAsync('BBB', 2.0));

        $this->assertNull($error);
        $this->assertSame('BBB', $value);
    }

    public function testAMatchAlreadyInTheBufferResolvesWithoutReading(): void
    {
        [$master] = $this->socketMaster();
        $loop = new RecordingLoop();
        $expect = new Expect($master, buffer: 'left over PROMPT', loop: $loop);

        $value = null;
        $expect->expectAsync('PROMPT', 1.0)->then(static function (string $v) use (&$value): void {
            $value = $v;
        });

        $this->assertSame('PROMPT', $value, 'a buffered match must resolve at once');
        // Nothing is armed, so run() returns straight away.
        $loop->run();
        $this->assertSame([], $loop->periodicTimers);
    }

    public function testRejectsOnTimeoutWithTheBytesThatDidArrive(): void
    {
        [$master, $peer] = $this->socketMaster();
        \fwrite($peer, 'partial');
        $loop = new StreamSelectLoop();

        $started = \microtime(true);
        $promise = Expect::on($master, loop: $loop)->expectAsync('PROMPT', 0.2);
        [, $error] = $this->settle($loop, $promise);
        $elapsed = \microtime(true) - $started;

        $this->assertInstanceOf(ExpectTimeoutException::class, $error);
        $this->assertSame('partial', $error->buffer);
        $this->assertGreaterThanOrEqual(0.15, $elapsed, 'the timeout must not fire early');
        $this->assertLessThan(2.0, $elapsed);
        $this->assertTrue(\stream_get_meta_data($master->stream())['blocked'], 'the blocking mode must be put back');
    }

    public function testRejectsWhenTheMasterReachesEof(): void
    {
        [$master, $peer] = $this->socketMaster();
        $loop = new StreamSelectLoop();
        $loop->addTimer(0.02, static function () use ($peer): void {
            \fwrite($peer, 'bye');
            \fclose($peer);
        });

        $promise = Expect::on($master, loop: $loop)->expectAsync('PROMPT', 3.0);
        [, $error] = $this->settle($loop, $promise);

        $this->assertInstanceOf(ExpectEofException::class, $error);
        $this->assertSame('bye', $error->buffer);
    }

    public function testRejectsOnChildExitWithNoMatchAndArmsNoPeriodicTimer(): void
    {
        $this->requireSigchld();

        // A master that never reaches EOF, so only the child's exit can end the wait.
        [$master, $peer] = $this->socketMaster();
        $child = $this->spawnShell('sleep 0.1; exit 4');
        $loop = new RecordingLoop();
        $loop->addTimer(0.02, static fn () => \fwrite($peer, 'no prompt here'));

        $started = \microtime(true);
        $promise = Expect::on($master, loop: $loop)->expectAsync('PROMPT', 4.0, child: $child);
        [, $error] = $this->settle($loop, $promise);

        $this->assertInstanceOf(ExpectEofException::class, $error);
        $this->assertStringContainsString('child exited with code 4', $error->getMessage());
        $this->assertSame('no prompt here', $error->buffer);
        $this->assertLessThan(3.0, \microtime(true) - $started, 'the exit, not the timeout, must end the wait');
        $this->assertSame([], $loop->periodicTimers, 'expectAsync must arm no periodic timer');
        $this->assertSame([\SIGCHLD], $loop->signalsAdded, 'the exit must ride the loop\'s own SIGCHLD');
        $this->assertSame([\SIGCHLD], $loop->signalsRemoved, 'the exit listener must be removed once it fired');
    }

    public function testAFinalFrameWrittenJustAfterTheExitStillMatches(): void
    {
        $this->requireSigchld();

        [$master, $peer] = $this->socketMaster();
        $child = $this->spawnShell('exit 0');
        $loop = new StreamSelectLoop();
        // Lands inside the post-exit drain window, after the exit is seen.
        $child->onExit($loop, static function () use ($loop, $peer): void {
            $loop->addTimer(0.03, static fn () => \fwrite($peer, 'PROMPT'));
        });

        $promise = Expect::on($master, loop: $loop)->expectAsync('PROMPT', 4.0, child: $child);
        [$value, $error] = $this->settle($loop, $promise);

        $this->assertNull($error);
        $this->assertSame('PROMPT', $value);
    }

    public function testAMatchArmsNoPeriodicTimerAndDetachesTheExitListener(): void
    {
        $this->requireSigchld();

        [$master, $peer] = $this->socketMaster();
        $child = $this->spawnShell('sleep 2');
        $loop = new RecordingLoop();
        $loop->addTimer(0.05, static fn () => \fwrite($peer, '$ PROMPT'));

        $promise = Expect::on($master, loop: $loop)->expectAsync('PROMPT', 4.0, child: $child);
        $this->assertSame([], $loop->periodicTimers, 'arming must add no periodic timer');
        [$value, $error] = $this->settle($loop, $promise);

        $this->assertNull($error);
        $this->assertSame('PROMPT', $value);
        $this->assertFalse($child->exited());
        $this->assertSame([], $loop->periodicTimers, 'expectAsync must arm no periodic timer');
        $this->assertSame([\SIGCHLD], $loop->signalsRemoved, 'a match must detach the exit listener');
    }

    public function testCancellingRejectsAndDisarms(): void
    {
        [$master] = $this->socketMaster();
        $loop = new StreamSelectLoop();

        $promise = Expect::on($master, loop: $loop)->expectAsync('PROMPT', 3.0);
        $error = null;
        $promise->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });
        $promise->cancel();

        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertStringContainsString('cancelled', $error->getMessage());
        // The read listener and the timeout timer are gone, so run() returns at once.
        $started = \microtime(true);
        $loop->run();
        $this->assertLessThan(1.0, \microtime(true) - $started);
    }

    public function testRecordsWhatItReads(): void
    {
        [$master, $peer] = $this->socketMaster();
        $loop = new StreamSelectLoop();
        $recorder = new class () implements \SugarCraft\Core\Recorder {
            public string $out = '';

            public function recordInputBytes(string $bytes): void
            {
            }

            public function recordOutput(string $bytes): void
            {
                $this->out .= $bytes;
            }

            public function recordResize(int $cols, int $rows): void
            {
            }

            public function recordQuit(): void
            {
            }

            public function close(): void
            {
            }
        };
        $loop->addTimer(0.02, static fn () => \fwrite($peer, 'go PROMPT'));

        $promise = Expect::on($master, recorder: $recorder, loop: $loop)->expectAsync('PROMPT', 2.0);
        $this->settle($loop, $promise);

        $this->assertSame('go PROMPT', $recorder->out);
    }

    public function testThrowsSynchronouslyWithoutALoop(): void
    {
        [$master] = $this->socketMaster();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs an event loop');

        Expect::on($master)->expectAsync('PROMPT', 1.0);
    }

    public function testThrowsSynchronouslyForAMasterWithNoStream(): void
    {
        $loop = new RecordingLoop();

        try {
            Expect::on(new StreamlessMaster(), loop: $loop)->expectAsync('PROMPT', 1.0);
            $this->fail('a master with no stream must throw');
        } catch (\RuntimeException $e) {
            $this->assertInstanceOf(PtyException::class, $e);
        }

        // Nothing was armed: run() returns straight away.
        $loop->run();
        $this->assertSame([], $loop->periodicTimers);
    }

    public function testRejectsInvalidArgumentsSynchronously(): void
    {
        [$master] = $this->socketMaster();
        $expect = Expect::on($master, loop: new StreamSelectLoop());

        $cases = [
            'empty needle' => static fn () => $expect->expectAsync(''),
            'empty list' => static fn () => $expect->expectAsync([]),
            'negative timeout' => static fn () => $expect->expectAsync('x', -1.0),
            'invalid regex' => static fn () => $expect->expectAsync('/(/', 1.0, isPattern: true),
            'pattern list' => static fn () => $expect->expectAsync(['/a/'], 1.0, isPattern: true),
            'child without onExit' => static fn () => $expect->expectAsync('x', 1.0, child: new ChildWithoutOnExit()),
        ];
        foreach ($cases as $label => $call) {
            try {
                $call();
                $this->fail("{$label} must throw");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testOnKeepsItsBlockingSignatureAndTakesALoopLast(): void
    {
        $params = (new \ReflectionMethod(Expect::class, 'on'))->getParameters();

        $this->assertSame(['master', 'readChunk', 'recorder', 'loop'], \array_map(static fn ($p) => $p->getName(), $params));
        $this->assertTrue($params[3]->isOptional());
        $this->assertNull($params[3]->getDefaultValue());
    }

    /**
     * Run $loop until $promise settles, behind the safety cap.
     *
     * @return array{0: mixed, 1: ?\Throwable}
     */
    private function settle(LoopInterface $loop, PromiseInterface $promise): array
    {
        $value = null;
        $error = null;
        $done = false;
        $promise->then(
            static function ($v) use (&$value, &$done, $loop): void {
                $value = $v;
                $done = true;
                $loop->stop();
            },
            static function (\Throwable $e) use (&$error, &$done, $loop): void {
                $error = $e;
                $done = true;
                $loop->stop();
            },
        );
        if (!$done) {
            $safety = $loop->addTimer(self::SAFETY_SECONDS, static fn () => $loop->stop());
            $loop->run();
            $loop->cancelTimer($safety);
        }
        $this->assertTrue($done, 'the promise must settle before the safety cap');

        return [$value, $error];
    }

    /**
     * A master over one end of a socket pair: the test writes the child's
     * output into the other end, and the master reaches EOF only when that
     * end is closed.
     *
     * @return array{0: SocketMaster, 1: resource}
     */
    private function socketMaster(): array
    {
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        if ($pair === false) {
            $this->markTestSkipped('stream_socket_pair() is unavailable.');
        }
        [$ours, $peer] = $pair;
        $this->sockets[] = $ours;
        $this->sockets[] = $peer;

        return [new SocketMaster($ours), $peer];
    }

    private function requireSigchld(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX only.');
        }
        if (!\function_exists('pcntl_signal') || !\function_exists('pcntl_signal_get_handler') || !\defined('SIGCHLD')) {
            $this->markTestSkipped('ext-pcntl is required for SIGCHLD delivery.');
        }
    }

    private function spawnShell(string $script): PosixChild
    {
        $proc = \proc_open(
            ['/bin/sh', '-c', $script],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);
        $status = \proc_get_status($proc);
        $child = new PosixChild((int) $status['pid'], $proc);
        $this->spawned[] = $child;

        return $child;
    }
}

/** A MasterPty over a plain stream, with the contract's read taxonomy. */
final class SocketMaster implements MasterPty
{
    private bool $closed = false;

    /** @param resource $stream */
    public function __construct(private $stream)
    {
    }

    public function read(int $len = 8192, ?float $timeout = 0.05): ?string
    {
        if ($timeout !== null) {
            $r = [$this->stream];
            $w = null;
            $e = null;
            $sec = (int) $timeout;
            if (@\stream_select($r, $w, $e, $sec, (int) (($timeout - $sec) * 1_000_000)) < 1) {
                return null;
            }
        }
        $bytes = @\fread($this->stream, $len);
        if ($bytes === false || $bytes === '') {
            return \feof($this->stream) ? '' : null;
        }

        return $bytes;
    }

    public function write(string $bytes): int
    {
        return (int) \fwrite($this->stream, $bytes);
    }

    public function resize(int $cols, int $rows): void
    {
    }

    public function size(): array
    {
        return ['rows' => 24, 'cols' => 80, 'xpix' => 0, 'ypix' => 0];
    }

    public function stream(): mixed
    {
        return $this->stream;
    }

    public function setBlocking(bool $blocking): void
    {
        \stream_set_blocking($this->stream, $blocking);
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function fd(): int
    {
        return 0;
    }
}

/** A MasterPty with no stream behind it, as ConPtyMasterPty is on Windows. */
final class StreamlessMaster implements MasterPty
{
    public function read(int $len = 8192, ?float $timeout = 0.05): ?string
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
        return ['rows' => 24, 'cols' => 80, 'xpix' => 0, 'ypix' => 0];
    }

    public function stream(): mixed
    {
        throw new PtyException('no stream behind this master');
    }

    public function setBlocking(bool $blocking): void
    {
        throw new PtyException('no blocking mode on this master');
    }

    public function close(): void
    {
    }

    public function isClosed(): bool
    {
        return false;
    }

    public function fd(): int
    {
        throw new PtyException('no fd behind this master');
    }
}

/** A Child with no onExit(), so its exit could only be found by polling. */
final class ChildWithoutOnExit implements Child
{
    public function pid(): int
    {
        return 1;
    }

    public function exited(): bool
    {
        return false;
    }

    public function wait(): int
    {
        return 0;
    }

    public function exitCode(): ?int
    {
        return null;
    }

    public function kill(int $signal): void
    {
    }
}

/** Records each read listener so a test can fire one readiness event by hand. */
final class ListenerCapturingLoop implements LoopInterface
{
    private readonly StreamSelectLoop $inner;

    /** @var list<callable> */
    public array $readListeners = [];

    /** futureTick() calls so far, all still pending until run(). */
    public int $ticksScheduled = 0;

    public function __construct()
    {
        $this->inner = new StreamSelectLoop();
    }

    public function addReadStream($stream, $listener): void
    {
        $this->readListeners[] = $listener;
    }

    public function addWriteStream($stream, $listener): void
    {
        $this->inner->addWriteStream($stream, $listener);
    }

    public function removeReadStream($stream): void
    {
    }

    public function removeWriteStream($stream): void
    {
        $this->inner->removeWriteStream($stream);
    }

    public function addTimer($interval, $callback): \React\EventLoop\TimerInterface
    {
        return $this->inner->addTimer($interval, $callback);
    }

    public function addPeriodicTimer($interval, $callback): \React\EventLoop\TimerInterface
    {
        return $this->inner->addPeriodicTimer($interval, $callback);
    }

    public function cancelTimer(\React\EventLoop\TimerInterface $timer): void
    {
        $this->inner->cancelTimer($timer);
    }

    public function futureTick($listener): void
    {
        $this->ticksScheduled++;
        $this->inner->futureTick($listener);
    }

    public function addSignal($signal, $listener): void
    {
        $this->inner->addSignal($signal, $listener);
    }

    public function removeSignal($signal, $listener): void
    {
        $this->inner->removeSignal($signal, $listener);
    }

    public function run(): void
    {
        $this->inner->run();
    }

    public function stop(): void
    {
        $this->inner->stop();
    }
}
