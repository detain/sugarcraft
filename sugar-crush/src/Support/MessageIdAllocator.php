<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

/**
 * Hands out transcript row identities for ONE session (roadmap 1.B-1): a
 * monotonic ref (`1, 2, 3, …`, never reused) and the storage id derived from
 * it, `m_<session>_<ref>`.
 *
 * WHY REFS NEVER COME FROM POSITIONS. A row's index shifts the moment anything
 * before it is compacted away, rewound or inserted; a pruning ledger or a
 * server event that named "row 17" would then silently name another row (the
 * failure class opencode-DCP hit, its #551/#614). So a ref is given once, the
 * high-water mark is persisted beside the transcript, and a ref seen on any
 * row - loaded or saved - only ever pushes that mark up.
 *
 * MUTABLE, ON PURPOSE, like {@see \SugarCraft\Crush\Session\DebouncedTranscriptWriter}:
 * it is the counter every save of the session in this process shares. It
 * knows nothing about which row got which ref - remembering that per
 * {@see \SugarCraft\Crush\Message} instance is the store's job
 * ({@see \SugarCraft\Crush\Session\EnhancedSessionStore::saveTranscript()}),
 * because a row keeps its identity across sessions: a `/branch` fork's rows
 * are its parent's rows.
 */
final class MessageIdAllocator
{
    private function __construct(
        private readonly string $sessionId,
        private int $nextRef,
    ) {}

    /**
     * An allocator for $sessionId whose next ref is $nextRef (clamped to 1).
     */
    public static function new(string $sessionId, int $nextRef = 1): self
    {
        return new self($sessionId, max(1, $nextRef));
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    /** The ref the next fresh row would be given - the persisted high-water mark. */
    public function nextRef(): int
    {
        return $this->nextRef;
    }

    /**
     * Record that $ref is taken, so it is never handed out again. A ref below
     * 1 is not a ref and is ignored.
     */
    public function observe(?int $ref): void
    {
        if ($ref !== null && $ref >= $this->nextRef) {
            $this->nextRef = $ref + 1;
        }
    }

    /** Take the next ref. */
    public function allocate(): int
    {
        return $this->nextRef++;
    }

    /**
     * The storage id for $ref in this session: `m_<session>_<ref>`. The
     * session part is reduced to `[A-Za-z0-9_-]` - the charset every
     * provider accepts in an id - so a hand-named session cannot put a
     * quote, a slash or a space into one.
     */
    public function idFor(int $ref): string
    {
        $session = preg_replace('/[^A-Za-z0-9_-]/', '-', $this->sessionId) ?? '';

        return 'm_' . ($session === '' ? 'session' : $session) . '_' . $ref;
    }

    /**
     * A complete `[id, ref]` from whatever part of one a row carries: both
     * kept when both are there (the ref observed), an id-only row given a
     * fresh ref, a ref-only row the id derived from it, a bare row both.
     *
     * @return array{0: string, 1: int}
     */
    public function identityFor(?string $id, ?int $ref): array
    {
        $id = $id === '' ? null : $id;
        if ($ref !== null && $ref >= 1) {
            $this->observe($ref);
        } else {
            $ref = $this->allocate();
        }

        return [$id ?? $this->idFor($ref), $ref];
    }
}
