<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Observability;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\TelemetryRetryTickMsg;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Events\SpendCapBreached;
use SugarCraft\Code\Events\StepStarted;
use SugarCraft\Code\Observability\BatchEventSink;
use SugarCraft\Code\Observability\OtelSink;

/**
 * C-SES-4.b: the retry tick finally has its production wiring. OBS-1 built
 * `BatchEventSink::tick()` but nothing ever called it — the refused batch
 * waited for a shutdown flush that could be a whole session away. This is
 * the chat-side half of that fix: `Chat::subscriptions()` declares a slow
 * 15-second `code.otel-retry` tick ONLY while the sink actually carries a
 * refused body, and the `TelemetryRetryTickMsg` arm re-posts it.
 *
 * The conditionality is the law (same shape as every other idle tick):
 * a dark launch (NullEventSink), a healthy stream, and a flushed sink all
 * arm NOTHING — telemetry never costs a timer when there is nothing to
 * retry. Everything here drives the synchronous collector seam; the async
 * leg's loop behaviour is {@see AsyncOtlpTransportTest}'s domain.
 */
final class ChatTelemetryRetryTickTest extends TestCase
{
    /** Bodies the sink's endpoint accepted (delivery evidence). */
    private array $delivered = [];

    /** Whether the fake endpoint answers true. */
    private bool $endpointUp = true;

    protected function setUp(): void
    {
        $this->delivered = [];
        $this->endpointUp = true;
    }

    public function testTheTickIsDeclaredOnlyWhileTheSinkCarriesARefusal(): void
    {
        $dark = new Chat();
        self::assertNull($dark->subscriptions(), 'a dark launch (NullEventSink) arms no telemetry timer, ever');

        $healthySink = $this->buildBatchSink(2);
        $healthySink->consume(new StepStarted(1, 9));
        $healthySink->consume(new SpendCapBreached(1, 2.0, 1.0));
        self::assertFalse($healthySink->hasCarryOver(), 'the accepted batch left nothing to retry');
        self::assertNull((new Chat(eventSink: $healthySink))->subscriptions(), 'a healthy stream arms nothing either');

        $carrying = $this->buildBatchSink(1);
        $this->endpointUp = false;
        $carrying->consume(new StepStarted(2, 9));
        $carrying->consume(new SpendCapBreached(2, 1.0, 0.5));
        self::assertTrue($carrying->hasCarryOver());

        $armed = (new Chat(eventSink: $carrying))->subscriptions();
        self::assertNotNull($armed, 'a refused batch waiting in the sink arms the tick');
        self::assertTrue($armed->has('code.otel-retry'));
    }

    public function testTheTickArmDeliversTheCarriedBodyAndDropsTheTimer(): void
    {
        $sink = $this->buildBatchSink(1);
        $this->endpointUp = false;
        $sink->consume(new StepStarted(1, 9));
        $sink->consume(new SpendCapBreached(1, 2.0, 1.0));
        $chat = new Chat(eventSink: $sink);
        self::assertTrue($chat->subscriptions()?->has('code.otel-retry') ?? false);

        // The endpoint recovers between ticks (the tick that lands the body
        // has no way to know, and does not need one).
        $this->endpointUp = true;
        [$next] = $chat->update(new TelemetryRetryTickMsg());

        self::assertCount(1, $this->delivered, 'the arm called tick(), which re-posted the rendered body');
        self::assertFalse($sink->hasCarryOver());
        self::assertNull($next->subscriptions(), 'the reconcile after the landing retry drops the timer');
    }

    public function testAStillRefusedTickKeepsTheTimerArmed(): void
    {
        $sink = $this->buildBatchSink(1);
        $this->endpointUp = false;
        $sink->consume(new StepStarted(1, 9));
        $sink->consume(new SpendCapBreached(1, 2.0, 1.0));
        $chat = new Chat(eventSink: $sink);

        [$next] = $chat->update(new TelemetryRetryTickMsg());
        self::assertTrue($sink->hasCarryOver(), 'the dead endpoint refused again; the body stays queued');
        self::assertSame([], $this->delivered);
        self::assertTrue($next->subscriptions()?->has('code.otel-retry') ?? false, 'one slow timer keeps waiting, never a per-event cost');
    }

    public function testAStrippedOrForeignSinkTickIsInert(): void
    {
        // A TelemetryRetryTickMsg arriving with a NON-retryable sink (the
        // dark default, or a bare OtelSink that never armed the tick — e.g.
        // after a hot transport swap) must change nothing and throw nothing:
        // the arm's instanceof gate is the whole defense.
        $chat = new Chat();
        [$same] = $chat->update(new TelemetryRetryTickMsg());
        self::assertNull($same->subscriptions());
    }

    /** A BatchEventSink over the current endpoint flag, sized per call. */
    private function buildBatchSink(int $batchSize): BatchEventSink
    {
        return new BatchEventSink(OtelSink::withTransport(function (string $body): bool {
            if (!$this->endpointUp) {
                return false;
            }
            $this->delivered[] = $body;

            return true;
        }), $batchSize);
    }
}
