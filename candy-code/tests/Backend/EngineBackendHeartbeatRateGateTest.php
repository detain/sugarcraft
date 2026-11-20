<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EngineBackend;

/**
 * The turn's "still alive" beat is rate-limited to one a second. The limit now
 * rides sugar-async's RateGate; these tests pin the behaviour the hand-rolled
 * timestamp check had: the first beat always lands, a burst inside the second
 * collapses to that one beat, and the next beat lands once a second has passed.
 */
final class EngineBackendHeartbeatRateGateTest extends TestCase
{
    /** @return (\Closure(): void)|null */
    private static function throttled(?callable $onHeartbeat, ?callable $onReasoning): ?\Closure
    {
        $method = new \ReflectionMethod(EngineBackend::class, 'throttledHeartbeat');

        /** @var (\Closure(): void)|null $beat */
        $beat = $method->invoke(null, $onHeartbeat, $onReasoning);

        return $beat;
    }

    public function testNoChannelYieldsNoBeat(): void
    {
        self::assertNull(self::throttled(null, null));
    }

    public function testABurstInsideOneSecondCollapsesToTheFirstBeat(): void
    {
        $beats = 0;
        $beat = self::throttled(static function () use (&$beats): void {
            $beats++;
        }, null);
        self::assertNotNull($beat);

        for ($i = 0; $i < 5; $i++) {
            $beat();
        }

        self::assertSame(1, $beats, 'the first beat is allowed; the rest inside the window are dropped');
    }

    public function testTheNextBeatLandsOnceASecondHasPassed(): void
    {
        $beats = 0;
        $beat = self::throttled(static function () use (&$beats): void {
            $beats++;
        }, null);
        self::assertNotNull($beat);

        $beat();
        \usleep(1_100_000);
        $beat();

        self::assertSame(2, $beats);
    }

    public function testWithoutAHeartbeatCallbackTheReasoningChannelGetsAnEmptyDelta(): void
    {
        $deltas = [];
        $beat = self::throttled(null, static function (string $delta) use (&$deltas): void {
            $deltas[] = $delta;
        });
        self::assertNotNull($beat);

        $beat();
        $beat();

        self::assertSame([''], $deltas, 'an empty reasoning delta is the alive-but-silent frame, sent once per window');
    }
}
