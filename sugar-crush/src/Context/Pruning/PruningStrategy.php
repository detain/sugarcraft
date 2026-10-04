<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning;

use SugarCraft\Crush\Messages\Message as TypedMessage;

/**
 * One deterministic pruning rule (roadmap 2.2-1, DCP §13.2 D): reads the
 * conversation as the model currently sees it and proposes what to take out.
 * A strategy never applies its own proposal — the caller decides whether the
 * delta is worth a cache rewrite — and the same input always yields the same
 * delta.
 */
interface PruningStrategy
{
    /**
     * @param list<TypedMessage> $messages the PROJECTED conversation (what the
     *                                     next request would send), so a rule
     *                                     never re-counts what is already gone
     */
    public function propose(array $messages, ContextLedger $ledger, PruningPolicy $policy): LedgerDelta;
}
