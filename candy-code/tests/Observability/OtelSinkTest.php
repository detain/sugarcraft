<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Observability;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Events\SpendCapBreached;
use SugarCraft\Code\Events\StepStarted;
use SugarCraft\Code\Events\UsageUpdated;
use SugarCraft\Code\Observability\OtelSink;

/**
 * OBS-1: the ungrouped exporter — the shape the app runs in debug mode and
 * the transport primitive the batch layer rides. Its never-throws law is
 * the sink contract: a rejecting collector may cost telemetry, nothing
 * else.
 */
final class OtelSinkTest extends TestCase
{
    public function testOnlyClosingEventsProducePosts(): void
    {
        $bodies = [];
        $sink = OtelSink::withTransport(function (string $body) use (&$bodies): bool {
            $bodies[] = $body;

            return true;
        });

        $sink->consume(new StepStarted(1, 5));
        $this->assertCount(0, $bodies);

        $sink->consume(new SpendCapBreached(1, 3.0, 1.0));
        $this->assertCount(1, $bodies);
        $this->assertStringContainsString('chat.turn', $bodies[0]);
    }

    public function testARefusingCollectorIsSilentInsideConsumeAndFlush(): void
    {
        $attempts = 0;
        $sink = OtelSink::withTransport(static function (string $body) use (&$attempts): bool {
            $attempts++;

            return false;
        });

        $sink->consume(new StepStarted(1, 5));
        $sink->consume(new SpendCapBreached(1, 3.0, 1.0));
        $sink->consume(new StepStarted(2, 5));
        $sink->flush();

        $this->assertSame(2, $attempts, 'one breach close plus one drained open span; no exception escapes');
    }

    public function testFlushPostsAnUnfinishedTurnOnce(): void
    {
        $bodies = [];
        $sink = OtelSink::withTransport(function (string $body) use (&$bodies): bool {
            $bodies[] = $body;

            return true;
        });
        $sink->consume(new StepStarted(1, 5));

        $sink->flush();
        $sink->flush();

        $this->assertCount(1, $bodies, 'the drain is not an infinite open-span fountain');
        $this->assertStringContainsString('"endTimeUnixNano"', $bodies[0]);
    }

    /**
     * C-SES-4.a: encodeAll takes consume-time-stamped pairs and answers at
     * most ONE body — every span the batch closed rides it, each keeping
     * its own stamped instants (OBS-1-P1 + OBS-1-P2).
     */
    public function testEncodeAllCarriesEveryStampedSpanInOneBody(): void
    {
        $sink = OtelSink::withTransport(static fn (string $body): bool => true);

        $bodies = $sink->encodeAll([
            [new StepStarted(1, 5), 10],
            [new UsageUpdated(1, null, null), 20],
            [new StepStarted(2, 5), 30],
            [new SpendCapBreached(2, 1.0, 0.5), 40],
        ]);

        $this->assertCount(1, $bodies, 'two closed spans, one body');
        $spans = json_decode($bodies[0], true, 512, \JSON_THROW_ON_ERROR)['resourceSpans'][0]['scopeSpans'][0]['spans'];
        $this->assertCount(2, $spans);
        $this->assertSame('10', $spans[0]['startTimeUnixNano']);
        $this->assertSame('20', $spans[0]['endTimeUnixNano'], 'the stamp, not the encode instant, ends the span');
        $this->assertSame('30', $spans[1]['startTimeUnixNano']);
        $this->assertSame('40', $spans[1]['endTimeUnixNano']);

        $this->assertSame([], $sink->encodeAll([[new StepStarted(3, 5), 50]]), 'a batch that closes nothing renders no body');
    }

    public function testTransportExceptionsAreSwallowedLikeRefusals(): void
    {
        $sink = OtelSink::withTransport(static function (string $body): bool {
            throw new \RuntimeException('collector exploded');
        });

        $sink->consume(new StepStarted(1, 5));
        $sink->consume(new SpendCapBreached(1, 3.0, 1.0));
        $this->assertTrue(true, 'reaching this line IS the assertion');
    }
}
