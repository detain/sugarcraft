<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning;

use SugarCraft\Crush\Context\Pruning\Strategies\DuplicateCallStrategy;
use SugarCraft\Crush\Context\Pruning\Strategies\ErroredInputStrategy;
use SugarCraft\Crush\Context\Pruning\Strategies\StaleReadStrategy;
use SugarCraft\Crush\Context\Pruning\Strategies\SupersededTurnContextStrategy;
use SugarCraft\Crush\Context\Pruning\Strategies\SupersededWriteInputStrategy;
use SugarCraft\Crush\Context\Pruning\Strategies\ToolOutputAgeStrategy;
use SugarCraft\Crush\Messages\Message as TypedMessage;

/**
 * The prune the engine makes when a step's request is over its budget
 * (roadmap 2.2-1; DCP §13.2 D, "only at the over-max emergency").
 *
 * One batch: the path-keyed rules first (roadmap 2.3 — duplicate calls
 * ({@see DuplicateCallStrategy}), reads an edit made stale
 * ({@see StaleReadStrategy}), superseded write content
 * ({@see SupersededWriteInputStrategy}), the input of calls that failed long
 * ago ({@see ErroredInputStrategy})), then the age rule's placeholders
 * ({@see ToolOutputAgeStrategy}) and the superseded `<turn-context>` rows
 * ({@see SupersededTurnContextStrategy}), proposed together and made only when
 * they free at least {@see PruningPolicy::$minFreedTokens} between them — a
 * prune rewrites cached bytes, so it is made rarely and in bulk, never a
 * little at every step.
 *
 * A call two rules name is pruned once, by the first: the ledger keeps one
 * entry per call, so the batch's own count keeps one too.
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
        $delta = LedgerDelta::new();
        foreach (self::strategies() as $strategy) {
            $delta = $delta->merge($strategy->propose($projected, $ledger, $policy));
        }
        $delta = self::oncePerCall($delta);

        return !$delta->isEmpty() && $delta->freedTokens() >= $policy->minFreedTokens ? $delta : null;
    }

    /** @return list<PruningStrategy> in the order a call named twice is decided */
    public static function strategies(): array
    {
        return [
            DuplicateCallStrategy::new(),
            StaleReadStrategy::new(),
            SupersededWriteInputStrategy::new(),
            ErroredInputStrategy::new(),
            ToolOutputAgeStrategy::new(),
            SupersededTurnContextStrategy::new(),
        ];
    }

    private static function oncePerCall(LedgerDelta $delta): LedgerDelta
    {
        $kept = LedgerDelta::new();
        $seen = [];
        foreach ($delta->prunes as $entry) {
            if (!isset($seen[$entry->toolCallId])) {
                $seen[$entry->toolCallId] = true;
                $kept = $kept->withPrune($entry);
            }
        }
        foreach ($delta->droppedContextRows as $key => $tokens) {
            $kept = $kept->withDroppedContextRow((string) $key, $tokens);
        }
        foreach ($delta->blocks as $block) {
            $kept = $kept->withBlock($block);
        }

        return $kept;
    }
}
