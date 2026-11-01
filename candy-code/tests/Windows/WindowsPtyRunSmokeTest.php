<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Windows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Support\ProcessContainment;
use SugarCraft\Code\Tests\Support\SuiteSkipRoster;
use SugarCraft\Code\Tools\BuiltIn\PtyRun;

/**
 * The C-TUI-6.d Windows smoke: PtyRun driven END-TO-END over a REAL ConPTY
 * allocation on a Windows runner — the whole point of the PtyBackend seam.
 * The command runs through the Git-for-Windows bash.exe leg of
 * {@see ProcessContainment::interactiveSpawnCommand()}, so `read` sees the
 * keystroke the step sends and the echoed answer proves the master-side
 * write and the master-side read are the same live pipe.
 *
 * Off Windows the row skips under {@see SuiteSkipRoster::windowsSmokeRuns()},
 * rostered as WINDOWS_PTYRUN_CONPTY — a dormant skip, never a silent
 * absence. The assertion is UNCONFIRMED-BY-RUN on the Linux development box
 * by construction (ConPTY is a Windows API); the windows-smoke CI job is its
 * first real execution. The POSIX parity of this exact drive shape is
 * already proven live on every host by tests/Tools/PtyRunToolTest.php.
 *
 * @see PtyRun
 * @see ProcessContainment
 */
final class WindowsPtyRunSmokeTest extends TestCase
{
    public function testPtyRunDrivesAReadPromptOverConPty(): void
    {
        if (!SuiteSkipRoster::windowsSmokeRuns()) {
            self::markTestSkipped(
                'the ConPTY leg allocates a Windows pseudoconsole, which only the windows-smoke '
                    . 'CI runner can provide',
            );
        }

        $result = (new PtyRun())->execute([
            'command' => 'read x; echo "got:$x"',
            'description' => 'Windows ConPTY smoke',
            'timeout' => 30,
            'cols' => 90,
            'rows' => 28,
            'steps' => [
                ['send' => 'hello<Enter>', 'expect' => 'got:hello', 'timeout' => 12000],
            ],
        ]);

        self::assertFalse($result->isError(), $result->content());
        self::assertStringContainsString('got:hello', $result->content());
        self::assertStringContainsString('exited code=0', $result->content());
    }
}
