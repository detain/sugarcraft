<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Observability;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Events\SpendCapBreached;
use SugarCraft\Code\Events\StepStarted;
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

    protected function setUp(): void
    {
        $this->bodies = [];
        $this->endpointAccepts = true;
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
