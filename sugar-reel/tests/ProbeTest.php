<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\Source\Probe;
use SugarCraft\Reel\Source\VideoSource;

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
 * @covers \SugarCraft\Reel\Source\Probe
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
}
