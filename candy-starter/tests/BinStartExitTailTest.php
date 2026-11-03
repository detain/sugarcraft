<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Reference-bin guard — the template's entry point must keep the shell's
 * signal-exit convention (lib-reviews-1102 cli-tui-architect M2 / qa M2).
 *
 * `Program::run()` stops both cooperatively (Cmd::quit(), a model-raised
 * InterruptMsg) and on OS signals; sugar-core's `stopSignal()` answers an
 * int only for the signal arms and its docblock assigns the 128+N
 * convention to the BIN. A bin that discards it — the shape this template
 * shipped before 2026-11 — makes every forked app exit 0 after SIGINT /
 * SIGTERM / SIGHUP, defeating supervisors, scripts and CI signal checks.
 * The reference bin IS what forks copy, so the tail is pinned here,
 * source-shape first: the real stopSignal() contract is sugar-core's to
 * test; the copy-paste obligation is the starter's.
 */
final class BinStartExitTailTest extends TestCase
{
    private const BIN = __DIR__ . '/../bin/start';

    public function testTheReferenceBinConsultsStopSignalAndExitsWithTheShellConvention(): void
    {
        $source = (string) file_get_contents(self::BIN);

        $this->assertStringContainsString('$program->run();', $source, 'run() result may be discarded, but the stop reason must not be');
        $this->assertStringContainsString('$program->stopSignal()', $source, 'the bin must read stopSignal() after run()');
        $this->assertStringContainsString('exit(128 + $stoppedBySignal)', $source, 'a signal-stopped run must exit 128+N');
    }

    public function testTheSignalExitTailIsGuardedByANullCheckSoCooperativeQuitsKeepZero(): void
    {
        $source = (string) file_get_contents(self::BIN);

        // The tail must be conditional: stopSignal() answers null for
        // cooperative stops, and those exits must stay 0 (unconditional
        // 128+N would break every "clean exit" caller).
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$stoppedBySignal\s*!==\s*null\s*\)\s*\{/',
            $source,
            'the exit(128 + N) leg must sit behind a strict null check on the captured stopSignal()',
        );
    }

    public function testTheCommentedProgramOptionsExampleKeepsTheSameRunPlusTailShape(): void
    {
        $source = (string) file_get_contents(self::BIN);

        // The tunables example once taught `->run();` as the whole story —
        // the exact defective tail this guard exists to kill. It must not
        // resurface as a complete-statement exemplar.
        $this->assertStringNotContainsString('ProgramOptions(framerate: 30.0)))->run();', $source);
        $this->assertStringContainsString('$program = new Program(new Counter(), new \\SugarCraft\\Core\\ProgramOptions(framerate: 30.0));', $source);
    }
}
