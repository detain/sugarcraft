<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning;

use SugarCraft\Crush\Context\Pruning\Strategies\DuplicateCallStrategy;
use SugarCraft\Crush\Context\Pruning\Strategies\ErroredInputStrategy;
use SugarCraft\Crush\Context\Pruning\Strategies\StaleReadStrategy;
use SugarCraft\Crush\Context\Pruning\Strategies\SupersededTurnContextStrategy;
use SugarCraft\Crush\Context\Pruning\Strategies\SupersededWriteInputStrategy;
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
     * projected conversation; their deltas are merged into one batch, and a
     * call two rules name is pruned once, by the first — the same order and
     * rule as {@see EmergencyPrune}. The path-keyed rules (roadmap 2.3) take
     * out only what a newer call answered again, an edit made stale, a later
     * write replaced, or a call that failed several turns ago.
     *
     * @var list<class-string<PruningStrategy>>
     */
    public const STRATEGIES = [
        DuplicateCallStrategy::class,
        StaleReadStrategy::class,
        SupersededWriteInputStrategy::class,
        ErroredInputStrategy::class,
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
        $delta = EmergencyPrune::oncePerCall($delta);

        return !$delta->isEmpty() && $delta->freedTokens() >= $policy->minFreedTokens ? $delta : null;
    }
}
