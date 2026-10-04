<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning;

/**
 * What has been taken out of the model's view of a conversation, kept apart
 * from the conversation itself (roadmap 2.2-1, DCP §13.2 B).
 *
 * The history is never rewritten: a turn's rows stay exactly as they
 * happened, and the {@see ContextProjector} builds each request from the rows
 * plus this ledger. That separation is what makes pruning safe to undo, to
 * persist (2.2-2) and to reason about for the prompt cache — the bytes a
 * request sends change only when the ledger does, and the ledger changes only
 * at a few deliberate points (today: the engine's over-budget emergency).
 *
 * Holds the pruned tool results ({@see PruneEntry}, keyed by tool-call id) and
 * the superseded `<turn-context>` rows to leave out (keyed by a hash of the
 * row's bytes — the row has no other identity, and two rows with the same
 * bytes say the same thing).
 */
final readonly class ContextLedger
{
    /**
     * @param array<string, PruneEntry> $prunes             by tool-call id
     * @param array<string, int>        $droppedContextRows by {@see contextRowKey()}
     *                                                      => estimated tokens
     */
    private function __construct(
        public array $prunes,
        public array $droppedContextRows,
    ) {
    }

    public static function new(): self
    {
        return new self([], []);
    }

    /** The key a `<turn-context>` row's bytes are filed under. */
    public static function contextRowKey(string $content): string
    {
        return hash('xxh128', $content);
    }

    /** This ledger with $entry; a call already pruned keeps its first entry. */
    public function withPrune(PruneEntry $entry): self
    {
        if (isset($this->prunes[$entry->toolCallId])) {
            return $this;
        }

        return new self([...$this->prunes, $entry->toolCallId => $entry], $this->droppedContextRows);
    }

    public function withDroppedContextRow(string $key, int $tokens): self
    {
        if (isset($this->droppedContextRows[$key])) {
            return $this;
        }

        return new self($this->prunes, [...$this->droppedContextRows, $key => $tokens]);
    }

    /** $delta applied; applying the same delta again changes nothing. */
    public function apply(LedgerDelta $delta): self
    {
        $ledger = $this;
        foreach ($delta->prunes as $entry) {
            $ledger = $ledger->withPrune($entry);
        }
        foreach ($delta->droppedContextRows as $key => $tokens) {
            $ledger = $ledger->withDroppedContextRow((string) $key, $tokens);
        }

        return $ledger;
    }

    public function isEmpty(): bool
    {
        return $this->prunes === [] && $this->droppedContextRows === [];
    }

    public function isPruned(string $toolCallId): bool
    {
        return isset($this->prunes[$toolCallId]);
    }

    public function prune(string $toolCallId): ?PruneEntry
    {
        return $this->prunes[$toolCallId] ?? null;
    }

    public function dropsContextRow(string $content): bool
    {
        return isset($this->droppedContextRows[self::contextRowKey($content)]);
    }

    /** @return array{prunes:list<array<string,mixed>>,droppedContextRows:array<string,int>} */
    public function toArray(): array
    {
        return [
            'prunes' => array_values(array_map(static fn (PruneEntry $entry): array => $entry->toArray(), $this->prunes)),
            'droppedContextRows' => $this->droppedContextRows,
        ];
    }

    /**
     * Rebuild from {@see toArray()}, LENIENTLY (DCP's `loadPruneMessagesState`):
     * an entry it cannot read is skipped rather than failing the whole ledger,
     * since losing one prune costs only context while refusing the ledger
     * would lose every other one with it. Anything that is not an array is an
     * empty ledger.
     */
    public static function fromArray(mixed $raw): self
    {
        $ledger = self::new();
        if (!is_array($raw)) {
            return $ledger;
        }
        foreach (is_array($raw['prunes'] ?? null) ? $raw['prunes'] : [] as $row) {
            $entry = PruneEntry::fromArray($row);
            if ($entry !== null) {
                $ledger = $ledger->withPrune($entry);
            }
        }
        foreach (is_array($raw['droppedContextRows'] ?? null) ? $raw['droppedContextRows'] : [] as $key => $tokens) {
            if (is_string($key) && $key !== '' && is_int($tokens)) {
                $ledger = $ledger->withDroppedContextRow($key, $tokens);
            }
        }

        return $ledger;
    }
}
