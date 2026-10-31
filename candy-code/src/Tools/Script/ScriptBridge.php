<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\Script;

/**
 * The parent-side NDJSON codec for the {@see ScriptRunner} bridge.
 *
 * One job: turn bytes off the socket into whole frames, and whole frames back
 * into bytes, under one hard rule — **nothing over
 * {@see MAX_FRAME_BYTES} is ever decoded**. A child is model-written code, so
 * its framing is untrusted input: an endless line with no newline must not
 * grow a parent buffer without bound, and a line that is not a JSON object
 * must not be half-parsed into an execution. Both shapes therefore arrive as
 * a synthetic {@see errorFrame()} — `id: null` when the raw bytes name no
 * usable id, the extracted id when they do — which the runner echoes and
 * {@see ScriptPrelude} surfaces to the script as an error. The frame is
 * refused, never trusted, and never silently dropped: the runner counts the
 * breach through {@see takeOversizeBytes()} so it lands in
 * {@see ScriptRun::$capBreaches}.
 *
 * This class owns no policy and calls no dispatch — it decodes, caps and
 * encodes. Methods are judged by {@see ScriptRunner}, and what the bridge
 * does NOT know is the point: a frame it cannot parse cannot reach the
 * dispatcher that knows what frames mean.
 */
final class ScriptBridge
{
    /**
     * The per-frame cap in the design (1 MiB), applied to each child-to-parent
     * line. `fgets` in the prelude mirrors the number + 1 so the child can
     * read a full legal response; a parent response never exceeds the cap
     * because {@see ScriptRunner} clips it before sending.
     */
    public const MAX_FRAME_BYTES = 1_048_576;

    /** Undecoded bytes still buffered from {@see feed()}. */
    private string $buffer = '';

    /** True while the remainder of a capped line is being discarded. */
    private bool $discarding = false;

    /** A capped line closed; {@see next()} owes the runner its refusal frame. */
    private bool $oversizePending = false;

    /** Bytes discarded past the cap on the line most recently refused. */
    private int $oversizeBytes = 0;

    /** Bytes discarded so far on the line still being discarded. */
    private int $discardedRunning = 0;

    /** Raw byte length of the line {@see next()} most recently returned. */
    private int $lastFrameBytes = 0;

    /**
     * Append child bytes to the read buffer.
     *
     * The cap is enforced HERE, not at decode: once a pending line crosses
     * {@see MAX_FRAME_BYTES} without naming a newline, the buffer stops
     * growing and the rest of that line counts as discarded until its newline
     * lands. A child that never sends a newline is bounded the same way,
     * because only ever the capped head is held.
     */
    public function feed(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }

        if ($this->discarding) {
            $newline = strpos($bytes, "\n");
            if ($newline === false) {
                $this->discardedRunning += strlen($bytes);
                return;
            }
            $this->discardedRunning += $newline;
            $this->oversizeBytes = $this->discardedRunning + self::MAX_FRAME_BYTES;
            $this->discardedRunning = 0;
            $this->discarding = false;
            $this->oversizePending = true;
            $this->feed(substr($bytes, $newline + 1));
            return;
        }

        $this->buffer .= $bytes;
        if (strlen($this->buffer) > self::MAX_FRAME_BYTES && strpos($this->buffer, "\n") === false) {
            $this->discardedRunning += strlen($this->buffer) - self::MAX_FRAME_BYTES;
            $this->buffer = '';
            $this->discarding = true;
        }
    }

    /**
     * The next whole frame, or null when the buffer holds none yet.
     *
     * A capped line answers with the refusal first, before any frame that
     * followed it — stream order is the ledger's order. Any other completed
     * line decodes to its array when it is a JSON object; every other shape
     * (bad JSON, a scalar, a blank is skipped) answers with the same
     * error-frame head, carrying the extracted id when the raw bytes named
     * one. Split and coalesced feeds make no difference to what comes out,
     * which is what the pairing test pins.
     *
     * @return array<string, mixed>|null
     */
    public function next(): ?array
    {
        $this->lastFrameBytes = 0;

        if ($this->oversizePending) {
            $this->oversizePending = false;
            $this->lastFrameBytes = $this->oversizeBytes;
            return self::errorFrame(null, sprintf(
                'frame exceeds the %d byte bridge cap',
                self::MAX_FRAME_BYTES,
            ));
        }

        $newline = strpos($this->buffer, "\n");
        if ($newline === false) {
            return null;
        }

        $line = substr($this->buffer, 0, $newline);
        $this->buffer = substr($this->buffer, $newline + 1);
        $this->lastFrameBytes = $newline + 1;

        if (trim($line) === '') {
            return $this->next();
        }

        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return self::errorFrame(self::extractId($line), 'malformed frame: not a JSON object');
    }

    /**
     * Bytes discarded on the most recently refused oversize line — read-and-
     * clear, so one breach is counted once.
     */
    public function takeOversizeBytes(): int
    {
        $bytes = $this->oversizeBytes;
        $this->oversizeBytes = 0;

        return $bytes;
    }

    /**
     * Whether a discarded line is still running (no newline yet since the cap
     * was crossed).
     *
     * The runner polls this to keep the wall clock honest: a child streaming
     * endless bytes without a newline produces no frames, and frames-never-
     * arrive is exactly what the clock is for — but it is also the shape of a
     * child merely being SLOW at the byte level, and the pause arithmetic must
     * not mistake one for the other. It never is a policy on its own.
     */
    public function discardingOversize(): bool
    {
        return $this->discarding;
    }

    /** Byte length of the line {@see next()} returned last, for the ledger. */
    public function lastFrameBytes(): int
    {
        return $this->lastFrameBytes;
    }

    /**
     * Encode a frame for the child: exactly one NDJSON line.
     *
     * A frame that cannot encode (a resource in a dispatch result, an invalid
     * UTF-8 byte in a tool's content) encodes as an error line naming why —
     * the child's read must never block on a response that does not exist.
     */
    public static function encode(array $frame): string
    {
        $encoded = json_encode($frame);
        if ($encoded === false) {
            $fallback = self::errorFrame(
                $frame['id'] ?? null,
                'script-runner: response is not JSON-encodable (' . json_last_error_msg() . ')',
            );
            $encoded = json_encode($fallback);
        }

        return ((string) $encoded) . "\n";
    }

    /**
     * The refusal frame shape. `id: null` means uncorrelatable — the prelude
     * surfaces it as an error on whatever call was outstanding.
     *
     * @return array<string, mixed>
     */
    public static function errorFrame(int|null $id, string $reason): array
    {
        return ['id' => $id, 'error' => $reason];
    }

    /**
     * The `"id":` value of an undecodable line, by token scan — a malformed
     * frame still deserves the reply it addressed.
     */
    private static function extractId(string $line): int|null
    {
        $match = [];
        if (preg_match('/"id"\s*:\s*(-?\d+)/', $line, $match) === 1) {
            return (int) $match[1];
        }

        return null;
    }
}
