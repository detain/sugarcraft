<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\ColorProfile\AsyncProbe;

/**
 * Structural + slot-hygiene pins for the bounded async probe (fixes
 * DC3/NIT-A/DM2/MT8). Behavior-side siblings live in {@see AsyncProbeTest}.
 *
 * These scan src/AsyncProbe.php the same way the existing ladder test scans
 * it: a handler-signature regression or a re-spotted blocking sync call is
 * invisible to behavior tests that cannot drive a SIGKILL through a test
 * loop, but must not survive review either. (That concession held until
 * CP-28: the deadline-seam band at the bottom of this file drives a real
 * SIGTERM→SIGKILL escalation; the needles above stay as cheap prose guards.)
 */
final class AsyncProbeBoundedTest extends TestCase
{
    private static function src(): string
    {
        $src = file_get_contents(__DIR__ . '/../src/AsyncProbe.php');
        self::assertIsString($src);
        return $src;
    }

    public function testExitHandlerAcceptsTheNullExitCodeASignalKillCarries(): void
    {
        // DC3: react/child-process emits (null, SIGKILL) when the child died
        // by signal. With the old strict `int $exitCode` the TypeError was
        // raised inside Loop::run() — killing the EMBEDDING application's
        // loop, not just this probe.
        self::assertStringContainsString('function (?int $exitCode', self::src());
    }

    public function testGraceKillTimerIsCancelledOnSettle(): void
    {
        // NIT-A: the exit/error handlers must cancel BOTH ladder rungs, or a
        // fast-exiting child still receives a stray SIGKILL at grace time.
        self::assertStringContainsString('cancelTimer($timeoutTimer)', self::src());
        self::assertStringContainsString('cancelTimer($graceTimer)', self::src());
    }

    public function testAsyncSettlesNeverRunTheBlockingSyncProbe(): void
    {
        // DM2: the ONLY sanctioned call of the synchronous full probe is the
        // null-$loop branch (a caller with no loop cannot block anything).
        // Every child-related settle path must compose from the chain.
        self::assertSame(
            1,
            substr_count(self::src(), 'Probe::colorProfile('),
            'async settles must come from the chain, not the blocking sync probe',
        );
    }

    public function testTheLoopContractIsExplicitNoSingletonPickupNoGetLoopSeam(): void
    {
        // CP-14: react/event-loop 1.x Loop::get() lazily CREATES its
        // singleton, so an "is there a loop?" probe could never answer no
        // and the documented sync fallback was dead code. The surface now
        // takes the loop as a parameter and spawns only on what it is given;
        // these needles keep the foot-gun from creeping back.
        $src = self::src();
        self::assertStringNotContainsString('Loop::get(', $src, 'the singleton must never be picked up implicitly');
        self::assertStringNotContainsString('function getLoop', $src, 'the always-true getLoop() seam is deleted');
        self::assertStringContainsString('colorProfile(?LoopInterface $loop = null)', $src, 'the loop is an explicit parameter');
    }

    public function testSpawnFailuresReleaseTheSlotAndReclaimReaps(): void
    {
        // CP-14 structural twins of the behavior pins in {@see AsyncProbeTest}:
        // start() (and the constructor) sit in a try whose catch releases the
        // booked slot, and stale reclamation calls the vendor reaper — a
        // terminate()-only reclaim leaves a zombie per abandoned child, which
        // is precisely what "kills children whose loop was abandoned" always
        // promised and did not do.
        $src = self::src();
        self::assertStringContainsString('$process->start($loop);', $src);
        self::assertStringContainsString('} catch (\Throwable) {', $src, 'fallible spawn calls must sit behind a catch');
        self::assertStringContainsString(
            'self::killAndReap($slot[\'process\']);',
            $src,
            'reclaim must reap, not only kill (CP-28 safe form: never close() on a possibly-dead handle)',
        );
        self::assertStringContainsString(
            'self::killAndReap($process);',
            $src,
            'the grace rung must kill AND reap through the handler path, or the killed child is never observed',
        );
    }

    public function testChildStderrIsDrainedIntoABoundedBuffer(): void
    {
        // MT8: an unread stderr pipe lets a chatty infocmp block on a full
        // buffer and die on the kill ladder; the drain is capped, not unbounded.
        //
        // The pump shape is pinned too: react/child-process ^0.6 never emits
        // 'stdout'/'stderr' Process events — the child pipes are public STREAM
        // PROPERTIES created inside start() — and the exit handler must read
        // the accumulator by reference or it judges a stale empty string.
        // The old dead-shape assertion is flipped to the live one in the same
        // commit as the fix; a revert to either flaw reddens this test.
        $src = self::src();
        self::assertStringContainsString('$process->stderr?->on(\'data\'', $src);
        self::assertStringContainsString('$process->stdout?->on(\'data\'', $src);
        self::assertStringContainsString('use ($deferred, &$stdout', $src);
        self::assertStringNotContainsString("\$process->on('stderr'", $src, 'the dead event name must not return');
        self::assertStringNotContainsString("\$process->on('stdout'", $src, 'the dead event name must not return');
        self::assertStringContainsString('STDERR_CAP_BYTES', $src);
    }

    public function testChildStdoutIsCappedIntoABoundedBuffer(): void
    {
        // M10: the stdout accumulator was a bare append — a pathological child
        // grew it without end before the capability scan ever judged it. The
        // pump must carry the same keep-a-prefix guard as stderr: a ceiling
        // check around the append, not a raw concatenation. The behavior-side
        // pin (a Tc line beyond the cap never lands) is in {@see AsyncProbeTest}.
        $src = self::src();
        self::assertStringContainsString('self::STDOUT_CAP_BYTES', $src);
        self::assertStringContainsString('\strlen($stdout) < self::STDOUT_CAP_BYTES', $src);

        $const = (new \ReflectionClass(AsyncProbe::class))->getReflectionConstant('STDOUT_CAP_BYTES');
        self::assertNotFalse($const, 'stdout needs its own named ceiling, mirroring stderr');
        self::assertSame(8192, $const->getValue(), 'the ceiling is the documented 8 KiB');
    }

    public function testConcurrencyCeilingIsAPublicDocumentedKnob(): void
    {
        $const = (new \ReflectionClass(AsyncProbe::class))->getReflectionConstant('MAX_CONCURRENT_PROBES');
        self::assertNotFalse($const);
        self::assertTrue($const->isPublic(), 'README documents it as the ceiling callers are told about');
        self::assertSame(4, $const->getValue());
    }

    public function testResetSeamClearsTheStaticSlotTable(): void
    {
        $slots = new \ReflectionProperty(AsyncProbe::class, 'inFlight');
        $token = new \ReflectionProperty(AsyncProbe::class, 'nextToken');

        $slots->setValue(null, [1 => ['process' => new \React\ChildProcess\Process('true'), 'deadline' => 0.0]]);
        $token->setValue(null, 42);

        AsyncProbe::resetForTesting();

        self::assertSame([], $slots->getValue());
        self::assertSame(1, $token->getValue());
    }

    public function testEveryInfocmpLaunchPassesTheSharedMinimalEnvironment(): void
    {
        // M7 structural: every exec site must hand the child the shared
        // minimal environment builder — the behavior pins live in
        // tests/Probe/InfocmpEnvPurityTest.php; these needles make a silent
        // slip back to an inherited-env launch red even if those hosts die.
        // CP-11 moved the synchronous launches behind one bounded door
        // (InfocmpBinary::runBounded), so the needles follow: Probe.php and
        // TerminalProbe.php must carry NO proc_open call site at all, and
        // the door itself must launch under childEnvironment.
        $async = self::src();
        self::assertStringContainsString(
            'new Process($command, null, InfocmpBinary::childEnvironment($term))',
            $async,
            'the async child must launch with the minimal environment',
        );

        $root = \dirname(__DIR__);
        $door = (string) \file_get_contents($root . '/src/Probe/InfocmpBinary.php');
        self::assertStringContainsString(
            'self::childEnvironment($term)',
            $door,
            'the bounded sync door must carry the minimal environment',
        );

        $sync = (string) \file_get_contents($root . '/src/Probe.php');
        self::assertStringNotContainsString(
            'proc_open(',
            $sync,
            'CP-11: the sync face must not launch a child of its own anymore',
        );

        $terminal = (string) \file_get_contents($root . '/src/Probe/TerminalProbe.php');
        self::assertStringNotContainsString(
            'proc_open(',
            $terminal,
            'CP-11: the terminal probe phase-2 leg must answer through the door',
        );
    }

    /**
     * CP-28 behavior pin (i) (sugar-colorprofile-029): a TERM-ignoring child
     * must die on the grace SIGKILL and be reaped. The spelling needles above
     * can only prove the source MENTIONS the ladder; this drives a real
     * escalation over the CP-28 deadline seam (1 s TERM, 1 s KILL) against a
     * single-process fixture that traps TERM away.
     */
    public function testTheGraceKillActuallyKillsAndReapsATermIgnoringChild(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX signal semantics (trap, SIGKILL) are not available here.');
        }

        $term = 'xterm-256color';
        $saved = $this->armCleanEnv();
        $_ENV['TERM'] = $term;
        \putenv("TERM={$term}");
        $path = $this->plantBinary("#!/bin/sh\ntrap '' TERM\nwhile :; do :; done\n");

        $process = null;
        $exit = null;
        $exitAt = null;
        $settled = null;
        $settleAt = null;
        $t0 = \microtime(true);

        AsyncProbe::setDeadlinesForTesting(1, 1);
        try {
            $loop = new \React\EventLoop\StreamSelectLoop();
            AsyncProbe::colorProfile($loop)->then(static function (\SugarCraft\ColorProfile\ColorProfile $p) use (&$settled, &$settleAt, $t0): void {
                $settled = $p;
                $settleAt = \microtime(true) - $t0;
            });

            $capture = $loop->addPeriodicTimer(0.05, static function () use ($loop, &$process, &$capture, &$exit, &$exitAt, $t0): void {
                $slots = (new \ReflectionProperty(AsyncProbe::class, 'inFlight'))->getValue();
                if ($slots === []) {
                    return;
                }
                $process = $slots[array_key_first($slots)]['process'];
                $process->on('exit', static function (?int $code, ?int $signal) use (&$exit, &$exitAt, $t0): void {
                    $exit = [$code, $signal];
                    $exitAt = \microtime(true) - $t0;
                });
                $loop->cancelTimer($capture);
            });
            $loop->addTimer(3.2, static function () use ($loop): void {
                $loop->stop();
            });
            $loop->run();

            self::assertNotNull($process, 'the slot must be capturable before the deadline releases it');
            self::assertNotNull($settled, 'the deadline arm must settle the caller with the chain answer');
            self::assertSame(
                \SugarCraft\ColorProfile\ColorProfile::Ansi256,
                $settled,
                'a ladder-killed child proves nothing about the TERM; the env rung must stand',
            );
            self::assertLessThanOrEqual(2.2, $settleAt, 'the detach at the deadline must not wait for the kill');
            self::assertNotNull($exit, 'the killed child must be observed and reaped through the vendor close path');
            // On a signal death this vendor build emits proc_close()'s
            // SIGNAL NUMBER as the exit code (PHP yields the signal number
            // for signal-killed children), and the signal slot only carries
            // a value when vendor's own status refresh won the wait — so the
            // deterministic proof of WHICH rung killed is the code: the
            // TERM-immune trap guarantees 15 can never appear here, and 9 is
            // the grace rung's signature.
            self::assertSame(9, $exit[0], 'the grace rung must answer with a real SIGKILL');
            self::assertTrue(
                $exit[1] === null || $exit[1] === \SIGKILL,
                'the vendor signal slot is race-dependent on a killed child (null or SIGKILL); SIGTERM never is',
            );
            self::assertGreaterThanOrEqual(1.5, $exitAt, 'the KILL lands at deadline+grace, never before the TERM rung');
            self::assertLessThanOrEqual(2.9, $exitAt, 'the KILL lands within grace + scheduling slack');
            self::assertFalse($process->isRunning(), 'no live child survives the settle');
            self::assertNull(
                (new \ReflectionProperty(\React\ChildProcess\Process::class, 'process'))->getValue($process),
                'vendor must have proc_close-d the killed child — reaped, not merely signaled',
            );
            self::assertSame(
                [],
                (new \ReflectionProperty(AsyncProbe::class, 'inFlight'))->getValue(),
                'the slot table must drain',
            );
            self::assertSame(
                [],
                (new \ReflectionProperty(AsyncProbe::class, 'settledMemo'))->getValue(),
                'CP-13 contract under a real signal kill: a killed child mints no memo entry',
            );
        } finally {
            if ($process !== null && $process->isRunning()) {
                $process->terminate(\defined('SIGKILL') ? \SIGKILL : 9);
            }
            if ($process !== null) {
                // CP-28 lesson: close() on a dead-but-unreaped handle
                // re-enters itself through the stream-close handler — drive
                // the same canonical path the library uses (the handler's
                // single close reaps; an already-closed handle no-ops).
                $process->stdout?->close();
                $process->stderr?->close();
                $process->stdin?->close();
            }
            AsyncProbe::resetForTesting();
            $this->restoreEnv($saved);
            unset($_ENV['TERM']);
            \putenv('TERM');
            @\unlink($path);
        }
    }

    /**
     * CP-28 behavior pin (ii): the positive control — a child that HONOURS
     * SIGTERM exits at the deadline rung with a clean status, never needing
     * the grace kill (5 s grace vs ~1 s to exit), and its clean exit mints
     * the CP-13 negative sentinel like any other zero-status child.
     */
    public function testATermHonoringChildExitsAtTheDeadlineRungWithoutWaitingForGrace(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX signal semantics (trap, SIGTERM) are not available here.');
        }

        $term = 'xterm-256color';
        $saved = $this->armCleanEnv();
        $_ENV['TERM'] = $term;
        \putenv("TERM={$term}");
        // One stdout byte on purpose: react's read streams self-close on EOF
        // once data has flowed (the established fixture behavior), so the
        // handler's exit at ~the deadline is observed WITHOUT the grace rung.
        // 'x' is not a terminfo cap — the CP-13 verdict stays negative.
        $path = $this->plantBinary("#!/bin/sh\ntrap 'exit 0' TERM\nprintf x\nwhile :; do :; done\n");

        $process = null;
        $exit = null;
        $exitAt = null;
        $settled = null;
        $t0 = \microtime(true);

        // Grace deliberately LONGER than the whole run: reaching the KILL
        // rung would push the observed exit past the assertions below.
        AsyncProbe::setDeadlinesForTesting(1, 5);
        try {
            $loop = new \React\EventLoop\StreamSelectLoop();
            AsyncProbe::colorProfile($loop)->then(static function (\SugarCraft\ColorProfile\ColorProfile $p) use (&$settled, $t0): void {
                $settled = $p;
            });

            $capture = $loop->addPeriodicTimer(0.05, static function () use ($loop, &$process, &$capture, &$exit, &$exitAt, $t0): void {
                $slots = (new \ReflectionProperty(AsyncProbe::class, 'inFlight'))->getValue();
                if ($slots === []) {
                    return;
                }
                $process = $slots[array_key_first($slots)]['process'];
                $process->on('exit', static function (?int $code, ?int $signal) use (&$exit, &$exitAt, $t0): void {
                    $exit = [$code, $signal];
                    $exitAt = \microtime(true) - $t0;
                });
                $loop->cancelTimer($capture);
            });
            $loop->addTimer(2.6, static function () use ($loop): void {
                $loop->stop();
            });
            $loop->run();

            self::assertNotNull($settled, 'the TERM rung settles the caller at the deadline');
            self::assertNotNull($exit, 'the TERM-honoring child must be observed and reaped');
            self::assertLessThanOrEqual(
                1.95,
                $exitAt,
                'the exit must land on the TERM rung, long before the 5 s grace — the KILL never fired',
            );
            self::assertSame([0, null], $exit, 'a handler exit carries status 0 and no kill signal — the grace KILL never fired');
            self::assertFalse($process->isRunning());
            self::assertSame(
                [$term => null],
                (new \ReflectionProperty(AsyncProbe::class, 'settledMemo'))->getValue(),
                'a clean zero-output child is a NEGATIVE verdict (CP-13 sentinel), not a promotion',
            );
        } finally {
            if ($process !== null && $process->isRunning()) {
                $process->terminate(\defined('SIGKILL') ? \SIGKILL : 9);
            }
            if ($process !== null) {
                // CP-28 lesson: close() on a dead-but-unreaped handle
                // re-enters itself through the stream-close handler — drive
                // the same canonical path the library uses (the handler's
                // single close reaps; an already-closed handle no-ops).
                $process->stdout?->close();
                $process->stderr?->close();
                $process->stdin?->close();
            }
            AsyncProbe::resetForTesting();
            $this->restoreEnv($saved);
            unset($_ENV['TERM']);
            \putenv('TERM');
            @\unlink($path);
        }
    }

    /**
     * Neutralize every key the ladder could answer TrueColor/suppression
     * from on THIS host (ambient COLORTERM would skip the spawn entirely),
     * house save/restore idiom. TERM is pinned by the callers.
     *
     * @return array<string, string|false>
     */
    private function armCleanEnv(): array
    {
        $saved = [];
        foreach ([
            'CLICOLOR_FORCE', 'FORCE_COLOR', 'NO_COLOR', 'CLICOLOR', 'COLORTERM',
            'TERM_PROGRAM', 'WT_SESSION', 'GOOGLE_CLOUD_SHELL', 'TMUX', 'STY',
        ] as $key) {
            $saved[$key] = \getenv($key);
            unset($_ENV[$key]);
            \putenv($key);
        }

        return $saved;
    }

    private function restoreEnv(array $saved): void
    {
        foreach ($saved as $key => $value) {
            if ($value === false) {
                unset($_ENV[$key]);
                \putenv($key);
                continue;
            }
            $_ENV[$key] = $value;
            \putenv("{$key}={$value}");
        }
    }

    private function plantBinary(string $body): string
    {
        // House binary-stub shape (M10 precedent): tempnam script + the
        // InfocmpBinary path seam stuffed by reflection.
        $path = \tempnam(\sys_get_temp_dir(), 'cp28');
        self::assertIsString($path);
        \file_put_contents($path, $body);
        \chmod($path, 0755);
        (new \ReflectionProperty(\SugarCraft\ColorProfile\Probe\InfocmpBinary::class, 'path'))->setValue(null, $path);

        return $path;
    }
}
