<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Tty;

use PHPUnit\Framework\TestCase;

/**
 * Fake-host coverage for {@see \SugarCraft\Core\Util\Tty\EnvDetect}
 * (finding sugar-core-065 remainder, sc-42).
 *
 * Each arm spawns a fresh `php env_detect_probe.php` child through
 * proc_open() with an explicitly-controlled environment (base env, minus
 * the six fakeable variables, plus this arm's overrides). Fresh process =
 * fresh `static $cached`, which is the ONLY way to see a positive WSL/Mintty/
 * Cygwin answer on a Linux box that is genuinely none of those: the memo is
 * function-local and deliberately has no in-process reset seam, because a
 * resettable platform detector is a switch a real host never flips.
 *
 * The probe's `control_env` echo is asserted before any judgement, so a
 * green row can never mean "the override failed to reach the child and the
 * default answer happened to match."
 */
final class EnvDetectTest extends TestCase
{
    /** Every arm's stdin is /dev/null: not a console, in every fake host shape. */
    private const CONSOLE_STDIN_EXPECT = false;

    public function testCleanNonWslLinuxHostDetectsNothing(): void
    {
        $result = $this->runProbe([]);

        // This is the arm that would be WRONG if the box were secretly WSL;
        // the probe reads the real osrelease and tells us which truth applies.
        $this->assertFalse($result['osrelease_microsoft'], 'this runner must be a clean non-WSL Linux host for the negative arms to mean anything');

        $this->assertFalse($result['is_wsl'], 'a Linux host with no WSL variables and no microsoft in osrelease is not WSL');
        $this->assertFalse($result['is_mintty']);
        $this->assertFalse($result['is_cygwin']);
    }

    public function testWslInteropVarSinglesWsl(): void
    {
        $result = $this->runProbe(['WSL_INTEROP' => '/run/ws/init/1']);

        $this->assertTrue($result['is_wsl'], 'WSL_INTEROP set means WSL regardless of osrelease');
        $this->assertFalse($result['is_mintty'], 'WSL is not a mintty session');
        $this->assertFalse($result['is_cygwin'], 'WSL is not cygwin');
    }

    public function testWslDistroNameVarSinglesWsl(): void
    {
        $result = $this->runProbe(['WSL_DISTRO_NAME' => 'Ubuntu']);

        $this->assertTrue($result['is_wsl'], 'WSL2 exports WSL_DISTRO_NAME even when WSL_INTEROP is absent');
    }

    public function testMsystemVarSinglesMinttyNotWsl(): void
    {
        $result = $this->runProbe(['MSYSTEM' => 'MINGW64']);

        $this->assertTrue($result['is_mintty'], 'MSYSTEM is the primary mintty/MSYS2 signal');
        $this->assertFalse($result['is_wsl'], 'Git-Bash on a real Windows host is not WSL');
        $this->assertFalse($result['is_cygwin'], 'MSYSTEM alone is not the cygwin signal');
    }

    public function testMinttyShortcutAndTermProgramBothSinglesMintty(): void
    {
        $viaShortcut = $this->runProbe(['MINTTY_SHORTCUT' => 'mintty.exe']);
        $viaTerm     = $this->runProbe(['TERM_PROGRAM' => 'mintty']);

        $this->assertTrue($viaShortcut['is_mintty'], 'MINTTY_SHORTCUT is set only by the mintty launcher');
        $this->assertTrue($viaTerm['is_mintty'], 'TERM_PROGRAM=mintty is the recognised session marker');
    }

    public function testOstypeCygwinSinglesCygwinAndIsPinned(): void
    {
        $cygwin  = $this->runProbe(['OSTYPE' => 'cygwin']);
        $linux   = $this->runProbe(['OSTYPE' => 'linux-gnu']);
        $unknown = $this->runProbe(['OSTYPE' => 'msys']);

        $this->assertTrue($cygwin['is_cygwin'], 'OSTYPE=cygwin is the sole cygwin signal');
        $this->assertFalse($cygwin['is_mintty'], 'legacy cygwin uses the OSTYPE arm, not MSYSTEM');
        $this->assertFalse($linux['is_cygwin'], 'a plain Linux OSTYPE is not cygwin');
        $this->assertFalse($unknown['is_cygwin'], 'msys is matched by neither the cygwin equality test nor mintty');
    }

    public function testStdinIsNeverAConsoleUnderTheHarness(): void
    {
        // Every arm runs with descriptor 0 pointed at /dev/null; that is the
        // one environmental constant across the whole matrix, and isConsole-
        // Stdin is the method the closed-descriptor family guards, so the
        // row proves the child really had a non-tty stdin while the env was
        // being faked around it.
        $result = $this->runProbe(['MSYSTEM' => 'MINGW64']);
        $this->assertSame(self::CONSOLE_STDIN_EXPECT, $result['is_console_stdin']);
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array{control_env:array<string,?string>, is_wsl:bool, is_mintty:bool, is_cygwin:bool, is_console_stdin:bool, osrelease_microsoft:bool, php_os_family:string}
     */
    private function runProbe(array $overrides): array
    {
        $resultFile = \tempnam(\sys_get_temp_dir(), 'envdetect-probe-');
        $probe      = __DIR__ . '/env_detect_probe.php';

        $this->assertIsString($resultFile);

        // Inherit the real environment, strip every variable an arm controls
        // so the only trace of it is the override we choose to add.
        $base = [];
        foreach (['WSL_INTEROP', 'WSL_DISTRO_NAME', 'MSYSTEM', 'MINTTY_SHORTCUT', 'TERM_PROGRAM', 'OSTYPE'] as $name) {
            $value = $overrides[$name] ?? null;
            if ($value !== null) {
                $base[] = $name . '=' . $value;
            }
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = \proc_open(
            [\PHP_BINARY, $probe, $resultFile],
            $descriptors,
            $pipes,
            \dirname($probe),
            $base,
        );

        try {
            if (!\is_resource($process)) {
                $this->fail('could not start the env-detect probe child');
            }

            // Drain both pipes before waiting, so a chatty child can never
            // block on a full pipe we forgot to read.
            $stdout = \stream_get_contents($pipes[1]);
            \fclose($pipes[1]);
            $stderr = \stream_get_contents($pipes[2]);
            \fclose($pipes[2]);

            $status = \proc_close($process);
            $this->assertSame(0, $status, "probe child exited $status; stdout=$stdout stderr=$stderr");

            $json = \file_get_contents($resultFile);
            $this->assertIsString($json, 'the probe wrote no result file');

            $decoded = \json_decode($json, true);
            $this->assertIsArray($decoded, 'the probe result was not valid JSON: ' . $json);

            $this->assertControlEnv($decoded['control_env'], $overrides);

            return $decoded;
        } finally {
            @unlink($resultFile);
        }
    }

    /**
     * The override must have landed as the child saw it, and every variable
     * this arm did NOT set must be genuinely absent. Without this the whole
     * matrix could pass while silently doing nothing.
     *
     * @param array<string,?string> $control
     * @param array<string,string>  $overrides
     */
    private function assertControlEnv(array $control, array $overrides): void
    {
        foreach (['WSL_INTEROP', 'WSL_DISTRO_NAME', 'MSYSTEM', 'MINTTY_SHORTCUT', 'TERM_PROGRAM', 'OSTYPE'] as $name) {
            $expected = $overrides[$name] ?? null;
            $this->assertArrayHasKey($name, $control, "control_env is missing $name — the probe stopped echoing its own environment");
            $this->assertSame(
                $expected,
                $control[$name],
                "the child's \$$name did not match the requested value (null means 'absent'), so this arm measured a host we did not ask for",
            );
        }
    }
}
