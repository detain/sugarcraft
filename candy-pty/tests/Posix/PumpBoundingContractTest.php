<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Pty\Contract\Child;
use SugarCraft\Pty\Posix\MultiPump;
use SugarCraft\Pty\Posix\PosixPump;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\Posix\ReactPump;
use SugarCraft\Pty\PumpOptions;

/**
 * E717 — the pump-family caller-bounding contract, pinned by behaviour.
 *
 * Every loop here is proven bounded ONLY by its documented exit conditions
 * (child exit, master EOF, stdout EPIPE, stdin-EOF grace, caller stop flag,
 * opt-in deadline) — never by a hidden wall-clock in production code:
 * an interactive PTY has no legitimate total deadline (E646-adjacent), so
 * a silent-forever child with an open silent stdin MUST spin forever.
 * Each test therefore arranges silence and then supplies the bound itself
 * (a kill from onIdle, or the opt-in deadline), asserting exit within a
 * generous ceiling whose only legitimate cause is the bound it armed.
 *
 * Coverage split with the neighbours (deliberate non-duplication):
 *  - STDIN EOF + grace expiry: PosixPumpEofGraceTest.
 *  - post-exit flush window:   PosixPumpFlushDeadlineTest / FlushEmptyTest.
 *  - retryOnEintr finite-timeout convergence: RetryOnEintrDeadlineTest.
 *  - ReactPump stop() flag:    ReactPumpTest::testStopResolvesMinusOneForLiveChild.
 * This file adds the other half of the contract: unbounded-without-a-bound,
 * the opt-in deadline on all three pumps, stdout EPIPE, and the machine-
 * checked documentation marker. HangWatchdog (tests/bootstrap.php) remains
 * the process-level backstop: a test built on these loops can only hang,
 * never fail — that is exactly why the no-default-deadline pin below spins
 * past a counted tick budget instead of timing an "infinite" wait.
 */
final class PumpBoundingContractTest extends TestCase
{
    /** Ceiling for "the bound I armed fired": every fixture child sleeps 30s, so anything under this can only be the bound. */
    private const BOUND_CEILING_SEC = 8.0;

    /** @var list<resource> streams to close in tearDown */
    private array $tempStreams = [];

    /** @var list<Child> children to kill+wait in tearDown */
    private array $tempChildren = [];

    protected function tearDown(): void
    {
        foreach ($this->tempChildren as $child) {
            try {
                if (!$child->exited()) {
                    $child->kill(Child::SIGKILL);
                }
                $child->wait();
            } catch (\Throwable) {
                // Teardown must never mask the assertion that failed.
            }
        }
        $this->tempChildren = [];
        foreach ($this->tempStreams as $stream) {
            if (\is_resource($stream)) {
                @\fclose($stream);
            }
        }
        $this->tempStreams = [];
    }

    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only; Windows ConPTY is a separate port.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required to exercise the libc PTY syscalls.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
        if (!\is_executable('/bin/sleep') || !\is_executable('/bin/bash')) {
            $this->markTestSkipped('/bin/sleep and /bin/bash are required by the pump fixtures.');
        }
    }

    /**
     * STDIN that never delivers bytes and never EOFs: one end of a socketpair
     * handed to the pump, the peer deliberately left open. select() reports it
     * not-ready forever and feof() stays false, so neither the stdin-EOF grace
     * nor any read path can end the pump — the pure "silent forever" shape.
     *
     * @return array{0: resource, 1: resource} [pump-side, retained peer]
     */
    private function silentOpenStdin(): array
    {
        $pair = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->assertIsArray($pair);
        $this->tempStreams[] = $pair[0];
        $this->tempStreams[] = $pair[1];
        return $pair;
    }

    /**
     * Sink whose read end is already closed: the first fwrite trips EPIPE and
     * returns false (measured PHP 8.3.6), the sync pump's second exit condition.
     *
     * @return resource the pump-side write handle
     */
    private function epipeSink(): mixed
    {
        $pair = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->assertIsArray($pair);
        \fclose($pair[1]);
        $this->tempStreams[] = $pair[0];
        return $pair[0];
    }

    // ---------------------------------------------------------------
    // PosixPump: the bound must come from the caller, not the pump.
    // ---------------------------------------------------------------

    public function testPosixPumpCarriesNoDefaultDeadlineAndSpinsUntilCallerKills(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);
        $child = $pair->slave()->spawn(['/bin/sleep', '30']);
        $this->tempChildren[] = $child;
        [$stdin] = $this->silentOpenStdin();
        $stdout = \fopen('php://temp', 'r+');
        $this->tempStreams[] = $stdout;

        // Default options: pumpDeadlineUs is null, so NOTHING may end this
        // loop on its own. Spin at least IDLE_TICKS_TO_PASS idle ticks; on
        // the third tick the CALLER supplies the bound by killing the child,
        // which trips the child-exit condition. A regression that baked any
        // default deadline into the pump shorter than the tick budget would
        // return with the child still alive and fail both assertions below.
        $idleTicksToPass = 3;
        $idleTicks = 0;
        $opts = (new PumpOptions())->withOnIdle(function () use (&$idleTicks, $child, $idleTicksToPass): void {
            $idleTicks++;
            if ($idleTicks >= $idleTicksToPass) {
                $child->kill(Child::SIGKILL);
            }
        });

        $started = \microtime(true);
        $exit = (new PosixPump())->run($pair->master(), $stdin, $stdout, $child, $opts);
        $elapsed = \microtime(true) - $started;

        $this->assertGreaterThanOrEqual($idleTicksToPass, $idleTicks, 'pump must survive N idle ticks with no bound armed');
        $this->assertTrue($child->exited(), 'the only exit cause here was the caller-supplied kill');
        $this->assertIsInt($exit);
        $this->assertLessThan(self::BOUND_CEILING_SEC, $elapsed, 'kill must trip the child-exit condition promptly');

        $pair->master()->close();
    }

    public function testPosixPumpDeadlineReturnsControlWithoutKillingState(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);
        $child = $pair->slave()->spawn(['/bin/sleep', '30']);
        $this->tempChildren[] = $child;
        [$stdin] = $this->silentOpenStdin();
        $stdout = \fopen('php://temp', 'r+');
        $this->tempStreams[] = $stdout;

        $opts = (new PumpOptions())->withPumpDeadlineUs(200_000);

        $started = \microtime(true);
        $exit = (new PosixPump())->run($pair->master(), $stdin, $stdout, $child, $opts);
        $elapsed = \microtime(true) - $started;

        // The deadline was the ONLY possible cause of return: the child
        // sleeps 30s, stdin is open-silent, stdout is a healthy temp sink.
        $this->assertSame(-1, $exit, 'deadline expiry must report the sync-pump live-child contract');
        $this->assertFalse($child->exited(), 'the bound hands control back — it does not kill the child');
        $this->assertLessThan(self::BOUND_CEILING_SEC, $elapsed);

        // Master still usable after the bound: write/read round-trip proves
        // the early return left the PTY open (state not destroyed).
        $pair->master()->write("resume-probe\n");
        $echoed = '';
        $drainDeadline = \microtime(true) + 2.0;
        while (\microtime(true) < $drainDeadline) {
            $chunk = $pair->master()->read(1024, 0.05);
            if ($chunk !== null && $chunk !== '') {
                $echoed .= $chunk;
                if (\str_contains($echoed, 'resume-probe')) {
                    break;
                }
            }
        }
        $this->assertStringContainsString('resume-probe', $echoed, 'master must survive a deadline exit');
        $pair->master()->close();
    }

    public function testPosixPumpStdoutEpipeExitsWithChildStillAlive(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);
        $child = $pair->slave()->spawn(['/bin/bash', '-c', "sleep 0.3; printf 'epipe-probe\\n'; sleep 30"]);
        $this->tempChildren[] = $child;
        [$stdin] = $this->silentOpenStdin();
        $sink = $this->epipeSink();

        $started = \microtime(true);
        $exit = (new PosixPump())->run($pair->master(), $stdin, $sink, $child);
        $elapsed = \microtime(true) - $started;

        // The child wrote its probe line then slept: the fwrite EPIPE is the
        // only exit condition available (stdin open-silent, child alive,
        // no deadline), and it must hand back control with -1, unprompted
        // by anything resembling a wall clock in the pump.
        $this->assertSame(-1, $exit, 'EPIPE must exit the pump with the live-child contract');
        $this->assertFalse($child->exited());
        $this->assertLessThan(self::BOUND_CEILING_SEC, $elapsed);

        $pair->master()->close();
    }

    // ---------------------------------------------------------------
    // ReactPump: the promise settles on an event — caller flag (stop)
    // or the opt-in deadline — never on a built-in clock.
    // ---------------------------------------------------------------

    public function testReactPumpDeadlineResolvesLiveChildWithoutKillingIt(): void
    {
        $this->requirePtySyscalls();

        // Per-test loop (ReactPumpTest convention): isolation so residue
        // cannot hide, and StreamSelectLoop refreshes its clock at arm
        // time, which makes the 5s safety cap trustworthy.
        $loop = new StreamSelectLoop();
        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);
        $child = $pair->slave()->spawn(['/bin/sleep', '30']);
        $this->tempChildren[] = $child;
        [$stdin] = $this->silentOpenStdin();
        $stdout = \fopen('php://temp', 'r+');
        $this->tempStreams[] = $stdout;

        $pump = new ReactPump($loop);
        $settled = null;
        $promise = $pump->start(
            $pair->master(),
            $stdin,
            $stdout,
            $child,
            (new PumpOptions())->withPumpDeadlineUs(200_000),
        );
        $promise->then(function (int $code) use (&$settled, $loop): void {
            $settled = $code;
            $loop->stop();
        });
        // Safety cap: if the deadline silently failed to fire, the promise
        // never settles and THIS timer ends the loop red-fast instead.
        $loop->addTimer(5.0, static fn () => $loop->stop());
        $loop->run();

        $this->assertSame(-1, $settled, 'deadline must settle the promise with the live-child contract');
        $this->assertFalse($pump->isRunning(), 'finish() must have deregistered every loop handle');
        $this->assertFalse($child->exited(), 'the bound hands control back — it does not kill the child');

        $child->kill(Child::SIGKILL);
        $child->wait();
        $pair->master()->close();
    }

    // ---------------------------------------------------------------
    // MultiPump: run() documents the contract; the deadline lives on
    // the method (supervisor-scoped), not on per-session options.
    // ---------------------------------------------------------------

    public function testMultiPumpRunCarriesNoDeadlineUntilSessionsDone(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);
        // Exits by itself: tick() observes exited(), the flush window
        // drains, and unbounded run() finishes on the session-done
        // condition alone — the bounded-when-it-dies half of the contract.
        $child = $pair->slave()->spawn(['/bin/bash', '-c', "printf 'bye\\n'; exit 3"]);
        $sink = \fopen('php://temp', 'r+');
        $this->tempStreams[] = $sink;

        $pump = new MultiPump();
        $id = $pump->add($pair->master(), $sink, $child);

        $started = \microtime(true);
        $exits = $pump->run();
        $elapsed = \microtime(true) - $started;

        $this->assertTrue($pump->allDone());
        $this->assertArrayHasKey($id, $exits);
        $this->assertLessThan(self::BOUND_CEILING_SEC, $elapsed);
        \rewind($sink);
        $this->assertStringContainsString('bye', (string) \stream_get_contents($sink));

        $pair->master()->close();
    }

    // ---------------------------------------------------------------
    // The contract is DOCUMENTED where it lives: machine-checked marker.
    // ---------------------------------------------------------------

    /**
     * @return array<string, array{0: class-string, 1: string|null}> [class, method — null means the CLASS docblock]
     */
    public static function pumpFamilyContractSites(): array
    {
        return [
            'Contract\\Pump::run'   => [\SugarCraft\Pty\Contract\Pump::class, 'run'],
            'PosixPump::run'        => [PosixPump::class, 'run'],
            'MultiPump::run'        => [MultiPump::class, 'run'],
            'ReactPump (class doc)' => [ReactPump::class, null],
            'Child::wait (trait)'   => [\SugarCraft\Pty\Posix\PosixChild::class, 'wait'],
            'PosixProcess::wait'    => [\SugarCraft\Pty\Posix\PosixProcess::class, 'wait'],
        ];
    }

    /**
     * The E717 wording is part of the public contract: every pump/poll
     * entry point named by the audit carries the marker sentence
     * "no internal deadline" in its docblock. Renames or doc deletions at
     * these sites go red here rather than silently un-documenting the
     * hazard AGENTS.md warns every test author about.
     */
    public function testPumpFamilyDocblocksCarryTheCallerBoundingMarker(): void
    {
        $checked = 0;
        foreach (self::pumpFamilyContractSites() as $label => [$class, $method]) {
            $ref = $method === null
                ? new \ReflectionClass($class)
                : new \ReflectionMethod($class, $method);
            $doc = (string) $ref->getDocComment();
            // Strip the /** */ envelope and per-line leading "*", then
            // flatten: the marker sentence may wrap across docblock lines.
            $body = (string) \preg_replace('~/\*\*|\*/~', '', $doc);
            $body = (string) \preg_replace('/^\s*\*?\s?/m', '', $body);
            $flat = (string) \preg_replace('/\s+/', ' ', $body);
            $this->assertStringContainsString(
                'no internal deadline',
                $flat,
                "contract marker missing from {$label} docblock",
            );
            $checked++;
        }
        $this->assertSame(6, $checked, 'the audited pump-family surface must be enumerated exhaustively');
    }
}
