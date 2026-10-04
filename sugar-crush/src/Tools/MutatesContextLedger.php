<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools;

use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\LedgerDelta;
use SugarCraft\Crush\Messages\Message as TypedMessage;

/**
 * A {@see Tool} that changes what the model is sent rather than anything on
 * disk: it reads and extends the TURN'S OWN {@see ContextLedger} (roadmap
 * 3.B-3, DCP §13.2 F) — the model's `Prune`, and `Compress` after it.
 *
 * Bound per turn by {@see \SugarCraft\Crush\Backend\EngineBackend}'s
 * `turnTools()`, the way {@see DelegatesToEngine} is: the ledger is a local of
 * the running turn, so no tool can hold it at construction time. The bound
 * copy is the one the Runtime executes; an unbound one has no ledger and
 * refuses every call.
 *
 * `$read` answers the ledger as the turn holds it now and the conversation
 * the model has read — the rows its refs were numbered over
 * ({@see ContextLedger::refsFor()}), so `r17` resolves to the result the model
 * saw tagged `r17` even while the turn's refs are still provisional. `$apply`
 * folds a {@see LedgerDelta} into the turn's ledger and answers the result, so
 * the turn's NEXT request is already projected through it; it answers null
 * when the ledger cannot be reached from where the call runs (a forked
 * child's copy would vanish), which the tool reports as a failure rather than
 * a prune that never happened.
 *
 * Such a tool must never be {@see ParallelSafe}: a concurrent batch runs each
 * call in a forked child, and the ledger lives in the turn's own process.
 */
interface MutatesContextLedger
{
    /**
     * @param \Closure(): array{0: ContextLedger, 1: list<TypedMessage>} $read
     * @param \Closure(LedgerDelta): ?ContextLedger                       $apply
     */
    public function withLedger(\Closure $read, \Closure $apply): Tool;

    /**
     * This tool with the turn's `PreCompact` gate (roadmap 3.B-3 / 3.B-4,
     * DCP §13.2 F "Both tools"): a change to what the model is sent is a
     * compaction, so a hook that refuses compactions refuses it too. `$gate`
     * runs the chain with the `trigger` (`auto` for the model's own call,
     * `manual` for one the person asked for) and the call's own label as
     * `custom_instructions`, and answers why it refused, or null to go on.
     * Bound after {@see withLedger()}, keeping that binding; unbound, nothing
     * gates the call.
     *
     * @param \Closure(string $trigger, string $focus): ?string $gate
     */
    public function withCompactionGate(\Closure $gate): Tool;
}
