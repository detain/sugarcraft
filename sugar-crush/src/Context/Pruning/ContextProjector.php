<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning;

use SugarCraft\Crush\Context\TurnContextBlock;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Builds the conversation a request sends from the conversation's rows plus a
 * {@see ContextLedger} (roadmap 2.2-1, DCP §13.2 C). Wired at
 * {@see \SugarCraft\Crush\Runtime::buildMessages()}, ahead of
 * {@see \SugarCraft\Crush\Messages\HistorySanitizer}.
 *
 * A PURE FUNCTION of its two inputs — the rows are never rewritten, and two
 * projections of the same rows and ledger are byte-identical. That is the
 * prompt-cache contract: the bytes before the earliest row the ledger touches
 * are the bytes the previous request sent, and the ledger itself changes only
 * at the deliberate points its owner chooses.
 *
 * Steps, in order:
 *  1. the active {@see CompressionBlock} (roadmap 2.4-1): every row before
 *     the step opener it keeps from is replaced by its summary row, a
 *     user-role row — never a system one, which some providers hoist into
 *     message 0. The kept part starts with an assistant row, so the summary
 *     never sits next to another user row of the block's making;
 *  2. a superseded `<turn-context>` row the ledger names is left out — never
 *     the last one, which is the state the model must read;
 *  3. a pruned tool result keeps its call id and error flag and has its
 *     content replaced by {@see PrunedOutputPlaceholder}, named from the call
 *     that asked for it.
 *
 * Steps 1 and 2 remove rows only at step boundaries or rows that pair with
 * nothing, so every tool call that is sent keeps its result.
 */
final class ContextProjector
{
    private function __construct()
    {
    }

    public static function new(): self
    {
        return new self();
    }

    /** @param list<TypedMessage> $messages */
    public function project(array $messages, ContextLedger $ledger): ProjectedContext
    {
        $messages = array_values($messages);
        if ($ledger->isEmpty()) {
            return new ProjectedContext($messages);
        }

        $calls = self::callsById($messages);
        $block = $ledger->activeBlock();
        if ($block !== null) {
            $keepFrom = self::stepOpening($messages, $block->keepFromToolCallId);
            if ($keepFrom !== null) {
                $messages = [$block->summaryRow(), ...\array_slice($messages, $keepFrom)];
            }
        }

        $lastContextRow = null;
        foreach ($messages as $index => $message) {
            if (TurnContextBlock::isTurnContext($message)) {
                $lastContextRow = $index;
            }
        }

        $projected = [];
        foreach ($messages as $index => $message) {
            if ($index !== $lastContextRow && TurnContextBlock::isTurnContext($message) && $ledger->dropsContextRow($message->content())) {
                continue;
            }
            if ($message instanceof ToolResultMessage) {
                $entry = $ledger->prune($message->toolCallId());
                if ($entry !== null) {
                    $message = self::pruned($message, $entry, $calls[$message->toolCallId()] ?? null);
                }
            }
            $projected[] = $message;
        }

        return new ProjectedContext($projected);
    }

    /**
     * Every call an assistant row in $messages issued, by id.
     *
     * @param list<TypedMessage> $messages
     * @return array<string, ToolCall>
     */
    public static function callsById(array $messages): array
    {
        $calls = [];
        foreach ($messages as $message) {
            if (!$message instanceof AssistantMessage) {
                continue;
            }
            foreach ($message->toolCalls() ?? [] as $call) {
                if ($call instanceof ToolCall) {
                    $calls[$call->id()] = $call;
                }
            }
        }

        return $calls;
    }

    /**
     * The index of the assistant row that issued $toolCallId, or null when no
     * row in $messages did.
     *
     * @param list<TypedMessage> $messages
     */
    public static function stepOpening(array $messages, string $toolCallId): ?int
    {
        foreach ($messages as $index => $message) {
            if (!$message instanceof AssistantMessage) {
                continue;
            }
            foreach ($message->toolCalls() ?? [] as $call) {
                if ($call instanceof ToolCall && $call->id() === $toolCallId) {
                    return $index;
                }
            }
        }

        return null;
    }

    private static function pruned(ToolResultMessage $result, PruneEntry $entry, ?ToolCall $call): ToolResultMessage
    {
        $content = match ($entry->kind) {
            PruneKind::Output => PrunedOutputPlaceholder::for($call?->name() ?? 'tool', $call?->arguments() ?? []),
        };

        // The image and the tool's own spend stay behind: neither is part of
        // the wire (toArray() omits them), and a placeholder carries neither.
        return new ToolResultMessage($result->toolCallId(), $content, $result->isError());
    }
}
