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
 *     that asked for it;
 *  4. a call whose INPUT is pruned (roadmap 2.3: a superseded write's
 *     content, a long-failed call's arguments) is rewritten on its assistant
 *     row by {@see PrunedInputPlaceholder}, keeping its id, name and keys;
 *  5. with ref tags on ({@see withRefTags()}, roadmap 3.B-2): every tool
 *     result — pruned or not — ends with its `<ctx-ref r="N"/>` tag
 *     ({@see RefTag}), the ref the ledger gives it ({@see ContextLedger::refsFor()},
 *     numbered over the rows BEFORE step 1 drops any, so a block never shifts
 *     a ref), and a tag the model echoed into its own text is stripped.
 *
 * Steps 1 and 2 remove rows only at step boundaries or rows that pair with
 * nothing, so every tool call that is sent keeps its result.
 */
final class ContextProjector
{
    private function __construct(private readonly bool $refTags = false)
    {
    }

    public static function new(): self
    {
        return new self();
    }

    /**
     * A projector that tags every tool result with its ref (step 4) — what a
     * request the model can name rows in is built with. Off by default: a
     * strategy reading the projected conversation sizes outputs without tags.
     */
    public function withRefTags(bool $refTags = true): self
    {
        return new self($refTags);
    }

    /** @param list<TypedMessage> $messages */
    public function project(array $messages, ContextLedger $ledger): ProjectedContext
    {
        $messages = array_values($messages);
        if ($ledger->isEmpty() && !$this->refTags) {
            return new ProjectedContext($messages);
        }

        $refs = $this->refTags ? $ledger->refsFor($messages) : [];
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
                if ($entry !== null && !$entry->kind->rewritesInput()) {
                    $message = self::pruned($message, $entry, $calls[$message->toolCallId()] ?? null);
                }
                $ref = $refs[$message->toolCallId()] ?? null;
                if ($ref !== null) {
                    $message = new ToolResultMessage(
                        $message->toolCallId(),
                        RefTag::appendTo($message->content(), $ref),
                        $message->isError(),
                        $message->imageBytes(),
                        $message->imageProtocol(),
                        $message->usage(),
                    );
                }
            } elseif ($message instanceof AssistantMessage) {
                if (($message->toolCalls() ?? []) !== []) {
                    $message = self::withPrunedInputs($message, $ledger);
                }
                if ($this->refTags) {
                    $stripped = RefTag::stripFrom($message->content());
                    if ($stripped !== $message->content()) {
                        $message = new AssistantMessage($stripped, $message->toolCalls(), $message->reasoning(), $message->usage(), $message->lengthStopped());
                    }
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

    /**
     * $message with the arguments of every call the ledger prunes the INPUT
     * of rewritten ({@see PrunedInputPlaceholder}, roadmap 2.3) — same id,
     * same name, same keys, so the call still pairs with its result. The row
     * itself is returned when nothing it carries is pruned.
     */
    private static function withPrunedInputs(AssistantMessage $message, ContextLedger $ledger): AssistantMessage
    {
        $changed = false;
        $calls = [];
        foreach ($message->toolCalls() ?? [] as $call) {
            $entry = $call instanceof ToolCall ? $ledger->prune($call->id()) : null;
            if ($entry !== null && $entry->kind->rewritesInput()) {
                $call = new ToolCall($call->id(), $call->name(), PrunedInputPlaceholder::apply($entry->kind, $call->arguments()), $call->argumentsError());
                $changed = true;
            }
            $calls[] = $call;
        }

        return $changed ? $message->withToolCalls($calls) : $message;
    }

    private static function pruned(ToolResultMessage $result, PruneEntry $entry, ?ToolCall $call): ToolResultMessage
    {
        $content = PrunedOutputPlaceholder::for($call?->name() ?? 'tool', $call?->arguments() ?? []);

        // The image and the tool's own spend stay behind: neither is part of
        // the wire (toArray() omits them), and a placeholder carries neither.
        return new ToolResultMessage($result->toolCallId(), $content, $result->isError());
    }
}
