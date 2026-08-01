<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\Source\VideoSource;
use SugarCraft\Reel\Tests\Concerns\HidesPathBinaries;

/**
 * Unit tests for VideoSource value object.
 * Mostly canned JSON fixtures; one guarded test exercises the live ffprobe
 * proc_open path (skips when ffmpeg/ffprobe are unavailable).
 *
 * @covers \SugarCraft\Reel\Source\VideoSource
 */
final class VideoSourceTest extends TestCase
{
    use HidesPathBinaries;

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
        // Hide ffprobe from PATH so the missing-binary default path runs
        // even on hosts where the toolchain is installed.
        $source = $this->withoutPathBinaries(
            static fn (): VideoSource => VideoSource::probe('/nonexistent.mp4'),
        );

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

    // -------------------------------------------------------------------------
    // probe — live ffprobe path (regression guard)
    // -------------------------------------------------------------------------

    /**
     * @testdox probe() reads real metadata via ffprobe (live proc_open path)
     */
    public function testProbeReadsRealVideoViaFfprobe(): void
    {
        exec('command -v ffmpeg', $foundFfmpeg, $rcFfmpeg);
        exec('command -v ffprobe', $foundFfprobe, $rcFfprobe);
        if ($rcFfmpeg !== 0 || $rcFfprobe !== 0) {
            $this->markTestSkipped('ffmpeg/ffprobe not available');
        }

        $file = tempnam(sys_get_temp_dir(), 'reel_probe_') . '.mp4';
        exec(sprintf(
            'ffmpeg -y -f lavfi -i testsrc=duration=1:size=320x240:rate=15 -pix_fmt yuv420p %s 2>/dev/null',
            escapeshellarg($file)
        ), $_, $rc);

        try {
            if ($rc !== 0 || !is_file($file) || filesize($file) === 0) {
                $this->markTestSkipped('could not synthesize a test video');
            }

            // Exercises the live proc_open path. The old code called
            // fclose($pipes[0]) / fclose($pipes[2]) on file-backed descriptors
            // that are absent from $pipes, raising a TypeError here.
            $source = VideoSource::probe($file);

            $this->assertSame(320, $source->width);
            $this->assertSame(240, $source->height);
            $this->assertEqualsWithDelta(15.0, $source->fps, 0.01);
            $this->assertGreaterThan(0.0, $source->duration);
            $this->assertFalse($source->hasAudio);
        } finally {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    // -------------------------------------------------------------------------
    // fromFfprobeJson — edge cases and parseFrameRate coverage
    // -------------------------------------------------------------------------

    /**
     * @testdox fromFfprobeJson returns defaults when JSON is null (json_decode failure)
     */
    public function testFromFfprobeJsonReturnsDefaultsOnNullJson(): void
    {
        // json_decode returns null on parse failure
        $source = VideoSource::fromFfprobeJson('/video.mp4', 'not valid json at all');

        $this->assertSame('/video.mp4', $source->path);
        $this->assertSame(0, $source->width);
        $this->assertSame(0, $source->height);
        $this->assertSame(0.0, $source->duration);
        $this->assertSame(0.0, $source->fps);
        $this->assertFalse($source->hasAudio);
    }

    /**
     * @testdox fromFfprobeJson returns defaults when streams is empty
     */
    public function testFromFfprobeJsonReturnsDefaultsOnEmptyStreams(): void
    {
        $json = json_encode(['streams' => []]);
        $source = VideoSource::fromFfprobeJson('/video.mp4', $json);

        $this->assertSame('/video.mp4', $source->path);
        $this->assertSame(0, $source->width);
        $this->assertSame(0, $source->height);
        $this->assertSame(0.0, $source->duration);
        $this->assertSame(0.0, $source->fps);
        $this->assertFalse($source->hasAudio);
    }

    /**
     * @testdox fromFfprobeJson returns defaults when streams key is missing
     */
    public function testFromFfprobeJsonReturnsDefaultsOnMissingStreams(): void
    {
        $json = json_encode(['format' => ['duration' => '100']]);
        $source = VideoSource::fromFfprobeJson('/video.mp4', $json);

        $this->assertSame('/video.mp4', $source->path);
        $this->assertSame(0, $source->width);
        $this->assertSame(0, $source->height);
        $this->assertSame(0.0, $source->duration);
        $this->assertSame(0.0, $source->fps);
        $this->assertFalse($source->hasAudio);
    }

    /**
     * @testdox fromFfprobeJson handles audio-only stream (no video track)
     */
    public function testFromFfprobeJsonHandlesAudioOnly(): void
    {
        $json = json_encode([
            'streams' => [
                ['codec_type' => 'audio', 'sample_rate' => '48000'],
            ],
        ]);
        $source = VideoSource::fromFfprobeJson('/audio.mp3', $json);

        $this->assertSame('/audio.mp3', $source->path);
        $this->assertSame(0, $source->width);
        $this->assertSame(0, $source->height);
        $this->assertSame(0.0, $source->duration);
        $this->assertSame(0.0, $source->fps);
        $this->assertTrue($source->hasAudio);
    }

    /**
     * @testdox fromFfprobeJson video stream with missing width uses default 0
     */
    public function testFromFfprobeJsonHandlesMissingWidth(): void
    {
        $json = json_encode([
            'streams' => [
                [
                    'codec_type' => 'video',
                    // no width
                    'height' => 720,
                    'duration' => '60.0',
                    'r_frame_rate' => '30/1',
                ],
            ],
        ]);
        $source = VideoSource::fromFfprobeJson('/video.mp4', $json);

        $this->assertSame(0, $source->width);
        $this->assertSame(720, $source->height);
    }

    /**
     * @testdox fromFfprobeJson video stream with missing height uses default 0
     */
    public function testFromFfprobeJsonHandlesMissingHeight(): void
    {
        $json = json_encode([
            'streams' => [
                [
                    'codec_type' => 'video',
                    'width' => 1920,
                    // no height
                    'duration' => '60.0',
                    'r_frame_rate' => '30/1',
                ],
            ],
        ]);
        $source = VideoSource::fromFfprobeJson('/video.mp4', $json);

        $this->assertSame(1920, $source->width);
        $this->assertSame(0, $source->height);
    }

    /**
     * @testdox fromFfprobeJson parseFrameRate handles empty string frame rate
     */
    public function testFromFfprobeJsonHandlesEmptyFrameRate(): void
    {
        $json = json_encode([
            'streams' => [
                [
                    'codec_type' => 'video',
                    'width' => 1920,
                    'height' => 1080,
                    'duration' => '60.0',
                    'r_frame_rate' => '',
                ],
            ],
        ]);
        $source = VideoSource::fromFfprobeJson('/video.mp4', $json);

        $this->assertSame(0.0, $source->fps);
    }

    /**
     * @testdox fromFfprobeJson parseFrameRate handles frame rate with no slash
     */
    public function testFromFfprobeJsonHandlesFrameRateWithoutSlash(): void
    {
        $json = json_encode([
            'streams' => [
                [
                    'codec_type' => 'video',
                    'width' => 1920,
                    'height' => 1080,
                    'duration' => '60.0',
                    'r_frame_rate' => '30', // no slash
                ],
            ],
        ]);
        $source = VideoSource::fromFfprobeJson('/video.mp4', $json);

        $this->assertSame(0.0, $source->fps);
    }

    /**
     * @testdox fromFfprobeJson parseFrameRate handles divide by zero (denominator 0)
     */
    public function testFromFfprobeJsonHandlesDivideByZero(): void
    {
        $json = json_encode([
            'streams' => [
                [
                    'codec_type' => 'video',
                    'width' => 1920,
                    'height' => 1080,
                    'duration' => '60.0',
                    'r_frame_rate' => '30/0', // division by zero
                ],
            ],
        ]);
        $source = VideoSource::fromFfprobeJson('/video.mp4', $json);

        $this->assertSame(0.0, $source->fps);
    }

    /**
     * @testdox fromFfprobeJson parseFrameRate computes 30000/1001 correctly (NTSC)
     */
    public function testFromFfprobeJsonHandlesNtscFrameRate(): void
    {
        $json = json_encode([
            'streams' => [
                [
                    'codec_type' => 'video',
                    'width' => 1920,
                    'height' => 1080,
                    'duration' => '60.0',
                    'r_frame_rate' => '30000/1001', // ~29.97 fps NTSC
                ],
            ],
        ]);
        $source = VideoSource::fromFfprobeJson('/video.mp4', $json);

        $this->assertEqualsWithDelta(29.97, $source->fps, 0.01);
    }

    /**
     * @testdox fromFfprobeJson extracts both video and audio from same stream set
     */
    public function testFromFfprobeJsonHandlesVideoAndAudioTogether(): void
    {
        $json = json_encode([
            'streams' => [
                [
                    'codec_type' => 'video',
                    'width' => 1280,
                    'height' => 720,
                    'duration' => '120.500',
                    'r_frame_rate' => '25/1',
                ],
                [
                    'codec_type' => 'audio',
                    'sample_rate' => '48000',
                ],
                [
                    'codec_type' => 'audio',
                    'sample_rate' => '44100',
                ],
            ],
        ]);
        $source = VideoSource::fromFfprobeJson('/video.mp4', $json);

        $this->assertSame(1280, $source->width);
        $this->assertSame(720, $source->height);
        $this->assertSame(120.5, $source->duration);
        $this->assertSame(25.0, $source->fps);
        $this->assertTrue($source->hasAudio, 'hasAudio should be true when any audio stream exists');
    }
}
