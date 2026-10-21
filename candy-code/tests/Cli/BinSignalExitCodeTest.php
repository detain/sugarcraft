<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Chat;

/**
 * v2:MINOR-6 — a signal-stopped TUI run must exit 128+N at the shell, not
 * fall off the end of the bin's try block with a clean 0.
 *
 * Same discipline as {@see BinTerminalGuardPidTest}: the emission lives at
 * the very end of a TUI launch that cannot run inside the suite (Program
 * needs a tty side; see BinInteractiveTtyGuardTest), so the pin is an
 * order-preserving source scan of bin/candy-code. A live sugar-core
 * guarantee it depends on — the null polarity of stopSignal() for
 * cooperative exits — is pinned in sugar-core's ProgramTerminalRecoveryTest;
 * the wiring premise (candy-code launches with catchInterrupts ON, so the
 * signal arms that feed stopSignal() are actually installed) is asserted
 * here in-process.
 */
final class BinSignalExitCodeTest extends TestCase
{
    private static ?string $bin = null;

    private function bin(): string
    {
        if (self::$bin === null) {
            $path = \dirname(__DIR__, 2) . '/bin/candy-code';
            $source = file_get_contents($path);
            $this->assertIsString($source, $path . ' must exist to be pinned');
            self::$bin = $source;
        }
        return self::$bin;
    }

    /** Offset of a needle, failing loudly when the mutation dropped it. */
    private function at(string $needle): int
    {
        $pos = strpos($this->bin(), $needle);
        $this->assertIsInt($pos, "bin/candy-code no longer contains: {$needle}");
        return $pos;
    }

    public function testTheTuiExitMapsARecordedStoppingSignalToTwelveEightPlusN(): void
    {
        $read = $this->at('$stoppedBySignal = $program->stopSignal();');
        $guard = $this->at('if ($stoppedBySignal !== null) {');
        $exit = $this->at('exit(128 + $stoppedBySignal);');

        // The read happens after run() returns — the only point where
        // sugar-core's stop record describes THIS launch.
        $this->assertLessThan($read, $this->at('$finalModel = $program->run();'));
        // The mapping sits last inside the TUI try: the Kitty pop and the
        // SessionEnd funnel keep running first, and a relaunch still execs
        // the fresh process instead of exiting a signal code here.
        $this->assertLessThan($read, $this->at("fwrite(STDOUT, Ansi::popKittyKeyboard());"));
        $this->assertLessThan($read, $this->at('NonInteractive::fireSessionEnd('));
        $this->assertLessThan($read, $this->at('exit(SessionRelaunch::exec($relaunchDir, $argv, __FILE__));'));
        // ...and inside the try, before its catch — no structural move.
        $this->assertGreaterThan($exit, $this->at('} catch (PermissionConfigException $e) {'));

        // Null-guarded: only a signal-stopped run maps; the exit is exactly
        // the shell's 128+N convention.
        $this->assertGreaterThan($read, $guard, 'the read must be followed by its null guard');
        $this->assertGreaterThan($guard, $exit, 'the mapped exit must sit inside the null guard');
        $this->assertSame(1, substr_count($this->bin(), 'exit(128 + $stoppedBySignal);'),
            'the 128+N mapping must exist exactly once');
    }

    public function testTheNormalQuitStillFallsOffTheEndOfTheTryWithZero(): void
    {
        // run() itself never exits 0 — if a future edit "helpfully" closed
        // the try with an unconditional exit, cooperative quits would keep
        // passing while the explicit null polarity stopped mattering.
        $tryTail = substr($this->bin(), $this->at('$finalModel = $program->run();'));
        $tryTail = substr($tryTail, 0, $this->at('} catch (PermissionConfigException $e) {')
            - $this->at('$finalModel = $program->run();'));
        $this->assertStringNotContainsString('exit(0)', $tryTail,
            'the cooperative path must remain the implicit 0 of the bin falling through');
    }

    public function testTheTuiLaunchKeepsCatchInterruptsOnSoTheSignalArmsLive(): void
    {
        // stopSignal() only becomes non-null through the SIGINT/SIGTERM/
        // SIGHUP arms, which sugar-core installs only when catchInterrupts
        // is on. A flip to false here would silently dead-code the mapping.
        $this->assertTrue(
            Chat::programOptions()->catchInterrupts,
            'the bin signal-exit mapping relies on sugar-core installing the signal stop path',
        );
    }
}
