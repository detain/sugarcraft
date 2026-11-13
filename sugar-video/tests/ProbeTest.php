<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Video\Source\Probe;
use SugarCraft\Video\Source\VideoSource;

/**
 * Test double that pretends no FFmpeg binaries are installed.
 * Overrides the protected which() method to always return null,
 * enabling deterministic "binary absent" testing without modifying the host.
 *
 * @internal
 */
final class FakeProbe extends Probe
{
    /** @override */
    protected static function which(string $cmd): ?string
    {
        return null;
    }
}

/**
 * Unit tests for Probe binary-detection class.
 * Uses FakeProbe to simulate "binary absent" behavior — no real ffmpeg needed.
 *
 * @covers \SugarCraft\Video\Source\Probe
 */
final class ProbeTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Binary-absent path — uses FakeProbe to simulate missing binaries
    // -------------------------------------------------------------------------

    /**
     * @testdox hasFFmpeg() returns false when no ffmpeg binary is present
     */
    public function testHasFFmpegReturnsFalseWhenMissing(): void
    {
        // FakeProbe::hasFFmpeg() calls FakeProbe::which() which returns null.
        $this->assertFalse(FakeProbe::hasFFmpeg());
    }

    /**
     * @testdox ffmpeg() returns null when ffmpeg binary is absent
     */
    public function testFfmpegReturnsNullWhenMissing(): void
    {
        $this->assertNull(FakeProbe::ffmpeg());
    }

    /**
     * @testdox ffprobe() returns null when ffprobe binary is absent
     */
    public function testFfprobeReturnsNullWhenMissing(): void
    {
        $this->assertNull(FakeProbe::ffprobe());
    }

    /**
     * @testdox ffplay() returns null when ffplay binary is absent
     */
    public function testFfplayReturnsNullWhenMissing(): void
    {
        $this->assertNull(FakeProbe::ffplay());
    }

    // -------------------------------------------------------------------------
    // Binary-present path — uses real Probe so we hit actual which() logic
    // -------------------------------------------------------------------------

    /**
     * @testdox hasFFmpeg() returns true when ffmpeg binary is present (skipped if absent)
     */
    public function testHasFFmpegReturnsTrueWhenPresent(): void
    {
        if (Probe::ffmpeg() === null) {
            $this->markTestSkipped('ffmpeg not found on this host');
        }
        $this->assertTrue(Probe::hasFFmpeg());
    }

    /**
     * @testdox ffmpeg() returns a non-null path string when binary is present (skipped if absent)
     */
    public function testFfmpegReturnsPathWhenPresent(): void
    {
        if (Probe::ffmpeg() === null) {
            $this->markTestSkipped('ffmpeg not found on this host');
        }
        $result = Probe::ffmpeg();
        $this->assertNotNull($result);
        $this->assertIsString($result);
        $this->assertFileExists($result);
    }

    // -------------------------------------------------------------------------
    // SV-X94: Windows shape arms (host-independent via the isWindows seam)
    // -------------------------------------------------------------------------

    /**
     * @testdox the Windows path arm refuses extension-blind names and accepts .exe/.bat/.cmd
     */
    public function testWindowsPathArmRequiresAnExecutableExtension(): void
    {
        $dir = sys_get_temp_dir() . '/reel-x94-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        foreach (['ffmpeg.txt', 'ffmpeg.exe', 'ffmpeg.BAT', 'ffmpeg.cmd'] as $name) {
            file_put_contents($dir . '/' . $name, "stub\n");
            chmod($dir . '/' . $name, 0755);
        }

        try {
            $this->assertNull(WindowsShapeProbe::locate($dir . '/ffmpeg.txt'), 'a stat-executable .txt is NOT a binary on Windows');
            $this->assertSame($dir . '/ffmpeg.exe', WindowsShapeProbe::locate($dir . '/ffmpeg.exe'));
            $this->assertSame($dir . '/ffmpeg.BAT', WindowsShapeProbe::locate($dir . '/ffmpeg.BAT'), 'extension match is case-insensitive');
            $this->assertSame($dir . '/ffmpeg.cmd', WindowsShapeProbe::locate($dir . '/ffmpeg.cmd'));
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    /**
     * @testdox outside a cmd-family ComSpec the Windows PATH probe refuses instead of misfiring
     */
    public function testWindowsProbeRefusesOnNonCmdShellFamily(): void
    {
        $saved = getenv('ComSpec');
        putenv('ComSpec'); // unset -> PowerShell-default shape
        Probe::clearBinaryCacheForTesting();
        WindowsShapeProbe::$shells = [];

        try {
            $this->assertNull(WindowsShapeProbe::locate('ffmpeg'));
            $this->assertSame([], WindowsShapeProbe::$shells, 'no shell may be launched when the family cannot parse it');
        } finally {
            putenv($saved === false ? 'ComSpec' : 'ComSpec=' . $saved);
            Probe::clearBinaryCacheForTesting();
        }
    }

    /**
     * @testdox under a cmd-family ComSpec the Windows PATH probe runs where.exe with the NUL redirect
     */
    public function testWindowsCmdFamilyRunsWhereExe(): void
    {
        $saved = getenv('ComSpec');
        putenv('ComSpec=C:\\Windows\\System32\\cmd.exe');
        Probe::clearBinaryCacheForTesting();
        WindowsShapeProbe::$shells = [];

        try {
            $this->assertNull(WindowsShapeProbe::locate('ffmpeg'), 'the taped seam answered empty');
            $this->assertCount(1, WindowsShapeProbe::$shells);
            $this->assertMatchesRegularExpression('/^where\.exe .?ffmpeg.? 2>NUL$/', WindowsShapeProbe::$shells[0]);
        } finally {
            putenv($saved === false ? 'ComSpec' : 'ComSpec=' . $saved);
            Probe::clearBinaryCacheForTesting();
        }
    }

    /**
     * @testdox the Linux path leg is byte-unchanged (a chmod-ed file answers even without a known extension)
     */
    public function testLinuxPathArmIsUnchanged(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Unix-leg pin — runs only on the non-Windows host');
        }

        $dir = sys_get_temp_dir() . '/reel-x94u-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        file_put_contents($dir . '/ffmpeg', "#!/bin/sh\nexit 0\n");
        chmod($dir . '/ffmpeg', 0755);

        try {
            $this->assertSame($dir . '/ffmpeg', LinuxShapeProbe::locate($dir . '/ffmpeg'));
        } finally {
            @unlink($dir . '/ffmpeg');
            @rmdir($dir);
        }
    }
}

/**
 * SV-X94 fixture: Windows OS-shape regardless of the real host, with the raw
 * shell seam recorded and answered empty. Proves the detection arms' shape on
 * any runner; the Linux legs stay covered by the real-which() tests above.
 *
 * @internal
 */
final class WindowsShapeProbe extends Probe
{
    /** @var list<string> */
    public static array $shells = [];

    protected static function isWindows(): bool
    {
        return true;
    }

    protected static function runShellLookup(string $shell): ?string
    {
        self::$shells[] = $shell;

        return '';
    }

    public static function locate(string $cmd): ?string
    {
        return static::which($cmd);
    }
}

/**
 * SV-X94 counterpart: explicit host-shape passthrough exposing the protected
 * which() for the unchanged-Linux-leg pin.
 *
 * @internal
 */
final class LinuxShapeProbe extends Probe
{
    public static function locate(string $cmd): ?string
    {
        return static::which($cmd);
    }
}
