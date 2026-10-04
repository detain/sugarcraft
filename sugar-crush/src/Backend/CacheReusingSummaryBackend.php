<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Message;

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
        return $this->engine->summariseAsync($history, $instruction, $cancellation);
    }
}
