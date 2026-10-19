<?php

declare(strict_types=1);

namespace SugarCraft\Code\Observability;

/**
 * Batching decorator over {@see OtelSink}: events accumulate and travel in
 * one OTLP render pass per batch instead of per event.
 *
 * Arms, all bounded and none timer-based:
 * - the buffer reaching `batchSize` renders and POSTs synchronously inside
 *   `consume()`,
 * - {@see flush()} is the shutdown arm (best-effort, never throws),
 * - {@see tick()} re-POSTs only bodies a previous attempt FAILED to
 *   deliver — designed to ride a bounded tick-drainer of the app's own
 *   event loop; a healthy stream therefore performs no I/O on ticks at all.
 *
 * A refused batch carries the already-RENDERED JSON bodies (not the raw
 * events): encoding closes spans, so replayed events could never reproduce
 * what a failed POST lost. The carry-over queue is capped at one batch.
 */
final class BatchEventSink implements EventSink
{
    /**
     * @var list<object>
     */
    private array $pending = [];

    /**
     * Rendered bodies the endpoint refused, oldest first.
     *
     * @var list<string>
     */
    private array $unsent = [];

    private bool $closed = false;

    private readonly int $batchSize;

    public function __construct(
        private readonly OtelSink $sink,
        int $batchSize = OtelConfig::DEFAULT_BATCH_SIZE,
    ) {
        $this->batchSize = max(OtelConfig::MIN_BATCH_SIZE, min(OtelConfig::MAX_BATCH_SIZE, $batchSize));
    }

    public function consume(object $event): void
    {
        if ($this->closed) {
            return;
        }

        $this->pending[] = $event;
        if (\count($this->pending) >= $this->batchSize) {
            $this->exportBuffer();
        }
    }

    /**
     * Loop-tick arm: re-posts failed bodies only. See the class docblock.
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

        $this->closed = true;
        $this->exportBuffer();
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

    private function exportBuffer(): void
    {
        if ([] === $this->pending) {
            return;
        }

        $events = $this->pending;
        $this->pending = [];
        $this->send($this->sink->encodeAll($events));
    }

    /**
     * @param list<string> $bodies
     */
    private function send(array $bodies): void
    {
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
}
