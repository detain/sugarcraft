<?php

declare(strict_types=1);

namespace SugarCraft\Code\Context\Pruning\Strategies;

use SugarCraft\Code\Context\Pruning\ContextLedger;
use SugarCraft\Code\Context\Pruning\LedgerDelta;
use SugarCraft\Code\Context\Pruning\PruningPolicy;
use SugarCraft\Code\Context\Pruning\PruningStrategy;
use SugarCraft\Code\Context\ContextPressure;
use SugarCraft\Code\Context\TurnContextBlock;

/**
 * Leaves out every `<turn-context>` row but the newest (roadmap 2.2-1, the
 * W4 1.A-2 follow-up).
 *
 * Step 1.A-2 persists the row into the history whenever its bytes change, so
 * a turn that writes on every step accumulates one copy of the git state and
 * both diffs per step — around 20 KB each — while each row's own preamble
 * says it supersedes any earlier one. They stay in the rows (the cache
 * contract forbids dropping them on every step); this strategy names them in
 * the ledger, so they go at the same deliberate point a prune does and in the
 * same batch.
 */
final class SupersededTurnContextStrategy implements PruningStrategy
{
    public static function new(): self
    {
        return new self();
    }

    public function propose(array $messages, ContextLedger $ledger, PruningPolicy $policy): LedgerDelta
    {
        $rows = [];
        foreach ($messages as $message) {
            if (TurnContextBlock::isTurnContext($message)) {
                $rows[] = $message->content();
            }
        }
        array_pop($rows);

        $delta = LedgerDelta::new();
        foreach ($rows as $content) {
            if (!$ledger->dropsContextRow($content)) {
                $delta = $delta->withDroppedContextRow(
                    ContextLedger::contextRowKey($content),
                    ContextPressure::MESSAGE_OVERHEAD_TOKENS + \SugarCraft\Code\Util\TokenEstimate::ofText($content),
                );
            }
        }

        return $delta;
    }
}
