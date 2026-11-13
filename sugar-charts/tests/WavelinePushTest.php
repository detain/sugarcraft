<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\LineChart\Waveline;

/**
 * CH-16 / sugar-charts-042: pushAll used to foreach over push() while the
 * constructor re-ran a Finite sweep over every EXISTING point — batch of
 * P into N was O(P × (N+P)) with full re-validation. The batch door now
 * accumulates, validates the new points once, and constructs once.
 */
final class WavelinePushTest extends TestCase
{
    public function testBatchEqualsStreamingByteForByte(): void
    {
        $pts = [];
        for ($i = 0; $i < 200; $i++) {
            $pts[] = [$i % 37, ($i * 7) % 23];
        }
        $chain = Waveline::new([], 40, 8);
        foreach ($pts as [$x, $y]) {
            $chain = $chain->push($x, $y);
        }
        $batch = Waveline::new([], 40, 8)->pushAll($pts);
        self::assertSame($chain->points, $batch->points);
        self::assertSame($chain->view(), $batch->view());
    }

    public function testEmptyBatchIsIdentity(): void
    {
        $w = Waveline::new([[1, 2]]);
        self::assertSame($w, $w->pushAll([]));
    }

    public function testBatchElementFailureNamesItsIndex(): void
    {
        try {
            Waveline::new()->pushAll([[1, 2], [3, 4], ['x', 9]]);
            self::fail('non-numeric batch element accepted');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('2', $e->getMessage(), 'index not named');
            self::assertStringContainsString('array', $e->getMessage());
        }
    }

    public function testNonFiniteBatchElementNamesItsIndex(): void
    {
        try {
            Waveline::new()->pushAll([[1, 2], [NAN, 4]]);
            self::fail('NaN coordinate sailed through');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('1', $e->getMessage());
        }
    }

    public function testPushKeepsRejectingNonFiniteSingles(): void
    {
        $this->expectException(\Throwable::class);
        Waveline::new()->push(INF, 0);
    }

    public function testNumericStringNormalizedNotStoredRaw(): void
    {
        $w = Waveline::new([['1', '2.5']]);
        self::assertSame([[1.0, 2.5]], $w->points, 'string contamination survived the door');
        // typed pairs stay as given (no gratuitous widening)
        self::assertSame([[1, 2]], Waveline::new([[1, 2]])->points);
    }

    public function testIngestDoorsAllValidate(): void
    {
        $probes = [
            'new'        => static fn() => Waveline::new([['abc', 1]]),
            'withPoints' => static fn() => Waveline::new([[1, 1]])->withPoints([['nope', 1]]),
        ];
        foreach ($probes as $name => $probe) {
            try {
                $probe();
                self::fail("{$name} door accepted a non-numeric point");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('0', $e->getMessage(), "{$name} door did not name the index");
            }
        }
    }

    public function testBatchOf1000Into10kCompletesLinearly(): void
    {
        $base = [];
        for ($i = 0; $i < 10000; $i++) {
            $base[] = [$i % 100, ($i * 3) % 100];
        }
        $chunk = [];
        for ($i = 0; $i < 1000; $i++) {
            $chunk[] = [$i, $i + 0.5];
        }
        $w = Waveline::new($base, 40, 8);
        $t0 = hrtime(true);
        $out = $w->pushAll($chunk);
        $ms = (hrtime(true) - $t0) / 1e6;
        self::assertCount(11000, $out->points);
        // The foreach-over-push regime measured hundreds of ms here
        // (quadratic re-validation); batched lands well under 5ms.
        self::assertLessThan(25.0, $ms, "pushAll of 1000 into 10k took {$ms}ms");
    }
}
