<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning;

use SugarCraft\Crush\Context\Pruning\Strategies\SupersededTurnContextStrategy;
use SugarCraft\Crush\Context\Pruning\Strategies\ToolOutputAgeStrategy;
use SugarCraft\Crush\Messages\Message as TypedMessage;

/**
 * The prune the engine makes when a step's request is over its budget
 * (roadmap 2.2-1; DCP §13.2 D, "only at the over-max emergency").
 *
 * One batch: the age rule's placeholders ({@see ToolOutputAgeStrategy}) and
 * the superseded `<turn-context>` rows ({@see SupersededTurnContextStrategy}),
 * proposed together and made only when they free at least
 * {@see PruningPolicy::$minFreedTokens} between them — a prune rewrites cached
 * bytes, so it is made rarely and in bulk, never a little at every step.
 */
final class EmergencyPrune
{
    /**
     * The delta to apply, or null when nothing worth a cache rewrite can go.
     *
     * @param list<TypedMessage> $projected the conversation as the over-budget
     *                                      request would send it
     */
    public static function propose(array $projected, ContextLedger $ledger, PruningPolicy $policy): ?LedgerDelta
    {
        $delta = ToolOutputAgeStrategy::new()->propose($projected, $ledger, $policy)
            ->merge(SupersededTurnContextStrategy::new()->propose($projected, $ledger, $policy));

        return !$delta->isEmpty() && $delta->freedTokens() >= $policy->minFreedTokens ? $delta : null;
    }
}
