<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning;

use SugarCraft\Crush\Context\Pruning\Strategies\SupersededTurnContextStrategy;
use SugarCraft\Crush\Messages\Message as TypedMessage;

/**
 * The prune a session in {@see PruningMode::Auto} makes at the start of each
 * turn (roadmap 3.B-2; DCP §13.2 D "Run points": a turn boundary, where the
 * cache breaks once).
 *
 * One batch of the zero-cost strategies in {@see STRATEGIES} — rules that only
 * take out what the conversation has already superseded — made only when it
 * frees at least {@see PruningPolicy::$minFreedTokens}, the same floor the
 * over-budget relief uses ({@see EmergencyPrune}): a prune rewrites bytes the
 * provider has cached, so it waits until it is worth a rewrite and then goes
 * in bulk. The age rule is NOT here: it throws away output the model may still
 * need, so it stays the over-budget emergency only.
 */
final class TurnStartPruning
{
    /**
     * The strategies a turn start runs, in order. Each proposes over the same
     * projected conversation; their deltas are merged into one batch.
     *
     * @var list<class-string<PruningStrategy>>
     */
    public const STRATEGIES = [
        SupersededTurnContextStrategy::class,
    ];

    /**
     * The delta to apply, or null when nothing worth a cache rewrite can go.
     *
     * @param list<TypedMessage> $projected the conversation as the turn's
     *                                      first request would send it
     */
    public static function propose(array $projected, ContextLedger $ledger, PruningPolicy $policy): ?LedgerDelta
    {
        $delta = LedgerDelta::new();
        foreach (self::STRATEGIES as $strategy) {
            $delta = $delta->merge($strategy::new()->propose($projected, $ledger, $policy));
        }

        return !$delta->isEmpty() && $delta->freedTokens() >= $policy->minFreedTokens ? $delta : null;
    }
}
