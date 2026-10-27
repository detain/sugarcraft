<?php

declare(strict_types=1);

namespace SugarCraft\Code\Observability;

/**
 * Batching decorator over {@see OtelSink}: events accumulate and travel in
 * one OTLP render pass per batch instead of per event.
 *
 * C-SES-4.a, three laws this class owns:
 * - OBS-1-P1 (timestamps): every event is stamped with unix nanos AT
 *   CONSUME TIME, anchored to `hrtime()` so a batch measures the real gap
 *   between events instead of the single instant the batch renders (before
 *   this, every batched span was ~0 ms long and stamped at export time).
 * - OBS-1-P2 (one body): a batch's closed spans render into ONE OTLP body,
 *   so one batch costs one transport call, not one per span.
 * - OBS-1-P4 (fork guard): the constructing pid is remembered, and
 *   {@see flush()} no-ops in any other pid — a `pcntl_fork()`ed child that
 *   leaves through `exit()` runs the parent's shutdown flush against its
 *   COPY of the batch, and must not re-send it.
 *
 * OBS-1: rendering is fallible — `OtelSink::encodeAll()` encodes with
 * `JSON_THROW_ON_ERROR`, so a payload PCRE or a non-finite float rejects the
 * whole batch at the encoder, not at the endpoint. The encode arm therefore
 * runs INSIDE the no-throw contract below: a batch that cannot be rendered is
 * dropped and counted ({@see encodeFailureCount()}), never thrown at the
 * caller and never queued (raw events cannot be replayed after encoding
 * closes spans).
 *
 * Arms, all bounded and none timer-based INSIDE this class:
 * - the buffer reaching `batchSize` renders and POSTs through the sink's
 *   async leg when it has one (C-SES-4.b) — the body is handed to the event
 *   loop and `consume()` returns without waiting for the response — or
 *   synchronously through the Guzzle leg when it has not (the debugging and
 *   test shapes, unchanged),
 * - {@see flush()} is the shutdown arm (best-effort, never throws, owner-pid
 *   only). It stays synchronous: it runs where the loop may never run again
 *   (`-p` exits between turns' settle points; a TUI leaving through
 *   `exit()`), so it re-posts through the Guzzle leg whatever the async leg
 *   had still not been answered — see the duplication note below,
 * - {@see tick()} re-POSTs only bodies a previous attempt FAILED to
 *   deliver — the retry rides the TUI's own tick since C-SES-4.b
 *   (`Chat::subscriptions()` declares it only while {@see hasCarryOver()}
 *   answers true); a healthy stream therefore performs no I/O on ticks at all.
 *
 * IN-FLIGHT DUPLICATION (C-SES-4.b, accepted): a body handed to the async
 * leg is neither delivered nor refused until its response arrives. If the
 * loop stops running with bodies in flight — the shutdown flush is exactly
 * that moment — flush cannot know whether the collector already stored them,
 * so it re-posts them synchronously. A lost response whose request DID land
 * therefore duplicates those spans. Telemetry tolerates the double; it does
 * not tolerate the silent loss of the alternative.
 *
 * A refused batch carries the already-RENDERED JSON bodies (not the stamped
 * events): encoding closes spans, so replayed events could never reproduce
 * what a failed POST lost. The carry-over queue is capped at one batch.
 */
final class BatchEventSink implements EventSink, RetryingEventSink
{
    /**
     * Buffered events, each paired with its consume-time unix nanos.
     *
     * @var list<array{object, int}>
     */
    private array $pending = [];

    /**
     * Rendered bodies the endpoint refused, oldest first.
     *
     * @var list<string>
     */
    private array $unsent = [];

    /**
     * Bodies handed to the SINK'S ASYNC LEG (C-SES-4.b) whose response has
     * not arrived yet, keyed by a per-send sequence number (bodies can be
     * byte-identical, so the body itself is no key). Neither delivered nor
     * refused yet: {@see flush()} re-posts whatever is still here, which is
     * where the documented duplicate-span trade lives.
     *
     * @var array<int, string>
     */
    private array $inFlight = [];

    /** @see $inFlight */
    private int $flightSeq = 0;

    private bool $closed = false;

    /**
     * Batches OBS-1 dropped at the encoder (render threw) rather than let
     * the failure escape `consume()`/`flush()`.
     */
    private int $encodeFailures = 0;

    private readonly int $batchSize;

    /** The pid whose `exit()` owns the shutdown flush (OBS-1-P4). */
    private readonly int $ownerPid;

    /** @var \Closure(): int monotonic unix-nanos clock */
    private readonly \Closure $unixNanos;

    /** @var \Closure(): int current-pid reader */
    private readonly \Closure $currentPid;

    /**
     * @param \Closure(): int|null $unixNanosClock @internal test seam returning
     *        the current unix nanos (default: a `microtime()` instant anchored
     *        to `hrtime()`, so successive reads are monotonic to the nanosecond)
     * @param \Closure(): int|null $currentPid @internal test seam returning the
     *        current pid (default `getmypid()`), same shape as the MCP arms'
     */
    public function __construct(
        private readonly OtelSink $sink,
        int $batchSize = OtelConfig::DEFAULT_BATCH_SIZE,
        ?\Closure $unixNanosClock = null,
        ?\Closure $currentPid = null,
    ) {
        $this->batchSize = max(OtelConfig::MIN_BATCH_SIZE, min(OtelConfig::MAX_BATCH_SIZE, $batchSize));

        if (null === $unixNanosClock) {
            // Anchor ONCE: unix resolution is far coarser than a batch's
            // span, so the fine grain comes from the monotonic clock and the
            // epoch offset from the wall clock, each read a single time.
            $unixBase = (int) (\microtime(true) * 1_000_000_000);
            $hrBase = \hrtime(true);
            $unixNanosClock = static fn (): int => $unixBase + (\hrtime(true) - $hrBase);
        }
        $this->unixNanos = $unixNanosClock;

        $this->currentPid = $currentPid ?? static fn (): int => (int) \getmypid();
        $this->ownerPid = ($this->currentPid)();
    }

    public function consume(object $event): void
    {
        if ($this->closed) {
            return;
        }

        $this->pending[] = [$event, ($this->unixNanos)()];
        if (\count($this->pending) >= $this->batchSize) {
            $this->exportBuffer();
        }
    }

    /**
     * Loop-tick arm: re-posts failed bodies only. See the class docblock.
     * Since C-SES-4.b this has a production caller — the 15-second
     * `code.otel-retry` tick {@see \SugarCraft\Code\Chat::subscriptions()}
     * declares while {@see hasCarryOver()} answers true, dispatched by
     * `HandlesSessionSurface`'s TelemetryRetryTickMsg arm.
     */
    public function tick(): void
    {
        if ($this->closed || [] === $this->unsent) {
            return;
        }

        $bodies = $this->unsent;
        $this->unsent = [];
        $this->send($bodies);
    }

    public function flush(): void
    {
        if ($this->closed) {
            return;
        }

        if (($this->currentPid)() !== $this->ownerPid) {
            // OBS-1-P4: the shutdown flush also runs in every forked child
            // that leaves through exit(), and the child's BatchEventSink is
            // a COPY of the parent's buffer — re-flushing there double-sends
            // the parent's batch (and a child that never consumed still
            // holds whatever the parent had). The owner pid flushes its own
            // buffer; here the arm is inert, batch left intact.
            return;
        }

        $this->closed = true;

        // C-SES-4.b reconciliation: bodies the async leg accepted for sending
        // but the loop never answered are INDETERMINATE — with the loop about
        // to stop for good they cannot still land their response. flush()
        // re-posts them through the synchronous Guzzle leg (settle callbacks
        // arriving later are inert: settleFlight() reads $closed and does
        // nothing). A collector that already stored one of these bodies sees
        // it twice — the duplicate-span trade the class docblock owns.
        $uncertain = \array_values($this->inFlight);
        $this->inFlight = [];

        // $closed is already true, so every send() below — here and in the
        // last-attempt arm — routes through the synchronous leg even when an
        // async transport was built. That is what "flush stays synchronous"
        // means concretely: the shutdown path never hands work to a loop it
        // is standing up next to an exit() with.
        $this->exportBuffer();
        if ([] !== $uncertain) {
            $this->send($uncertain);
        }
        if ([] !== $this->unsent) {
            // One bounded last attempt for whatever the batch arms refused.
            $bodies = $this->unsent;
            $this->unsent = [];
            $this->send($bodies);
        }
    }

    /** Whether a failed body is waiting for the next {@see tick()}. */
    public function hasCarryOver(): bool
    {
        return [] !== $this->unsent;
    }

    /** Number of events currently buffered (test/diagnostic surface). */
    public function pendingCount(): int
    {
        return \count($this->pending);
    }

    /** Number of refused bodies waiting for a retry (test/diagnostic surface). */
    public function carryCount(): int
    {
        return \count($this->unsent);
    }

    /** Number of batches OBS-1 dropped because rendering itself threw. */
    public function encodeFailureCount(): int
    {
        return $this->encodeFailures;
    }

    private function exportBuffer(): void
    {
        if ([] === $this->pending) {
            return;
        }

        $stamped = $this->pending;
        $this->pending = [];
        try {
            $bodies = $this->sink->encodeAll($stamped);
        } catch (\Throwable) {
            // OBS-1: the encode arm used to sit outside any try while the
            // class promised consume()/flush() never throw. A batch that
            // cannot be rendered is lost and counted; undeliverable bytes
            // must not poison the caller's turn.
            ++$this->encodeFailures;

            return;
        }
        $this->send($bodies);
    }

    /**
     * @param list<string> $bodies
     */
    private function send(array $bodies): void
    {
        if (!$this->closed && $this->sink->hasAsyncTransport()) {
            // C-SES-4.b: hand each body to the loop and return. The TUI's
            // update() path no longer waits on the POST; a refusal (or a
            // transport rejection) lands on the retry queue from the settle
            // callback, which runs on the same loop the caller pumps.
            foreach ($bodies as $body) {
                $key = ++$this->flightSeq;
                $this->inFlight[$key] = $body;
                $this->sink->postAsync($body)->then(
                    function (bool $accepted) use ($key): void {
                        $this->settleFlight($key, $accepted);
                    },
                    function () use ($key): void {
                        $this->settleFlight($key, false);
                    },
                );
            }

            return;
        }

        foreach ($bodies as $index => $body) {
            try {
                $this->sink->post($body);
            } catch (\Throwable) {
                // Carry this body and every remaining one onto the
                // oldest-first retry queue, capped at one batch (oldest
                // evicted) so a permanently dead endpoint costs bounded
                // memory. A later successful send drains the queue by
                // replacing it wholesale on the next failure only.
                $remaining = \array_slice($bodies, $index);
                $this->unsent = \array_slice(array_merge($this->unsent, $remaining), -$this->batchSize);

                return;
            }
        }
    }

    /**
     * A body's async leg settled. On refusal it joins the retry queue (same
     * oldest-first, one-batch-capped rule the synchronous arm uses); on
     * acceptance it is simply dropped from the in-flight map.
     */
    private function settleFlight(int $key, bool $accepted): void
    {
        if (!isset($this->inFlight[$key])) {
            // The key was already reconciled by flush() (which cleared the
            // whole map before its synchronous re-post); a late loop tick
            // after shutdown must not resurrect or double-carry it.
            return;
        }

        $body = $this->inFlight[$key];
        unset($this->inFlight[$key]);

        if ($accepted) {
            return;
        }

        $this->unsent = \array_slice(array_merge($this->unsent, [$body]), -$this->batchSize);
    }
}
