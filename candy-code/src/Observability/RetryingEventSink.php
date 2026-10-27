<?php

declare(strict_types=1);

namespace SugarCraft\Code\Observability;

/**
 * The retry contract {@see BatchEventSink} grew for C-SES-4.b: a sink that
 * keeps bodies the endpoint refused, and knows how to re-post them when the
 * event loop next wakes.
 *
 * Declared as its own type so the TUI can arm the retry tick without naming
 * the concrete batch class: `Chat::subscriptions()` asks the sink it was
 * handed (any `EventSink`, in principle) whether it is retryable and has
 * carry-over, and a dark launch's {@see NullEventSink} simply never answers,
 * so no timer is ever armed. The two methods are exactly the surface the
 * tick closure and its handler need — nothing else on BatchEventSink
 * (flush, the counters) belongs here.
 *
 * The tick arm is the missing production caller OBS-1 left behind:
 * BatchEventSink::tick() existed with no one to call it, so refused bodies
 * sat until the process exited.
 */
interface RetryingEventSink
{
    /**
     * Re-post carried-over bodies, if any. Bounded and cheap when there are
     * none: a healthy stream performs no I/O on ticks at all.
     */
    public function tick(): void;

    /** Whether a refused body is waiting for the next {@see tick()}. */
    public function hasCarryOver(): bool;
}
