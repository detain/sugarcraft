<?php

declare(strict_types=1);

namespace SugarCraft\Crush\LSP;

/**
 * What one {@see LspConnection} exchange leaves behind for the next one — in
 * whichever process that next one runs (audit B7).
 *
 * The two halves of a stdio connection fail differently when the process
 * driving an exchange dies, so they are tracked separately:
 *
 *  - STDOUT is described by {@see $phase}. While it is CLEAN or WRITING, the
 *    server's stdout is exactly {@see $buffer} followed by whatever is still
 *    in the pipe, starting at a frame boundary. READING means the holder had
 *    taken the buffer into its own memory and was consuming the pipe: if it
 *    died there, the next reader may start in the middle of a frame and has
 *    to resynchronise.
 *  - STDIN is described by the FRAME record: a `Content-Length` frame being
 *    written ({@see $frameLength} > 0, its bytes in the lock's frame file),
 *    how much of it the server has already been sent ({@see $frameWritten}),
 *    and whether a write syscall was in flight when the record was taken
 *    ({@see $frameInflight}). Content-Length framing has no resync point, so
 *    a half-written frame is either FINISHED by the next holder (the record
 *    says exactly how much is owed) or — when an in-flight write makes the
 *    count unknowable — the stream is latched {@see $broken} for everyone.
 *
 * {@see $noteSeq} is the sequence number of the newest server notification in
 * the shared journal, so a process can tell whether it has any to replay
 * without reading the journal on every exchange.
 */
final class LspExchangeState
{
    /** Between exchanges: stdout is {@see $buffer} + pipe, at a frame boundary. */
    public const PHASE_CLEAN = 'C';

    /** A holder is writing; stdout is still {@see $buffer} + pipe. */
    public const PHASE_WRITING = 'W';

    /** A holder owns the read buffer privately; stdout may now be mid-frame. */
    public const PHASE_READING = 'R';

    private function __construct(
        public readonly string $phase,
        public readonly string $buffer,
        public readonly bool $broken,
        public readonly int $frameLength,
        public readonly int $frameWritten,
        public readonly bool $frameInflight,
        public readonly int $noteSeq,
    ) {
    }

    /** A fresh connection: clean stdout, nothing owed on stdin, no notes yet. */
    public static function new(): self
    {
        return new self(self::PHASE_CLEAN, '', false, 0, 0, false, 0);
    }

    /**
     * Decode what {@see encode()} wrote. Anything unreadable is treated as a
     * holder that died mid-read — the one assumption that never trusts a
     * buffer it cannot vouch for.
     */
    public static function decode(string $raw): self
    {
        $data = $raw === '' ? false : @unserialize($raw, ['allowed_classes' => false]);

        if (!is_array($data)
            || !in_array($data['phase'] ?? null, [self::PHASE_CLEAN, self::PHASE_WRITING, self::PHASE_READING], true)) {
            return self::new()->withPhase(self::PHASE_READING);
        }

        return new self(
            $data['phase'],
            is_string($data['buffer'] ?? null) ? $data['buffer'] : '',
            (bool) ($data['broken'] ?? false),
            max(0, (int) ($data['frameLength'] ?? 0)),
            max(0, (int) ($data['frameWritten'] ?? 0)),
            (bool) ($data['frameInflight'] ?? false),
            max(0, (int) ($data['noteSeq'] ?? 0)),
        );
    }

    public function encode(): string
    {
        return serialize([
            'phase' => $this->phase,
            'buffer' => $this->buffer,
            'broken' => $this->broken,
            'frameLength' => $this->frameLength,
            'frameWritten' => $this->frameWritten,
            'frameInflight' => $this->frameInflight,
            'noteSeq' => $this->noteSeq,
        ]);
    }

    public function withPhase(string $phase): self
    {
        return $this->mutate(phase: $phase);
    }

    public function withBuffer(string $buffer): self
    {
        return $this->mutate(buffer: $buffer);
    }

    public function withBroken(bool $broken): self
    {
        return $this->mutate(broken: $broken);
    }

    /** Record a frame being written: its length, bytes sent so far, write in flight. */
    public function withFrame(int $length, int $written, bool $inflight): self
    {
        return $this->mutate(frameLength: $length, frameWritten: $written, frameInflight: $inflight);
    }

    /** Nothing owed on stdin any more. */
    public function withoutFrame(): self
    {
        return $this->withFrame(0, 0, false);
    }

    public function withNoteSeq(int $noteSeq): self
    {
        return $this->mutate(noteSeq: $noteSeq);
    }

    /** Does stdin still owe the server part of a frame? */
    public function owesFrame(): bool
    {
        return $this->frameLength > 0 && $this->frameWritten < $this->frameLength;
    }

    private function mutate(
        ?string $phase = null,
        ?string $buffer = null,
        ?bool $broken = null,
        ?int $frameLength = null,
        ?int $frameWritten = null,
        ?bool $frameInflight = null,
        ?int $noteSeq = null,
    ): self {
        return new self(
            $phase ?? $this->phase,
            $buffer ?? $this->buffer,
            $broken ?? $this->broken,
            $frameLength ?? $this->frameLength,
            $frameWritten ?? $this->frameWritten,
            $frameInflight ?? $this->frameInflight,
            $noteSeq ?? $this->noteSeq,
        );
    }
}
