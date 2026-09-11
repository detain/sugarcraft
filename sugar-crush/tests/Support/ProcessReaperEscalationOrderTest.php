<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\ProcessReaper;

/**
 * E676 — the escalation ladder is single-sourced, and this is the pin that
 * says WHICH ORDER it runs in.
 *
 * The fold moved three private copies of "SIGTERM, bounded wait, signal 9,
 * bounded wait" into {@see ProcessReaper::escalate()}. A consolidation is
 * only behaviour-preserving if the RUNGS survive in order, so every row here
 * drives the ladder through recorded callables and asserts the exact
 * sequence: TERM first, the escalate notice strictly BETWEEN the rungs,
 * signal 9 only after the TERM budget expired, and both budgets honoured
 * against the wall clock. Pulling a rung out of escalate() reddens this file;
 * reordering the rungs reddens it harder.
 *
 * One row drives a REAL child (a `sh` that traps TERM away) through
 * {@see ProcessReaper::terminateAndAwaitExit()} — the folded shape, not just
 * the extracted callable — because the closure seam could in principle be
 * wired backwards and still pass four scripted rows.
 */
final class ProcessReaperEscalationOrderTest extends TestCase
{
    public function testTheLadderEscalatesTermThenKillInOrderAndAnnouncesBetween(): void
    {
        $seen = [];
        $gone = false;

        $reaped = ProcessReaper::escalate(
            function (int $signal) use (&$seen): void {
                $seen[] = $signal;
            },
            function () use (&$seen, &$gone): bool {
                if (count($seen) >= 2) {
                    $gone = true;
                }

                return $gone;
            },
            0.05,
            0.05,
            function () use (&$seen): void {
                $seen[] = 'escalate';
            },
        );

        $this->assertSame([15, 'escalate', 9], $seen, 'the rungs changed order or one went missing');
        $this->assertTrue($reaped, 'a child gone after the kill rung must be reported as gone');
    }

    public function testAChildThatDiesOnTermNeverSeesTheKillRung(): void
    {
        $seen = [];

        $reaped = ProcessReaper::escalate(
            function (int $signal) use (&$seen): void {
                $seen[] = $signal;
            },
            static fn (): bool => true,
            0.05,
            0.05,
            function () use (&$seen): void {
                $seen[] = 'escalate';
            },
        );

        $this->assertSame([15], $seen, 'signal 9 (or its notice) fired for a child that honoured SIGTERM');
        $this->assertTrue($reaped);
    }

    public function testBothBudgetsAreSpentAndAnUnkillableChildIsReportedUnreaped(): void
    {
        $seen = [];
        $start = microtime(true);

        $reaped = ProcessReaper::escalate(
            function (int $signal) use (&$seen): void {
                $seen[] = $signal;
            },
            static fn (): bool => false,
            0.05,
            0.05,
        );

        $elapsed = microtime(true) - $start;
        $this->assertSame([15, 9], $seen, 'the two-rung sequence did not complete');
        $this->assertFalse($reaped, 'a never-gone child must be reported honestly');
        $this->assertGreaterThanOrEqual(0.09, $elapsed, 'a rung budget was skipped');
        $this->assertLessThan(2.0, $elapsed, 'the ladder is not bounded any more');
    }

    public function testTheDefaultRungBudgetsAreTheClassConstants(): void
    {
        $parameters = (new \ReflectionMethod(ProcessReaper::class, 'escalate'))->getParameters();

        $this->assertSame(
            ProcessReaper::TERMINATE_GRACE_SECONDS,
            $parameters[2]->getDefaultValue(),
            'the TERM rung drifted off its documented budget',
        );
        $this->assertSame(
            ProcessReaper::KILL_GRACE_SECONDS,
            $parameters[3]->getDefaultValue(),
            'the KILL rung drifted off its documented budget',
        );
    }

    public function testTheFoldedLadderKillsARealChildThatIgnoresSigterm(): void
    {
        // `sh` with the trap set BEFORE the loop: the TERM rung lands, is
        // ignored, the bounded wait expires, and only signal 9 ends it. The
        // elapsed floor is the TERM budget itself — a ladder that skipped
        // TERM, or that escalated without waiting, could not take the second
        // it is contractually required to give.
        // ARRAY command form: the string form of proc_open() runs the
        // command through escapeshellcmd(), which mangles the trap's empty
        // quotes. And the trap must be ASKED FOR BEFORE the ladder starts:
        // a TERM arriving in the exec-to-trap window kills a trap-less sh
        // with default disposition, and the ladder correctly no-ops in 5 ms,
        // testing nothing (MEASURED, twice, the hard way). The ready file is
        // the handshake — written strictly AFTER `trap` is in effect.
        $ready = \tempnam(\sys_get_temp_dir(), 'crush-term-ignorer-');
        $process = \proc_open(
            ['/bin/sh', '-c', 'trap "" TERM; echo ready > ' . \escapeshellarg($ready) . '; while :; do sleep 0.05; done'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);

        // Bound by arithmetic, and NO read of the child's pipes in here:
        // stream_get_contents() on a live stderr pipe blocks until EOF — an
        // eagerly-evaluated argument once hung this loop for the child's
        // whole lifetime (MEASURED). Failure detail comes after, non-blocking.
        $waited = 0.0;
        while (\trim((string) @\file_get_contents($ready)) !== 'ready') {
            \usleep(10_000);
            $waited += 0.01;
            if ($waited >= 5.0) {
                \stream_set_blocking($pipes[2], false);
                $this->fail('the TERM-ignoring child never announced readiness — it died at exec (' . (string) \stream_get_contents($pipes[2]) . ')');
            }
        }

        try {
            $start = microtime(true);
            $gone = ProcessReaper::terminateAndAwaitExit($process);
            $elapsed = microtime(true) - $start;

            $this->assertTrue($gone, 'the KILL rung did not land');
            $this->assertGreaterThanOrEqual(ProcessReaper::TERMINATE_GRACE_SECONDS * 0.9, $elapsed, 'the TERM budget was not actually spent');
            $this->assertLessThan(4.0, $elapsed, 'the ladder exceeded both documented budgets');
            $this->assertFalse(\proc_get_status($process)['running']);
        } finally {
            @\fclose($pipes[0]);
            @\fclose($pipes[1]);
            @\fclose($pipes[2]);
            \proc_close($process);
            @\unlink($ready);
        }
    }
}
