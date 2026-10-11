<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host;

use SugarCraft\Crush\Message;
use SugarCraft\Crush\ToolResult;

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
 * {@see toArray()} gives them in the shape the transcript is saved in, plus
 * the media refs {@see mediaBearingRow()} folds onto finished calls.
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
     * The wire shape: rows as they are saved ({@see Message::jsonSerialize()})
     * with the finished calls' media refs folded in ({@see mediaBearingRow()}),
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
            'messages' => array_map(self::mediaBearingRow(...), $this->history),
            'queued' => $this->queued,
            'spentUsd' => $this->spentUsd,
            'lastSeq' => $this->lastSeq,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * One saved row as the snapshot carries it: the persisted shape plus, on
     * each finished tool entry that names an artifact, the `media` list and —
     * only when the cap bites — `mediaTruncated`, built by the very same
     * {@see TranscriptProjector::mediaRefs()} that stamps `tool.finished`
     * (W7 snapshot fold, R16(a): a reload must show the same MediaCards a live
     * tab saw, so the row and the event must never hold two derivations).
     *
     * The fold is wire-only: the saved row ({@see Message::jsonSerialize()})
     * stays as it was, because a ref is derived data — derived at read time it
     * always agrees with the row it describes; persisted, it could go stale
     * against a later ref law. A result without an artifact path carries no
     * keys at all, so every media-less row serialises byte-for-byte as before.
     */
    private static function mediaBearingRow(Message $message): array
    {
        $row = $message->jsonSerialize();
        foreach ($message->toolResults as $index => $result) {
            if (!$result instanceof ToolResult) {
                continue;
            }
            $truncated = false;
            $media = TranscriptProjector::mediaRefs($result, $truncated);
            if ($media === null) {
                continue;
            }
            $row['toolResults'][$index]['media'] = $media;
            if ($truncated) {
                $row['toolResults'][$index]['mediaTruncated'] = true;
            }
        }

        return $row;
    }
}
