<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning;

/**
 * A change a {@see PruningStrategy} proposes to the {@see ContextLedger}
 * (roadmap 2.2-1, DCP §13.2 B): the tool results to prune, the superseded
 * `<turn-context>` rows to drop, and the step summaries to apply (2.4-1). {@see ContextLedger::apply()} is idempotent,
 * so a delta applied twice — or replayed after it crossed a process boundary
 * (2.2-2) — leaves the ledger as one application did.
 */
final readonly class LedgerDelta
{
    /**
     * @param list<PruneEntry>   $prunes             results to prune, keyed in
     *                                               the ledger by call id
     * @param array<string, int> $droppedContextRows `<turn-context>` row
     *                                               content hash
     *                                               ({@see ContextLedger::contextRowKey()})
     *                                               => estimated tokens freed
     * @param list<CompressionBlock> $blocks          step summaries to apply
     */
    private function __construct(
        public array $prunes,
        public array $droppedContextRows,
        public array $blocks = [],
    ) {
    }

    public static function new(): self
    {
        return new self([], []);
    }

    public function withPrune(PruneEntry $entry): self
    {
        return new self([...$this->prunes, $entry], $this->droppedContextRows, $this->blocks);
    }

    public function withDroppedContextRow(string $key, int $tokens): self
    {
        return new self($this->prunes, [...$this->droppedContextRows, $key => $tokens], $this->blocks);
    }

    public function withBlock(CompressionBlock $block): self
    {
        return new self($this->prunes, $this->droppedContextRows, [...$this->blocks, $block]);
    }

    /** This delta followed by $other, as one. */
    public function merge(self $other): self
    {
        return new self(
            [...$this->prunes, ...$other->prunes],
            [...$this->droppedContextRows, ...$other->droppedContextRows],
            [...$this->blocks, ...$other->blocks],
        );
    }

    public function isEmpty(): bool
    {
        return $this->prunes === [] && $this->droppedContextRows === [] && $this->blocks === [];
    }

    /** Estimated tokens the delta frees from the projected request. */
    public function freedTokens(): int
    {
        $tokens = array_sum($this->droppedContextRows);
        foreach ($this->prunes as $entry) {
            $tokens += $entry->tokens;
        }
        foreach ($this->blocks as $block) {
            $tokens += max(0, $block->compressedTokens - $block->summaryTokens);
        }

        return $tokens;
    }
}
