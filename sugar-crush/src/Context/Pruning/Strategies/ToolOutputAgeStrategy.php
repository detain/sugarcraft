<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning\Strategies;

use SugarCraft\Crush\Context\Pruning\CompressionBlock;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\ContextProjector;
use SugarCraft\Crush\Context\Pruning\LedgerDelta;
use SugarCraft\Crush\Context\Pruning\PruneAuthor;
use SugarCraft\Crush\Context\Pruning\PruneEntry;
use SugarCraft\Crush\Context\Pruning\PruneKind;
use SugarCraft\Crush\Context\Pruning\PruneReason;
use SugarCraft\Crush\Context\Pruning\PrunedOutputPlaceholder;
use SugarCraft\Crush\Context\Pruning\PruningPolicy;
use SugarCraft\Crush\Context\Pruning\PruningStrategy;
use SugarCraft\Crush\Context\TurnContextBlock;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Util\TokenEstimate;

/**
 * The age rule (roadmap 2.2-1; opencode's session prune, DCP §13.2 D): older
 * tool output becomes a placeholder naming the call, newest output and the
 * last user turns stay verbatim.
 *
 * Walking back from the newest row: everything from the
 * {@see PruningPolicy::$protectUserTurns}th-last user prompt on is kept;
 * behind that, tool output is kept until {@see PruningPolicy::$protectTokens}
 * of it has been counted, and everything older is a candidate — unless its
 * tool is protected ({@see PruningPolicy::isProtected()}, which also keeps it
 * out of the count). The candidates are proposed only when together they free
 * at least {@see PruningPolicy::$minFreedTokens}; below that the rule proposes
 * nothing, because a prune is a cache rewrite and must be worth one.
 *
 * Deterministic: the same projected conversation and ledger give the same
 * delta. A result under a call id that appears more than once (a transcript
 * from before step 0.2) is never proposed — one ledger key would prune both.
 *
 * {@see select()} is the rule itself over plain rows, shared with
 * {@see \SugarCraft\Crush\Context\ContextCompactor::removeToolResults()}, so
 * Chat's compaction and the engine prune by the same figures.
 */
final class ToolOutputAgeStrategy implements PruningStrategy
{
    public static function new(): self
    {
        return new self();
    }

    public function propose(array $messages, ContextLedger $ledger, PruningPolicy $policy): LedgerDelta
    {
        $calls = ContextProjector::callsById($messages);
        $seen = [];
        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage) {
                $seen[$message->toolCallId()] = ($seen[$message->toolCallId()] ?? 0) + 1;
            }
        }

        $rows = [];
        foreach ($messages as $index => $message) {
            if (self::isUserTurn($message)) {
                $rows[] = ['userTurn' => true];

                continue;
            }
            if (!$message instanceof ToolResultMessage) {
                continue;
            }
            $id = $message->toolCallId();
            if ($id === '' || $ledger->isPruned($id) || ($seen[$id] ?? 0) > 1) {
                continue;
            }
            $call = $calls[$id] ?? null;
            $rows[] = [
                'key' => $id,
                'tool' => $call?->name() ?? '',
                'tokens' => TokenEstimate::ofText($message->content()),
                'placeholderTokens' => TokenEstimate::ofText(PrunedOutputPlaceholder::for($call?->name() ?? 'tool', $call?->arguments() ?? [])),
            ];
        }

        $delta = LedgerDelta::new();
        foreach (self::select($rows, $policy) as $id => $freed) {
            $delta = $delta->withPrune(new PruneEntry((string) $id, PruneKind::Output, PruneReason::Aged, PruneAuthor::Strategy, $freed));
        }

        return $delta;
    }

    /**
     * The rule over plain rows, oldest first. A row is either a user-turn
     * marker (`['userTurn' => true]`) or a tool output
     * (`['key' => …, 'tool' => name, 'tokens' => int, 'placeholderTokens' => int]`,
     * plus `'keep' => true` for output the caller must keep verbatim for its
     * own reasons — it still counts towards the protected newest output).
     * Answers the keys to prune => the tokens each frees, or nothing when the
     * total is under the policy's floor.
     *
     * @param list<array{userTurn?:bool,key?:string|int,tool?:string,tokens?:int,placeholderTokens?:int,keep?:bool}> $rows
     * @return array<string|int, int>
     */
    public static function select(array $rows, PruningPolicy $policy): array
    {
        $turns = 0;
        $kept = 0;
        $selected = [];
        $freed = 0;
        for ($index = count($rows) - 1; $index >= 0; $index--) {
            $row = $rows[$index];
            if (($row['userTurn'] ?? false) === true) {
                $turns++;

                continue;
            }
            if ($turns < $policy->protectUserTurns || !isset($row['key'])) {
                continue;
            }
            if ($policy->isProtected($row['tool'] ?? '')) {
                continue;
            }
            $tokens = $row['tokens'] ?? 0;
            $kept += $tokens;
            if ($kept <= $policy->protectTokens || ($row['keep'] ?? false) === true) {
                continue;
            }
            $saves = $tokens - ($row['placeholderTokens'] ?? 0);
            if ($saves <= 0) {
                continue;
            }
            $selected[$row['key']] = $saves;
            $freed += $saves;
        }

        return $freed >= $policy->minFreedTokens && $selected !== [] ? array_reverse($selected, true) : [];
    }

    /**
     * A prompt that opens a user turn: a user row that is neither the
     * harness's own `<turn-context>` row nor a step summary's row.
     */
    private static function isUserTurn(TypedMessage $message): bool
    {
        return $message instanceof UserMessage
            && !TurnContextBlock::isTurnContext($message)
            && !CompressionBlock::isSummaryRow($message);
    }
}
