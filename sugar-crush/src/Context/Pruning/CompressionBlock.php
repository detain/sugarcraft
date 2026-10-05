<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning;

use SugarCraft\Crush\Messages\UserMessage;

/**
 * A summary that stands in for the start of a conversation (roadmap 2.4-1,
 * DCP §13.2 B), written by the harness when a step's request is still over
 * its budget after the deterministic prune
 * ({@see \SugarCraft\Crush\Context\Compaction\StepSummarizer}).
 *
 * The {@see ContextProjector} drops every row before the assistant row that
 * issued {@see $keepFromToolCallId} and puts {@see summaryRow()} in their
 * place; that row and everything after it are sent verbatim. The boundary is
 * named by a tool-call id because that is the one identity a row has on every
 * path today (unique per session since step 0.2), and it always names a step
 * opener, so no call is ever cut from its result. If the row is gone — the
 * conversation was rewound or compacted past it — the block is inert.
 *
 * A later block covers everything an earlier one did and more, so it
 * CONSUMES it ({@see ContextLedger::withBlock()}): only one block is active.
 *
 * RANGE BLOCKS (roadmap 3.B-4, DCP §13.2 F `Compress`). The model's own
 * `Compress` call writes a block over a CLOSED RANGE of the conversation
 * rather than its whole start: {@see $fromKey} .. {@see $toKey} name the
 * range's first and last rows ({@see ContextLedger::boundaryKey()} — a step
 * by the call id its opener issued, a user row by its ref key), and the
 * {@see ContextProjector} drops the rows between them and puts
 * {@see summaryRow()} in their place. Several range blocks are active at once,
 * one per closed range; a range block that covers an earlier one CONSUMES it
 * (its id in {@see $consumedBlockIds}, its summary standing in for the
 * `(bN)` placeholder in this one's). The person can take one back
 * (`/decompress bN`, {@see $deactivatedByUser}) and restore it
 * (`/recompress bN`).
 */
final readonly class CompressionBlock
{
    /** The summary row opens with this, `%d` the block id. */
    public const HEADER = '[Conversation summary b%d — the harness condensed the earlier part of this conversation '
        . 'to free context; this summary replaces it]';

    /**
     * A range block's summary row opens with this: the block id, its topic,
     * and the refs of the range it replaces.
     */
    public const RANGE_HEADER = '[Compressed section b%d: "%s" — replaces %s…%s]';

    /** The placeholder a range summary writes for a block it covers. */
    public const PLACEHOLDER = '(b%d)';

    /**
     * @param int         $compressedTokens estimate of the rows it replaces, as
     *                                      they were projected when summarised
     * @param int         $summaryTokens    estimate of {@see summaryRow()}
     * @param list<int>   $consumedBlockIds earlier blocks this one replaced
     * @param string|null $topic            a range block's label (null: a step summary)
     * @param string|null $fromKey          a range block's first row
     * @param string|null $toKey            a range block's last row
     * @param int|null    $fromRef          the ref the range starts at, as the model named it
     * @param int|null    $toRef            the ref it ends at
     * @param bool        $deactivatedByUser taken back by `/decompress`
     */
    public function __construct(
        public int $id,
        public string $keepFromToolCallId,
        public string $summary,
        public int $compressedTokens,
        public int $summaryTokens,
        public PruneAuthor $by,
        public bool $active = true,
        public array $consumedBlockIds = [],
        public ?string $topic = null,
        public ?string $fromKey = null,
        public ?string $toKey = null,
        public ?int $fromRef = null,
        public ?int $toRef = null,
        public bool $deactivatedByUser = false,
    ) {
    }

    /**
     * A range block (roadmap 3.B-4): the model's summary of the rows from
     * $fromKey to $toKey, inclusive.
     *
     * @param list<int> $consumedBlockIds the range blocks it covers
     */
    public static function range(
        int $id,
        string $topic,
        string $fromKey,
        string $toKey,
        int $fromRef,
        int $toRef,
        string $summary,
        int $compressedTokens,
        int $summaryTokens,
        array $consumedBlockIds = [],
    ): self {
        return new self($id, '', $summary, $compressedTokens, $summaryTokens, PruneAuthor::Model, true, array_values($consumedBlockIds), $topic, $fromKey, $toKey, $fromRef, $toRef);
    }

    /** Whether this is a {@see range()} block rather than a step summary. */
    public function isRange(): bool
    {
        return $this->fromKey !== null && $this->toKey !== null;
    }

    /** `b3` — how the model and the person name it. */
    public function label(): string
    {
        return 'b' . $this->id;
    }

    /**
     * The user-role row the model reads in place of the rows it replaces.
     * A range block's $expanded is its summary with every `(bN)` placeholder
     * already replaced ({@see ContextProjector}); a step summary has none.
     */
    public function summaryRow(?string $expanded = null): UserMessage
    {
        if (!$this->isRange()) {
            return new UserMessage(sprintf(self::HEADER, $this->id) . "\n" . $this->summary);
        }

        return new UserMessage(sprintf(
            self::RANGE_HEADER,
            $this->id,
            $this->topic ?? '',
            RefTag::label((int) $this->fromRef),
            RefTag::label((int) $this->toRef),
        ) . "\n" . ($expanded ?? $this->summary));
    }

    /**
     * Whether $message is a block's summary row — a harness row, so it never
     * counts as one of the user's turns.
     */
    public static function isSummaryRow(mixed $message): bool
    {
        return $message instanceof UserMessage
            && (str_starts_with($message->content(), '[Conversation summary b') || str_starts_with($message->content(), '[Compressed section b'));
    }

    /**
     * The block ids $summary names as `(bN)` placeholders, in order, each as
     * often as it is named.
     *
     * @return list<int>
     */
    public static function placeholdersIn(string $summary): array
    {
        preg_match_all('/\(b(\d+)\)/', $summary, $m);

        return array_map('intval', $m[1]);
    }

    public function deactivated(): self
    {
        return $this->mutate(active: false);
    }

    /** Active again (a consumer was taken back, or `/recompress`). */
    public function reactivated(): self
    {
        return $this->mutate(active: true, deactivatedByUser: false);
    }

    /** Taken back by the person (`/decompress`). */
    public function decompressed(): self
    {
        return $this->mutate(active: false, deactivatedByUser: true);
    }

    /** @param list<int> $ids */
    public function withConsumedBlockIds(array $ids): self
    {
        return $this->mutate(consumedBlockIds: array_values($ids));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'keepFromToolCallId' => $this->keepFromToolCallId,
            'summary' => $this->summary,
            'compressedTokens' => $this->compressedTokens,
            'summaryTokens' => $this->summaryTokens,
            'by' => $this->by->value,
            'active' => $this->active,
            'consumedBlockIds' => $this->consumedBlockIds,
            // A step summary keeps the shape it was stored in before 3.B-4.
            ...($this->isRange() ? [
                'topic' => $this->topic,
                'fromKey' => $this->fromKey,
                'toKey' => $this->toKey,
                'fromRef' => $this->fromRef,
                'toRef' => $this->toRef,
                'deactivatedByUser' => $this->deactivatedByUser,
            ] : []),
        ];
    }

    /** Rebuild from {@see toArray()}; null for anything it did not write. */
    public static function fromArray(mixed $raw): ?self
    {
        if (!is_array($raw) || !is_int($raw['id'] ?? null) || $raw['id'] < 1) {
            return null;
        }
        $keepFrom = $raw['keepFromToolCallId'] ?? null;
        $summary = $raw['summary'] ?? null;
        $by = is_string($raw['by'] ?? null) ? PruneAuthor::tryFrom($raw['by']) : null;
        $fromKey = is_string($raw['fromKey'] ?? null) && $raw['fromKey'] !== '' ? $raw['fromKey'] : null;
        $toKey = is_string($raw['toKey'] ?? null) && $raw['toKey'] !== '' ? $raw['toKey'] : null;
        $range = $fromKey !== null && $toKey !== null
            && is_int($raw['fromRef'] ?? null) && is_int($raw['toRef'] ?? null) && is_string($raw['topic'] ?? null);
        if (!is_string($keepFrom) || ($keepFrom === '' && !$range) || !is_string($summary) || $by === null) {
            return null;
        }
        $consumed = array_values(array_filter(is_array($raw['consumedBlockIds'] ?? null) ? $raw['consumedBlockIds'] : [], 'is_int'));

        return new self(
            $raw['id'],
            $keepFrom,
            $summary,
            is_int($raw['compressedTokens'] ?? null) ? $raw['compressedTokens'] : 0,
            is_int($raw['summaryTokens'] ?? null) ? $raw['summaryTokens'] : 0,
            $by,
            ($raw['active'] ?? true) === true,
            $consumed,
            $range ? $raw['topic'] : null,
            $range ? $fromKey : null,
            $range ? $toKey : null,
            $range ? $raw['fromRef'] : null,
            $range ? $raw['toRef'] : null,
            $range && ($raw['deactivatedByUser'] ?? false) === true,
        );
    }

    /** A copy with the named fields replaced; every other field carried. */
    private function mutate(mixed ...$changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }
}
