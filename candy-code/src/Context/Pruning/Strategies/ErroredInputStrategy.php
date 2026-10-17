<?php

declare(strict_types=1);

namespace SugarCraft\Code\Context\Pruning\Strategies;

use SugarCraft\Code\Context\Pruning\CompressionBlock;
use SugarCraft\Code\Context\Pruning\ContextLedger;
use SugarCraft\Code\Context\Pruning\ContextProjector;
use SugarCraft\Code\Context\Pruning\LedgerDelta;
use SugarCraft\Code\Context\Pruning\PruneAuthor;
use SugarCraft\Code\Context\Pruning\PruneEntry;
use SugarCraft\Code\Context\Pruning\PruneKind;
use SugarCraft\Code\Context\Pruning\PruneReason;
use SugarCraft\Code\Context\Pruning\PrunedInputPlaceholder;
use SugarCraft\Code\Context\Pruning\PruningPolicy;
use SugarCraft\Code\Context\Pruning\PruningStrategy;
use SugarCraft\Code\Context\TurnContextBlock;
use SugarCraft\Code\Messages\Message as TypedMessage;
use SugarCraft\Code\Messages\ToolResultMessage;
use SugarCraft\Code\Messages\UserMessage;

/**
 * The input of a call that failed long ago is blanked (roadmap 2.3, DCP's
 * purge-errors): once {@see $turns} user turns have passed since a call
 * failed, its arguments are sent as placeholders but for the main one
 * ({@see PrunedInputPlaceholder::blanked()}). The error it answered with is
 * kept — that it failed, and why, is what the model needs; the large edit it
 * tried is not.
 */
final class ErroredInputStrategy implements PruningStrategy
{
    /** User turns that must pass before a failed call's input goes (DCP's default). */
    public const TURNS = 4;

    private function __construct(private readonly int $turns)
    {
    }

    public static function new(int $turns = self::TURNS): self
    {
        return new self(max(1, $turns));
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

        $turns = 0;
        $entries = [];
        foreach (array_reverse($messages) as $message) {
            if (self::isUserTurn($message)) {
                $turns++;

                continue;
            }
            if (!$message instanceof ToolResultMessage || $turns < $this->turns) {
                continue;
            }
            $id = $message->toolCallId();
            $call = $calls[$id] ?? null;
            if ($call === null || $id === '' || ($seen[$id] ?? 0) > 1 || $ledger->isPruned($id)) {
                continue;
            }
            if (!$message->isError() && $call->argumentsError() === null) {
                continue;
            }
            $saves = PrunedInputPlaceholder::savedTokens(PruneKind::Input, $call->arguments());
            if ($saves > 0) {
                $entries[] = new PruneEntry($id, PruneKind::Input, PruneReason::Errored, PruneAuthor::Strategy, $saves);
            }
        }

        $delta = LedgerDelta::new();
        foreach (array_reverse($entries) as $entry) {
            $delta = $delta->withPrune($entry);
        }

        return $delta;
    }

    /** A prompt that opens a user turn, as the age rule counts them. */
    private static function isUserTurn(TypedMessage $message): bool
    {
        return $message instanceof UserMessage
            && !TurnContextBlock::isTurnContext($message)
            && !CompressionBlock::isSummaryRow($message);
    }
}
