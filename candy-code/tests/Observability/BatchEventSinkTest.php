<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Observability;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Events\SpendCapBreached;
use SugarCraft\Code\Events\StepStarted;
use SugarCraft\Code\Events\UsageUpdated;
use SugarCraft\Code\Observability\BatchEventSink;
use SugarCraft\Code\Observability\OtelSink;

/**
 * OBS-1: the batching arm. A batch renders and leaves on count, on
 * shutdown flush, and (for REFUSED bodies only) on the loop-tick retry arm.
 * Nothing here ever starts a timer: every I/O is triggered by an event, by
 * the count, or by the process exiting, and each individual POST is
 * bounded by the HTTP client's own timeout (E646).
 */
final class BatchEventSinkTest extends TestCase
{
    /** @var list<string> */
    private array $bodies = [];

    private bool $endpointAccepts = true;

    /** Consume-time clock fed through the seam (C-SES-4.a test surface). */
    private int $clockNanos = 1_000;

    /** Pid the owner-pid seam reports (OBS-1-P4 test surface). */
    private int $pidAsSeen = 0;

    protected function setUp(): void
    {
        $this->bodies = [];
        $this->endpointAccepts = true;
        $this->clockNanos = 1_000;
        $this->pidAsSeen = \getmypid();
    }

    public function testBatchRendersOnCountAndNothingLeavesPerEventBeforeThat(): void
    {
        $sink = new BatchEventSink($this->collectorSink(), 2);

        $sink->consume(new StepStarted(1, 9));
        $this->assertSame([], $this->bodies, 'an opening event closes no span, so nothing is even rendered');
        $this->assertSame(1, $sink->pendingCount());

        $sink->consume(new SpendCapBreached(1, 2.0, 1.0));
        $this->assertCount(1, $this->bodies, 'the second consume fills the batch; the breach closes a span -> one body');
        $this->assertSame(0, $sink->pendingCount());
        $this->assertFalse($sink->hasCarryOver());
    }

    public function testShutdownFlushDrainsAPartialBatch(): void
    {
        $sink = new BatchEventSink($this->collectorSink(), 64);
        $sink->consume(new StepStarted(1, 9));
        $sink->consume(new SpendCapBreached(1, 2.0, 1.0));
        $this->assertSame([], $this->bodies, '2 of 64: still buffered');

        $sink->flush();
        $this->assertCount(1, $this->bodies);
        $this->assertSame(0, $sink->pendingCount());
    }

    public function testConsumeAfterFlushIsInert(): void
    {
        $sink = new BatchEventSink($this->collectorSink(), 1);
        $sink->flush();

        $sink->consume(new StepStarted(1, 9));
        $sink->consume(new SpendCapBreached(1, 2.0, 1.0));
        $this->assertSame([], $this->bodies, 'the sink closed with the flush; nothing accumulates afterwards');
        $this->assertSame(0, $sink->pendingCount());
    }

    public function testRefusedBodyCarriesAndTheTickArmRePostsTheRenderedBodyOnce(): void
    {
        $this->endpointAccepts = false;
        $sink = new BatchEventSink($this->collectorSink(), 1);

        $sink->consume(new StepStarted(1, 9));
        $sink->consume(new SpendCapBreached(1, 2.0, 1.0));
        $this->assertTrue($sink->hasCarryOver(), 'the refused body is queued for retry');
        $this->assertSame(1, $sink->carryCount());

        $sink->tick();
        $this->assertTrue($sink->hasCarryOver(), 'still refused, still one body');
        $this->assertSame([], $this->bodies);

        $this->endpointAccepts = true;
        $sink->tick();
        $this->assertCount(1, $this->bodies, 'the RENDERED body is re-posted; no re-encode, no duplicate, no loss');
        $this->assertFalse($sink->hasCarryOver());
    }

    public function testTickWithoutCarryOverTouchesNoTransport(): void
    {
        $sink = new BatchEventSink($this->collectorSink(), 64);
        $sink->consume(new StepStarted(1, 9));

        $sink->tick();
        $this->assertSame([], $this->bodies, 'a healthy stream performs no I/O on ticks');
    }

    public function testForeverRefusedEndpointCarriesAtMostOneBatch(): void
    {
        $this->endpointAccepts = false;
        $sink = new BatchEventSink($this->collectorSink(), 1);

        for ($step = 1; $step <= 10; $step++) {
            $sink->consume(new StepStarted($step, 30));
            $sink->consume(new SpendCapBreached($step, 1.0, 0.5));
        }

        $this->assertSame(0, $sink->pendingCount());
        $this->assertSame(1, $sink->carryCount(), 'bounded memory: the retry queue holds one batch, newest kept');
    }

    public function testFlushMakesOneLastAttemptForCarriedBodies(): void
    {
        $this->endpointAccepts = false;
        $sink = new BatchEventSink($this->collectorSink(), 1);
        $sink->consume(new StepStarted(1, 9));
        $sink->consume(new SpendCapBreached(1, 2.0, 1.0));
        $this->assertSame(1, $sink->carryCount());

        $this->endpointAccepts = true;
        $sink->flush();
        $this->assertCount(1, $this->bodies, 'the shutdown arm gets its one bounded last chance');
        $this->assertFalse($sink->hasCarryOver());
    }

    /**
     * OBS-1: render failure was the one way the batch arm broke its
     * never-throws promise — `encodeAll()`'s `JSON_THROW_ON_ERROR` sat
     * outside any try while `consume()` and `flush()` promised silence, so a
     * payload PCRE (a non-finite spend figure) escaped into the caller's
     * turn. Such a batch is now dropped and counted, never thrown; the loss
     * is visible on the diagnostic surface instead of vanishing silently.
     */
    public function testABatchThatCannotBeRenderedIsDroppedAndCountedNotThrown(): void
    {
        $sink = new BatchEventSink($this->collectorSink(), 2);

        $sink->consume(new StepStarted(1, 9));
        $sink->consume(new SpendCapBreached(1, INF, 1.0));

        $this->assertSame([], $this->bodies, 'nothing was posted — the batch died at the encoder');
        $this->assertSame(0, $sink->pendingCount(), 'the doomed batch left the buffer, it does not pile up');
        $this->assertSame(1, $sink->encodeFailureCount());
        $this->assertFalse($sink->hasCarryOver(), 'undeliverable bytes are not retryable carry-over');

        // The sink stays usable: a later healthy batch flushes normally.
        $sink->consume(new StepStarted(2, 9));
        $sink->consume(new SpendCapBreached(2, 2.0, 1.0));
        $this->assertCount(1, $this->bodies, 'the drop was one batch, not a wedged sink');
        $this->assertSame(1, $sink->encodeFailureCount());

        // The flush arm honours the same promise: an unrenderable partial
        // batch at shutdown never escapes flush(). The StepStarted opens the
        // span the breach closes — without one there is no body to render.
        $shutdown = new BatchEventSink($this->collectorSink(), 5);
        $shutdown->consume(new StepStarted(1, 9));
        $shutdown->consume(new SpendCapBreached(1, INF, 1.0));
        $shutdown->flush();
        $this->assertSame(1, $shutdown->encodeFailureCount());
    }

    /**
     * C-SES-4.a / OBS-1-P1: the span's duration is the gap between the two
     * CONSUME instants, whatever the batch size — before the fix, events
     * were stamped at the shared render instant and every batched span was
     * about 0 ms long at the wrong time.
     */
    public function testSpanDurationIsTheConsumeTimeGapNotTheEncodeTimeGap(): void
    {
        $start = 1_700_000_000_123_456_789;
        $gap = 250_000_000;

        foreach ([2, 64] as $batchSize) {
            $this->bodies = [];
            $sink = $this->batchSinkSeamed($batchSize);

            $this->clockNanos = $start;
            $sink->consume(new StepStarted(1, 9));
            $this->clockNanos = $start + $gap;
            $sink->consume(new UsageUpdated(1, null, null));

            if (64 === $batchSize) {
                $sink->flush();
            }

            $this->assertCount(1, $this->bodies, "batch size {$batchSize}: the closed span leaves in one body");
            $span = self::spansFromBody($this->bodies[0])[0];
            $this->assertSame((string) $start, $span['startTimeUnixNano'], "batch size {$batchSize}");
            $this->assertSame((string) ($start + $gap), $span['endTimeUnixNano'], "batch size {$batchSize}");
        }
    }

    /**
     * C-SES-4.a / OBS-1-P2 (the one-body half): a batch that closes K spans
     * produces ONE transport call, not K.
     */
    public function testABatchOfClosedSpansLeavesInOneTransportCall(): void
    {
        $sink = $this->batchSinkSeamed(64);

        for ($step = 1; $step <= 3; $step++) {
            $sink->consume(new StepStarted($step, 9));
            $sink->consume(new UsageUpdated($step, null, null));
            $this->clockNanos += 1_000;
        }
        $this->assertSame([], $this->bodies, 'nothing leaves per event: 6 of 64, still buffered');

        $sink->flush();
        $this->assertCount(1, $this->bodies, 'three closed spans, one body, one POST');
        $this->assertCount(3, self::spansFromBody($this->bodies[0]));
    }

    /**
     * C-SES-4.a / OBS-1-P4: a forked child leaving through exit() must not
     * re-flush the parent's copied batch.
     */
    public function testFlushFromAForeignPidSendsNothing(): void
    {
        $sink = $this->batchSinkSeamed(64);
        $sink->consume(new StepStarted(1, 9));
        $sink->consume(new UsageUpdated(1, null, null));

        $this->pidAsSeen = \getmypid() + 1;
        $sink->flush();
        $this->assertSame([], $this->bodies, 'the foreign pid exported nothing');
        $this->assertSame(2, $sink->pendingCount(), 'the batch stays the owner process\'s to flush');

        $this->pidAsSeen = \getmypid();
        $sink->flush();
        $this->assertCount(1, $this->bodies, 'the owner flush still lands');
    }

    private function batchSinkSeamed(int $batchSize): BatchEventSink
    {
        return new BatchEventSink(
            $this->collectorSink(),
            $batchSize,
            function (): int {
                return $this->clockNanos;
            },
            function (): int {
                return $this->pidAsSeen;
            },
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function spansFromBody(string $body): array
    {
        return json_decode($body, true, 512, \JSON_THROW_ON_ERROR)['resourceSpans'][0]['scopeSpans'][0]['spans'];
    }

    private function collectorSink(): OtelSink
    {
        return OtelSink::withTransport(function (string $body): bool {
            if (!$this->endpointAccepts) {
                return false;
            }

            $this->bodies[] = $body;

            return true;
        });
    }
}
