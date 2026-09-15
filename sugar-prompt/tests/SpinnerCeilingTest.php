<?php

declare(strict_types=1);

namespace SugarCraft\Prompt\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Prompt\Spinner;

/**
 * E711 (round 82): the forked spinner action is lifetime-bounded.
 *
 * Before this, `Spinner::run()`'s spin loop exited only on "child reaped" —
 * a wedged worker spun forever with no wall-clock ceiling. These fixtures
 * wedge a REAL child (pcntl is not optional here) with a self-cap so every
 * failure mode is a late red rather than a hang.
 */
final class SpinnerCeilingTest extends TestCase
{
    /** Seconds a wedged fixture child lives before exiting on its own. */
    private const CHILD_SELF_CAP_SECONDS = 8.0;

    private string $tempDir;

    protected function setUp(): void
    {
        if (\function_exists('pcntl_fork') === FALSE || \function_exists('posix_kill') === FALSE) {
            $this->markTestSkipped('pcntl and posix required to exercise the fork ceiling');
        }
        $this->tempDir = \sys_get_temp_dir() . '/q1-spinner-' . \uniqid('', TRUE);
        \mkdir($this->tempDir, 0700, TRUE);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->tempDir . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->tempDir);
    }

    public function testAWedgedChildHitsTheCeilingAndIsTornDownThroughTheLadder(): void
    {
        // The flagship wedge: a child that ignores SIGTERM and sleeps. The
        // deadline must fire at ~0.3s, the ladder must escalate to KILL,
        // run() must throw, and the child must end up REAPED (no zombie,
        // no orphan). Neuter the ceiling check and this goes red only after
        // the child's self-cap lets the old loop reap it — late, but loud.
        $pidFile = $this->tempDir . '/child-pid';
        $spinner = Spinner::new()
            ->withTitle('wedged')
            ->withMaxRuntimeSeconds(0.3)
            ->withAction(static function () use ($pidFile): void {
                \file_put_contents($pidFile, (string) \getmypid());
                \pcntl_signal(\SIGTERM, \SIG_IGN);
                \usleep((int) (8 * 1_000_000));
            });

        $started = \microtime(true);
        $thrown = null;
        try {
            $spinner->run();
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }
        $elapsed = \microtime(true) - $started;

        self::assertNotNull($thrown, 'a child ignoring SIGTERM past its bound must not return silently');
        self::assertStringContainsString('lifetime bound and was terminated', $thrown->getMessage());
        self::assertLessThan(6.0, $elapsed, 'ceiling + TERM budget + KILL budget is well under ~2.5s; anything longer means the deadline did not fire early');

        // The spinner must have REAPED the child it killed: a later
        // waitpid on the same pid is ESRCH (-1), not a status pickup.
        $childPid = (int) \file_get_contents($pidFile);
        self::assertGreaterThan(0, $childPid);
        $status = 0;
        self::assertSame(-1, @\pcntl_waitpid($childPid, $status, \WNOHANG), 'the torn-down child must not linger as a zombie');
    }

    public function testTheCeilingGivesTheGraceRungAChanceToLandFirst(): void
    {
        // A child that HANDLES SIGTERM must observe it (the ladder's first
        // rung) and not be killed by 9. Rung ordering is load-bearing — a
        // ladder that jumped straight to KILL would shred any cooperative
        // cleanup and still satisfy test #1's message.
        $marker = $this->tempDir . '/term-seen';
        $spinner = Spinner::new()
            ->withMaxRuntimeSeconds(0.3)
            ->withAction(static function () use ($marker): void {
                \pcntl_async_signals(TRUE);
                \pcntl_signal(\SIGTERM, static function () use ($marker): void {
                    \file_put_contents($marker, 'seen');
                    exit(0);
                });
                \usleep((int) (8 * 1_000_000));
            });

        $thrown = null;
        try {
            $spinner->run();
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, 'the child stalls past the bound even though it exits gracefully on TERM');
        self::assertStringContainsString('lifetime bound and was terminated', $thrown->getMessage());
        self::assertSame('seen', \file_get_contents($marker), 'the TERM rung must reach a cooperative child before KILL is considered');
    }

    public function testAFastActionUnderAGenerousCeilingIsUntouched(): void
    {
        // The bound must not bite normal work — ceiling 30s, action done
        // in well under one. Guards against an always-fire regression.
        $flag = $this->tempDir . '/done';
        $spinner = Spinner::new()
            ->withMaxRuntimeSeconds(30.0)
            ->withAction(static function () use ($flag): void {
                \file_put_contents($flag, 'ran');
            });

        $spinner->run(); // must not throw

        // Child-side file write is the only signal the action ran (state
        // cannot cross the fork); the reap happened inside run(), so the
        // file is flushed by the child's exit before run() returns.
        self::assertSame('ran', \file_get_contents($flag));
    }

    /**
     * @dataProvider nonsenseBoundProvider
     */
    public function testANonsenseBoundIsRefusedAtTheSetter(float $seconds, string $label): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('positive finite');

        Spinner::new()->withMaxRuntimeSeconds($seconds);
    }

    /**
     * @return array<string, array{0: float, 1: string}>
     */
    public static function nonsenseBoundProvider(): array
    {
        return [
            'zero (would kill every action at the first frame)' => [0.0, 'zero'],
            'negative' => [-1.0, 'negative'],
            'NAN' => [\NAN, 'NAN'],
            'INF (the forever this setter exists to remove)' => [\INF, 'INF'],
        ];
    }

    public function testTheDefaultCeilingIsTheDocumentedLifetimeBound(): void
    {
        $reflected = new \ReflectionProperty(Spinner::class, 'maxRuntimeSeconds');

        self::assertSame(600.0, Spinner::MAX_RUNTIME_SECONDS);
        // The instance default is the constant (name-derived reflection:
        // the value lives on a fresh instance, not on a mutate() clone).
        $fresh = Spinner::new();
        self::assertSame(600.0, $reflected->getValue($fresh));
        self::assertSame(3.0, $reflected->getValue($fresh->withMaxRuntimeSeconds(3.0)), 'the setter rides mutate() like every other field');
        self::assertSame(600.0, $reflected->getValue($fresh), 'fluent setter leaves the original alone');
    }
}
