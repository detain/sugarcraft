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

    /**
     * The plain-array form a frame carries across the fork (roadmap 3.B-3's
     * {@see \SugarCraft\Crush\Events\ContextLedgerChanged}): the parent
     * unserializes with `allowed_classes => false`, so no object may ride it.
     *
     * @return array{prunes:list<array<string,mixed>>,droppedContextRows:array<string,int>,blocks:list<array<string,mixed>>}
     */
    public function toArray(): array
    {
        return [
            'prunes' => array_map(static fn (PruneEntry $entry): array => $entry->toArray(), $this->prunes),
            'droppedContextRows' => $this->droppedContextRows,
            'blocks' => array_map(static fn (CompressionBlock $block): array => $block->toArray(), $this->blocks),
        ];
    }

    /**
     * Rebuild from {@see toArray()}, LENIENTLY like
     * {@see ContextLedger::fromArray()}: an entry it cannot read is skipped,
     * and anything that is not an array is an empty delta. Applying a delta
     * is idempotent, so one that lost an entry costs only that entry.
     */
    public static function fromArray(mixed $raw): self
    {
        $delta = self::new();
        if (!is_array($raw)) {
            return $delta;
        }
        foreach (is_array($raw['prunes'] ?? null) ? $raw['prunes'] : [] as $row) {
            $entry = PruneEntry::fromArray($row);
            if ($entry !== null) {
                $delta = $delta->withPrune($entry);
            }
        }
        foreach (is_array($raw['droppedContextRows'] ?? null) ? $raw['droppedContextRows'] : [] as $key => $tokens) {
            if (is_string($key) && $key !== '' && is_int($tokens)) {
                $delta = $delta->withDroppedContextRow($key, $tokens);
            }
        }
        foreach (is_array($raw['blocks'] ?? null) ? $raw['blocks'] : [] as $row) {
            $block = CompressionBlock::fromArray($row);
            if ($block !== null) {
                $delta = $delta->withBlock($block);
            }
        }

        return $delta;
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
