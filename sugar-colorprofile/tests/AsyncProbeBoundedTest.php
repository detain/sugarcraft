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
        // getLoop()===null branch (nothing can block when there is no loop).
        // Every child-related settle path must compose from the chain.
        self::assertSame(
            1,
            substr_count(self::src(), 'Probe::colorProfile('),
            'async settles must come from the chain, not the blocking sync probe',
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

        AsyncProbe::_reset();

        self::assertSame([], $slots->getValue());
        self::assertSame(1, $token->getValue());
    }
}
