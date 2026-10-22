<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * 1021 v2:NEW-1 (MAJOR) — the terminal-shutdown guard must not fire in forked children.
 *
 * THE DEFECT: the guard in `bin/candy-code` is armed by a BY-REFERENCE flag
 * (`use (&$terminalWasRun)`) that is set true just before `Program::run()`.
 * `AgentWorkerPool` forks children mid-session whose tail is a plain `exit(0)`
 * (src/Agents/AgentWorkerPool.php); a fork clones the registered closure AND
 * the already-armed referent, so every completed forked stage ran the guard in
 * the child — painting DEC-off bytes onto STDOUT and a false "exited abnormally
 * — terminal restored" line onto STDERR while the parent's TUI was mid-render.
 *
 * THE FIX THIS PINS: the guard records the pid that installed it and each
 * shutdown handler compares `getmypid()` against that capture, early-returning
 * in silence on a mismatch. Like the sibling {@see BinInteractiveTtyGuardTest}
 * this cannot be exercised in-process — a real fork of the running suite would
 * exit the test itself — so the pin is the sanctioned order-preserving source
 * scan: the capture must exist BEFORE the registration (a capture taken inside
 * the closure body would re-run per shutdown, in the child's own pid, and
 * always match), the closure must carry it by value, and the pid early-return
 * must precede both emissions. Removing the pid check reddens this class; the
 * existing flag-shape pins in the sibling class stay green, because the fix is
 * an ADDITIVE early-return — the two guards are separate statements on purpose.
 */
final class BinTerminalGuardPidTest extends TestCase
{
    private static function binSource(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/bin/candy-code');
    }

    /**
     * Offset of the terminal guard's OWN registration. `strpos` on a bare
     * 'register_shutdown_function' finds the earlier `--tui-error-log` handler
     * and slices the wrong function, so every read here starts from the flag's
     * initialisation that immediately precedes the registration.
     */
    private static function guardStart(string $bin): int
    {
        $flagInit = strpos($bin, '$terminalWasRun = false;');
        self::assertIsInt($flagInit, 'the by-ref flag the guard closes over is gone');

        $register = strpos($bin, 'register_shutdown_function', (int) $flagInit);
        self::assertIsInt($register, 'no shutdown guard follows the flag — the recovery vanished');

        return (int) $register;
    }

    public function testTheInstallingPidIsCapturedBeforeTheGuardIsRegistered(): void
    {
        $bin = self::binSource();
        $flagInit = (int) strpos($bin, '$terminalWasRun = false;');
        $register = self::guardStart($bin);

        $capture = strpos($bin, '$installPid = getmypid();');

        $this->assertIsInt($capture, 'the guard records no installing pid — forked children fire it');
        $this->assertLessThan(
            $register,
            $capture,
            'the pid capture must happen before registration; inside the closure it would take the '
                . 'child\'s own pid at shutdown time and match, which is the defect restated',
        );
        $this->assertGreaterThan(
            $flagInit,
            $capture,
            'the capture must sit in the guard\'s own preamble, between the flag and its registration',
        );
    }

    public function testTheClosureCarriesTheCapturedPidByValue(): void
    {
        $bin = self::binSource();

        $register = self::guardStart($bin);
        $arm = (int) strpos($bin, '$terminalWasRun = true;');
        $this->assertGreaterThan($register, $arm);
        $closureHead = substr($bin, $register, $arm - $register);

        $this->assertStringContainsString(
            'use (&$terminalWasRun, $installPid)',
            $closureHead,
            'the closure must capture $installPid by value — by-ref would let a child "correct" it, '
                . 'and its absence means the guard cannot tell whose shutdown this is',
        );
    }

    public function testTheGuardEarlyReturnsOnPidMismatchBeforeEmittingAnything(): void
    {
        $bin = self::binSource();

        $start = self::guardStart($bin);
        $end = (int) strpos($bin, '$terminalWasRun = true;');
        $guard = substr($bin, $start, $end - $start);

        $mismatch = strpos($guard, 'if (getmypid() !== $installPid) {');
        $this->assertIsInt(
            $mismatch,
            'no pid comparison in the guard body — every forked AgentWorkerPool child restores a '
                . 'terminal that was never its own to touch',
        );
        $firstEmission = strpos($guard, 'fwrite(STDOUT');
        $this->assertIsInt($firstEmission);
        $this->assertLessThan(
            $firstEmission,
            $mismatch,
            'the pid early-return must precede the DEC-off emissions; a restore that writes first '
                . 'and then thinks is the original defect',
        );
        $stderrEmission = strpos($guard, 'fwrite(STDERR');
        $this->assertIsInt($stderrEmission);
        $this->assertLessThan(
            $stderrEmission,
            $mismatch,
            'the pid early-return must precede the "terminal restored" stderr line too',
        );

        // The two early-returns are separate statements on purpose: the flag
        // guard stays byte-identical so the sibling class's flag-shape pin
        // ({@see BinInteractiveTtyGuardTest}) keeps discriminating clean exits.
        $this->assertStringContainsString('if (!$terminalWasRun)', $guard);
        $this->assertStringContainsString("if (getmypid() !== \$installPid) {\n        return;\n    }", $guard);
    }
}
