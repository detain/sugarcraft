<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning;

use SugarCraft\Crush\Context\TurnContextBlock;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
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
 *     that asked for it — a distilled one (roadmap 3.B-3) by the model's own
 *     text under that name ({@see PrunedOutputPlaceholder::distilled()});
 *  4. a call whose INPUT is pruned (roadmap 2.3: a superseded write's
 *     content, a long-failed call's arguments) is rewritten on its assistant
 *     row by {@see PrunedInputPlaceholder}, keeping its id, name and keys;
 *  5. with ref tags on ({@see withRefTags()}, roadmap 3.B-2): every tool
 *     result — pruned or not — ends with its `<ctx-ref r="N"/>` tag
 *     ({@see RefTag}), the ref the ledger gives it ({@see ContextLedger::refsFor()},
 *     numbered over the rows BEFORE step 1 drops any, so a block never shifts
 *     a ref), and a tag the model echoed into its own text is stripped. Since
 *     roadmap 3.B-4 a user prompt carries its ref too;
 *  6. roadmap 3.B-4: every active `Compress` range block
 *     ({@see ContextLedger::activeRangeBlocks()}) replaces the rows from its
 *     first to its last with one user-role summary row — its placeholders
 *     expanded ({@see expandedSummary()}), the protected outputs inside it
 *     kept verbatim ({@see protectedOutputs()}) — applied right after step 1;
 *  7. a nudge anchored on a row ({@see NudgePolicy}, roadmap 3.B-4) is
 *     appended to that tool result or prompt — never to an assistant row —
 *     and a reminder block the model echoed is stripped like a tag.
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

        // Refs and row keys are read off the rows BEFORE any block drops one,
        // so a block never shifts a ref or loses a nudge's anchor.
        $refs = $this->refTags ? $ledger->refsFor($messages) : [];
        $keys = ContextLedger::rowKeys($messages, true);
        $calls = self::callsById($messages);

        /** @var list<array{0: TypedMessage, 1: ?string}> $rows */
        $rows = [];
        foreach ($messages as $index => $message) {
            $rows[] = [$message, $keys[$index] ?? null];
        }

        $block = $ledger->activeBlock();
        if ($block !== null) {
            $keepFrom = self::stepOpening($messages, $block->keepFromToolCallId);
            if ($keepFrom !== null) {
                $rows = [[$block->summaryRow(), null], ...\array_slice($rows, $keepFrom)];
            }
        }
        foreach ($ledger->activeRangeBlocks() as $range) {
            $rows = self::applyRange($rows, $range, $ledger, $calls, $refs);
        }

        $lastContextRow = null;
        foreach ($rows as $index => [$message]) {
            if (TurnContextBlock::isTurnContext($message)) {
                $lastContextRow = $index;
            }
        }

        $projected = [];
        foreach ($rows as $index => [$message, $key]) {
            if ($index !== $lastContextRow && TurnContextBlock::isTurnContext($message) && $ledger->dropsContextRow($message->content())) {
                continue;
            }
            $nudge = $key === null ? null : ($ledger->nudges[$key] ?? null);
            if ($message instanceof ToolResultMessage) {
                $entry = $ledger->prune($message->toolCallId());
                if ($entry !== null && !$entry->kind->rewritesInput()) {
                    $message = self::pruned($message, $entry, $calls[$message->toolCallId()] ?? null);
                }
                $ref = $refs[$message->toolCallId()] ?? null;
                $content = $ref === null ? $message->content() : RefTag::appendTo($message->content(), $ref);
                $content = $nudge === null ? $content : NudgePolicy::appendTo($content, $nudge);
                if ($content !== $message->content()) {
                    $message = new ToolResultMessage(
                        $message->toolCallId(),
                        $content,
                        $message->isError(),
                        $message->imageBytes(),
                        $message->imageProtocol(),
                        $message->usage(),
                    );
                }
            } elseif ($message instanceof UserMessage && $key !== null) {
                // Roadmap 3.B-4: a prompt carries its ref too, so a Compress
                // range can start or end at it — and its anchored nudge.
                $ref = $refs[$key] ?? null;
                $content = $ref === null ? $message->content() : RefTag::appendTo($message->content(), $ref);
                $content = $nudge === null ? $content : NudgePolicy::appendTo($content, $nudge);
                if ($content !== $message->content()) {
                    $message = new UserMessage($content, $message->attachments());
                }
            } elseif ($message instanceof AssistantMessage) {
                if (($message->toolCalls() ?? []) !== []) {
                    $message = self::withPrunedInputs($message, $ledger);
                }
                if ($this->refTags) {
                    $stripped = NudgePolicy::stripFrom(RefTag::stripFrom($message->content()));
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
     * Where a range block's first and last rows sit in $messages, or null
     * when either is gone (the block is then inert): a user row by its key,
     * a step from the assistant row that opened it through the last of the
     * results that answer it — so no call is ever cut from its result.
     *
     * @param list<TypedMessage>  $messages
     * @param array<int, ?string> $keys     index => row key
     * @return array{0: int, 1: int}|null
     */
    public static function rangeBounds(array $messages, array $keys, string $fromKey, string $toKey): ?array
    {
        $start = self::boundaryIndex($messages, $keys, $fromKey, false);
        $end = self::boundaryIndex($messages, $keys, $toKey, true);

        return $start === null || $end === null || $start > $end ? null : [$start, $end];
    }

    /**
     * The summary a range block shows, every `(bN)` placeholder replaced by
     * the summary of the block it names — recursively, each block at most
     * once along a path, so a corrupt cycle cannot loop.
     *
     * @param array<int, true> $seen
     */
    public static function expandedSummary(CompressionBlock $block, ContextLedger $ledger, array $seen = []): string
    {
        $seen[$block->id] = true;

        return (string) preg_replace_callback('/\(b(\d+)\)/', static function (array $m) use ($ledger, $seen): string {
            $child = $ledger->block((int) $m[1]);
            if ($child === null || isset($seen[$child->id])) {
                return $m[0];
            }

            return self::expandedSummary($child, $ledger, $seen);
        }, $block->summary);
    }

    /**
     * The verbatim outputs of the protected tools ({@see PruningPolicy::COMPRESS_PROTECTED_TOOLS})
     * among $rows — what a range summary carries whole, since a `Task` report
     * or a `Skill` body cannot be fetched again (DCP `appendProtectedTools`).
     * Empty when there are none.
     *
     * @param list<TypedMessage>     $rows
     * @param array<string, ToolCall> $calls
     * @param array<string, int>      $refs
     */
    public static function protectedOutputs(array $rows, array $calls, array $refs = []): string
    {
        $kept = [];
        foreach ($rows as $row) {
            if (!$row instanceof ToolResultMessage) {
                continue;
            }
            $tool = ($calls[$row->toolCallId()] ?? null)?->name();
            if ($tool === null || !\in_array($tool, PruningPolicy::COMPRESS_PROTECTED_TOOLS, true)) {
                continue;
            }
            $ref = $refs[$row->toolCallId()] ?? null;
            $kept[] = '### ' . $tool . ($ref === null ? '' : ' (' . RefTag::label($ref) . ')') . "\n" . $row->content();
        }

        return $kept === [] ? '' : "The following protected tool outputs from this section are kept verbatim:\n" . implode("\n\n", $kept);
    }

    /**
     * $rows with $block's range replaced by its summary row, or unchanged
     * when the range is gone or no longer whole.
     *
     * @param list<array{0: TypedMessage, 1: ?string}> $rows
     * @param array<string, ToolCall>                    $calls
     * @param array<string, int>                         $refs
     * @return list<array{0: TypedMessage, 1: ?string}>
     */
    private static function applyRange(array $rows, CompressionBlock $block, ContextLedger $ledger, array $calls, array $refs): array
    {
        $messages = array_map(static fn (array $row): TypedMessage => $row[0], $rows);
        $keys = array_map(static fn (array $row): ?string => $row[1], $rows);
        $bounds = self::rangeBounds($messages, $keys, (string) $block->fromKey, (string) $block->toKey);
        if ($bounds === null) {
            return $rows;
        }
        [$start, $end] = $bounds;
        $covered = \array_slice($messages, $start, $end - $start + 1);
        foreach ($covered as $row) {
            // Another block already stands here: the ranges overlap, which
            // the tool refuses — an inert block rather than a mangled view.
            if (CompressionBlock::isSummaryRow($row)) {
                return $rows;
            }
        }

        $text = self::expandedSummary($block, $ledger);
        $kept = self::protectedOutputs($covered, $calls, $refs);
        $summary = $block->summaryRow($kept === '' ? $text : $text . "\n\n" . $kept);

        return [...\array_slice($rows, 0, $start), [$summary, null], ...\array_slice($rows, $end + 1)];
    }

    /**
     * @param list<TypedMessage>  $messages
     * @param array<int, ?string> $keys
     */
    private static function boundaryIndex(array $messages, array $keys, string $key, bool $end): ?int
    {
        if (!str_starts_with($key, 's:')) {
            $index = array_search($key, $keys, true);

            return $index === false ? null : (int) $index;
        }
        $opener = self::stepOpening($messages, substr($key, 2));
        if ($opener === null || !$end) {
            return $opener;
        }
        $last = $opener;
        while (($messages[$last + 1] ?? null) instanceof ToolResultMessage) {
            $last++;
        }

        return $last;
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
        // Roadmap 3.B-3: a distilled output keeps the model's own stand-in.
        $content = $entry->kind === PruneKind::Distilled && $entry->distillation !== null
            ? PrunedOutputPlaceholder::distilled($call?->name() ?? 'tool', $call?->arguments() ?? [], $entry->distillation)
            : PrunedOutputPlaceholder::for($call?->name() ?? 'tool', $call?->arguments() ?? []);

        // The image and the tool's own spend stay behind: neither is part of
        // the wire (toArray() omits them), and a placeholder carries neither.
        return new ToolResultMessage($result->toolCallId(), $content, $result->isError());
    }
}
