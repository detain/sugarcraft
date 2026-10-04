<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host;

use SugarCraft\Crush\Message;

/**
 * One open session as a client first sees it (roadmap O-2g, Appendix O §4.3
 * `SessionHost::snapshot()`): its transcript rows, whether a turn is running
 * and which, the prompts waiting behind it, and what it has spent.
 *
 * WHAT A CLIENT NEEDS TO START FOLLOWING THE SESSION, and nothing it could
 * not render: a client that attaches mid-turn takes this, then follows the
 * session's {@see SessionEvent}s from {@see $lastSeq} on, so nothing that
 * happens between the snapshot and the subscription is missed or doubled
 * (Appendix O §6.8). Rows are carried as the {@see Message}s the host holds;
 * {@see toArray()} gives them in the shape the transcript is saved in.
 *
 * Immutable: a snapshot is a moment, and the host's next change is a new one.
 */
final class SessionSnapshot
{
    /**
     * @param list<Message> $history
     * @param list<string> $queued
     */
    private function __construct(
        public readonly string $sessionId,
        public readonly array $history,
        public readonly array $queued,
        public readonly bool $busy,
        public readonly ?string $turnId,
        public readonly float $spentUsd,
        public readonly int $lastSeq,
    ) {
    }

    /**
     * @param list<Message> $history
     * @param list<string> $queued
     */
    public static function new(
        string $sessionId,
        array $history = [],
        array $queued = [],
        bool $busy = false,
        ?string $turnId = null,
        float $spentUsd = 0.0,
        int $lastSeq = 0,
    ): self {
        return new self($sessionId, array_values($history), array_values($queued), $busy, $turnId, $spentUsd, $lastSeq);
    }

    /** The session's status word (Appendix O §6.5 `session.status`): busy or idle. */
    public function status(): string
    {
        return $this->busy ? 'busy' : 'idle';
    }

    /**
     * The wire shape: rows as they are saved ({@see Message::jsonSerialize()}),
     * plus the status the rows cannot show.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'sessionId' => $this->sessionId,
            'status' => $this->status(),
            'turnId' => $this->turnId,
            'messages' => array_map(static fn (Message $row): array => $row->jsonSerialize(), $this->history),
            'queued' => $this->queued,
            'spentUsd' => $this->spentUsd,
            'lastSeq' => $this->lastSeq,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
