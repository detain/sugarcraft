<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Sparkline;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\Sparkline\Sparkline;

/**
 * lib-reviews-1103 batch 2, M11 follow-through: the streaming path for
 * bounded-memory hosts is pushAll() (one copy for the whole batch, versus
 * one copy of the recorded history per push()). Batch and single-push
 * chains must land in identical states.
 */
final class SparklineBatchEquivalenceTest extends TestCase
{
    public function testPushAllEqualsPushChain(): void
    {
        $values = range(1, 50);

        $streamed = Sparkline::new(width: 8);
        foreach ($values as $v) {
            $streamed = $streamed->push($v);
        }
        $batched = Sparkline::new(width: 8)->pushAll($values);

        $this->assertSame($streamed->data, $batched->data);
        $this->assertSame($streamed->view(), $batched->view());
    }

    public function testEmptyPushAllIsIdentity(): void
    {
        $s = Sparkline::new([1.0, 2.0, 3.0]);
        $this->assertSame($s->data, $s->pushAll([])->data);
    }

    public function testAutoWidthTracksFullHistoryLikePush(): void
    {
        // Documented full-history contract: with the default (auto) width
        // the window grows with the data, so a 1..5 batch equals 5 pushes.
        $streamed = Sparkline::new()->push(1)->push(2)->push(3)->push(4)->push(5);
        $batched = Sparkline::new()->pushAll([1, 2, 3, 4, 5]);
        $this->assertSame($streamed->data, $batched->data);
        $this->assertSame($streamed->view(), $batched->view());
    }
}
