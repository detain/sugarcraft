<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Windows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Support\WindowsShell;
use SugarCraft\Code\Tests\Support\SuiteSkipRoster;
use SugarCraft\Code\Tools\BuiltIn\Bash;

/**
 * The C-TUI-6.d Windows smoke: the Bash tool driven END-TO-END on a real
 * Windows runner, over the exact §6.3 law the 6.a layer encodes — Git-for-
 * Windows bash.exe located by the PATH ladder, the script handed over as ONE
 * argv element, and the `taskkill /T /F` tree collecting everything the
 * command backgrounded.
 *
 * On any host that is not Windows both rows skip under
 * {@see SuiteSkipRoster::windowsSmokeRuns()}, rostered as the two
 * WINDOWS_BASH_* keys — dormant skips, never silent absences. The assertions
 * themselves are UNCONFIRMED-BY-RUN on the Linux development box: this file
 * is the first real measurement, which is exactly what the windows-smoke CI
 * job exists to take. The quoting and locate semantics are NOT re-pinned
 * here — tests/Support/PosixShellQuoteTest.php and WindowsShellTest.php run
 * those live on every host via the 6.a seams; this class is the seam-free
 * end-to-end proof only.
 *
 * @see WindowsShell
 * @see Bash
 */
final class WindowsBashSmokeTest extends TestCase
{
    /**
     * The no-cmd.exe law, measured on the real thing: a string carrying the
     * two shapes cmd.exe WOULD rewrite (`%PATH%` expansion, a bare `!`) comes
     * back VERBATIM because the interpreter is bash, and the exit is clean.
     * A cmd.exe fallback would print the whole PATH here instead.
     */
    public function testBashAnswersAGitForWindowsLineVerbatim(): void
    {
        if (!SuiteSkipRoster::windowsSmokeRuns()) {
            self::markTestSkipped(
                'the Windows smoke legs drive a real Git-for-Windows bash.exe, which only exists '
                    . 'on the windows-smoke CI runner',
            );
        }

        if (WindowsShell::bash() === '') {
            self::fail(
                'the runner has no bash.exe on PATH or under %ProgramFiles%\Git\bin — the smoke '
                    . 'job must install Git for Windows (the Bash tool would refuse the same way)',
            );
        }

        $result = (new Bash())->execute([
            'command' => 'echo "%PATH%" !x',
            'description' => 'Windows verbatim quoting smoke',
            'timeout' => 30,
        ]);

        self::assertFalse($result->isError(), $result->content());
        self::assertStringContainsString('%PATH%', $result->content(), 'cmd syntax stays literal');
        self::assertStringContainsString('!x', $result->content(), 'the history-shape byte survives');
        self::assertStringNotContainsString(
            'C:\\Windows',
            $result->content(),
            'a cmd.exe expansion of %PATH% would have dumped the system root',
        );
    }

    /**
     * The tree law, measured on the real thing: a command that backgrounds a
     * grandchild and then waits must die WHOLE at its timeout — head-kill
     * alone would leave `wait` blocked until the 40 s sleep ends, and a
     * group-blind kill would leave sleep.exe visible in tasklist. Both
     * failure shapes are asserted away: the call returns inside its own
     * bound, and no sleep.exe survives it.
     */
    public function testTheTimeoutKillsTheWholeProcessTree(): void
    {
        if (!SuiteSkipRoster::windowsSmokeRuns()) {
            self::markTestSkipped(
                'the Windows smoke legs need the real taskkill tree ladder, which only exists '
                    . 'on the windows-smoke CI runner',
            );
        }

        $started = microtime(true);
        $result = (new Bash())->execute([
            'command' => 'sleep 40 & sleep 40 & wait',
            'description' => 'Windows tree-kill smoke',
            'timeout' => 2,
        ]);
        $elapsed = microtime(true) - $started;

        self::assertTrue($result->isError(), 'a timed-out run answers as an error');
        self::assertStringContainsString('[timed out after 2 s', $result->content());
        self::assertStringContainsString('process group were killed', $result->content());
        self::assertLessThan(
            20.0,
            $elapsed,
            'the call returned well before the 40 s grandchildren could finish on their own — '
                . 'if only the head died, wait() would have blocked this leg for ~40 s',
        );

        // The survivors check: tasklist is the Windows-native census. Serial
        // CI means any sleep.exe still visible here is ours, un-reaped.
        $pipes = [];
        $probe = proc_open(
            ['tasklist', '/FI', 'IMAGENAME eq sleep.exe', '/FO', 'CSV', '/NH'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($probe, 'tasklist must start on a Windows runner');
        fclose($pipes[0]);
        $census = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($probe);

        self::assertStringNotContainsString(
            'sleep.exe',
            $census,
            'the timeout tree-kill left a grandchild alive — taskkill /T /F did not collect it',
        );
    }
}
