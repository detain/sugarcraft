<?php

declare(strict_types=1);

namespace SugarCraft\Video\Source;

use SugarCraft\Core\Util\Proc\BoundedShutdown;
use SugarCraft\Video\Support\FfmpegCommandBuilder;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Video\AudioPlayer;
use SugarCraft\Video\Lang;

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
 * A LOCAL file that ffprobe chokes on outright (non-zero exit, no salvageable
 * video stream) is the loud exception to that rule: probe throws rather than
 * handing Player::open() a zeroed VO that plays as a blank screen (M3).
 *
 * A hung ffprobe (a stalled remote URL, a pathological file) yields the same
 * zeroed default after {@see self::PROBE_TIMEOUT_SECONDS} rather than blocking forever.
 *
 * Mirrors the metadata shape used by maxcurzi/tplay and joelibaceta/video-to-ascii.
 */
final class VideoSource
{
    /**
     * Wall-clock ceiling for a single `ffprobe` invocation before the probe is
     * abandoned (child killed, empty default returned). Comfortably longer than
     * any healthy local probe, short enough that the UI never appears wedged.
     */
    private const PROBE_TIMEOUT_SECONDS = 10.0;

    /**
     * How long a drained-but-still-running ffprobe gets to exit on its own
     * before the reaper escalates — bounds proc_close() the way
     * {@see self::PROBE_TIMEOUT_SECONDS} bounds the stdout read.
     */
    private const PROBE_EXIT_GRACE_SECONDS = 1.0;

    /**
     * Hard ceiling on drained ffprobe stdout — a pathological or hostile
     * probe emitting endlessly cannot pin unbounded bytes in the parent.
     */
    private const MAX_PROBE_OUTPUT_BYTES = 8 * 1024 * 1024;

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

            // m10: a cover-art stream (attached_pic) must never shadow the real
        // video track — the FIRST non-cover video stream answers, later ones
        // (including a trailing cover) are ignored.
        if ($codecType === 'video' && $width === 0 && !(((int) ($stream['disposition']['attached_pic'] ?? $stream['attached_pic'] ?? 0)) === 1)) {
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
     *
     * @param string $path Absolute or relative path to the video file
     * @param bool   $hostRestricted true when the calling Reel carries a bound
     *        host allowlist: an http(s) probe then pins `-max_redirects 0`
     *        (same SSRF transport law as the decoder — see
     *        {@see FfmpegCommandBuilder::ALLOWED_MAX_REDIRECTS}), so even the
     *        metadata child cannot be redirected out of the allowlist.
     */
    public static function probe(string $path, bool $hostRestricted = false): self
    {
        $ffprobe = Probe::ffprobe();
        if ($ffprobe === null) {
            // m15: the zeroed default used to be dead silent — hosts could not
            // tell "no ffprobe" from "no metadata". Announce once per process.
            static $announced = false;
            if (!$announced) {
                $announced = true;
                error_log(Lang::t('source.probe_missing'));
            }

            return new self($path, 0, 0, 0.0, 0.0, false);
        }

        // M9: playback speaks exactly two source shapes — local files and
        // http(s) URLs. Anything option-shaped ('-flag') would be parsed as
        // an ffprobe OPTION, and exotic protocols (rtsp/tcp/udp/concat/pipe:)
        // would be DIALED OUTBOUND by the probe before the player-side
        // refusal ever runs; neither reaches the child here.
        if (!FfmpegCommandBuilder::isNetworkSource($path) && (str_starts_with($path, '-') || !is_file($path))) {
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
        ];
        if ($hostRestricted && FfmpegCommandBuilder::isNetworkSource($path)) {
            array_push($cmd, '-max_redirects', (string) FfmpegCommandBuilder::ALLOWED_MAX_REDIRECTS);
        }
        $cmd[] = $path;

        $devNull = DIRECTORY_SEPARATOR === '\\' ? '\\\\.\\NUL' : '/dev/null';
        $descriptorSpec = [
            0 => ['file', $devNull, 'r'],  // stdin — unused
            1 => ['pipe', 'w'],             // stdout — read result
            2 => ['file', $devNull, 'w'],  // stderr — discard
        ];

        // M8: ffprobe gets the same scrubbed env as every other media child.
        $process = proc_open($cmd, $descriptorSpec, $pipes, null, FfmpegCommandBuilder::scrubbedEnv());
        if (!is_resource($process)) {
            return new self($path, 0, 0, 0.0, 0.0, false);
        }

        // Only descriptor 1 (stdout) is a pipe; descriptors 0 and 2 are
        // file-backed (/dev/null) and are NOT present in $pipes.
        //
        // Why the probe is fail-closed: stream_get_contents() would block
        // until ffprobe closes stdout. On a stalled remote URL, a corrupt file
        // that keeps ffprobe probing, or a hung network mount, that never
        // happens — and Player::open() calls probe() synchronously, so the whole
        // UI freezes before the first frame ever renders. We drain stdout under
        // a monotonic (hrtime) deadline instead: on overrun the child is killed and the
        // probe returns the empty default (the same "unknown source" shape an
        // absent ffprobe already yields), so playback degrades instead of hanging.
        $stdout = self::drainWithTimeout($pipes[1], self::PROBE_TIMEOUT_SECONDS);
        fclose($pipes[1]);

        if ($stdout === null) {
            // Timed out: escalate-kill the wedged ffprobe before the shared
            // reap below, so no orphan survives the failed probe.
            BoundedShutdown::terminateAndAwaitExit($process);
        } else {
            // EOF on stdout is not proof the child is gone: an ffprobe that
            // printed its JSON then wedged before exiting would block the
            // proc_close() below unboundedly — the very freeze the drain just
            // removed from the read side. Give it a short grace to exit
            // naturally, then escalate; a killed child returns non-zero and
            // fails closed even though bytes arrived.
            $exitDeadline = hrtime(true) / 1e9 + self::PROBE_EXIT_GRACE_SECONDS;
            while (
                (proc_get_status($process)['running'] ?? false)
                && hrtime(true) / 1e9 < $exitDeadline
            ) {
                usleep(10_000);
            }
            if (proc_get_status($process)['running'] ?? false) {
                BoundedShutdown::terminateAndAwaitExit($process);
            }
        }

        // Single unconditional reap — deliberately at function-body level so
        // tools/check-child-lifetimes.php can PROVE it covers every path out
        // of probe() (the scanner reads a close inside a branch as unproven).
        $exitCode = proc_close($process);

        if ($stdout === null || $exitCode !== 0 || $stdout === '') {
            // M3: a LOCAL file that exists, was drained without timing out, and
            // yields no usable video stream is a hard input error — the old
            // zeroed default sent Player::open() into a blank "playing nothing"
            // session. Fail loud and typed instead. A timeout (stdout null)
            // keeps the degrade-to-default contract pinned by the probe tests;
            // an exit-0 partial JSON with a usable video stream keeps playing
            // (salvageable damage is ffmpeg's own judgment, not ours).
            if ($stdout !== null && $exitCode !== 0 && is_file($path)) {
                $salvaged = self::fromFfprobeJson($path, $stdout);
                if ($salvaged->width === 0) {
                    throw new \RuntimeException(Lang::t('source.unparseable', [
                        'code' => (string) $exitCode,
                        'source' => mb_strcut(trim(Sanitize::cellValue(AudioPlayer::redactCredentials($path))), 0, 160),
                    ]));
                }
            }
            return new self($path, 0, 0, 0.0, 0.0, false);
        }

        return self::fromFfprobeJson($path, $stdout);
    }

    /**
     * Read a child's stdout in full, but never block longer than $timeoutSeconds.
     *
     * Returns the collected output on a clean EOF, or null when the deadline
     * passed first (the child is still producing nothing). Non-blocking reads +
     * stream_select keep this compatible with a pipe that drains in pieces.
     * The deadline runs on hrtime(), not the wall clock: an NTP step must never
     * truncate the bound and kill a healthy ffprobe mid-drain.
     *
     * @param resource $pipe
     */
    private static function drainWithTimeout($pipe, float $timeoutSeconds): ?string
    {
        stream_set_blocking($pipe, false);
        $deadline = hrtime(true) / 1e9 + $timeoutSeconds;
        $buffer = '';

        while (true) {
            $chunk = fread($pipe, 65536);
            if ($chunk !== false && $chunk !== '') {
                $buffer .= $chunk;
                if (strlen($buffer) > self::MAX_PROBE_OUTPUT_BYTES) {
                    return null;
                }
                continue;
            }

            $meta = stream_get_meta_data($pipe);
            if ($meta['eof'] ?? false) {
                return $buffer;
            }

            $remaining = $deadline - hrtime(true) / 1e9;
            if ($remaining <= 0.0) {
                return null;
            }

            // Nothing readable right now: wait a bounded slice, then re-check.
            // stream_select returning 0 is a slice timeout (loop); false is an
            // error; a child that died without more output reaches EOF next pass.
            $read = [$pipe];
            $write = null;
            $except = null;
            $seconds = (int) floor($remaining);
            // round() can land on exactly 1_000_000, which stream_select
            // rejects (tv_usec ≤ 999_999) and reports as an error — that would
            // abort the whole bounded drain early (fail-closed); clamp instead.
            $micros = (int) min(999_999, round(($remaining - $seconds) * 1_000_000));
            if (@stream_select($read, $write, $except, $seconds, $micros) === false) {
                return null;
            }
        }
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
