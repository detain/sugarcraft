<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Source;

/**
 * Immutable value object describing a video source probed from ffprobe JSON output.
 *
 * Examples:
 *
 * ```php
 * $source = VideoSource::probe('/path/to/video.mp4');
 * $source = VideoSource::fromFfprobeJson('/path/to/video.mp4', $jsonString);
 * ```
 *
 * When ffprobe is absent `probe()` returns a VideoSource with zero dimensions
 * and all numeric fields at 0.0 / false — playback will degrade gracefully
 * (GIF fallback will be used if available, or a clear error message shown).
 *
 * Mirrors the metadata shape used by maxcurzi/tplay and joelibaceta/video-to-ascii.
 */
final class VideoSource
{
    /**
     * @param string $path    Resolved file path (never empty after probe)
     * @param int    $width   Frame width in pixels
     * @param int    $height  Frame height in pixels
     * @param float  $duration Duration in seconds (float for sub-second precision)
     * @param float  $fps      Frames per second (float for fractional rates)
     * @param bool   $hasAudio True when the stream contains at least one audio track
     */
    public function __construct(
        public readonly string $path,
        public readonly int $width,
        public readonly int $height,
        public readonly float $duration,
        public readonly float $fps,
        public readonly bool $hasAudio,
    ) {
    }

    /**
     * Construct a VideoSource from a path and raw ffprobe JSON output.
     *
     * Expected JSON structure (simplified):
     * {
     *   "streams": [
     *     { "codec_type": "video", "width": 1920, "height": 1080,
     *       "duration": "120.500000", "r_frame_rate": "30/1" },
     *     { "codec_type": "audio", ... }
     *   ]
     * }
     *
     * @param string $path Absolute or relative path to the video file
     * @param string $json Raw JSON output from: ffprobe -v quiet -print_format json -show_format -show_streams <path>
     */
    public static function fromFfprobeJson(string $path, string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return new self($path, 0, 0, 0.0, 0.0, false);
        }

        $width = 0;
        $height = 0;
        $duration = 0.0;
        $fps = 0.0;
        $hasAudio = false;

        $streams = $data['streams'] ?? [];
        foreach ($streams as $stream) {
            $codecType = $stream['codec_type'] ?? '';

            if ($codecType === 'video') {
                $width = (int) ($stream['width'] ?? 0);
                $height = (int) ($stream['height'] ?? 0);
                $duration = (float) ($stream['duration'] ?? '0');
                $fps = self::parseFrameRate($stream['r_frame_rate'] ?? '0/1');
            }

            if ($codecType === 'audio') {
                $hasAudio = true;
            }
        }

        return new self($path, $width, $height, $duration, $fps, $hasAudio);
    }

    /**
     * Probe a video file using ffprobe and return a VideoSource.
     *
     * The ffprobe command run is:
     *   ffprobe -v quiet -print_format json -show_format -show_streams <path>
     *
     * If ffprobe is not available, returns a sensible empty/default object
     * (path unchanged, w=0, h=0, duration=0.0, fps=0.0, hasAudio=false).
     * All CLI arguments are safely escaped with escapeshellarg().
     *
     * @param string $path Absolute or relative path to the video file
     */
    public static function probe(string $path): self
    {
        $ffprobe = Probe::ffprobe();
        if ($ffprobe === null) {
            return new self($path, 0, 0, 0.0, 0.0, false);
        }

        $cmd = [
            $ffprobe,
            '-v',
            'quiet',
            '-print_format',
            'json',
            '-show_format',
            '-show_streams',
            $path,
        ];

        // Escape every argument to prevent shell injection.
        // array_map is safe here — each element is already a string from the array above.
        $cmd = array_map(escapeshellarg(...), $cmd);

        $descriptorSpec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open(implode(' ', $cmd), $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            return new self($path, 0, 0, 0.0, 0.0, false);
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode !== 0 || $stdout === false || $stdout === '') {
            return new self($path, 0, 0, 0.0, 0.0, false);
        }

        return self::fromFfprobeJson($path, $stdout);
    }

    /**
     * Parse an ffprobe r_frame_rate fraction string like "30/1" to a float.
     *
     * @param string $frameRate Fraction string, e.g. "30/1", "30000/1001", "0/1"
     */
    private static function parseFrameRate(string $frameRate): float
    {
        if ($frameRate === '' || $frameRate === '0/1') {
            return 0.0;
        }
        $parts = explode('/', $frameRate, 2);
        if (count($parts) !== 2) {
            return 0.0;
        }
        $numerator = (float) $parts[0];
        $denominator = (float) $parts[1];
        if ($denominator === 0.0) {
            return 0.0;
        }
        return $numerator / $denominator;
    }
}
