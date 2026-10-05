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
     * @param list<CompressionBlock> $blocks          step summaries and
     *                                               `Compress` ranges to apply
     * @param array<string, string> $nudges          {@see NudgePolicy} anchors
     *                                               to add, row key => kind
     *                                               (roadmap 3.B-4)
     * @param bool               $clearNudges        every anchor is cleared
     *                                               first — the cooldown after
     *                                               a prune or a compress
     */
    private function __construct(
        public array $prunes,
        public array $droppedContextRows,
        public array $blocks = [],
        public array $nudges = [],
        public bool $clearNudges = false,
    ) {
    }

    public static function new(): self
    {
        return new self([], []);
    }

    public function withPrune(PruneEntry $entry): self
    {
        return $this->mutate(prunes: [...$this->prunes, $entry]);
    }

    public function withDroppedContextRow(string $key, int $tokens): self
    {
        return $this->mutate(droppedContextRows: [...$this->droppedContextRows, $key => $tokens]);
    }

    public function withBlock(CompressionBlock $block): self
    {
        return $this->mutate(blocks: [...$this->blocks, $block]);
    }

    /** This delta with a nudge of $kind anchored on row $key (roadmap 3.B-4). */
    public function withNudge(string $key, string $kind): self
    {
        return $this->mutate(nudges: [...$this->nudges, $key => $kind]);
    }

    /** This delta clearing every anchor before it adds its own. */
    public function withNudgesCleared(): self
    {
        return $this->mutate(clearNudges: true, nudges: []);
    }

    /**
     * This delta followed by $other, as one. A clear in $other drops the
     * anchors this one would have added.
     */
    public function merge(self $other): self
    {
        return new self(
            [...$this->prunes, ...$other->prunes],
            [...$this->droppedContextRows, ...$other->droppedContextRows],
            [...$this->blocks, ...$other->blocks],
            $other->clearNudges ? $other->nudges : [...$this->nudges, ...$other->nudges],
            $this->clearNudges || $other->clearNudges,
        );
    }

    public function isEmpty(): bool
    {
        return $this->prunes === [] && $this->droppedContextRows === [] && $this->blocks === []
            && $this->nudges === [] && !$this->clearNudges;
    }

    /**
     * The plain-array form a frame carries across the fork (roadmap 3.B-3's
     * {@see \SugarCraft\Crush\Events\ContextLedgerChanged}): the parent
     * unserializes with `allowed_classes => false`, so no object may ride it.
     *
     * @return array{prunes:list<array<string,mixed>>,droppedContextRows:array<string,int>,blocks:list<array<string,mixed>>,nudges?:array<string,string>,clearNudges?:bool}
     */
    public function toArray(): array
    {
        return [
            'prunes' => array_map(static fn (PruneEntry $entry): array => $entry->toArray(), $this->prunes),
            'droppedContextRows' => $this->droppedContextRows,
            'blocks' => array_map(static fn (CompressionBlock $block): array => $block->toArray(), $this->blocks),
            // Only when set, so a delta without them keeps its 3.B-3 shape.
            ...($this->nudges === [] ? [] : ['nudges' => $this->nudges]),
            ...($this->clearNudges ? ['clearNudges' => true] : []),
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
        if (($raw['clearNudges'] ?? false) === true) {
            $delta = $delta->withNudgesCleared();
        }
        foreach (is_array($raw['nudges'] ?? null) ? $raw['nudges'] : [] as $key => $kind) {
            if ((string) $key !== '' && is_string($kind) && NudgePolicy::isKind($kind)) {
                $delta = $delta->withNudge((string) $key, $kind);
            }
        }

        return $delta;
    }

    /** A copy with the named fields replaced; every other field carried. */
    private function mutate(mixed ...$changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
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
