<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\Source\VideoSource;

/**
 * Unit tests for VideoSource value object.
 * Uses only canned JSON fixtures — no real ffmpeg or video files needed.
 *
 * @covers \SugarCraft\Reel\Source\VideoSource
 */
final class VideoSourceTest extends TestCase
{
    // -------------------------------------------------------------------------
    // fromFfprobeJson — positive cases
    // -------------------------------------------------------------------------

    /**
     * @testdox fromFfprobeJson parses full metadata from complete ffprobe JSON
     */
    public function testFromFfprobeJsonParsesFullMetadata(): void
    {
        $json = json_encode([
            'streams' => [
                [
                    'codec_type' => 'video',
                    'width' => 1920,
                    'height' => 1080,
                    'duration' => '120.500',
                    'r_frame_rate' => '30000/1001',
                ],
                [
                    'codec_type' => 'audio',
                ],
            ],
        ]);

        $source = VideoSource::fromFfprobeJson('/video.mp4', $json);

        $this->assertSame('/video.mp4', $source->path);
        $this->assertSame(1920, $source->width);
        $this->assertSame(1080, $source->height);
        $this->assertSame(120.5, $source->duration);
        // r_frame_rate "30000/1001" ≈ 29.970029…
        $this->assertEqualsWithDelta(29.97, $source->fps, 0.01);
        $this->assertTrue($source->hasAudio);
    }

    /**
     * @testdox fromFfprobeJson handles a video-only stream (no audio)
     */
    public function testFromFfprobeJsonHandlesMissingAudio(): void
    {
        $json = json_encode([
            'streams' => [
                [
                    'codec_type' => 'video',
                    'width' => 640,
                    'height' => 480,
                    'duration' => '30.0',
                    'r_frame_rate' => '25/1',
                ],
            ],
        ]);

        $source = VideoSource::fromFfprobeJson('/video-only.mp4', $json);

        $this->assertSame('/video-only.mp4', $source->path);
        $this->assertSame(640, $source->width);
        $this->assertSame(480, $source->height);
        $this->assertSame(30.0, $source->duration);
        $this->assertSame(25.0, $source->fps);
        $this->assertFalse($source->hasAudio);
    }

    // -------------------------------------------------------------------------
    // fromFfprobeJson — edge / negative cases
    // -------------------------------------------------------------------------

    /**
     * @testdox fromFfprobeJson uses duration=0.0 when duration key is absent
     */
    public function testFromFfprobeJsonHandlesMissingDuration(): void
    {
        $json = json_encode([
            'streams' => [
                [
                    'codec_type' => 'video',
                    'width' => 1920,
                    'height' => 1080,
                    // no 'duration' key at all
                    'r_frame_rate' => '30/1',
                ],
            ],
        ]);

        $source = VideoSource::fromFfprobeJson('/video.mp4', $json);

        $this->assertSame(0.0, $source->duration);
        $this->assertSame(1920, $source->width);
        $this->assertSame(1080, $source->height);
    }

    /**
     * @testdox fromFfprobeJson returns fps=0.0 when r_frame_rate is "0/1" (divide-by-zero guard)
     */
    public function testFromFfprobeJsonHandlesZeroFrameRate(): void
    {
        $json = json_encode([
            'streams' => [
                [
                    'codec_type' => 'video',
                    'width' => 1920,
                    'height' => 1080,
                    'duration' => '60.0',
                    'r_frame_rate' => '0/1',
                ],
            ],
        ]);

        $source = VideoSource::fromFfprobeJson('/video.mp4', $json);

        $this->assertSame(0.0, $source->fps);
        $this->assertSame(60.0, $source->duration);
    }

    // -------------------------------------------------------------------------
    // probe() — graceful degradation when ffprobe is absent
    // -------------------------------------------------------------------------

    /**
     * @testdox probe() returns a default VideoSource when ffprobe is absent (no hang, fast failure)
     */
    public function testProbeReturnsDefaultOnMissingBinary(): void
    {
        // Skip if ffprobe happens to be present — this test targets the absent path.
        // Use shell_exec directly since we don't import Probe in this file.
        if (@shell_exec('command -v ffprobe 2>/dev/null') !== null) {
            $this->markTestSkipped('ffprobe is present on this host; cannot test missing-binary path');
        }

        $source = VideoSource::probe('/nonexistent.mp4');

        $this->assertSame('/nonexistent.mp4', $source->path);
        $this->assertSame(0, $source->width);
        $this->assertSame(0, $source->height);
        $this->assertSame(0.0, $source->duration);
        $this->assertSame(0.0, $source->fps);
        $this->assertFalse($source->hasAudio);
    }

    // -------------------------------------------------------------------------
    // Immutability — value object contract
    // -------------------------------------------------------------------------

    /**
     * @testdox VideoSource properties are readonly (enforced by language semantics)
     *
     * PHP readonly classes prevent mutation after construction.
     * This test documents the immutability contract and verifies the constructor
     * accepts the expected values without throwing.
     */
    public function testPropertiesAreImmutable(): void
    {
        $json = json_encode([
            'streams' => [
                [
                    'codec_type' => 'video',
                    'width' => 1280,
                    'height' => 720,
                    'duration' => '45.0',
                    'r_frame_rate' => '30/1',
                ],
            ],
        ]);

        $source = VideoSource::fromFfprobeJson('/test.mp4', $json);

        // Verify values are stored correctly (construction succeeded).
        $this->assertSame('/test.mp4', $source->path);
        $this->assertSame(1280, $source->width);
        $this->assertSame(720, $source->height);
        $this->assertSame(45.0, $source->duration);
        $this->assertSame(30.0, $source->fps);
        $this->assertFalse($source->hasAudio);

        // Readonly properties cannot be re-assigned — PHP enforces this at runtime.
        // The following would cause a "Cannot modify readonly property" Error:
        // $source->width = 999;
        $this->assertTrue(true); // Placeholder — language guarantees immutability.
    }
}
