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
 */
final readonly class CompressionBlock
{
    /** The summary row opens with this, `%d` the block id. */
    public const HEADER = '[Conversation summary b%d — the harness condensed the earlier part of this conversation '
        . 'to free context; this summary replaces it]';

    /**
     * @param int       $compressedTokens estimate of the rows it replaces, as
     *                                    they were projected when summarised
     * @param int       $summaryTokens    estimate of {@see summaryRow()}
     * @param list<int> $consumedBlockIds earlier blocks this one replaced
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
    ) {
    }

    /** The user-role row the model reads in place of the rows it replaces. */
    public function summaryRow(): UserMessage
    {
        return new UserMessage(sprintf(self::HEADER, $this->id) . "\n" . $this->summary);
    }

    /**
     * Whether $message is a block's summary row — a harness row, so it never
     * counts as one of the user's turns.
     */
    public static function isSummaryRow(mixed $message): bool
    {
        return $message instanceof UserMessage && str_starts_with($message->content(), '[Conversation summary b');
    }

    public function deactivated(): self
    {
        return new self($this->id, $this->keepFromToolCallId, $this->summary, $this->compressedTokens, $this->summaryTokens, $this->by, false, $this->consumedBlockIds);
    }

    /** @param list<int> $ids */
    public function withConsumedBlockIds(array $ids): self
    {
        return new self($this->id, $this->keepFromToolCallId, $this->summary, $this->compressedTokens, $this->summaryTokens, $this->by, $this->active, array_values($ids));
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
        if (!is_string($keepFrom) || $keepFrom === '' || !is_string($summary) || $by === null) {
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
        );
    }
}
