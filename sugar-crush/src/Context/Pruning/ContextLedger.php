<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning;

use SugarCraft\Crush\Context\TurnContextBlock;
use SugarCraft\Crush\Messages\ToolResultMessage;

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
 * Holds the pruned tool results ({@see PruneEntry}, keyed by tool-call id),
 * the superseded `<turn-context>` rows to leave out (keyed by a hash of the
 * row's bytes — the row has no other identity, and two rows with the same
 * bytes say the same thing), the step summaries that stand in for the
 * start of the conversation ({@see CompressionBlock}, roadmap 2.4-1), and
 * the short REFS the model addresses tool results by ({@see RefTag}).
 *
 * ACROSS TURNS (roadmap 2.2-2). The ledger is session state: the host keeps
 * it per session ({@see \SugarCraft\Crush\Host\TurnRunner}), stores it
 * beside the transcript
 * ({@see \SugarCraft\Crush\Session\EnhancedSessionStore::saveContextLedger()}),
 * hands it to the turn ({@see \SugarCraft\Crush\Backend\EngineBackend::withContextLedger()})
 * and takes back the one the turn ends with — across the fork on the
 * `result` frame. Before that each turn started from an empty ledger and
 * re-pruned from the full history, so the cache-stable rewrite a prune
 * bought was lost at every prompt. {@see syncAgainst()} forgets what names
 * a row the conversation no longer has (a `/rewind`, a compaction).
 *
 * REFS ARE NEVER REUSED OR RENUMBERED. A tool result's ref is fixed the
 * first time a turn ends with it in the conversation ({@see withRefsAssigned()})
 * and {@see $nextRef} only grows, so `r17` names one output for the whole
 * session — the DCP #614/#551 failure class (a ref that meant one row on one
 * request and another on the next) cannot occur. Until then a result's ref
 * is PROVISIONAL ({@see refsFor()}): the next numbers in order of first
 * appearance, which is exactly what the turn's end then fixes, so every
 * request of the turn already shows the ref the result keeps.
 */
final readonly class ContextLedger
{
    /**
     * @param array<string, PruneEntry>     $prunes             by tool-call id
     * @param array<string, int>            $droppedContextRows by {@see contextRowKey()}
     *                                                          => estimated tokens
     * @param array<int, CompressionBlock>  $blocks             by block id
     * @param int                           $nextBlockId        the id the next
     *                                                          block takes; never
     *                                                          reused
     * @param array<string, int>            $refs               tool-call id => its
     *                                                          fixed ref
     * @param int                           $nextRef            the ref the next
     *                                                          result takes; never
     *                                                          reused
     */
    private function __construct(
        public array $prunes,
        public array $droppedContextRows,
        public array $blocks = [],
        public int $nextBlockId = 1,
        public array $refs = [],
        public int $nextRef = 1,
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

        return $this->mutate(prunes: [...$this->prunes, $entry->toolCallId => $entry]);
    }

    public function withDroppedContextRow(string $key, int $tokens): self
    {
        if (isset($this->droppedContextRows[$key])) {
            return $this;
        }

        return $this->mutate(droppedContextRows: [...$this->droppedContextRows, $key => $tokens]);
    }

    /**
     * This ledger with $block active. Every block already active is consumed
     * by it — a step summary covers the conversation's whole start, so the
     * newer one covers the older one's rows and its summary too. A block id
     * already present changes nothing, which keeps {@see apply()} idempotent.
     */
    public function withBlock(CompressionBlock $block): self
    {
        if (isset($this->blocks[$block->id])) {
            return $this;
        }

        $blocks = [];
        $consumed = [];
        foreach ($this->blocks as $id => $existing) {
            if ($existing->active) {
                $consumed[] = $id;
                $existing = $existing->deactivated();
            }
            $blocks[$id] = $existing;
        }
        $blocks[$block->id] = $block->withConsumedBlockIds([...$block->consumedBlockIds, ...$consumed]);

        return $this->mutate(blocks: $blocks, nextBlockId: max($this->nextBlockId, $block->id + 1));
    }

    /** The block the projection applies, or null. */
    public function activeBlock(): ?CompressionBlock
    {
        $active = null;
        foreach ($this->blocks as $block) {
            if ($block->active) {
                $active = $block;
            }
        }

        return $active;
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
        foreach ($delta->blocks as $block) {
            $ledger = $ledger->withBlock($block);
        }

        return $ledger;
    }

    /**
     * The ref every tool result in $messages is shown under: its fixed one
     * when it has one, else a PROVISIONAL one — {@see $nextRef} onwards, in
     * order of first appearance. Pure: the same rows and ledger give the same
     * refs, and appending rows never changes the refs of the rows before
     * them, which is what keeps a turn's requests byte-stable while its refs
     * are still provisional. A result with an empty call id gets none.
     *
     * @param iterable<mixed> $messages typed messages; anything else is skipped
     * @return array<string, int> tool-call id => ref
     */
    public function refsFor(iterable $messages): array
    {
        $refs = [];
        $next = $this->nextRef;
        foreach ($messages as $message) {
            if (!$message instanceof ToolResultMessage) {
                continue;
            }
            $id = $message->toolCallId();
            if ($id === '' || isset($refs[$id])) {
                continue;
            }
            $refs[$id] = $this->refs[$id] ?? $next++;
        }

        return $refs;
    }

    /**
     * This ledger with every provisional ref in $messages fixed — the refs
     * {@see refsFor()} showed, now kept. Called where a turn ends, over the
     * conversation in the order the model read it.
     *
     * @param iterable<mixed> $messages
     */
    public function withRefsAssigned(iterable $messages): self
    {
        $refs = $this->refs;
        $next = $this->nextRef;
        foreach ($this->refsFor($messages) as $id => $ref) {
            if (!isset($refs[$id])) {
                $refs[$id] = $ref;
            }
            $next = max($next, $ref + 1);
        }

        return $refs === $this->refs ? $this : $this->mutate(refs: $refs, nextRef: $next);
    }

    /** The fixed ref of $toolCallId's result, or null when it has none yet. */
    public function refOf(string $toolCallId): ?int
    {
        return $this->refs[$toolCallId] ?? null;
    }

    /** The tool-call id whose result holds the fixed ref $ref, or null. */
    public function toolCallIdForRef(int $ref): ?string
    {
        $id = array_search($ref, $this->refs, true);

        // An all-digit id is an int key in a PHP array; it is still the id.
        return $id === false ? null : (string) $id;
    }

    /**
     * This ledger with everything that names a row the conversation no longer
     * has forgotten (DCP §13.2 B "sync", its `syncCompressionBlocks`): a
     * prune or a ref whose tool call is gone, a dropped `<turn-context>` row
     * whose bytes no row carries, and — deactivated rather than removed, so
     * its id is never reused — a block whose boundary call is gone. A
     * `/rewind` past a row, or a compaction that summarised it away, leaves
     * the ledger exactly as clean as the rows. {@see $nextRef} and
     * {@see $nextBlockId} never go back.
     *
     * @param iterable<string> $callIds        every tool-call id the
     *                                         conversation still has
     * @param iterable<string> $contextRowKeys {@see contextRowKey()} of every
     *                                         `<turn-context>` row it still has
     */
    public function syncAgainst(iterable $callIds, iterable $contextRowKeys): self
    {
        $live = [];
        foreach ($callIds as $id) {
            $live[$id] = true;
        }
        $rows = [];
        foreach ($contextRowKeys as $key) {
            $rows[$key] = true;
        }

        $prunes = array_filter($this->prunes, static fn (PruneEntry $entry): bool => isset($live[$entry->toolCallId]));
        $dropped = array_filter($this->droppedContextRows, static fn (int|string $key): bool => isset($rows[$key]), ARRAY_FILTER_USE_KEY);
        $refs = array_filter($this->refs, static fn (int|string $id): bool => isset($live[$id]), ARRAY_FILTER_USE_KEY);
        $blocks = [];
        foreach ($this->blocks as $id => $block) {
            $blocks[$id] = $block->active && !isset($live[$block->keepFromToolCallId]) ? $block->deactivated() : $block;
        }

        if ($prunes === $this->prunes && $dropped === $this->droppedContextRows && $refs === $this->refs && $blocks == $this->blocks) {
            return $this;
        }

        return $this->mutate(prunes: $prunes, droppedContextRows: $dropped, blocks: $blocks, refs: $refs);
    }

    /**
     * {@see syncAgainst()} over a session's stored rows — what the host
     * holds between turns: every call id a row makes or answers, and every
     * stored `<turn-context>` row.
     *
     * @param iterable<mixed> $history root {@see \SugarCraft\Crush\Message} rows; anything else is skipped
     */
    public function syncAgainstHistory(iterable $history): self
    {
        $callIds = [];
        $contextRows = [];
        foreach ($history as $row) {
            if (!$row instanceof \SugarCraft\Crush\Message) {
                continue;
            }
            foreach ($row->toolCalls as $call) {
                if ($call instanceof \SugarCraft\Crush\ToolCall && $call->id !== null && $call->id !== '') {
                    $callIds[] = $call->id;
                }
            }
            foreach ($row->toolResults as $result) {
                if ($result instanceof \SugarCraft\Crush\ToolResult && $result->id !== null && $result->id !== '') {
                    $callIds[] = $result->id;
                }
            }
            if (TurnContextBlock::isTurnContext($row)) {
                $contextRows[] = self::contextRowKey($row->content);
            }
        }

        return $this->syncAgainst($callIds, $contextRows);
    }

    public function isEmpty(): bool
    {
        return $this->prunes === [] && $this->droppedContextRows === [] && $this->blocks === [];
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

    /** @return array{prunes:list<array<string,mixed>>,droppedContextRows:array<string,int>,blocks:list<array<string,mixed>>,nextBlockId:int,refs:array<string,int>,nextRef:int} */
    public function toArray(): array
    {
        return [
            'prunes' => array_values(array_map(static fn (PruneEntry $entry): array => $entry->toArray(), $this->prunes)),
            'droppedContextRows' => $this->droppedContextRows,
            'blocks' => array_values(array_map(static fn (CompressionBlock $block): array => $block->toArray(), $this->blocks)),
            'nextBlockId' => $this->nextBlockId,
            'refs' => $this->refs,
            'nextRef' => $this->nextRef,
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
        // Blocks are restored as written — active flag and consumed ids
        // included — not replayed through withBlock(), which would re-derive
        // which one is active from their order.
        $blocks = [];
        foreach (is_array($raw['blocks'] ?? null) ? $raw['blocks'] : [] as $row) {
            $block = CompressionBlock::fromArray($row);
            if ($block !== null) {
                $blocks[$block->id] = $block;
            }
        }
        $next = is_int($raw['nextBlockId'] ?? null) ? $raw['nextBlockId'] : 1;
        foreach ($blocks as $id => $_) {
            $next = max($next, $id + 1);
        }

        // A ref is kept only when it is a positive int no other call already
        // holds — two calls under one ref is the one corruption a reader
        // could not recover from. The next ref is past every one kept.
        $refs = [];
        $taken = [];
        // A JSON round trip turns an all-digit id into an int key; it is
        // still the id it was.
        foreach (is_array($raw['refs'] ?? null) ? $raw['refs'] : [] as $id => $ref) {
            $id = (string) $id;
            if ($id !== '' && is_int($ref) && $ref >= 1 && !isset($taken[$ref])) {
                $refs[$id] = $ref;
                $taken[$ref] = true;
            }
        }
        $nextRef = is_int($raw['nextRef'] ?? null) && $raw['nextRef'] >= 1 ? $raw['nextRef'] : 1;
        foreach ($refs as $ref) {
            $nextRef = max($nextRef, $ref + 1);
        }

        return new self($ledger->prunes, $ledger->droppedContextRows, $blocks, $next, $refs, $nextRef);
    }

    /** A copy with the named fields replaced; every other field carried. */
    private function mutate(mixed ...$changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }
}
