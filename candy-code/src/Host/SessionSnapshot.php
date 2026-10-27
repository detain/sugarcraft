<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host;

use SugarCraft\Code\Message;
use SugarCraft\Code\Support\MediaStore;
use SugarCraft\Code\ToolResult;

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
 * {@see pagedArray()} is the same moment with the `messages` window capped, so
 * a long session cannot outgrow one server frame (ISS-058).
 */
final class SessionSnapshot
{
    /**
     * Most rows one paged snapshot may carry (ISS-058): the whole transcript
     * in one answer passed {@see \SugarCraft\Code\Protocol\Dispatcher::MAX_SERVER_FRAME_BYTES}
     * for long sessions, and the answer came back `too_large`.
     */
    public const MAX_PAGE_ROWS = 500;

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
        /**
         * SES-1 (≥ law): whether {@see $spentUsd} is a LOWER BOUND because
         * the session counted a call from a model with no price on file —
         * the companion bit the dollar surfaces all carry, so a client
         * never renders a blind sum as a measurement. Additive: hosts that
         * do not know it pass nothing, and the wire key stays absent.
         */
        public readonly bool $spendUnpriced = false,
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
        bool $spendUnpriced = false,
    ): self {
        return new self($sessionId, array_values($history), array_values($queued), $busy, $turnId, $spentUsd, $lastSeq, $spendUnpriced);
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
        return $this->shape($this->history, null);
    }

    /**
     * The wire shape with the `messages` window paged (ISS-058): the newest
     * $limit rows strictly OLDER than $beforeIndex — the row AT that index is
     * excluded, the bound is exclusive-upper — still oldest-row-first inside
     * the page. Null $beforeIndex windows over the whole history. Null $limit
     * answers exactly {@see toArray()}. A paged answer
     * says where its window sits — `totalMessages`, the `oldestIndex` of its
     * first row in the full history, and `hasMoreOlder` — so a client that
     * wants the beginning pages older with `beforeIndex = oldestIndex`
     * (indices are counted from the first row, which appending never shifts).
     *
     * @return array<string, mixed>
     */
    public function pagedArray(?int $limit, ?int $beforeIndex = null): array
    {
        if ($limit === null) {
            return $this->toArray();
        }

        $total = count($this->history);
        $upper = min($total, max(0, $beforeIndex ?? $total));
        $lower = max(0, $upper - $limit);

        return $this->shape(
            array_slice($this->history, $lower, $upper - $lower),
            ['totalMessages' => $total, 'oldestIndex' => $lower, 'hasMoreOlder' => $lower > 0],
        );
    }

    /**
     * @param list<Message> $rows
     * @param array<string, mixed>|null $page the paging meta, null for the whole transcript
     * @return array<string, mixed>
     */
    private function shape(array $rows, ?array $page): array
    {
        $store = $this->storeForRows($rows);
        $shape = array_filter([
            'sessionId' => $this->sessionId,
            'status' => $this->status(),
            'turnId' => $this->turnId,
            'messages' => array_map(
                static fn (Message $row): array => self::mediaBearingRow($row, $store),
                $rows,
            ),
            'queued' => $this->queued,
            'spentUsd' => $this->spentUsd,
            'lastSeq' => $this->lastSeq,
            // SES-1: only when set, on the Message::jsonSerialize precedent —
            // a priced snapshot serialises byte-for-byte as before, and a
            // client reads `payload['spendUnpriced'] ?? false` tolerantly.
            ...($this->spendUnpriced ? ['spendUnpriced' => true] : []),
        ], static fn (mixed $value): bool => $value !== null);

        return $page === null ? $shape : $shape + $page;
    }

    /**
     * The session store the media fold adopts through, or null — opened
     * through {@see TranscriptProjector::mediaStoreForSession()} so the live
     * event and this re-fold judge paths against one derivation (R16(a)).
     * Built once per shaped page, and only when some row actually names an
     * artifact: a session of plain `Bash` rows never mints a media
     * directory on the strength of a snapshot alone.
     *
     * @param list<Message> $rows
     */
    private function storeForRows(array $rows): ?MediaStore
    {
        foreach ($rows as $row) {
            foreach ($row->toolResults as $result) {
                if ($result instanceof ToolResult && $result->imagePath !== null) {
                    return TranscriptProjector::mediaStoreForSession($this->sessionId);
                }
            }
        }

        return null;
    }

    /**
     * One saved row as the snapshot carries it: the persisted shape plus, on
     * each finished tool entry that names an artifact, the `media` list and —
     * only when the cap bites — `mediaTruncated`, built by the very same
     * {@see TranscriptProjector::mediaRefs()} that stamps `tool.finished`
     * (W7 snapshot fold, R16(a): a reload must show the same MediaCards a live
     * tab saw, so the row and the event must never hold two derivations).
     * C-WEB-1.d extends the shared fold: with $store the same adoption runs
     * here that the live event ran, and adoption is deterministic in the
     * persisted (id, path) pair, so the reload names the very artifact the
     * live tab fetched — reusing its copy rather than writing a second one.
     *
     * The fold is wire-only: the saved row ({@see Message::jsonSerialize()})
     * stays as it was, because a ref is derived data — derived at read time it
     * always agrees with the row it describes; persisted, it could go stale
     * against a later ref law. A result whose path the store neither owns nor
     * can adopt carries no keys at all, so every media-less row serialises
     * byte-for-byte as before.
     */
    private static function mediaBearingRow(Message $message, ?MediaStore $store): array
    {
        $row = $message->jsonSerialize();
        foreach ($message->toolResults as $index => $result) {
            if (!$result instanceof ToolResult) {
                continue;
            }
            $truncated = false;
            $media = TranscriptProjector::mediaRefs($result, $truncated, $store);
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
