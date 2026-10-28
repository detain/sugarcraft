<?php

declare(strict_types=1);

namespace SugarCraft\Code\Backend;

use React\Promise\PromiseInterface;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Host\CompactionService;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\ContextOverflow;
use SugarCraft\Code\Providers\TransientFailure;

/**
 * The session's summary backend (roadmap 2.4-2): compaction summaries reuse
 * the conversation's cached prefix, everything else stays tool-less.
 *
 * Two jobs share the one `summaryBackend` slot a Chat holds, and they want
 * different requests:
 *
 *  - `/compact` and the automatic 85% tier summarise THE CONVERSATION. Sent
 *    as the conversation's own request plus one instruction row
 *    ({@see SummarisesWithCache}), everything before the instruction is a
 *    prefix the provider cached on the previous turn; sent anywhere else, the
 *    whole conversation is prefilled again at full price. So
 *    {@see summariseAsync()} goes to the MAIN engine.
 *  - Auto-memory consolidation sends a prompt of its own through plain
 *    {@see completeAsync()}. On the main engine that would be a whole agentic
 *    turn — tools, hooks, permission prompts — so {@see complete()} and
 *    {@see completeAsync()} go to the TOOL-LESS backend, exactly as before.
 *
 * Neither half can run a tool: the tool-less backend has none, and the main
 * engine's summary request advertises its tools but takes the reply before a
 * call is dispatched.
 */
final class CacheReusingSummaryBackend implements Backend, SummarisesWithCache
{
    private function __construct(
        private readonly Backend $toolless,
        private readonly SummarisesWithCache $engine,
    ) {
    }

    public static function new(Backend $toolless, SummarisesWithCache $engine): self
    {
        return new self($toolless, $engine);
    }

    /** The backend plain completions go to — never one that carries tools. */
    public function toolless(): Backend
    {
        return $this->toolless;
    }

    /** The engine whose prefix the summaries reuse. */
    public function engine(): SummarisesWithCache
    {
        return $this->engine;
    }

    public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
    {
        return $this->toolless->complete($history, $onToken, $onEvent);
    }

    public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
    {
        return $this->toolless->completeAsync($history, $onToken, $cancellation, $onEvent);
    }

    public function summariseAsync(array $history, string $instruction, ?CancellationToken $cancellation = null): PromiseInterface
    {
        return $this->engine->summariseAsync($history, $instruction, $cancellation)
            ->then(null, fn (\Throwable $error): PromiseInterface => $this->retrySummaryOnce(
                $error,
                $history,
                $instruction,
                $cancellation,
            ));
    }

    /**
     * The one retry a failed summary request is worth, or the original error.
     *
     * Two failures used to fold heuristically on the FIRST try and stay folded
     * even though each has a cheap second shape (C-CTX-5.a):
     *
     *  - The compaction request OVERFLOWS the window itself — the history grew
     *    so large that even the summariser cannot read it. Retrying the same
     *    bytes is pointless, but {@see CompactionService::clipOlderToolOutputs()}
     *    cuts the session's fattest, least load-bearing rows (old tool outputs)
     *    to head and tail and keeps the newest exchanges whole, which is the
     *    same rescue the manual overflow notice recommends, automated. The leg
     *    fires only when that clip changes the bytes: an overflow the clip
     *    cannot relieve would otherwise be re-sent verbatim, and the retry
     *    would spend its one billed call on a guaranteed second refusal.
     *  - A configured `summaryModel` that is simply UNAVAILABLE (unknown model,
     *    a 4xx refusal) should not fold the session when the session's own
     *    model demonstrably answers — the conversation rode it moments ago. One
     *    retry with the override dropped ({@see EngineBackend::withSummaryModel()})
     *    asks the model that is known to work.
     *
     * Everything else — transient failures (the engine's own retry policy
     * owns those), a cancelled token, any error that is neither overflow nor a
     * failing override — rethrows unchanged, and the caller's heuristic fold
     * lands exactly as before.
     *
     * BUDGET: at most ONE extra billed call per summary. The handler is
     * attached to the FIRST rejection only; the retry's own promise carries its
     * result (success or failure) straight through without re-entering here.
     *
     * HONEST SCOPE — the fork flattens classes: when {@see EngineBackend} runs
     * the summary in a forked worker, the child's typed exception arrives here
     * as a plain {@see \RuntimeException} carrying only the provider's message
     * text. The overflow leg still fires because {@see ContextOverflow} falls
     * back to wording patterns; the fallback leg fires because the flattened
     * text classifies as non-transient under
     * {@see TransientFailure::isTransient()} (which never consults text). The
     * accepted trade: on that path a flattened 429 or 5xx spends the one
     * session-model retry too — still capped at one call, and the alternative
     * is folding when the session model was never asked.
     *
     * E646: no timeout is added anywhere here. Each leg answers a REFUSED call;
     * a call that never answers still dies on the engine's existing stall
     * ceiling, and 408/504/stall rejections ride the unchanged fold.
     *
     * @param list<Message> $history
     */
    private function retrySummaryOnce(
        \Throwable $error,
        array $history,
        string $instruction,
        ?CancellationToken $cancellation,
    ): PromiseInterface {
        if ($cancellation?->isCancelled() === true) {
            // Nothing may be dispatched on a dead turn; reject as today.
            throw $error;
        }

        if (ContextOverflow::matches($error)) {
            $clipped = CompactionService::clipOlderToolOutputs($history);
            if ($clipped === $history) {
                // The clip found nothing to cut: the retry would re-send the
                // refused bytes. Fold on the first answer instead of paying
                // twice for the same overflow.
                throw $error;
            }

            return $this->engine->summariseAsync($clipped, $instruction, $cancellation);
        }

        if ($this->engine instanceof EngineBackend
            && $this->engine->summaryModel() !== null
            && !TransientFailure::isTransient($error)) {
            return $this->engine->withSummaryModel(null)->summariseAsync($history, $instruction, $cancellation);
        }

        throw $error;
    }
}
