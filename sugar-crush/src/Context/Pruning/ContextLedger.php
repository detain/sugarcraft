<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning;

use SugarCraft\Crush\Context\TurnContextBlock;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;

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
 *
 * USER ROWS HAVE REFS TOO (roadmap 3.B-4), so a `Compress` range can start
 * or end at a prompt. A user row has no stored identity on the typed path,
 * so it is keyed by its bytes and which occurrence of them it is
 * ({@see userRowKey()}); harness rows are not tagged ({@see rowKeys()}).
 * The ref counter is one namespace: `r12` may be a prompt and `r13` the
 * result after it.
 *
 * NUDGES (roadmap 3.B-4, {@see NudgePolicy}) are anchored on a row's key and
 * re-rendered at that row on every later request, so a reminder never moves
 * along the tail and costs the prompt cache one row, once.
 *
 * THE COMPACTION CYCLE (roadmap 2.11). {@see $compactionCycle} counts the
 * compactions that have landed in the session — a step summary the engine
 * wrote ({@see withBlock()}) and a host compaction (`/compact`, the 85% tier),
 * seen through the boundary row it leaves in the history
 * ({@see syncAgainstHistory()}). {@see $memoryFlushedCycle} is the cycle the
 * last pre-compaction memory flush ran in
 * ({@see \SugarCraft\Crush\Context\Compaction\MemoryFlush}), so the flush runs
 * at most once per cycle whichever route compacts, and across turns: a summary
 * that fails and is retried a turn later finds the cycle already flushed.
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
     * @param PruningMode|null              $mode               the mode `/pruning`
     *                                                          set for this session;
     *                                                          null follows
     *                                                          $defaultMode
     * @param PruningMode                   $defaultMode        the configured mode
     *                                                          ({@see PruningMode::configured()}),
     *                                                          resolved by the host
     *                                                          per turn — never
     *                                                          persisted, so a
     *                                                          changed setting reaches
     *                                                          every session that did
     *                                                          not choose its own.
     *                                                          `off` until a host sets
     *                                                          it: a ledger no host
     *                                                          keeps (a turn's own
     *                                                          relief, a sub-agent's)
     *                                                          names no refs nobody
     *                                                          will keep
     * @param array<string, string>         $nudges             row key => the
     *                                                          {@see NudgePolicy}
     *                                                          kind anchored there
     * @param int                           $compactionCycle    compactions landed
     *                                                          so far; only grows
     * @param int                           $compactionBoundaries host compaction
     *                                                          boundary rows the
     *                                                          history showed when
     *                                                          last synced
     * @param int|null                      $memoryFlushedCycle the cycle the last
     *                                                          memory flush ran in,
     *                                                          or null for none
     */
    private function __construct(
        public array $prunes,
        public array $droppedContextRows,
        public array $blocks = [],
        public int $nextBlockId = 1,
        public array $refs = [],
        public int $nextRef = 1,
        public ?PruningMode $mode = null,
        public PruningMode $defaultMode = PruningMode::Off,
        public array $nudges = [],
        public int $compactionCycle = 0,
        public int $compactionBoundaries = 0,
        public ?int $memoryFlushedCycle = null,
    ) {
    }

    public static function new(): self
    {
        return new self([], []);
    }

    /**
     * The key a user row is filed under (roadmap 3.B-4): its bytes and which
     * occurrence of those bytes it is, counted from the conversation's start,
     * so two identical prompts ("continue") are two rows.
     */
    public static function userRowKey(string $content, int $occurrence): string
    {
        return 'u:' . hash('xxh128', $content) . ':' . $occurrence;
    }

    /** Whether $key names a user row ({@see userRowKey()}) rather than a tool result. */
    public static function isUserRowKey(string $key): bool
    {
        return str_starts_with($key, 'u:');
    }

    /**
     * The key a `Compress` range boundary names a STEP by (roadmap 3.B-4):
     * the call id its opening assistant row issued — the one identity a step
     * has on every path.
     */
    public static function stepKey(string $toolCallId): string
    {
        return 's:' . $toolCallId;
    }

    /**
     * Every row of $messages that carries a ref, by index => key: each tool
     * result (keyed by its call id) and each user row (keyed by
     * {@see userRowKey()}) — except harness rows the model never names: a
     * `<turn-context>` row, a block's summary row, and a user row that is the
     * LAST of $messages, which is either the prompt being answered right now
     * (never part of a range: it is not closed) or a harness instruction
     * appended for one request (a summary's, a continuation's). Such a row
     * gets its ref once something follows it, and no ref before it moves.
     * $includeLastUserRow keys that last row too — what a nudge anchored on
     * the prompt being answered needs ({@see NudgePolicy}); its key is the one
     * it keeps once something follows it.
     *
     * @param list<mixed> $messages
     * @return array<int, string>
     */
    public static function rowKeys(array $messages, bool $includeLastUserRow = false): array
    {
        $keys = [];
        $seen = [];
        $last = array_key_last($messages);
        foreach ($messages as $index => $message) {
            if ($message instanceof ToolResultMessage) {
                if ($message->toolCallId() !== '') {
                    $keys[$index] = $message->toolCallId();
                }
            } elseif ($message instanceof UserMessage
                && !TurnContextBlock::isTurnContext($message)
                && !CompressionBlock::isSummaryRow($message)
            ) {
                $content = RefTag::stripFrom($message->content());
                $occurrence = $seen[$content] = ($seen[$content] ?? 0) + 1;
                if ($index !== $last || $includeLastUserRow) {
                    $keys[$index] = self::userRowKey($content, $occurrence);
                }
            }
        }

        return $keys;
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
     * A range block ({@see CompressionBlock::isRange()}) consumes only the
     * blocks it names ({@see withRangeBlock()}).
     */
    public function withBlock(CompressionBlock $block): self
    {
        if (isset($this->blocks[$block->id])) {
            return $this;
        }
        if ($block->isRange()) {
            return $this->withRangeBlock($block);
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

        // A step summary is a compaction landing (roadmap 2.11): the next one
        // is a new cycle, and may flush the memory again.
        return $this->mutate(
            blocks: $blocks,
            nextBlockId: max($this->nextBlockId, $block->id + 1),
            compactionCycle: $this->compactionCycle + 1,
        );
    }

    /**
     * This ledger with the range block $block active (roadmap 3.B-4) and every
     * block it names in {@see CompressionBlock::$consumedBlockIds} — the
     * earlier ranges it covers — deactivated beneath it. Idempotent like
     * {@see withBlock()}.
     */
    public function withRangeBlock(CompressionBlock $block): self
    {
        if (isset($this->blocks[$block->id])) {
            return $this;
        }
        $blocks = $this->blocks;
        foreach ($block->consumedBlockIds as $id) {
            if (isset($blocks[$id]) && $blocks[$id]->active) {
                $blocks[$id] = $blocks[$id]->deactivated();
            }
        }
        $blocks[$block->id] = $block;

        return $this->mutate(blocks: $blocks, nextBlockId: max($this->nextBlockId, $block->id + 1));
    }

    /**
     * Every range block the projection applies, in id order: active, so not
     * consumed by a later one and not taken back by the person.
     *
     * @return list<CompressionBlock>
     */
    public function activeRangeBlocks(): array
    {
        $active = array_values(array_filter($this->blocks, static fn (CompressionBlock $b): bool => $b->active && $b->isRange()));
        usort($active, static fn (CompressionBlock $a, CompressionBlock $b): int => $a->id <=> $b->id);

        return $active;
    }

    public function block(int $id): ?CompressionBlock
    {
        return $this->blocks[$id] ?? null;
    }

    /** The block that consumed block $id, or null when none did. */
    public function consumerOf(int $id): ?CompressionBlock
    {
        foreach ($this->blocks as $block) {
            if (\in_array($id, $block->consumedBlockIds, true)) {
                return $block;
            }
        }

        return null;
    }

    /**
     * This ledger with range block $id taken back by the person
     * (`/decompress`, roadmap 3.B-4): its rows are sent again, and the
     * blocks it had consumed — not themselves taken back — stand again in
     * its place. Unchanged for anything but an active range block.
     */
    public function withBlockDecompressed(int $id): self
    {
        $block = $this->blocks[$id] ?? null;
        if ($block === null || !$block->isRange() || !$block->active) {
            return $this;
        }
        $blocks = $this->blocks;
        $blocks[$id] = $block->decompressed();
        foreach ($block->consumedBlockIds as $child) {
            if (isset($blocks[$child]) && !$blocks[$child]->deactivatedByUser) {
                $blocks[$child] = $blocks[$child]->reactivated();
            }
        }

        return $this->mutate(blocks: $blocks);
    }

    /**
     * This ledger with range block $id the person took back applied again
     * (`/recompress`): it consumes its children again. Unchanged for anything
     * but a range block that was decompressed.
     */
    public function withBlockRecompressed(int $id): self
    {
        $block = $this->blocks[$id] ?? null;
        if ($block === null || !$block->isRange() || !$block->deactivatedByUser) {
            return $this;
        }
        $blocks = $this->blocks;
        $blocks[$id] = $block->reactivated();
        foreach ($block->consumedBlockIds as $child) {
            if (isset($blocks[$child]) && $blocks[$child]->active) {
                $blocks[$child] = $blocks[$child]->deactivated();
            }
        }

        return $this->mutate(blocks: $blocks);
    }

    /** This ledger with a nudge of $kind anchored on row $key (roadmap 3.B-4). */
    public function withNudge(string $key, string $kind): self
    {
        if (($this->nudges[$key] ?? null) === $kind) {
            return $this;
        }

        return $this->mutate(nudges: [...$this->nudges, $key => $kind]);
    }

    /** This ledger with every nudge anchor cleared — the cooldown after a prune or a compress. */
    public function withoutNudges(): self
    {
        return $this->nudges === [] ? $this : $this->mutate(nudges: []);
    }

    /** This ledger with the session's own mode set (null: follow the configured one). */
    public function withMode(?PruningMode $mode): self
    {
        return $mode === $this->mode ? $this : $this->mutate(mode: $mode);
    }

    /** This ledger following $mode wherever the session set none. */
    public function withDefaultMode(PruningMode $mode): self
    {
        return $mode === $this->defaultMode ? $this : $this->mutate(defaultMode: $mode);
    }

    /** The mode in force: the session's own, else the configured one. */
    public function effectiveMode(): PruningMode
    {
        return $this->mode ?? $this->defaultMode;
    }

    /**
     * Whether the pre-compaction memory flush has yet to run in the current
     * compaction cycle (roadmap 2.11) — see {@see $compactionCycle}.
     */
    public function memoryFlushDue(): bool
    {
        return $this->memoryFlushedCycle !== $this->compactionCycle;
    }

    /**
     * This ledger with the memory flush recorded for the current cycle, so no
     * route flushes again until the next compaction lands. Recorded when the
     * flush is DECIDED, before it runs: a flush that fails costs the
     * compaction nothing and is not retried in the same cycle.
     */
    public function withMemoryFlushed(): self
    {
        return $this->memoryFlushDue() ? $this->mutate(memoryFlushedCycle: $this->compactionCycle) : $this;
    }

    /**
     * This ledger having seen $boundaries host compaction boundary rows in
     * the history: more than last time is that many compactions landed, each
     * a new cycle. Fewer (a `/rewind` past one) moves only the mark, never
     * the cycle back — so the next compaction is a new cycle too.
     */
    private function withCompactionBoundaries(int $boundaries): self
    {
        if ($boundaries === $this->compactionBoundaries) {
            return $this;
        }

        return $this->mutate(
            compactionBoundaries: $boundaries,
            compactionCycle: $this->compactionCycle + max(0, $boundaries - $this->compactionBoundaries),
        );
    }

    /** The step summary the projection applies, or null (range blocks: {@see activeRangeBlocks()}). */
    public function activeBlock(): ?CompressionBlock
    {
        $active = null;
        foreach ($this->blocks as $block) {
            if ($block->active && !$block->isRange()) {
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
        if ($delta->clearNudges) {
            $ledger = $ledger->withoutNudges();
        }
        foreach ($delta->nudges as $key => $kind) {
            $ledger = $ledger->withNudge((string) $key, $kind);
        }

        return $ledger;
    }

    /**
     * What this ledger holds that $before did not, as the delta that takes
     * $before here (roadmap 3.B-3's live `ledger` frame, DCP §13.2 G): every
     * prune and dropped `<turn-context>` row it added, and every block it
     * added, in the order they were made. A delta carries only additions —
     * the one kind of change a turn makes — so a block $before already held
     * and this ledger merely deactivated is not in it; applying the delta to
     * $before re-derives that consumption ({@see withBlock()}).
     */
    public function deltaSince(self $before): LedgerDelta
    {
        $delta = LedgerDelta::new();
        if ($before === $this) {
            return $delta;
        }
        foreach ($this->prunes as $id => $entry) {
            if (!isset($before->prunes[$id])) {
                $delta = $delta->withPrune($entry);
            }
        }
        foreach ($this->droppedContextRows as $key => $tokens) {
            if (!isset($before->droppedContextRows[$key])) {
                $delta = $delta->withDroppedContextRow((string) $key, $tokens);
            }
        }
        foreach ($this->blocks as $id => $block) {
            if (!isset($before->blocks[$id])) {
                $delta = $delta->withBlock($block);
            }
        }
        if (array_diff_key($before->nudges, $this->nudges) !== []) {
            $delta = $delta->withNudgesCleared();
        }
        foreach ($this->nudges as $key => $kind) {
            if (($before->nudges[$key] ?? null) !== $kind || $delta->clearNudges) {
                $delta = $delta->withNudge((string) $key, $kind);
            }
        }

        return $delta;
    }

    /**
     * The ref every tool result in $messages is shown under: its fixed one
     * when it has one, else a PROVISIONAL one — {@see $nextRef} onwards, in
     * order of first appearance. Pure: the same rows and ledger give the same
     * refs, and appending rows never changes the refs of the rows before
     * them, which is what keeps a turn's requests byte-stable while its refs
     * are still provisional. A result with an empty call id gets none. User
     * rows are numbered in the same order ({@see rowKeys()}, roadmap 3.B-4).
     *
     * @param iterable<mixed> $messages typed messages; anything else is skipped
     * @return array<string, int> tool-call id (or user-row key) => ref
     */
    public function refsFor(iterable $messages): array
    {
        $refs = [];
        $next = $this->nextRef;
        foreach (self::rowKeys(\is_array($messages) ? array_values($messages) : iterator_to_array($messages, false)) as $id) {
            if (isset($refs[$id])) {
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
        // A user row's ref (3.B-4) is kept: its key is its bytes, which the
        // stored rows and the typed ones need not spell alike, and a ref that
        // names no row any more costs one map entry, never a wrong row.
        $refs = array_filter($this->refs, static fn (int|string $id): bool => isset($live[$id]) || self::isUserRowKey((string) $id), ARRAY_FILTER_USE_KEY);
        $nudges = array_filter($this->nudges, static fn (int|string $key): bool => isset($live[$key]) || self::isUserRowKey((string) $key), ARRAY_FILTER_USE_KEY);
        $blocks = [];
        foreach ($this->blocks as $id => $block) {
            $gone = $block->isRange()
                ? !self::boundaryLive((string) $block->fromKey, $live) || !self::boundaryLive((string) $block->toKey, $live)
                : !isset($live[$block->keepFromToolCallId]);
            $blocks[$id] = $block->active && $gone ? $block->deactivated() : $block;
        }

        if ($prunes === $this->prunes && $dropped === $this->droppedContextRows && $refs === $this->refs && $blocks == $this->blocks && $nudges === $this->nudges) {
            return $this;
        }

        return $this->mutate(prunes: $prunes, droppedContextRows: $dropped, blocks: $blocks, refs: $refs, nudges: $nudges);
    }

    /**
     * Whether a range boundary still names a row: a step's call is live; a
     * user row is taken to be (see the refs note in {@see syncAgainst()}).
     *
     * @param array<string, true> $live
     */
    private static function boundaryLive(string $key, array $live): bool
    {
        return str_starts_with($key, 's:') ? isset($live[substr($key, 2)]) : true;
    }

    /**
     * {@see syncAgainst()} over a session's stored rows — what the host
     * holds between turns: every call id a row makes or answers, and every
     * stored `<turn-context>` row. It also counts the host compactions'
     * boundary rows ({@see \SugarCraft\Crush\Host\CompactionService::isCompactionBoundary()}),
     * which is how a `/compact` or 85% compaction that landed moves the
     * compaction cycle ({@see withCompactionBoundaries()}).
     *
     * @param iterable<mixed> $history root {@see \SugarCraft\Crush\Message} rows; anything else is skipped
     */
    public function syncAgainstHistory(iterable $history): self
    {
        $callIds = [];
        $contextRows = [];
        $boundaries = 0;
        foreach ($history as $row) {
            if (!$row instanceof \SugarCraft\Crush\Message) {
                continue;
            }
            if (\SugarCraft\Crush\Host\CompactionService::isCompactionBoundary($row)) {
                $boundaries++;
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

        return $this->syncAgainst($callIds, $contextRows)->withCompactionBoundaries($boundaries);
    }

    /**
     * Estimated tokens this ledger takes out of what the model is sent: every
     * prune, every dropped `<turn-context>` row, and every ACTIVE block's
     * replaced rows less its summary — the status bar's `−52K pruned`.
     */
    public function freedTokens(): int
    {
        $tokens = array_sum($this->droppedContextRows);
        foreach ($this->prunes as $entry) {
            $tokens += $entry->tokens;
        }
        foreach ($this->blocks as $block) {
            if ($block->active) {
                $tokens += max(0, $block->compressedTokens - $block->summaryTokens);
            }
        }

        return $tokens;
    }

    public function isEmpty(): bool
    {
        return $this->prunes === [] && $this->droppedContextRows === [] && $this->blocks === [] && $this->nudges === [];
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

    /** @return array{prunes:list<array<string,mixed>>,droppedContextRows:array<string,int>,blocks:list<array<string,mixed>>,nextBlockId:int,refs:array<string,int>,nextRef:int,mode:?string,nudges?:array<string,string>,compactionCycle?:int,compactionBoundaries?:int,memoryFlushedCycle?:int} */
    public function toArray(): array
    {
        return [
            'prunes' => array_values(array_map(static fn (PruneEntry $entry): array => $entry->toArray(), $this->prunes)),
            'droppedContextRows' => $this->droppedContextRows,
            'blocks' => array_values(array_map(static fn (CompressionBlock $block): array => $block->toArray(), $this->blocks)),
            'nextBlockId' => $this->nextBlockId,
            'refs' => $this->refs,
            'nextRef' => $this->nextRef,
            'mode' => $this->mode?->value,
            // Written only when there are any, so a ledger with none keeps
            // the shape it was stored in before 3.B-4.
            ...($this->nudges === [] ? [] : ['nudges' => $this->nudges]),
            // Roadmap 2.11, the same rule: only once a compaction landed or a
            // flush ran, so an older ledger keeps its stored shape.
            ...($this->compactionCycle === 0 ? [] : ['compactionCycle' => $this->compactionCycle]),
            ...($this->compactionBoundaries === 0 ? [] : ['compactionBoundaries' => $this->compactionBoundaries]),
            ...($this->memoryFlushedCycle === null ? [] : ['memoryFlushedCycle' => $this->memoryFlushedCycle]),
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

        $mode = is_string($raw['mode'] ?? null) ? PruningMode::tryFrom($raw['mode']) : null;

        $nudges = [];
        foreach (is_array($raw['nudges'] ?? null) ? $raw['nudges'] : [] as $key => $kind) {
            if ((string) $key !== '' && is_string($kind) && NudgePolicy::isKind($kind)) {
                $nudges[(string) $key] = $kind;
            }
        }

        // Roadmap 2.11: an unreadable count starts the cycle again at zero,
        // which costs at most one extra flush.
        $cycle = is_int($raw['compactionCycle'] ?? null) && $raw['compactionCycle'] >= 0 ? $raw['compactionCycle'] : 0;
        $boundaries = is_int($raw['compactionBoundaries'] ?? null) && $raw['compactionBoundaries'] >= 0 ? $raw['compactionBoundaries'] : 0;
        $flushed = is_int($raw['memoryFlushedCycle'] ?? null) && $raw['memoryFlushedCycle'] >= 0 && $raw['memoryFlushedCycle'] <= $cycle
            ? $raw['memoryFlushedCycle']
            : null;

        return new self($ledger->prunes, $ledger->droppedContextRows, $blocks, $next, $refs, $nextRef, $mode, PruningMode::Off, $nudges, $cycle, $boundaries, $flushed);
    }

    /** A copy with the named fields replaced; every other field carried. */
    private function mutate(mixed ...$changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }
}
