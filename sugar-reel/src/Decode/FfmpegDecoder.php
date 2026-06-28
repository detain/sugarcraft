<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Decode;

use SugarCraft\Reel\Render\Mode;
use SugarCraft\Reel\Source\Probe;

/**
 * Ffmpeg-based video decoder using proc_open.
 *
 * Two output pipelines, chosen by the render mode:
 *
 *  - **Text/cell modes** (ASCII, ANSI, half-/quarter-block): a raw rgb24 pipe.
 *    HalfBlock scales to cellsH*2 rows (2 source rows per cell); QuarterBlock to
 *    cellsW*2 × cellsH*2; the 1:1 modes to cellsW × cellsH. One frame =
 *    width * height * 3 bytes, framed by its exact byte length.
 *  - **Graphics modes** (Sixel/Kitty/iTerm2): a PNG `image2pipe`, scaled to the
 *    terminal's FULL pixel resolution (cellsW·cellPxW × cellsH·cellPxH). ffmpeg
 *    does the scale and the PNG encode in C, so the graphics protocols get a
 *    full-resolution image with no per-pixel PHP work — the fix for postage-stamp
 *    sixel/iterm2 output that came from decoding one pixel per cell. Frames are
 *    self-delimiting PNGs, split on the IEND end-chunk marker.
 *
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
    /** The 12-byte PNG IEND end-chunk (length 0 + "IEND" + fixed CRC) — every PNG ends with exactly these bytes. */
    private const PNG_IEND = "\x00\x00\x00\x00IEND\xae\x42\x60\x82";

    /** @var resource|\Process|null */
    private $process = null;

    /** @var resource|null */
    private $stdout = null;

    private int $cellsW = 0;
    private int $cellsH = 0;
    private int $frameBytes = 0;
    private int $frameW = 0;
    private int $frameH = 0;

    /** True when decoding to PNG image2pipe (graphics modes) rather than rawvideo. */
    private bool $graphics = false;

    /** Carry-over bytes from the PNG pipe between next() calls (a frame may straddle reads). */
    private string $pngBuffer = '';

    /**
     * @param int $cellPxW Pixel width of one terminal cell — graphics modes decode at
     *                     cellsW·cellPxW pixels so the image fills the cell box at full
     *                     resolution. Defaults match the SixelRenderer's assumed font box.
     * @param int $cellPxH Pixel height of one terminal cell.
     */
    public function __construct(
        private readonly int $cellPxW = 10,
        private readonly int $cellPxH = 20,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function open(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0): void
    {
        $this->cellsW = $cellsW;
        $this->cellsH = $cellsH;
        $this->graphics = $mode?->isGraphics() ?? false;
        $this->pngBuffer = '';

        if ($this->graphics) {
            // Graphics modes decode at the terminal's FULL pixel resolution so the
            // image protocols get real detail (not one pixel per cell). The cell
            // pixel geometry is the terminal's font box.
            $this->frameW = max(1, $cellsW * $this->cellPxW);
            $this->frameH = max(1, $cellsH * $this->cellPxH);
            $this->frameBytes = 0; // PNG frames are self-delimiting, not fixed-length
        } else {
            // Text modes: scale each axis by the mode's source-pixels-per-cell.
            // HalfBlock packs 2 rows per cell (cellsH*2); QuarterBlock packs 2 rows
            // AND 2 cols (cellsW*2 × cellsH*2); the 1:1 modes use cellsW × cellsH.
            // $mode === null defaults to HalfBlock (2 rows, 1 col), per DecoderFactory.
            $this->frameW = $cellsW * ($mode?->colsPerCell() ?? 1);
            $this->frameH = $cellsH * ($mode?->rowsPerCell() ?? 2);
            $this->frameBytes = $this->frameW * $this->frameH * 3;
        }

        $ffmpegPath = Probe::ffmpeg();
        if ($ffmpegPath === null) {
            throw new \RuntimeException('ffmpeg not found on this host');
        }

        // Build command as array — never a shell string.
        // No escaping needed; proc_open passes args directly with no shell.
        $cmd = self::buildCommand($ffmpegPath, $source, $this->frameW, $this->frameH, $fps, $startSec, $this->graphics);

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
     * For graphics modes ($graphics = true) the output is a PNG `image2pipe`
     * instead of a raw rgb24 pipe: ffmpeg encodes each scaled frame to PNG in C
     * (fast, full resolution), and the reader splits the stream on the PNG IEND
     * marker. `-compression_level 1` keeps the per-frame encode cheap.
     *
     * @return list<string>
     */
    public static function buildCommand(string $ffmpegPath, string $source, int $frameW, int $frameH, float $fps, float $startSec = 0.0, bool $graphics = false): array
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

        array_push($cmd, '-i', $source);

        // Output format: a self-delimiting PNG stream for graphics modes, else a
        // fixed-length raw rgb24 stream for the text/cell renderers.
        if ($graphics) {
            array_push($cmd, '-f', 'image2pipe', '-vcodec', 'png', '-compression_level', '1');
        } else {
            array_push($cmd, '-f', 'rawvideo', '-pix_fmt', 'rgb24');
        }

        array_push(
            $cmd,
            // Preserve the source aspect ratio: scale to FIT within the frame
            // (force_original_aspect_ratio=decrease) then pad to the exact frame
            // size, centring the image with black bars. The frame is sized to the
            // terminal's display aspect, so a 4:3 video is pillarboxed and centred
            // instead of stretched edge-to-edge (and the bars are clean black, not
            // leftover noise).
            '-vf', sprintf(
                'fps=%s,scale=%d:%d:force_original_aspect_ratio=decrease:flags=bilinear,pad=%d:%d:(ow-iw)/2:(oh-ih)/2',
                (string) $fps,
                $frameW,
                $frameH,
                $frameW,
                $frameH,
            ),
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

        if ($this->graphics) {
            return $this->nextPng();
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

        return new RgbFrame($frameBytes, $this->frameW, $this->frameH);
    }

    /**
     * Read the next complete PNG frame from the image2pipe stream.
     *
     * PNGs are concatenated back-to-back on the pipe, so a frame ends at the
     * 12-byte IEND end-chunk. We accumulate pipe bytes until that marker appears,
     * slice off the one frame (keeping any trailing bytes of the next frame in
     * {@see $pngBuffer}), and return it as a PNG-payload RgbFrame. On EOF without a
     * complete frame the partial tail is discarded (matching the rawvideo path).
     */
    private function nextPng(): ?RgbFrame
    {
        while (true) {
            $end = strpos($this->pngBuffer, self::PNG_IEND);
            if ($end !== false) {
                $cut = $end + strlen(self::PNG_IEND);
                $png = substr($this->pngBuffer, 0, $cut);
                $this->pngBuffer = substr($this->pngBuffer, $cut);

                return new RgbFrame('', $this->frameW, $this->frameH, $png);
            }

            $chunk = $this->stdout !== null && is_resource($this->stdout)
                ? fread($this->stdout, 65536)
                : false;
            if ($chunk === false || $chunk === '') {
                return null; // EOF — any partial trailing PNG is discarded
            }
            $this->pngBuffer .= $chunk;
        }
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
