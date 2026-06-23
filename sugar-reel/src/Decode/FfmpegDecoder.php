<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Decode;

use SugarCraft\Reel\Render\Mode;
use SugarCraft\Reel\Source\Probe;

/**
 * Ffmpeg-based video decoder using proc_open with a raw rgb24 pipe.
 *
 * For HalfBlock mode: ffmpeg scales to cellsH*2 rows so each terminal cell
 * maps to 2 source rows. One frame = cellsW * cellsH * 2 * 3 bytes.
 * For other modes: ffmpeg scales to cellsH rows. One frame = cellsW * cellsH * 3 bytes.
 * All CLI args are passed via array to proc_open (no shell injection).
 * Partial frames at end-of-stream are silently discarded.
 *
 * ffmpeg's stderr is redirected straight to the OS null device (a file sink,
 * not a pipe). A reader-less stderr pipe deadlocks once ffmpeg fills the ~64KB
 * kernel buffer on noisy input — which then wedges our blocking fread(stdout) —
 * so we never hold an unread stderr pipe.
 *
 * The source may be a local path OR an http(s) URL — ffmpeg decodes a network
 * stream natively (so the console client can direct-play the server's signed
 * stream URL, bypassing any transcode). For URL sources the http/https protocol
 * reconnect options are passed so a momentary drop does not abort playback.
 *
 * @see video_plan.md lines 79-82
 * @implements Decoder
 */
final class FfmpegDecoder implements Decoder
{
    /** @var resource|\Process|null */
    private $process = null;

    /** @var resource|null */
    private $stdout = null;

    private int $cellsW = 0;
    private int $cellsH = 0;
    private int $frameBytes = 0;
    private int $frameH = 0;

    /**
     * @inheritDoc
     */
    public function open(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0): void
    {
        $this->cellsW = $cellsW;
        $this->cellsH = $cellsH;

        // Scale the frame height by rowsPerCell: HalfBlock packs 2 source rows
        // per cell (cellsH*2); the 1-row modes scale to cellsH. $mode === null
        // defaults to 2 (HalfBlock), matching DecoderFactory's null-default.
        $this->frameH = $cellsH * ($mode?->rowsPerCell() ?? 2);
        $this->frameBytes = $cellsW * $this->frameH * 3;

        $ffmpegPath = Probe::ffmpeg();
        if ($ffmpegPath === null) {
            throw new \RuntimeException('ffmpeg not found on this host');
        }

        // Build command as array — never a shell string.
        // No escaping needed; proc_open passes args directly with no shell.
        $cmd = self::buildCommand($ffmpegPath, $source, $this->cellsW, $this->frameH, $fps, $startSec);

        // stderr goes to a file sink (the OS null device), never a pipe — an
        // unread stderr pipe deadlocks ffmpeg once its ~64KB buffer fills.
        $devNull = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';

        $descriptorSpec = [
            ['pipe', 'r'],            // stdin
            ['pipe', 'w'],            // stdout
            ['file', $devNull, 'w'],  // stderr → sink
        ];

        $this->process = proc_open($cmd, $descriptorSpec, $pipes);
        if (!is_resource($this->process)) {
            throw new \RuntimeException('Failed to start ffmpeg process');
        }

        $this->stdout = $pipes[1];
        // Close stdin as we don't write to it
        if (is_resource($pipes[0])) {
            \fclose($pipes[0]);
        }
    }

    /**
     * Assemble the ffmpeg argv as an array (never a shell string — proc_open
     * passes the args verbatim with no shell, so nothing needs escaping).
     *
     * For an http(s) source the http/https protocol reconnect options are
     * inserted BEFORE `-i` (they are input options) so a transient network drop
     * or a slow signed-URL response reconnects instead of ending the stream.
     * They are valid only for the network protocols, so a local path omits them
     * (ffmpeg rejects `-reconnect` on a file input).
     *
     * Static and pure (input → argv) so the assembly is unit-testable without
     * launching a subprocess.
     *
     * When $startSec > 0 a fast input seek (`-ss` BEFORE `-i`) decodes from the
     * keyframe at/just before that time without walking the whole file — what
     * makes scrubbing a multi-GB network stream instant (slightly less
     * frame-exact than output seeking, an acceptable trade for instant seeks).
     *
     * @return list<string>
     */
    public static function buildCommand(string $ffmpegPath, string $source, int $cellsW, int $frameH, float $fps, float $startSec = 0.0): array
    {
        $cmd = [$ffmpegPath, '-hide_banner', '-loglevel', 'error'];

        if (self::isNetworkSource($source)) {
            array_push(
                $cmd,
                '-reconnect', '1',
                '-reconnect_streamed', '1',
                '-reconnect_on_network_error', '1',
                '-reconnect_delay_max', '4',
            );
        }

        if ($startSec > 0.0) {
            array_push($cmd, '-ss', sprintf('%.3f', $startSec));
        }

        array_push(
            $cmd,
            '-i', $source,
            '-f', 'rawvideo',
            '-pix_fmt', 'rgb24',
            '-vf', sprintf('fps=%s,scale=%d:%d:flags=bilinear', (string) $fps, $cellsW, $frameH),
            '-',
        );

        return $cmd;
    }

    /**
     * Whether the source is an http(s) URL (vs a local file path). ffmpeg's
     * reconnect options apply only to the network protocols.
     */
    private static function isNetworkSource(string $source): bool
    {
        return preg_match('#^https?://#i', $source) === 1;
    }

    /**
     * @inheritDoc
     */
    public function next(): ?RgbFrame
    {
        if ($this->stdout === null || !is_resource($this->stdout)) {
            return null;
        }

        $frameBytes = '';
        $bytesRead = 0;

        // Read until we have a complete frame or reach EOF.
        // Handle incomplete frames due to ffmpeg flushing.
        while ($bytesRead < $this->frameBytes) {
            $chunk = fread($this->stdout, $this->frameBytes - $bytesRead);
            if ($chunk === false || $chunk === '') {
                // EOF or error — check if we have a complete frame
                if ($bytesRead === 0) {
                    return null; // No more data
                }
                // Incomplete last frame — discard it
                if ($bytesRead < $this->frameBytes) {
                    return null;
                }
                break;
            }
            $frameBytes .= $chunk;
            $bytesRead += strlen($chunk);
        }

        // Discard incomplete frames
        if ($bytesRead < $this->frameBytes) {
            return null;
        }

        return new RgbFrame($frameBytes, $this->cellsW, $this->frameH);
    }

    /**
     * @inheritDoc
     */
    public function getIterator(): \Generator
    {
        while (($frame = $this->next()) !== null) {
            yield $frame;
        }
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        if ($this->stdout !== null && is_resource($this->stdout)) {
            \fclose($this->stdout);
            $this->stdout = null;
        }

        if ($this->process !== null && is_resource($this->process)) {
            $exitCode = proc_close($this->process);
            $this->process = null;

            // If ffmpeg exited non-zero (and we didn't already consume all frames),
            // that indicates an error. We don't throw here since next() returning
            // null will signal end of stream to the caller.
        }
    }
}
