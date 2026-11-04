<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * The 1021 v2:NEW-1 defect: the recovery shutdown guard bin/candy-code used
 * to register ran in EVERY process that shut down the entrypoint script —
 * including forked children. AgentWorkerPool::startAgent() forks inside the
 * main chat process, and a worker child reaches a shutdown path (exit(0)
 * after running its task), so the PARENT's inherited guard closure re-ran in
 * the child: DEC-off bytes painted the parent's live TUI mid-render and a
 * false "exited abnormally - terminal restored" line printed on a normal
 * worker exit. The guard's fix at the time was a pid latch on the consumer
 * side, and this class pinned it there.
 *
 * X-CORE1 (2026-11-04, owner ruling OD-17) retired both hand-rolled guards —
 * bin's and src/Cli/Attach.php's twin — in favour of sugar-core's single
 * authoritative release path. The entrypoint now arms the core backstop on
 * the Program itself (armTerminalRelease(), registered before run() so the
 * constructor-time window the flag-guarded superset used to cover stays
 * covered) and the install-pid discipline lives in the library: Program
 * captures the pid at arm time and releaseTerminalForShutdown() returns
 * silently in any process other than the installer. That fork-child safety
 * net is pinned in sugar-core/tests/ProgramTerminalRecoveryTest.php —
 * testShutdownReleaseStaysSilentBehindEveryGate (the gate returns) and
 * testReleaseHookArmsOncePerProgramWithCaptureBeforeRegister (the capture
 * precedes registration). What this class pins instead is the shape of the
 * retirement: the entrypoint carries no recovery byte list, no flag, and no
 * second shutdown function - only the fatal-error writer that answers a
 * different question, and the arm in the order that protects the window.
 * A planted copy of the old byte-emitting guard reddens the absence and
 * single-shutdown-function pins; moving the arm after the run reddens the
 * order pin.
 */
final class BinTerminalGuardPidTest extends TestCase
{
    /**
     * The load-bearing order: construct the Program, arm the core release on
     * it, then enter run(). Arming BEFORE the call is what keeps the
     * window around setupTerminal() covered - the property the retired flag
     * guard encoded as "armed at the call, a deliberate superset".
     */
    public function testTheCoreReleaseHookIsArmedBeforeTheRunItProtects(): void
    {
        $bin = $this->entrypointSource();

        $construct = strpos($bin, '$program = new Program(');
        $arm = strpos($bin, '$program->armTerminalRelease();');
        $run = strpos($bin, '$program->run();');

        self::assertGreaterThan(0, $construct, 'the entrypoint must build the named Program the arm hangs off');
        self::assertGreaterThan($construct, $arm, 'the core release must be armed on the constructed Program');
        self::assertGreaterThan($arm, $run, 'the arm must precede run() - a hook armed after the call protects nothing');
    }

    /**
     * The byte list is core-owned now: no flag, no captured pid, no
     * abnormal-exit announcement, and none of the seven recovery sequences
     * may reappear in the entrypoint. Each is a name the retired guard
     * carried; re-spreading any of them here duplicates the library's
     * teardown (the drift this lane exists to end).
     */
    public function testTheEntrypointCarriesNoHandRolledRecoveryByteList(): void
    {
        $bin = $this->entrypointSource();

        foreach ([
            '$terminalWasRun',
            '$installPid',
            'exited abnormally',
            'Ansi::mouseCellMotionOff()',
            'Ansi::mouseAllMotionOff()',
            'Ansi::bracketedPasteOff()',
            'Ansi::unicodeOff()',
            'Ansi::cursorShow()',
            'Ansi::altScreenLeave()',
        ] as $retired) {
            self::assertFalse(
                str_contains($bin, $retired),
                "the retired guard shape '{$retired}' must not live in the entrypoint - sugar-core owns the terminal release",
            );
        }
    }

    /**
     * Exactly one shutdown function survives in bin: the tui-error-log
     * fatal writer, which announces fatals and is a different concern. The
     * deleted guard was the second; a re-planted recovery closure is caught
     * by the count, and deleting the fatal writer out from under the error
     * log is caught by the phrase.
     */
    public function testTheFatalWriterRemainsTheOnlyBinShutdownFunction(): void
    {
        $bin = $this->entrypointSource();

        self::assertSame(
            1,
            substr_count($bin, 'register_shutdown_function'),
            'the tui-error-log fatal writer is bin\'s only shutdown function since X-CORE1 retired the recovery guard',
        );
        self::assertStringContainsString(
            'candy-code: fatal error',
            $bin,
            'the fatal writer\'s announcement itself must survive the retirement',
        );
    }

    /**
     * @return string the bin/candy-code source, the only artifact that can
     *                pin the entrypoint's shape
     */
    private function entrypointSource(): string
    {
        $path = dirname(__DIR__, 2) . '/bin/candy-code';
        $source = file_get_contents($path);
        self::assertIsString($source, "the entrypoint at {$path} must be readable for its shape to be pinned");

        return $source;
    }
}
