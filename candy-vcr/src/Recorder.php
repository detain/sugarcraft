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
 * Output-fidelity contract (F1, round 90): recording never throws because a
 * PTY chunk sliced a multibyte sequence — {@see recordOutput()} defers the
 * incomplete tail to the next tap so split glyphs stay byte-exact, and any
 * remaining invalid bytes degrade to U+FFFD via
 * `JSON_INVALID_UTF8_SUBSTITUTE` at encode time. A non-UTF-8 child program
 * therefore records as replacement text rather than aborting the session;
 * this is deliberately lossy there (see the base64 decision note in
 * {@see \SugarCraft\Vcr\Format\JsonlFormat}).
 *
 * Implements {@see \SugarCraft\Core\Recorder} so it can be attached via
 * {@see \SugarCraft\Core\Program::withRecorder()}.
 *
 * Usage:
 * ```php
 * $recorder = Recorder::open('/tmp/session.cas');
 * (new Program($model))->withRecorder($recorder)->run();
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
     * instead of `t` (absolute timestamp). Set via {@see withFormat()}.
     */
    private bool $useRelativeTimestamps = false;

    /**
     * Cumulative absolute timestamp used to calculate deltas for relative mode.
     * Tracked separately from $cumulativeTrim so deltas reflect effective time.
     */
    private float $cumulativeEffectiveT = 0.0;

    /**
     * Bytes of an incomplete UTF-8 sequence whose start arrived but whose
     * continuation bytes did not (F1, round 90). A PTY read loop slices the
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
        $headerLine = [
            'v' => $header->version,
            'created' => $header->createdAt,
            'cols' => $header->cols,
            'rows' => $header->rows,
            'runtime' => $header->runtime,
        ];
        if ($header->timestampMode !== CassetteHeader::TIMESTAMP_MODE_ABSOLUTE) {
            $headerLine['timestampMode'] = $header->timestampMode;
        }
        if ($header->env !== []) {
            $headerLine['env'] = $header->env;
        }
        $this->writeLine($headerLine);
    }

    /**
     * Open a cassette file at the given path for writing. Convenience
     * factory — fills in a sensible CassetteHeader (cols=80, rows=24,
     * current UTC time, candy-vcr runtime tag) when omitted; an initial
     * `recordResize` event will overwrite the placeholder dimensions.
     */
    public static function open(
        string $path,
        ?CassetteHeader $header = null,
    ): self {
        $fh = @fopen($path, 'wb');
        if ($fh === false) {
            throw new \RuntimeException("candy-vcr: cannot open recorder file {$path}");
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
     * Select the cassette format for timestamp encoding. Currently only
     * {@see RelativeFormat} is supported for relative timestamps; all other
     * formats produce absolute `t` values.
     *
     * @return $this
     */
    public function withFormat(Format $f): self
    {
        $this->useRelativeTimestamps = $f instanceof RelativeFormat;
        return $this;
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
        // F1 (round 90): re-join the previous tap's torn UTF-8 tail before
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
        // F1 (round 90): a session that ends mid-glyph must not silently drop
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
            // E712 keep: close() is teardown and runs from finally on EVERY
            // failure path; a double-close/EBADF warning raised here would
            // mask the exception actually unwinding. The is_resource guard
            // above already handles the meaningful case.
            @fclose($this->fh);
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

        $line = ['t' => $event->t, 'k' => $event->kind->value, ...$event->payload];
        if ($this->useRelativeTimestamps) {
            $delta = round($event->t - $this->cumulativeEffectiveT, 3);
            unset($line['t']);
            $line['dt'] = $delta;
            $this->cumulativeEffectiveT = $event->t;
        }
        if ($this->cumulativeTrim > 0.0 && !isset($line['tRaw'])) {
            // P6.5.3 dual-timestamp: any event after a trim carries the
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
            throw new \RuntimeException('candy-vcr: json_encode failed: ' . json_last_error_msg());
        }
        if (!is_resource($this->fh)) {
            throw new \LogicException('candy-vcr: recorder stream is not open');
        }
        fwrite($this->fh, $json . "\n");
        // E712 keep: per-event flush is best-effort — fclose() in close()
        // (and the OS) backstops the buffer; refusing the flush mid
        // recording must not abort the session.
        @fflush($this->fh);
    }
}
