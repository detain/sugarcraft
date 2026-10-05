<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Compaction;

use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Context\ContextPressure;
use SugarCraft\Crush\Context\Pruning\CompressionBlock;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\ContextProjector;
use SugarCraft\Crush\Context\Pruning\PruneAuthor;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Util\TokenEstimate;

/**
 * Summarises a turn at step level when its next request is still over the
 * step budget after the deterministic prune (roadmap 2.4-1), and owns the one
 * summary request every engine-side summary makes ({@see summaryStep()}).
 *
 * REUSING THE CACHE. The summary request is the request the model was last
 * sent — the same system prompt, the same tool schemas, the same history,
 * projected through the same ledger — plus one final user row asking for the
 * summary and saying not to call tools (Claude Code's compaction shape). Its
 * prefix is therefore a prefix the provider has already cached; a separate
 * tool-less request with its own system prompt would re-prefill the whole
 * conversation. Tools stay ADVERTISED but can never RUN: the reply is taken
 * the moment the assistant message arrives — {@see Runtime::run()} yields it
 * before it dispatches a single call — and any calls it asked for anyway are
 * dropped from it.
 *
 * WHAT IS SUMMARISED. Only rows the model has already been sent, cut at a
 * step boundary ({@see cutIndex()}): the summary replaces everything before
 * the step that opens the unsent tail, and that tail — the last step's calls
 * and results, the new state row, anything the user sent mid-turn — goes out
 * verbatim after it (nanobot's rule), so no call is ever separated from its
 * result. The result is a {@see CompressionBlock} written by the harness; the
 * turn's rows are never rewritten.
 *
 * THE MODEL. The turn's own by default — that is what makes the cache hit.
 * `SUGARCRUSH_SUMMARY_MODEL` / `summaryModel`, the knob `/compact` reads too,
 * picks another model on the same provider: resolved once at launch
 * ({@see modelOverride()} for the environment half) and carried on the
 * backend (`EngineBackend::withSummaryModel()`), which hands it in here.
 *
 * A summary that fails — the call throws, the reply is empty, or it is not
 * smaller than what it would replace — is no block at all: the turn goes on
 * with the request as it stands, and a failed summary never fails the turn.
 */
final class StepSummarizer
{
    /** The final user row of a step summary request. */
    public const INSTRUCTION = 'The conversation above is close to the context limit, so the harness will replace it with a '
        . 'summary you write now. Do not call any tools — answer with the summary only. Cover, in this order: the '
        . 'user\'s requests (quote the most recent one verbatim); what has been done so far, naming every file read, '
        . 'created or changed; key findings, decisions and their reasons; errors met and how they were resolved; what '
        . 'remains to be done; and the very next step. Be specific and complete but concise: anything you leave out '
        . 'is lost.';

    /**
     * A range smaller than this, in estimated tokens, is not worth a model
     * call: its summary would free too little to matter.
     */
    public const MIN_SOURCE_TOKENS = 10_000;

    /**
     * The index of the step the summary keeps from, or null when no cut can
     * be made: the last assistant row at or before $unsentFrom that opens a
     * step (it carries tool calls with ids) and before which every call has
     * its result, with something before it to summarise.
     *
     * @param list<TypedMessage> $rows
     * @param int                $unsentFrom the first row the model has not
     *                                       been sent (the rows the last
     *                                       request was built from); past the
     *                                       end means every row was sent
     */
    public static function cutIndex(array $rows, int $unsentFrom): ?int
    {
        $rows = array_values($rows);
        $limit = min(max(0, $unsentFrom), count($rows) - 1);
        $pending = [];
        $cut = null;
        foreach ($rows as $index => $row) {
            if ($index > $limit) {
                break;
            }
            if ($index > 0 && $pending === [] && self::firstCallId($row) !== null) {
                $cut = $index;
            }
            if ($row instanceof AssistantMessage) {
                foreach ($row->toolCalls() ?? [] as $call) {
                    if ($call instanceof ToolCall && $call->id() !== '') {
                        $pending[$call->id()] = true;
                    }
                }
            } elseif ($row instanceof ToolResultMessage) {
                unset($pending[$row->toolCallId()]);
            }
        }

        return $cut;
    }

    /**
     * Summarise $app's conversation up to the unsent tail, as a block for
     * $ledger, or null when there is nothing worth summarising or the summary
     * failed (see the class docblock).
     *
     * @param \Closure(AssistantMessage): void $onAssistant bills the summary's
     *                                                      response like any step
     * @param ?callable                        $onLiveness  `function(): void`,
     *                                                      told while the summary
     *                                                      streams so a watchdog
     *                                                      sees progress; the
     *                                                      summary's text itself
     *                                                      is never shown
     */
    public static function summarise(
        Runtime $runtime,
        App $app,
        ContextLedger $ledger,
        int $unsentFrom,
        \Closure $onAssistant,
        ?callable $onLiveness = null,
        ?callable $onHeartbeat = null,
        ?string $model = null,
    ): ?CompressionBlock {
        $plan = self::plan($app, $ledger, $unsentFrom);
        if ($plan === null) {
            return null;
        }
        [$range, $keepFrom, $compressed] = $plan;

        $summaryApp = $app->withMessages($range)->withContextLedger($ledger);
        if ($model !== null && $model !== '') {
            $summaryApp = $summaryApp->withModel($model);
        }

        $progress = $onLiveness === null ? null : static function (string $delta) use ($onLiveness): void {
            $onLiveness();
        };

        try {
            $reply = self::summaryStep($runtime, $summaryApp, new UserMessage(self::INSTRUCTION), $onAssistant, null, $progress, $onHeartbeat);
        } catch (\Throwable) {
            return null;
        }

        $summary = trim($reply?->content() ?? '');
        if ($summary === '') {
            return null;
        }
        $block = new CompressionBlock($ledger->nextBlockId, $keepFrom, $summary, $compressed, 0, PruneAuthor::Harness);
        $summaryTokens = ContextPressure::MESSAGE_OVERHEAD_TOKENS + TokenEstimate::ofText($block->summaryRow()->content());
        if ($summaryTokens >= $compressed) {
            return null;
        }

        return new CompressionBlock($block->id, $keepFrom, $summary, $compressed, $summaryTokens, PruneAuthor::Harness);
    }

    /**
     * Whether {@see summarise()} would ask the provider for a summary of $app
     * at all — a cut exists, and what it would condense is worth a summary —
     * without asking. What runs ahead of a summary and costs a provider call
     * of its own (the memory flush, roadmap 2.11) asks this first, so a turn
     * with nothing to condense is never sent a request for it.
     */
    public static function wouldSummarise(App $app, ContextLedger $ledger, int $unsentFrom): bool
    {
        return self::plan($app, $ledger, $unsentFrom) !== null;
    }

    /**
     * The rows {@see summarise()} would condense, the call id the kept tail
     * starts at, and their projected tokens; null when there is no cut or too
     * little before it.
     *
     * @return array{0: list<mixed>, 1: string, 2: int}|null
     */
    private static function plan(App $app, ContextLedger $ledger, int $unsentFrom): ?array
    {
        $rows = array_values($app->messages);
        $cut = self::cutIndex($rows, $unsentFrom);
        $keepFrom = $cut === null ? null : self::firstCallId($rows[$cut]);
        if ($cut === null || $keepFrom === null) {
            return null;
        }

        $range = \array_slice($rows, 0, $cut);
        $compressed = ContextProjector::new()->project($range, $ledger)->tokens();

        return $compressed < self::MIN_SOURCE_TOKENS ? null : [$range, $keepFrom, $compressed];
    }

    /**
     * One summary request: $app's conversation plus $instruction, with $app's
     * tools still advertised, answered by the first assistant message — whose
     * tool calls, if it asked for any, are dropped and never run. Null when the
     * provider produced no assistant message.
     *
     * Shared by the step summary above and the stopped-turn summary
     * ({@see \SugarCraft\Crush\Backend\EngineBackend}), so both reuse the
     * turn's cached prefix the same way.
     *
     * @param \Closure(AssistantMessage): void $onAssistant
     */
    public static function summaryStep(
        Runtime $runtime,
        App $app,
        UserMessage $instruction,
        \Closure $onAssistant,
        ?callable $onToken = null,
        ?callable $onProgress = null,
        ?callable $onHeartbeat = null,
    ): ?AssistantMessage {
        $request = $app->withMessages([...$app->messages, $instruction]);

        foreach ($runtime->run($request, null, null, $onToken, $onProgress, $onHeartbeat) as $message) {
            if ($message instanceof AssistantMessage) {
                $onAssistant($message);

                // Leaving the generator here is what keeps the advertised
                // tools from running: run() yields the assistant message
                // before it dispatches any of its calls.
                return ($message->toolCalls() ?? []) === [] ? $message : $message->withToolCalls(null);
            }
        }

        return null;
    }

    /**
     * The model `SUGARCRUSH_SUMMARY_MODEL` names, or null when it names none.
     *
     * The environment half of the launch-time resolution
     * (`Bootstrap::summaryModel()`, which falls back to the `summaryModel`
     * key): read once at launch and carried on the backend
     * (`EngineBackend::withSummaryModel()`), so a turn never re-reads a key
     * that is documented as read once (apply mode: restart).
     */
    public static function modelOverride(): ?string
    {
        $env = getenv('SUGARCRUSH_SUMMARY_MODEL');

        return is_string($env) && trim($env) !== '' ? trim($env) : null;
    }

    /** The first tool-call id an assistant row carries, or null. */
    private static function firstCallId(TypedMessage $row): ?string
    {
        if (!$row instanceof AssistantMessage) {
            return null;
        }
        foreach ($row->toolCalls() ?? [] as $call) {
            if ($call instanceof ToolCall && $call->id() !== '') {
                return $call->id();
            }
        }

        return null;
    }
}
