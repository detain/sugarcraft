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
 * loop, but must not survive review either.
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
        self::assertStringContainsString('$slot[\'process\']->close();', $src, 'reclaim must reap, not only kill');
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

        AsyncProbe::_reset();

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
}
