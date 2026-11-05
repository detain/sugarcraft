<?php

declare(strict_types=1);

namespace SugarCraft\Vcr;

use SugarCraft\Core\Recorder as RecorderInterface;
use SugarCraft\Vcr\Format\Format;
use SugarCraft\Vcr\Format\RelativeFormat;
use SugarCraft\Vcr\Hook\HookRegistry;

/**
 * Streaming JSONL cassette writer. Each event is encoded and flushed
 * immediately so a crash mid-recording doesn't lose the cassette.
 *
 * Output-fidelity contract: recording never throws because a
 * PTY chunk sliced a multibyte sequence — {@see recordOutput()} defers the
 * incomplete tail to the next tap so split glyphs stay byte-exact, and any
 * remaining invalid bytes degrade to U+FFFD via
 * `JSON_INVALID_UTF8_SUBSTITUTE` at encode time. A non-UTF-8 child program
 * therefore records as replacement text rather than aborting the session;
 * this is deliberately lossy there (see the base64 decision note in
 * {@see \SugarCraft\Vcr\Format\JsonlFormat}).
 *
 * Implements {@see \SugarCraft\Core\Recorder} so it can be attached via
 * {@see \SugarCraft\Core\Program::setRecorder()}.
 *
 * Usage:
 * ```php
 * $recorder = Recorder::open('/tmp/session.cas');
 * $program = new Program($model);
 * $program->setRecorder($recorder);
 * $program->run();
 * // recorder is closed automatically when the loop ends
 * ```
 */
final class Recorder implements RecorderInterface
{
    /** @var resource|null */
    private $fh;
    private readonly float $startTime;
    private bool $closed = false;
    private HookRegistry $hooks;

    /**
     * Idle-trim threshold in seconds. When set, inter-event gaps
     * larger than this are compressed to {@see $idleTrimCompressedMaxSec}
     * and the trimmed event(s) carry a `tRaw` payload field with the
     * original wallclock-relative timestamp.
     *
     * Null disables trimming. Wired via {@see withIdleTrim()}.
     */
    private ?float $idleTrimSec = null;

    /** Compressed gap that replaces a > $idleTrimSec idle period. */
    private float $idleTrimCompressedMaxSec = 0.5;

    /**
     * Real-time stamp (seconds since startTime) of the previous event.
     * Kept unrounded so a first event with a sub-millisecond realT
     * still anchors the gap calculation for subsequent events.
     */
    private float $prevRealT = 0.0;

    /**
     * True once at least one event has been written — guards the gap
     * arithmetic so the first event never appears to be preceded by
     * a 0 → realT idle. Separate flag instead of `prevRealT > 0` because
     * the rounded prevRealT can legitimately equal 0.0 on a fast first
     * event.
     */
    private bool $hasPriorEvent = false;

    /** Cumulative seconds shaved off the timeline by all trims so far. */
    private float $cumulativeTrim = 0.0;

    /**
     * When true, events are written with `dt` (delta since previous event)
     * instead of `t` (absolute timestamp). Seeded from the CassetteHeader's
     * timestampMode at construction — the header on disk is the authority,
     * {@see withFormat()} can only confirm, never flip it.
     */
    private bool $useRelativeTimestamps = false;

    /**
     * Count of failed stream writes + flushes (C3). The recorder never
     * aborts a live session over I/O errors (best-effort ruling: the
     * child program outranks the tape), but the losses are NO LONGER
     * invisible — {@see captureWriteErrors()} surfaces them so the CLI
     * can refuse to call an incomplete capture a success.
     */
    private int $writeErrors = 0;
    private int $flushErrors = 0;

    /**
     * Cumulative absolute timestamp used to calculate deltas for relative mode.
     * Tracked separately from $cumulativeTrim so deltas reflect effective time.
     */
    private float $cumulativeEffectiveT = 0.0;

    /**
     * Bytes of an incomplete UTF-8 sequence whose start arrived but whose
     * continuation bytes did not. A PTY read loop slices the
     * output stream at arbitrary byte boundaries, so a 3-byte glyph can
     * arrive across two {@see recordOutput()} taps. Writing the torn half
     * straight into `json_encode` would either abort the session (pre-fix
     * behaviour) or rewrite it to U+FFFD (substitute-only behaviour) —
     * deferring the maximal valid UTF-8 prefix tail to the next tap keeps
     * the recorded byte stream byte-exact for the split case. The tail is
     * structurally bounded at 3 bytes (UTF-8's max continuation count), so
     * the deferral can never grow unboundedly, and a tail that the next tap
     * never completes is flushed by {@see close()} — substitution then
     * degrades only genuinely invalid bytes (a non-UTF-8 child program),
     * which no escaping could have made meaningful anyway.
     */
    private string $pendingUtf8Tail = '';

    /**
     * @param resource $fh  Open writable stream — typically a file opened
     *                      via {@see open()}, but any wb-mode stream works
     *                      (php://memory, php://temp, network sockets).
     */
    public function __construct(
        $fh,
        CassetteHeader $header,
        ?float $startTime = null,
        ?HookRegistry $hooks = null,
    ) {
        if (!is_resource($fh)) {
            throw new \InvalidArgumentException('Recorder requires an open stream resource');
        }
        $this->fh = $fh;
        $this->startTime = $startTime ?? microtime(true);
        $this->hooks = $hooks ?? new HookRegistry();
        // The header is being written by this very call, so its
        // timestampMode is the cassette's permanent contract: seed the
        // line encoder from it (a relative-mode header with absolute `t`
        // lines — the pre-fix shape — minted a file the reader could
        // never decode).
        $this->useRelativeTimestamps = $header->timestampMode === CassetteHeader::TIMESTAMP_MODE_RELATIVE;
        $this->writeLine($header->toWireArray());
    }

    /**
     * Open a cassette file at the given path for writing. Convenience
     * factory — fills in a sensible CassetteHeader (cols=80, rows=24,
     * current UTC time, candy-vcr runtime tag) when omitted; an initial
     * `recordResize` event will overwrite the placeholder dimensions.
     *
     * The file is created and immediately tightened to 0600 (owner-only).
     * Cassettes embed captured terminal output and — under `--env-all` —
     * the full environment in plaintext, so a default-umask world-readable
     * cassette is a secret leak waiting for a shared CI runner. A failed
     * chmod is fatal: half-permissioned output is worse than none.
     */
    public static function open(
        string $path,
        ?CassetteHeader $header = null,
    ): self {
        $fh = @fopen($path, 'wb');
        if ($fh === false) {
            throw new \RuntimeException("cannot open recorder file {$path}");
        }
        if (@chmod($path, 0600) === false) {
            fclose($fh);
            throw new \RuntimeException("cannot set owner-only permissions on cassette file {$path}");
        }
        return new self($fh, $header ?? self::defaultHeader());
    }

    public static function defaultHeader(int $cols = 80, int $rows = 24, string $runtime = 'sugarcraft/candy-vcr@dev'): CassetteHeader
    {
        return new CassetteHeader(
            version: CassetteHeader::CURRENT_VERSION,
            createdAt: gmdate('Y-m-d\TH:i:s\Z'),
            cols: $cols,
            rows: $rows,
            runtime: $runtime,
        );
    }

    /**
     * Add a hook to be called during recording.
     *
     * NOTE (M14, deliberate deviation from the house fluent-clone idiom):
     * the Recorder owns an OPEN STREAM resource — cloning it would fork
     * two writers onto one file handle, so `with*()` here are in-place
     * configurators that return `$this` for chaining, NOT immutable
     * derivations. Reconfiguring a shared recorder through an alias is
     * visible to every holder of the instance.
     *
     * @return $this
     */
    public function withHook(\SugarCraft\Vcr\Hook\Hook $hook): self
    {
        $this->hooks->addHook($hook);
        return $this;
    }

    /**
     * Enable idle-trim: gaps between consecutive events that exceed
     * `$thresholdSec` are compressed to `$compressedMaxSec`, and the
     * trimmed events carry a `tRaw` payload with their original
     * (uncompressed) timestamp so a replay can opt back into the
     * real cadence via `--no-trim` (player flag).
     *
     * Passing null disables trimming and resets the cumulative offset
     * so subsequent events resume at real-time.
     *
     * @return $this
     */
    public function withIdleTrim(?float $thresholdSec, float $compressedMaxSec = 0.5): self
    {
        if ($thresholdSec !== null && $thresholdSec <= 0.0) {
            throw new \InvalidArgumentException("idle-trim threshold must be > 0, got {$thresholdSec}");
        }
        if ($compressedMaxSec < 0.0) {
            throw new \InvalidArgumentException("idle-trim compressedMaxSec must be >= 0, got {$compressedMaxSec}");
        }
        $this->idleTrimSec = $thresholdSec;
        $this->idleTrimCompressedMaxSec = $compressedMaxSec;
        if ($thresholdSec === null) {
            $this->cumulativeTrim = 0.0;
        }
        return $this;
    }

    /**
     * Get the hook registry for direct manipulation.
     */
    public function hooks(): HookRegistry
    {
        return $this->hooks;
    }

    /**
     * Confirm the timestamp format against the cassette's header.
     *
     * The Recorder is a streaming writer: its header line was already on
     * disk before this call ever ran, so the header's `timestampMode` is
     * the permanent contract for the file (seeded into the line encoder by
     * the constructor). Passing {@see RelativeFormat} selects `dt` lines
     * ONLY for a relative-mode header; passing any absolute-timestamp
     * format ({@see JsonlFormat}, {@see CompressedJsonlFormat} — which
     * delegates to Jsonl) confirms `t` lines. A contradiction throws
     * instead of minting a tape whose event lines disagree with its own
     * header (pre-fix, `withFormat()` silently flipped the line encoder
     * under an absolute header — every reader then choked on the file).
     *
     * Note the recorder's on-disk layout is newline-delimited JSON in
     * both modes: absolute-side formats (Jsonl, Compressed — the
     * container choice belongs to batch writing, not the stream) only
     * confirm the mode, so a YamlFormat passed here behaves as the
     * absolute encoder it is checked against. What is NEVER tolerated is
     * a mode contradiction against the header.
     *
     * @return $this
     */
    public function withFormat(Format $f): self
    {
        $wantsRelative = $f instanceof RelativeFormat;
        if ($wantsRelative === $this->useRelativeTimestamps) {
            return $this;
        }
        if ($wantsRelative) {
            throw new \RuntimeException(
                'RelativeFormat requested against an absolute-timestamp cassette — '
                . 'pass a CassetteHeader with timestampMode "relative" when opening the recorder'
            );
        }
        throw new \RuntimeException(sprintf(
            '%s requested against a relative-timestamp cassette — the header was '
            . 'written with timestampMode "relative"; use RelativeFormat (or open with an '
            . 'absolute header)',
            $f::class
        ));
    }

    public function recordResize(int $cols, int $rows): void
    {
        if ($this->closed) {
            return;
        }
        $this->writeEvent('resize', ['cols' => $cols, 'rows' => $rows]);
    }

    public function recordInputBytes(string $bytes): void
    {
        if ($this->closed || $bytes === '') {
            return;
        }
        $this->writeEvent('input', ['b' => $bytes]);
    }

    public function recordOutput(string $bytes): void
    {
        if ($this->closed) {
            return;
        }
        // Re-join the previous tap's torn UTF-8 tail before
        // deciding what to write. An empty merged buffer means there is
        // nothing new and no completed prefix to flush — stay silent, the
        // old per-tap empty skip generalises to "skip empty writes".
        $bytes = $this->pendingUtf8Tail . $bytes;
        $this->pendingUtf8Tail = '';
        if ($bytes === '') {
            return;
        }
        [$complete, $tail] = self::splitAtUtf8Boundary($bytes);
        $this->pendingUtf8Tail = $tail;
        if ($complete !== '') {
            $this->writeEvent('output', ['b' => $complete]);
        }
    }

    /**
     * Split a byte string into the largest prefix that is safe to encode now
     * and the suffix that is the start of a not-yet-complete UTF-8 sequence.
     *
     * Only a real UTF-8 prefix (lead byte followed by fewer continuation
     * bytes than the lead demands) is deferred — anything else (lone
     * continuation runs, invalid leads, complete sequences) is reported as
     * final and reaches `JSON_INVALID_UTF8_SUBSTITUTE` in
     * {@see writeLine()} unchanged. The returned tail is therefore at most
     * 3 bytes long.
     *
     * @return array{0: string, 1: string} [complete-prefix, pending-tail]
     */
    private static function splitAtUtf8Boundary(string $bytes): array
    {
        $n = strlen($bytes);
        // A trailing ASCII byte ends the stream on a codepoint boundary.
        if ($n === 0 || ord($bytes[$n - 1]) < 0x80) {
            return [$bytes, ''];
        }
        // Walk back over continuation bytes (10xxxxxx) to the lead, at most
        // 3 of them — a valid sequence never carries more.
        for ($i = 1; $i <= 4 && $i <= $n; $i++) {
            $b = ord($bytes[$n - $i]);
            if (($b & 0xC0) === 0x80) {
                continue;
            }
            $expected = match (true) {
                ($b & 0xE0) === 0xC0 => 2,
                ($b & 0xF0) === 0xE0 => 3,
                ($b & 0xF8) === 0xF0 => 4,
                default => 1, // non-ASCII byte that cannot lead a valid
                              // sequence — invalid, not pending; substitute
            };
            if ($i < $expected) {
                return [substr($bytes, 0, $n - $i), substr($bytes, $n - $i)];
            }
            return [$bytes, '']; // the final sequence is complete
        }
        // Ran out of window without finding a lead: the trailing bytes are a
        // continuation run that no lead can complete — invalid input, not a
        // split glyph. Record it as-is and let substitution degrade it.
        return [$bytes, ''];
    }

    public function recordQuit(): void
    {
        if ($this->closed) {
            return;
        }
        $this->writeEvent('quit', []);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        // A session that ends mid-glyph must not silently drop
        // the deferred bytes — flush the pending tail as a final output event
        // while the stream is still open (substitution degrades it if it is
        // genuinely invalid, which by then nothing can complete anyway).
        if ($this->pendingUtf8Tail !== '') {
            $tail = $this->pendingUtf8Tail;
            $this->pendingUtf8Tail = '';
            $this->writeEvent('output', ['b' => $tail]);
        }
        $this->closed = true;
        if (is_resource($this->fh)) {
            // Keep: close() is teardown and runs from finally on EVERY
            // failure path; a double-close/EBADF warning raised here would
            // mask the exception actually unwinding. The is_resource guard
            // above already handles the meaningful case. A failed close is
            // still counted (C3) — buffered tail data may never reach disk.
            if (@fclose($this->fh) === false) {
                $this->writeErrors++;
            }
        }
        $this->fh = null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeEvent(string $kind, array $payload): void
    {
        $realT = microtime(true) - $this->startTime;

        if ($this->idleTrimSec !== null && $this->hasPriorEvent) {
            $gap = $realT - $this->prevRealT;
            if ($gap > $this->idleTrimSec) {
                $newGap = min($this->idleTrimSec, $this->idleTrimCompressedMaxSec);
                $this->cumulativeTrim += ($gap - $newGap);
            }
        }
        $effectiveT = round($realT - $this->cumulativeTrim, 3);
        $this->prevRealT = $realT;
        $this->hasPriorEvent = true;

        $event = new Event(
            t: $effectiveT,
            kind: EventKind::from($kind),
            payload: $payload,
        );

        // Run beforeSave hooks
        $event = $this->hooks->beforeSave($event);
        if ($event === null) {
            // Event was suppressed by a hook
            return;
        }

        // Reserved keys lead and win: `+` (not spread) means a payload entry
        // literally named `t`/`k` can never clobber the event's timing or
        // kind on the wire (JsonlFormat documents the contract).
        $line = ['t' => $event->t, 'k' => $event->kind->value] + $event->payload;
        if ($this->useRelativeTimestamps) {
            $delta = round($event->t - $this->cumulativeEffectiveT, 3);
            unset($line['t']);
            $line['dt'] = $delta;
            $this->cumulativeEffectiveT = $event->t;
        }
        if ($this->cumulativeTrim > 0.0 && !isset($line['tRaw'])) {
            // Dual-timestamp: any event after a trim carries the
            // original wallclock-relative timestamp so a replay can opt
            // back into the real cadence via --no-trim.
            $line['tRaw'] = round($realT, 3);
        }
        $this->writeLine($line);

        // Run afterCapture hooks (fire-and-forget)
        $this->hooks->afterCapture($event);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeLine(array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new \RuntimeException('json_encode failed: ' . json_last_error_msg());
        }
        if (!is_resource($this->fh)) {
            throw new \LogicException('recorder stream is not open');
        }
        $payload = $json . "\n";
        $written = @fwrite($this->fh, $payload);
        // Keep (later hardening): never ABORT the session over tape I/O —
        // but a lost write is now COUNTED, not discarded. Disk-full /
        // broken-device short writes previously vanished (fwrite's return
        // dropped entirely), and the CLI still announced success.
        if ($written === false || $written < \strlen($payload)) {
            $this->writeErrors++;
        }
        // Keep: per-event flush is best-effort — fclose() in close()
        // (and the OS) backstops the buffer; refusing the flush mid
        // recording must not abort the session. Failures are counted (C3).
        if (@fflush($this->fh) === false) {
            $this->flushErrors++;
        }
    }

    /**
     * Total tape-writing failures this recorder has absorbed (C3): short
     * or failed writes plus failed flushes, including the final close().
     * Zero means every event the recorder accepted reached the stream.
     * Non-zero means the cassette at this path is INCOMPLETE — callers
     * (the record CLI) must not report an unconditional success over it.
     */
    public function captureWriteErrors(): int
    {
        return $this->writeErrors + $this->flushErrors;
    }
}
