<?php

declare(strict_types=1);

namespace SugarCraft\Video\Decode;

use SugarCraft\Core\Util\Proc\BoundedShutdown;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Video\AudioPlayer;
use SugarCraft\Video\Lang;
use SugarCraft\Video\Render\Mode;
use SugarCraft\Video\Source\HttpHeaders;
use SugarCraft\Video\Source\Probe;
use SugarCraft\Video\Support\FfmpegCommandBuilder;

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
 * ffmpeg's stderr is redirected to a private TEMP FILE (a file sink, never a
 * pipe — a reader-less stderr pipe deadlocks once ffmpeg fills the ~64KB
 * kernel buffer on noisy input, which would wedge the stdout reader). The
 * file is not /dev/null anymore: when the child dies abnormally mid-stream,
 * {@see next()} throws {@see FfmpegDecodeFailure} carrying the exit code AND
 * the tail of that stderr, so the diagnostic ffmpeg already produced reaches
 * the user instead of evaporating (M3/M12). The file is unlinked in close().
 *
 * READS ARE BOUNDED, RESUMABLE — PUMP CONTRACT (
 * re-shaped same day by w4-reel). {@see next()} and {@see nextPng()} fill a
 * frame from the ffmpeg stdout pipe under a per-frame-FILL deadline
 * ({@see READ_TIMEOUT_SEC}): a stalled or silent stream returns null with
 * the partial bytes buffered, and the NEXT call resumes the same frame —
 * null therefore means "no frame right now" as well as EOF, and a mid-frame
 * null NEVER desyncs the forward-only pipe. The law it satisfies is
 * unchanged: no single call parks the loop forever (the pre-timeout plain
 * fread it documented could). THE CALLER still owns the latency budget
 * (the r69 pump law, same shape as sugar-pty's PosixPump): repeated nulls
 * on a live-but-silent ffmpeg persist past any one deadline, so whoever
 * drives next() decides when to give up and reach close(). What this class
 * guarantees is that the child cannot pin a read forever once the caller
 * reaches close():
 * stderr goes to a file sink (a wedged writer cannot stall ffmpeg on a
 * full stderr pipe) and close() walks the bounded {@see BoundedShutdown}
 * ladder — at most GRACE+TERM+KILL = 3.5s from entry to a dead child —
 * after which every outstanding read sees EOF. A live-but-silent ffmpeg
 * (network stream stalled mid-reconnect, input wedged on an unopened
 * source) DOES park next() for as long as it lives; that is the caller's
 * bound to enforce, not a defect to time around here.
 *
 * The source may be a local path OR an http(s) URL — ffmpeg decodes a network
 * stream natively (so the console client can direct-play the server's signed
 * stream URL, bypassing any transcode). For URL sources the http/https protocol
 * reconnect options are passed so a momentary drop does not abort playback.
 *
 * @implements Decoder
 */
final class FfmpegDecoder implements Decoder
{
    /** The 12-byte PNG IEND end-chunk (length 0 + "IEND" + fixed CRC) — every PNG ends with exactly these bytes. */
    private const PNG_IEND = "\x00\x00\x00\x00IEND\xae\x42\x60\x82";

    /** Maximum PNG buffer size in bytes (100 MB) — prevents unbounded memory growth on malformed input. */
    private const MAX_PNG_BUFFER = 100 * 1024 * 1024;

    /** A terminal cell is roughly twice as tall as it is wide — used to letterbox text-mode video to the true on-screen display aspect. */
    private const CELL_ASPECT = 2;

    /**
     * Ceiling on how long a single stdout read may block waiting for the next
     * byte from ffmpeg (seconds). A stalled network stream or wedged decoder
     * otherwise hangs `fread()` forever, freezing the entire TEA update loop —
     * input, redraw and resize all stop. On timeout the frame is treated as
     * unavailable (null) and playback fails closed rather than blocking the
     * process. ffmpeg's `-reconnect` options absorb drops shorter than this.
     */
    private const READ_TIMEOUT_SEC = 5.0;

    /**
     * Per-frame-fill read deadline in seconds, initialised from
     * {@see self::READ_TIMEOUT_SEC}. A test seam (set by reflection, like
     * `$rawBuffer`) so the bound can be witnessed on a short clock scale
     * instead of after five real seconds.
     */
    private float $readTimeout = self::READ_TIMEOUT_SEC;

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
     * Byte offset to resume the PNG_IEND scan from (SV-M37).
     *
     * Without it, every 64KB top-up re-scans the WHOLE buffer from byte 0 —
     * O(N²) in buffer size: a stalling-but-live child lets a slow trickle
     * inflate toward the 100MB cap while memchr earns its keep hundreds of
     * times per frame. The cursor parks (marker length − 1) bytes behind the
     * buffer end — the marker may straddle a top-up — so each
     * byte is examined at most once between consumptions. Reset on frame
     * delivery, buffer drop, and open.
     */
    private int $pngScanCursor = 0;

    /**
     * Carry-over bytes of a partially-read rawvideo frame between next() calls.
     *
     * A bounded read can return with a frame only half-received (the pipe stalled
     * past the timeout). Keeping the partial here lets the next tick resume that
     * exact frame instead of discarding it and desyncing on the pipe's byte stream
     * — a decoder is forward-only, so a dropped half-frame would corrupt every
     * frame after it.
     */
    private string $rawBuffer = '';

    /** Cached fps value from the last open() call — avoids re-probing the source. */
    private float $fps = 0.0;

    /**
     * ffmpeg's exit status when its output pipe goes away: `AVERROR(EPIPE)`
     * (-32) truncated to a byte. See {@see isTeardownExit()}.
     */
    private const EXIT_EPIPE = 224;

    /**
     * Seconds the child gets to exit on its own — on the EPIPE its write
     * error delivers once close() has dropped the stdout read end — before
     * the canonical ladder escalates to SIGTERM. The budget is a decoder
     * fact (how fast ffmpeg answers a closed pipe), so it lives with the
     * caller, per sugar-core `BoundedShutdown`'s "budgets stay with the
     * callers" doctrine; the rungs themselves do not.
     */
    private const CLOSE_GRACE_SECONDS = 1.5;

    /** Captured exit code from the ffmpeg process, populated on close(). */
    private ?int $exitCode = null;

    /**
     * The source string open() last spawned against — kept so a late decode
     * failure can name the source (redacted) in its exception without the
     * caller threading it back through.
     */
    private string $source = '';

    /**
     * Path of the temp file ffmpeg's stderr was pointed at for the current
     * child, or null when the spawn fell back to the null device (tempnam
     * failed) or after close(). Read by {@see refuseAbnormalExit()} only.
     */
    private ?string $stderrPath = null;

    /** Bytes of ffmpeg stderr quoted into a failure message — enough for the tail of an `error`-level abort. */
    private const STDERR_TAIL_BYTES = 400;

    /**
     * Validated request headers for the current network source, empty otherwise.
     *
     * Held on the instance (not just passed to buildCommand) so {@see reopen()}
     * can re-present them without the caller repeating them — a rebuild that
     * forgot the credentials would fail a signed stream's second request.
     */
    private HttpHeaders $headers;

    /**
     * @param int $cellPxW Pixel width of one terminal cell — graphics modes decode at
     *                     cellsW·cellPxW pixels so the image fills the cell box at full
     *                     resolution. Defaults match the SixelRenderer's assumed font box.
     * @param int $cellPxH Pixel height of one terminal cell.
     */
    public function __construct(
        private readonly int $cellPxW = 10,
        private readonly int $cellPxH = 20,
        private readonly bool $hostRestricted = false,
    ) {
        $this->headers = HttpHeaders::none();
    }

    /**
     * @inheritDoc
     *
     * @param array<array-key, string> $headers HTTP request headers for an http(s)
     *        source. Validated here, at the boundary, before anything reaches ffmpeg.
     */
    public function open(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0, array $headers = []): void
    {
        // SV-M44 (owner-gate default leg, route a): LOUD refusal of the ffmpeg
        // video path on Windows. stream_select() on proc_open pipes is
        // documented-unsupported there — a failed select answers "no data"
        // until the deadline, which looks like silent black playback with a
        // live child. Better a clear error than a lie. The blocking-read and
        // file-sink pump reworks stay open follow-ups (README "Windows").
        if (self::runningOnWindows()) {
            throw new \RuntimeException(Lang::t('decoder.windows_refused'));
        }

        // Re-opening a live instance must not orphan the previous ffmpeg child
        // and its stdout fd — close() is idempotent, so this is free on the
        // normal first open() and correct on any second one.
        $this->close();

        $this->cellsW = $cellsW;
        $this->cellsH = $cellsH;
        $this->fps = $fps;
        $this->source = $source;
        $this->graphics = $mode?->isGraphics() ?? false;
        $this->pngBuffer = '';
        $this->pngScanCursor = 0;
        $this->rawBuffer = '';
        $this->headers = HttpHeaders::parse($headers);

        // Headers only mean something to a protocol that sends them. Attaching
        // `-headers` to a local-file input is not merely useless, ffmpeg rejects
        // it as an unknown option for the file protocol — so they are dropped,
        // and said so out loud rather than silently swallowing credentials a
        // caller believed were being sent.
        if (!$this->headers->isEmpty() && !self::isNetworkSource($source)) {
            error_log(Lang::t('header.ignored_local_source', [
                'count' => count($this->headers->pairs()),
                // m4: the raw source can carry URL userinfo — the audio
                // sibling redacts the same event; a video-side leak here
                // would defeat it (refusing to send credentials and then
                // logging them is no refusal at all).
                'source' => AudioPlayer::redactCredentials($source),
            ]));
            $this->headers = HttpHeaders::none();
        }

        if ($this->graphics) {
            // Graphics modes decode at the terminal's FULL pixel resolution so the
            // image protocols get real detail (not one pixel per cell). The cell
            // pixel geometry is the terminal's font box.
            $this->frameW = max(1, $cellsW * $this->cellPxW);
            $this->frameH = max(1, $cellsH * $this->cellPxH);
            $this->frameBytes = 0; // PNG frames are self-delimiting, not fixed-length
        } else {
            // Text modes: scale each axis by the mode's source-pixels-per-cell.
            // The packing table lives in Mode (SV-L51); $mode === null keeps the
            // DecoderFactory policy — HalfBlock (2 rows, 1 col) spelled as a mode.
            $pack = $mode ?? Mode::HalfBlock;
            $this->frameW = $cellsW * $pack->colsPerCell();
            $this->frameH = $cellsH * $pack->rowsPerCell();
            $this->frameBytes = $this->frameW * $this->frameH * 3;
        }

        $ffmpegPath = Probe::ffmpeg();
        if ($ffmpegPath === null) {
            throw new \RuntimeException(Lang::t('decoder.ffmpeg_missing'));
        }

        // For local sources, verify the file exists before spawning ffmpeg so
        // a missing file surfaces as a clear exception rather than a silent
        // empty decode. Only http(s) URLs skip this check — and note the
        // factory's catch-all branch DOES route exotic ffmpeg protocol sources
        // (pipe:, fd:, rtsp://…) here, where they are rejected below: those
        // protocols are not wired for playback through this decoder. An
        // injected test decoder that never calls open() never reaches here.
        if (!self::isNetworkSource($source) && !is_file($source)) {
            // m5: the path is attacker-influenced content (a filename from a
            // playlist, a CLI argument) about to be interpolated into an
            // exception the TUI may paint — sweep control/escape bytes and
            // bound it, same discipline as the caption row.
            throw new \RuntimeException(Lang::t('decoder.source_missing', ['path' => self::describePath(AudioPlayer::redactCredentials($source))]));
        }

        // Aspect-correct letterbox box. The on-screen display area for cellsW × cellsH
        // cells has aspect cellsW : cellsH·CELL_ASPECT (cells are ~2× taller than wide).
        // The video must be letterboxed to THAT aspect, not the raw frame-pixel aspect —
        // they only coincide for HalfBlock. For QuarterBlock (cellsW·2 × cellsH·2) and the
        // 1-px text modes the frame is squarer than the screen, so without this the video
        // is squished horizontally (QuarterBlock came out ~half width). buildCommand
        // letterboxes to (padW × padH) then squashes to the frame grid; the cell grid
        // stretches it back to true aspect on screen. Graphics modes already encode the
        // cell ratio via cellPx, so they letterbox to the frame directly.
        if ($this->graphics) {
            [$padW, $padH] = [$this->frameW, $this->frameH];
        } else {
            $padW = $this->frameW;
            $padH = max(1, (int) round($this->frameW * $cellsH * self::CELL_ASPECT / max(1, $cellsW)));
        }

        // Build command as array — never a shell string.
        // No escaping needed; proc_open passes args directly with no shell.
        // The parsed $headers (not the raw array) go in: they were validated at
        // the boundary above, so buildCommand trusts them and only assembles argv.
        $cmd = self::buildCommand($ffmpegPath, $source, $this->frameW, $this->frameH, $fps, $startSec, $this->graphics, $padW, $padH, $this->headers, $this->hostRestricted);

        // stderr goes to a PRIVATE TEMP FILE when we can make one, else the OS
        // null device — never a pipe (an unread stderr pipe deadlocks ffmpeg
        // once its ~64KB buffer fills). The temp file buys diagnostics: an
        // abnormal exit is quoted back from it (refuseAbnormalExit), which is
        // exactly what the old /dev/null sink threw away. close() unlinks.
        //
        // SV-L83 (house law, sugar-image/sugar-mosaic precedent): the sink
        // lives in a process-OWNED 0700 directory, not in shared /tmp root —
        // a same-uid attacker could symlink-swap a root-level name between
        // the spawn and the tail-read and surface 400 bytes of an arbitrary
        // file (sanitized) into the exception. Residual window accepted and
        // documented: a same-uid attacker who can ptrace or signal this
        // process can read any path anyway; what this closes out is the
        // passive /tmp-sweeper class, which is the realistic attack. If no
        // owned dir can be secured, we lose diagnostics — we do NOT fall
        // back to a world-visible location. close() unlinks the file; the
        // (then-empty, 0700) dir rides the host's tmp reaper.
        $devNull = DIRECTORY_SEPARATOR === '\\' ? '\\\\.\\NUL' : '/dev/null';
        $sinkDir = self::sinkDir();
        $stderrSink = $sinkDir !== null ? @tempnam($sinkDir, 'ffmpeg-stderr-') : false;
        $this->stderrPath = is_string($stderrSink) ? $stderrSink : null;

        $descriptorSpec = [
            ['pipe', 'r'],            // stdin
            ['pipe', 'w'],            // stdout
            ['file', $this->stderrPath ?? $devNull, 'w'],  // stderr → diagnostic sink
        ];

        // M8: an explicit scrubbed env — proxy/secret variables must never
        // steer or leak through a media child.
        $this->process = proc_open($cmd, $descriptorSpec, $pipes, null, FfmpegCommandBuilder::scrubbedEnv());
        if (!is_resource($this->process)) {
            throw new \RuntimeException('Failed to start ffmpeg process');
        }

        $this->stdout = $pipes[1];
        // Non-blocking so readStdout()'s bound is real: stream_select() only
        // guards the wait for the FIRST byte, while a blocking fread() would
        // then loop internally until it has all requested bytes — a stream
        // trickling mid-frame would freeze the TEA loop past READ_TIMEOUT_SEC
        // and make the $rawBuffer reassembly dead code. With this set, fread()
        // returns whatever has arrived; the frame loop tops the buffer up
        // across calls, still bounded by the select between reads.
        \stream_set_blocking($this->stdout, false);
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
     * $padW × $padH is the aspect-correct LETTERBOX box (the on-screen display
     * aspect). When it differs from the frame grid (QuarterBlock, 1-px text modes)
     * the video is fitted+padded to that box and then squashed to $frameW×$frameH,
     * so the cell grid stretches it back to true aspect on screen. When it equals
     * the frame (HalfBlock, graphics) it collapses to the original single fit+pad.
     * Defaults to the frame size, preserving the old behaviour for callers that
     * don't pass it.
     *
     * $headers (already validated by {@see HttpHeaders::parse()}) become ffmpeg
     * input options for a network source: `-headers` carries every header except
     * User-Agent (each terminated with CRLF exactly as it hits the wire), and a
     * User-Agent is passed as the dedicated `-user_agent` option rather than
     * duplicated inside the header blob. They are INPUT options, so like
     * `-reconnect` they precede `-i`, and they are omitted for a local file
     * (ffmpeg rejects them on a non-network input).
     *
     * @return list<string>
     */
    public static function buildCommand(string $ffmpegPath, string $source, int $frameW, int $frameH, float $fps, float $startSec = 0.0, bool $graphics = false, ?int $padW = null, ?int $padH = null, ?HttpHeaders $headers = null, bool $hostRestricted = false): array
    {
        $padW ??= $frameW;
        $padH ??= $frameH;
        $headers ??= HttpHeaders::none();
        $cmd = [$ffmpegPath, '-hide_banner', '-loglevel', 'error'];

        $network = FfmpegCommandBuilder::isNetworkSource($source);

        if ($network) {
            array_push($cmd, ...FfmpegCommandBuilder::networkReconnectFlags($hostRestricted));

            // Authenticated passthrough: emit the header blob / user agent only
            // for a network source. A local path omits them (open() already
            // logged that non-network headers are dropped).
            array_push($cmd, ...FfmpegCommandBuilder::headerInputFlags($headers));
        }

        array_push($cmd, ...FfmpegCommandBuilder::inputSeekFlag($startSec));

        array_push($cmd, '-i', $source);

        // Output format: a self-delimiting PNG stream for graphics modes, else a
        // fixed-length raw rgb24 stream for the text/cell renderers.
        if ($graphics) {
            array_push($cmd, '-f', 'image2pipe', '-vcodec', 'png', '-compression_level', '1');
        } else {
            array_push($cmd, '-f', 'rawvideo', '-pix_fmt', 'rgb24');
        }

        // Preserve the source aspect ratio: scale to FIT within the letterbox box
        // (force_original_aspect_ratio=decrease) then pad to it, centring the image
        // with clean black bars — so a 4:3 video is pillarboxed, not edge-to-edge
        // stretched. When the letterbox box differs from the frame grid, a final
        // unconditional scale squashes it onto the grid (the cell grid stretches it
        // back to true aspect on screen — fixes QuarterBlock's half-width look).
        $vf = sprintf(
            'fps=%s,scale=%d:%d:force_original_aspect_ratio=decrease:flags=bilinear,pad=%d:%d:(ow-iw)/2:(oh-ih)/2',
            FfmpegCommandBuilder::formatFloat($fps),
            $padW,
            $padH,
            $padW,
            $padH,
        );
        if ($padW !== $frameW || $padH !== $frameH) {
            $vf .= sprintf(',scale=%d:%d:flags=bilinear', $frameW, $frameH);
        }

        array_push($cmd, '-vf', $vf, '-');

        return $cmd;
    }

    /**
     * Whether the source is an http(s) URL (vs a local file path). ffmpeg's
     * reconnect options apply only to the network protocols.
     *
     * Public because {@see DecoderFactory::new()} routes on exactly this
     * test — one predicate means the factory's routing and the decoder's
     * header-gating decision cannot drift apart. The predicate itself now lives
     * in {@see FfmpegCommandBuilder::isNetworkSource()} so the audio command
     * builders route on the same test; this is the decoder's own spelling of it.
     */
    public static function isNetworkSource(string $source): bool
    {
        return FfmpegCommandBuilder::isNetworkSource($source);
    }

    /**
     * @inheritDoc
     *
     * Why bounded: fills one rawvideo frame under the per-call FILL
     * deadline — see the class pump-contract paragraph. Null means the
     * stream ended cleanly OR the fill deadline expired mid-frame (partial
     * bytes stay buffered; the next call resumes the same frame). M12: a
     * child that died abnormally no longer answers null — it throws
     * {@see FfmpegDecodeFailure} with the exit code and stderr tail, so
     * "crashed" can never again be mistaken for "ended".
     */
    public function next(): ?RgbFrame
    {
        if ($this->stdout === null || !is_resource($this->stdout)) {
            return null;
        }

        if ($this->graphics) {
            return $this->nextPng();
        }

        // SV-H7/007: a zero-byte text-mode frame (cellsW/cellsH 0 through a
        // directly-minted decoder, bypassing the Player pane window) would
        // "complete" on an empty buffer forever — the fill loop reads nothing,
        // every call returns a 0-byte frame, and the stream never ends while
        // the pipe backs up. Treat it as immediate end-of-stream. The
        // graphics leg self-terminates on PNG markers and cannot hit this.
        if ($this->frameBytes < 1) {
            return null;
        }

        // Fill the frame from the persistent partial buffer, then top it up from
        // the pipe with BOUNDED reads (a bare fread() blocks
        // forever on a stalled stream and freezes the whole UI loop). The bound
        // is a per-frame-FILL deadline, not a per-read one: every read inside
        // this fill races the same clock, so a trickle that dribbles bytes just
        // inside each individual select window still dies when the fill's budget
        // is spent. A frame left incomplete gets a FRESH fill budget on the next
        // call — the point is that no single next() blocks past READ_TIMEOUT_SEC,
        // not that a stalled stream ever "catches up" on its own.
        // A timeout mid-frame leaves the bytes received so far in $rawBuffer and
        // returns null; the next tick resumes this same frame, so the
        // forward-only pipe never desyncs.
        $deadline = self::monotonic() + $this->readTimeout;
        while (strlen($this->rawBuffer) < $this->frameBytes) {
            $chunk = $this->readStdout($this->frameBytes - strlen($this->rawBuffer), $deadline);
            if ($chunk === null) {
                // M12: null must mean "clean EOF or still waiting", never a
                // masked crash. A dead-abnormal child throws here instead;
                // a live-but-silent one passes the check and returns null.
                $this->refuseAbnormalExit();
                return null; // stalled (timeout) or EOF — partial stays buffered
            }
            $this->rawBuffer .= $chunk;
        }

        $frameBytes = substr($this->rawBuffer, 0, $this->frameBytes);
        $this->rawBuffer = substr($this->rawBuffer, $this->frameBytes);

        return new RgbFrame($frameBytes, $this->frameW, $this->frameH);
    }

    /**
     * Read up to $bytes from the ffmpeg stdout pipe, waiting no longer than the
     * caller's absolute monotonic $deadline for data to arrive.
     *
     * The pipe is non-blocking (set in open()), so select+read together bound
     * the whole call: select guards the wait for data, and the following fread
     * only ever returns what has ALREADY arrived — never looping internally
     * for the full $bytes the way a blocking read would.
     *
     * The deadline is per frame-fill, owned by the caller (next()/nextPng()),
     * so a server trickling bytes just inside each individual select window
     * still cannot extend one frame's total wait beyond the fill's deadline
     * ({@see $readTimeout}, {@see self::READ_TIMEOUT_SEC} by default).
     *
     * Returns null on timeout or EOF/error (the caller treats both as "no frame
     * available") and the data string otherwise. `stream_select()` reports a pipe
     * that reached EOF as readable, after which fread() returns '' — mapped to
     * null here too, so a real end-of-stream and a wedge look alike to the caller
     * and neither blocks the process.
     *
     * @param float $deadline absolute {@see self::monotonic()} seconds
     *
     * @return string|null the bytes read, or null when none are available
     */
    private function readStdout(int $bytes, float $deadline): ?string
    {
        if ($this->stdout === null || !is_resource($this->stdout)) {
            return null;
        }

        $remaining = $deadline - self::monotonic();
        if ($remaining <= 0.0) {
            return null; // the frame's whole budget is spent — resume next call
        }

        $read = [$this->stdout];
        $write = null;
        $except = null;
        $seconds = (int) floor($remaining);
        // round() can land on exactly 1_000_000, which stream_select rejects
        // (tv_usec ≤ 999_999) — it would warn and return false, aborting the
        // WHOLE remaining bounded wait instead of just shaving a microsecond.
        // Clamp instead.
        $micros = (int) min(999_999, round(($remaining - $seconds) * 1_000_000));

        $ready = @stream_select($read, $write, $except, $seconds, $micros);
        if ($ready === false || $ready === 0) {
            // Select error or timed out waiting for data — fail closed, don't block.
            return null;
        }

        $chunk = fread($this->stdout, $bytes);

        return ($chunk === false || $chunk === '') ? null : $chunk;
    }

    /**
     * Monotonic seconds, immune to wall-clock steps — the same clock discipline
     * AudioPlayer banks positions on.
     */
    private static function monotonic(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    /**
     * Read the next complete PNG frame from the image2pipe stream.
     *
     * PNGs are concatenated back-to-back on the pipe, so a frame ends at the
     * 12-byte IEND end-chunk. We accumulate pipe bytes until that marker appears,
     * slice off the one frame (keeping any trailing bytes of the next frame in
     * {@see $pngBuffer}), and return it as a PNG-payload RgbFrame. On EOF without a
     * complete frame the partial tail is discarded (matching the rawvideo path).
     *
     * Like the rawvideo path the fill is deadline-bounded and
     * resumable — a live-but-silent ffmpeg yields null with the partial PNG
     * buffered until close() takes the child down (class pump contract). The {@see MAX_PNG_BUFFER} ceiling bounds MEMORY against a
     * malicious/huge-frame stream, not latency; tripping it is a fail-closed
     * stream end, not a timeout.
     */
    private function nextPng(): ?RgbFrame
    {
        $deadline = self::monotonic() + $this->readTimeout;

        while (true) {
            $end = strpos($this->pngBuffer, self::PNG_IEND, $this->pngScanCursor);
            if ($end !== false) {
                $cut = $end + strlen(self::PNG_IEND);
                $png = substr($this->pngBuffer, 0, $cut);
                $this->pngBuffer = substr($this->pngBuffer, $cut);
                $this->pngScanCursor = 0;

                return new RgbFrame('', $this->frameW, $this->frameH, $png);
            }

            // SV-M37: nothing found — park the cursor where a marker could
            // still START inside the current tail, so the next top-up scans
            // only the bytes it has never seen.
            $this->pngScanCursor = max(0, strlen($this->pngBuffer) - (strlen(self::PNG_IEND) - 1));

            $chunk = $this->readStdout(65536, $deadline);
            if ($chunk === null) {
                // M12: same refusal as the raw path — a dead-abnormal ffmpeg
                // throws rather than masquerading as end-of-stream.
                $this->refuseAbnormalExit();
                // EOF or stalled — the PNG buffer already holds whole frames,
                // so returning null here neither loses a complete frame nor
                // blocks the process (any partial trailing PNG stays buffered
                // and, like the raw path, is simply never completed).
                return null;
            }
            if (strlen($this->pngBuffer) + strlen($chunk) > self::MAX_PNG_BUFFER) {
                // m8: drop the buffered bytes as we bail. Without the clear,
                // every later next() re-trips on the same over-budget buffer —
                // a permanent stall that also pins the megabytes forever. After
                // the reset the framing is unrecoverable anyway, but the
                // close()/EOF paths proceed and the memory actually drops.
                $this->pngBuffer = '';
                $this->pngScanCursor = 0;
                error_log("FfmpegDecoder: PNG buffer exceeded limit, aborting");
                return null;
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
     *
     * E366: the teardown used to be a bare `proc_close()` — which WAITS, so
     * an ffmpeg that never exits (wedged network read, stuck encoder) took
     * the caller down with it. The parent-side pipes are closed first,
     * because that is the polite exit for a stream decoder: ffmpeg dies on
     * its write error within microseconds of losing stdout. A bounded
     * {@see CLOSE_GRACE_SECONDS} window is given for exactly that —
     * {@see BoundedShutdown::hasExited()} polls without signalling — and
     * only when the grace buys nothing does
     * {@see BoundedShutdown::terminateAndAwaitExit()} run the SIGTERM→SIGKILL
     * escalation, so `proc_close()` below reaps instead of waiting. The
     * recorded exit code keeps its meaning: a process that took the
     * escalation ends signalled, and {@see exitCode()} still reports it.
     *
     * What is NOT reported is an exit this method itself caused — see
     * {@see isTeardownExit()}. Closing the pipe to make ffmpeg leave and then
     * logging the resulting EPIPE as a decoder error meant every quit, seek,
     * resize and mode change printed `ffmpeg exited with code 224` to stderr,
     * i.e. over the frame sugar-core had just composed.
     */
    public function close(): void
    {
        if ($this->stdout !== null && is_resource($this->stdout)) {
            \fclose($this->stdout);
            $this->stdout = null;
        }

        if ($this->process !== null && is_resource($this->process)) {
            // Grace first, escalation second — the fold of the deleted
            // per-lib BoundedReaper onto sugar-core's canonical ladder,
            // which deliberately has no pre-TERM natural-exit rung: that
            // budget is exactly what "stays with the caller".
            if (!BoundedShutdown::hasExited($this->process, self::CLOSE_GRACE_SECONDS)) {
                BoundedShutdown::terminateAndAwaitExit($this->process);
            }
            // Read the disposition BEFORE proc_close(): once the child is
            // reaped, proc_close() collapses "killed by signal N" to a flat
            // -1, and the reaper's own SIGTERM becomes indistinguishable from
            // a crash.
            $status = @proc_get_status($this->process);
            $signalled = is_array($status) && ($status['signaled'] ?? false) === true;
            $this->exitCode = proc_close($this->process);
            $this->process = null;

            if ($this->exitCode !== 0 && !self::isTeardownExit($this->exitCode, $signalled)) {
                error_log("FfmpegDecoder: ffmpeg exited with code {$this->exitCode}");
            }
        }

        // The stderr diagnostic file belongs to the child we just reaped.
        if ($this->stderrPath !== null) {
            @unlink($this->stderrPath);
            $this->stderrPath = null;
        }
    }

    /**
     * Whether a non-zero exit is one {@see close()} itself provoked.
     *
     * MEASURED on ffmpeg 6.1.1 / PHP 8.3.6 / Linux 6.8. `close()` deliberately
     * closes the read end of the stdout pipe first — the class docblock calls
     * that "the polite exit for a stream decoder", because ffmpeg then dies on
     * its own write error in microseconds instead of having to be signalled.
     * ffmpeg reports that write error by exiting with the libav errno
     * truncated to a byte: `AVERROR(EPIPE)` is -32, and -32 & 0xFF is
     * {@see EXIT_EPIPE} (224). A child that ignored the closed pipe and had to
     * be TERMed or KILLed by {@see BoundedShutdown} comes back `signaled`
     * instead, and for a signalled child `proc_close()` returns -1.
     *
     * Both outcomes are this object's own teardown reported back to it, and
     * neither says anything about the stream that was decoded — so neither is
     * logged. A genuine failure (unreadable input, missing codec, a filtergraph
     * ffmpeg rejects) still exits with its own status and still gets its line.
     *
     * SV-X95 disposition: this tolerance table is MEASURED on Linux only. On
     * Windows `signaled` never reports true and the shutdown ladder's own
     * terminate stamps platform-specific codes, so a teardown there could be
     * classified as an abnormal exit — the risk direction is FALSE-ABNORMAL
     * LOG CHURN, never a missed crash (an unrecognised exit still prints its
     * line, which is the safe side). No behaviour is widened on guess: the
     * tolerance may only gain Windows arms when a Windows CI leg exists to
     * measure them (owed-conditional — same named-survivor class as the rest
     * of the program's Windows backlog). SV-M44's default-leg refusal makes
     * the whole question unreachable at runtime on Windows anyway: the ffmpeg
     * pump cannot open there, so only the stub-harness legs can reach this
     * code on that platform.
     *
     * @param bool $signalled `proc_get_status()['signaled']`, sampled before
     *                        the reap that would have erased it
     */
    private static function isTeardownExit(int $exitCode, bool $signalled): bool
    {
        return $exitCode === self::EXIT_EPIPE || $signalled || $exitCode === -1;
    }

    /**
     * Throw when the ffmpeg child has already died abnormally (M12).
     *
     * The Decoder contract says null means "no frame available" — but until
     * now a crashed ffmpeg (unreadable input, rejected filtergraph, OOM kill
     * that beat the reaper) answered with the SAME null as a clean end of
     * stream, so the Player froze a black frame and called it "ended" (M3's
     * blank-player root cause). This turns "the child is dead with a non-zero,
     * non-teardown status" into a typed loud failure carrying ffmpeg's own
     * stderr tail.
     *
     * Deliberately narrow: a still-running child (live-but-silent stream) and
     * a reaped/closed one both pass here without throwing, so the pump
     * contract's timeout-nulls and close()-then-next() stay exactly as pinned.
     *
     * @throws FfmpegDecodeFailure
     */
    private function refuseAbnormalExit(): void
    {
        if ($this->process === null || !is_resource($this->process)) {
            return;
        }

        $status = @proc_get_status($this->process);
        if (!is_array($status) || ($status['running'] ?? true)) {
            return; // still alive — a timeout null, honest per the pump contract
        }

        $code = (int) ($status['exitcode'] ?? 0);
        $signalled = ($status['signaled'] ?? false) === true;
        if ($code === 0 || self::isTeardownExit($code, $signalled)) {
            return; // clean end (or a disposition close() itself provoked)
        }

        // SV-L49/049: the sighting is data — record the final status here so
        // exitCode() answers truthfully from the moment the death is known,
        // not only once close() runs (which then re-captures the same value
        // from proc_close on the way down).
        $this->exitCode = $code;

        $detail = self::stderrTail($this->stderrPath);
        throw new FfmpegDecodeFailure(
            Lang::t('decoder.ffmpeg_exit', [
                'code' => (string) $code,
                'source' => self::describePath(AudioPlayer::redactCredentials($this->source)),
            ]) . ($detail !== '' ? ' — ffmpeg said: ' . $detail : '')
        );
    }

    /**
     * SV-M44 host-shape seam: the live answer is the real OS family; the
     * testing override (same shape as {@see \SugarCraft\Video\Render\RendererFactory::setDiagnoseForTesting()}
     * elsewhere in this lib) lets the Windows refusal arms run on any runner.
     */
    private static ?bool $windowsForTesting = null;

    public static function setWindowsHostForTesting(?bool $isWindows): void
    {
        self::$windowsForTesting = $isWindows;
    }

    private static function runningOnWindows(): bool
    {
        return self::$windowsForTesting ?? (\PHP_OS_FAMILY === 'Windows');
    }

    /**
     * Per-process owned sink directory (SV-L83): a 0700 dir created exactly
     * once under the system temp root and owned by this process alone. The
     * random suffix means a would-be pre-creator cannot guess the name; if
     * creation fails OR the path already existed (someone else's — never
     * trust it), the answer is null and callers lose the diagnostic sink
     * rather than handing stderr to a shared location.
     */
    private static function sinkDir(): ?string
    {
        /** @var ?string null = not tried yet, '' = tried and failed (sticky) */
        static $dir = null;

        if ($dir === '') {
            return null;
        }
        if ($dir !== null) {
            return is_dir($dir) ? $dir : null;
        }

        $candidate = sys_get_temp_dir() . '/sugar-video-' . getmypid() . '-' . bin2hex(random_bytes(6));
        if (!@mkdir($candidate, 0700) || !is_dir($candidate)) {
            // Either we failed, or the name pre-existed and is NOT ours.
            $dir = '';

            return null;
        }
        @chmod($candidate, 0700); // defeat an exotic umask that ate the owner bits

        return $dir = $candidate;
    }

    /**
     * The last {@see STDERR_TAIL_BYTES} of ffmpeg's stderr file, swept to a
     * single control-free line fit for an exception message (and onward into
     * the TUI). Unreadable or empty → ''.
     */
    private static function stderrTail(?string $path): string
    {
        if ($path === null || !is_file($path)) {
            return '';
        }

        $size = @filesize($path);
        if ($size === false || $size === 0) {
            return '';
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }

        try {
            if ($size > self::STDERR_TAIL_BYTES) {
                // SV-M14: the old `-$size + 400` offset was NEGATIVE for any
                // sink over 400 bytes (the common case), the @-suppressed
                // fseek failed silently, and the read returned the FIRST 400
                // bytes — the head, not the tail — exactly when the detail
                // mattered. Seek to the true tail and take failures loudly.
                $offset = $size - self::STDERR_TAIL_BYTES;
                if (@fseek($handle, $offset, SEEK_SET) !== 0) {
                    return '';
                }
            }
            $raw = (string) @fread($handle, self::STDERR_TAIL_BYTES);
        } finally {
            fclose($handle);
        }

        // The tail came from a child process fed hostile input — it may embed
        // escape sequences from filenames/URLs. Sweep before it reaches any
        // renderer (house pattern: Sanitize::cellValue = C0/C1 + newline fold).
        return trim(Sanitize::cellValue($raw));
    }

    /**
     * A user-presentable one-line sketch of a caller-supplied path/URL:
     * credentials masked (caller's job — see redactCredentials), control and
     * escape bytes swept, hard-bounded length.
     */
    private static function describePath(string $value): string
    {
        return mb_strcut(trim(Sanitize::cellValue($value)), 0, 160);
    }

    /**
     * Returns the exit status of the last ffmpeg process once a termination
     * has been OBSERVED — the close() reap or, earlier, the not-running
     * sighting in {@see refuseAbnormalExit()} (SV-L49/049); null before
     * either, or while the child is still running.
     */
    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    /**
     * @inheritDoc
     *
     * Closes and re-opens the decoder with the given parameters. The stored
     * request headers survive the rebuild (a seek/resize of a signed stream must
     * keep presenting its credentials), unless new ones are supplied here.
     */
    public function reopen(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0, array $headers = []): void
    {
        $this->close();
        $this->open($source, $cellsW, $cellsH, $fps, $mode, $startSec, $headers !== [] ? $headers : self::pairArray($this->headers));
    }

    /**
     * An ffmpeg decoder is NOT reopened in place: every geometry/mode change
     * rebuilds the scale filtergraph and (for a seek) the `-ss` input position,
     * so the honest rebuild is close()+a fresh DecoderFactory build. Player only
     * consults this to decide whether it may keep the instance; here it says no,
     * so Player rebuilds via the factory using the source path — which an
     * ffmpeg-backed Player always has.
     */
    public function reopensInPlace(): bool
    {
        return false;
    }

    /**
     * Flatten a parsed header set back to the name => value array open() accepts,
     * so reopen() can re-present the live credentials without the caller holding
     * them. Repeated names collapse to the last; none are expected in practice.
     *
     * @return array<string, string>
     */
    private static function pairArray(HttpHeaders $headers): array
    {
        $out = [];
        foreach ($headers->pairs() as $pair) {
            $out[$pair['name']] = $pair['value'];
        }

        return $out;
    }

    /**
     * Return the cached fps value from the last open() call.
     *
     * Allows callers (e.g. Player) to retrieve the fps without re-probing the
     * source through VideoSource::probe().
     */
    public function fps(): float
    {
        return $this->fps;
    }

    /**
     * E366 backstop: a decoder that merely goes out of scope does not wait
     * for its child — PHP's resource destructor abandons a RUNNING one to
     * init with every inherited descriptor. close() is idempotent, so an
     * explicit call before teardown still wins; this covers the path where
     * nobody remembered to make one.
     */
    public function __destruct()
    {
        $this->close();
    }
}
