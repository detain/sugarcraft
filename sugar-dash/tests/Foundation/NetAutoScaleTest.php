<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Foundation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Foundation\NetAutoScale;

final class NetAutoScaleTest extends TestCase
{
    public function testFreshScalerIsUnscaledAndArmed(): void
    {
        $s = NetAutoScale::new();
        $this->assertSame(0, $s->downloadMax());
        $this->assertSame(0, $s->uploadMax());
        $this->assertTrue($s->rescalePending());
        $this->assertFalse($s->rescaled);
        $this->assertFalse($s->sync);
    }

    public function testFirstOfferRescalesFromCurrentSpeedWithFloor(): void
    {
        $s = NetAutoScale::new()->offer(100_000, 1_000);
        // ≤5 samples held → avg is the current speed; fast factor 1.3.
        $this->assertSame(130_000, $s->downloadMax());
        // 1000 × 1.3 = 1300 → raised to the 10 KiB floor.
        $this->assertSame(NetAutoScale::FLOOR, $s->uploadMax());
        $this->assertSame(10240, NetAutoScale::FLOOR);
        $this->assertTrue($s->rescaled);
        $this->assertFalse($s->rescalePending());
    }

    /**
     * Planted sequences: [download speeds after the seeding tick, expected
     * ceiling after each tick, expected fast/slow counters after each tick].
     *
     * @return iterable<string, array{int, list<int>, list<int>, list<array{0:int,1:int}>}>
     */
    public static function hysteresisTable(): iterable
    {
        yield 'fast rescale fires exactly at the 5th count' => [
            1_000,
            [20_000, 20_000, 20_000, 20_000, 20_000],
            [10_240, 10_240, 10_240, 10_240, 26_000],
            [[1, 0], [2, 0], [3, 0], [4, 0], [0, 0]],
        ];
        yield 'slow rescale ×3.0 with avg of last 5, floored' => [
            1_000_000,
            [1_000, 1_000, 1_000, 1_000, 1_000],
            [1_300_000, 1_300_000, 1_300_000, 1_300_000, 10_240],
            [[0, 1], [0, 2], [0, 3], [0, 4], [0, 0]],
        ];
        yield 'slow rescale above the floor' => [
            1_000_000,
            [9_000, 9_000, 9_000, 9_000, 9_000],
            [1_300_000, 1_300_000, 1_300_000, 1_300_000, 27_000],
            [[0, 1], [0, 2], [0, 3], [0, 4], [0, 0]],
        ];
        yield 'opposite count decrements, floored at 0' => [
            100_000,
            [200_000, 200_000, 1, 1, 1, 200_000],
            [130_000, 130_000, 130_000, 130_000, 130_000, 130_000],
            [[1, 0], [2, 0], [1, 1], [0, 2], [0, 3], [1, 2]],
        ];
        yield 'speed equal to ceiling or to ceiling/10 does not count' => [
            100_000,
            [130_000, 13_000],
            [130_000, 130_000],
            [[0, 0], [0, 0]],
        ];
        yield 'no slow counting at the 10 KiB floor' => [
            0,
            [0, 0, 0, 0, 0, 0],
            [10_240, 10_240, 10_240, 10_240, 10_240, 10_240],
            [[0, 0], [0, 0], [0, 0], [0, 0], [0, 0], [0, 0]],
        ];
    }

    /**
     * @param list<int> $speeds
     * @param list<int> $maxes
     * @param list<array{0:int,1:int}> $counters
     */
    #[DataProvider('hysteresisTable')]
    public function testHysteresisTable(int $seed, array $speeds, array $maxes, array $counters): void
    {
        $s = NetAutoScale::new()->offer($seed, 0);
        foreach ($speeds as $i => $speed) {
            $s = $s->offer($speed, 0);
            $this->assertSame($maxes[$i], $s->downloadMax(), "tick $i ceiling");
            $this->assertSame($counters[$i], $s->counters(NetAutoScale::DOWNLOAD), "tick $i counters");
            $this->assertSame($i === count($speeds) - 1 && $maxes[$i] !== $maxes[max(0, $i - 1)], $s->rescaled);
        }
    }

    public function testAverageNeedsMoreThanFiveSamples(): void
    {
        // 4 seeded + this tick = 5 held → current speed (60000 × 1.3).
        $five = NetAutoScale::new()
            ->forceRescale([10_000, 20_000, 30_000, 40_000])
            ->offer(60_000, 0);
        $this->assertSame(78_000, $five->downloadMax());

        // 5 seeded + this tick = 6 held → mean of the last 5 = 40000 × 1.3.
        $six = NetAutoScale::new()
            ->forceRescale([10_000, 20_000, 30_000, 40_000, 50_000])
            ->offer(60_000, 0);
        $this->assertSame(52_000, $six->downloadMax());
    }

    public function testAverageUsesIntegerDivisionAndTruncatingCast(): void
    {
        $s = NetAutoScale::new()
            ->forceRescale([0, 33_335, 33_335, 33_335, 33_335])
            ->offer(33_337, 0);
        // sum = 133340 + 33337 = 166677 → /5 = 33335 → ×1.3 = 43335.5 → 43335.
        $this->assertSame(43_335, $s->downloadMax());
    }

    public function testHugeSpeedsSaturateInsteadOfThrowing(): void
    {
        $big = intdiv(PHP_INT_MAX, 2);
        // Six huge samples: the 5-sample sum overflows int (array_sum → float),
        // which must not reach intdiv() as a float; the float mean is used.
        $s = NetAutoScale::new()->forceRescale([$big, $big, $big, $big, $big])->offer($big, 0);
        $this->assertSame((int) ((float) $big * 1.3), $s->downloadMax());

        // At PHP_INT_MAX the ×1.3 product is out of int range and saturates.
        $max = NetAutoScale::new()
            ->forceRescale(array_fill(0, 5, PHP_INT_MAX))
            ->offer(PHP_INT_MAX, 0);
        $this->assertSame(PHP_INT_MAX, $max->downloadMax());

        // Current-speed path (≤5 held) saturates the same way.
        $this->assertSame(PHP_INT_MAX, NetAutoScale::new()->offer(PHP_INT_MAX, 0)->downloadMax());
    }

    public function testDirectionsRescaleIndependentlyWithoutSync(): void
    {
        $s = NetAutoScale::new()->offer(100_000, 200_000);
        $this->assertSame(130_000, $s->downloadMax());
        $this->assertSame(260_000, $s->uploadMax());
    }

    public function testSyncCopiesDownloadCeilingOntoUploadAndStops(): void
    {
        $s = NetAutoScale::new(sync: true)->offer(50_000, 900_000);
        // Download rescales first, sync copies it and ends the pass — upload's
        // own 900000 never gets a say this tick.
        $this->assertSame(65_000, $s->downloadMax());
        $this->assertSame(65_000, $s->uploadMax());
    }

    public function testSyncSkipPolarityFollowsBtopUpdateOrder(): void
    {
        $s = NetAutoScale::new(sync: true)->offer(50_000, 1_000);

        // Download compares against the PREVIOUS upload (1000) → counts;
        // upload 400000 < this tick's download 500000 → skipped.
        $s = $s->offer(500_000, 400_000);
        $this->assertSame([1, 0], $s->counters(NetAutoScale::DOWNLOAD));
        $this->assertSame([0, 0], $s->counters(NetAutoScale::UPLOAD));

        // Download 300000 < previous upload 400000 → skipped; upload 400000
        // vs this tick's download 300000 → counts.
        $s = $s->offer(300_000, 400_000);
        $this->assertSame([1, 0], $s->counters(NetAutoScale::DOWNLOAD));
        $this->assertSame([1, 0], $s->counters(NetAutoScale::UPLOAD));
    }

    public function testSyncUploadRescaleCopiesOntoDownload(): void
    {
        $s = NetAutoScale::new(sync: true)->offer(0, 0);
        $this->assertSame(10_240, $s->downloadMax());
        // Upload dominates: from the 2nd tick download is skipped (slower than
        // the previous upload); upload counts fast until the 5th tick fires.
        for ($i = 0; $i < 5; $i++) {
            $s = $s->offer(0, 100_000);
        }
        $this->assertTrue($s->rescaled);
        // History: 0 + five 100000 = 6 held → avg 100000 × 1.3.
        $this->assertSame(130_000, $s->uploadMax());
        $this->assertSame(130_000, $s->downloadMax());
        $this->assertSame([0, 0], $s->counters(NetAutoScale::DOWNLOAD));
    }

    public function testForceRescaleResetsCountersAndUsesFastFactor(): void
    {
        $s = NetAutoScale::new()->offer(1_000_000, 0);
        $s = $s->offer(1_000, 0)->offer(1_000, 0);
        $this->assertSame([0, 2], $s->counters(NetAutoScale::DOWNLOAD));

        $armed = $s->forceRescale();
        $this->assertTrue($armed->rescalePending());
        $this->assertSame([0, 0], $armed->counters(NetAutoScale::DOWNLOAD));
        $this->assertSame(1_300_000, $armed->downloadMax());

        // Rescale ignores the low-speed trend: ×1.3 on current speed (≤5 held).
        $after = $armed->offer(50_000, 0);
        $this->assertSame(65_000, $after->downloadMax());
        $this->assertFalse($after->rescalePending());
    }

    public function testForceRescaleSwapsInterfaceHistoryKeepingLastSix(): void
    {
        $s = NetAutoScale::new()
            ->forceRescale([999_999, 999_999, 5, 5, 5, 5, 5])
            ->offer(5, 0);
        // Window keeps the last 6 → after this tick the last 5 are all 5.
        $this->assertSame(NetAutoScale::FLOOR, $s->downloadMax());
    }

    public function testRescaleNowAppliesImmediatelyWithoutOfferingASample(): void
    {
        $s = NetAutoScale::new()->offer(1_000_000, 1_000_000);
        $this->assertSame(1_300_000, $s->downloadMax());

        // Six held samples → integer mean of the last five, ×1.3.
        $now = $s->rescaleNow([9, 50_000, 50_000, 50_000, 50_000, 60_000], [20_000, 20_000]);
        $this->assertSame(67_600, $now->downloadMax()); // mean 52000 × 1.3
        // ≤5 held → the newest sample is the current speed: 20000 × 1.3.
        $this->assertSame(26_000, $now->uploadMax());
        $this->assertTrue($now->rescaled);
        $this->assertFalse($now->rescalePending());
        $this->assertSame([0, 0], $now->counters(NetAutoScale::DOWNLOAD));
        $this->assertSame(1_300_000, $s->downloadMax(), 'immutable');

        // No rescale is left armed: an in-band offer keeps the new ceiling.
        $next = $now->offer(50_000, 20_000);
        $this->assertSame($now->downloadMax(), $next->downloadMax());
    }

    public function testRescaleNowWithEmptyHistoryFallsToTheFloorAndSyncCopies(): void
    {
        $s = NetAutoScale::new(true)->offer(1_000_000, 10)->rescaleNow([], [400_000]);
        $this->assertSame(NetAutoScale::FLOOR, $s->downloadMax());
        $this->assertSame(NetAutoScale::FLOOR, $s->uploadMax(), 'sync copies the download ceiling and stops');

        $kept = NetAutoScale::new()->offer(100_000, 200_000)->rescaleNow();
        $this->assertSame(130_000, $kept->downloadMax(), 'omitted histories keep the held window');
        $this->assertSame(260_000, $kept->uploadMax());
    }

    public function testNegativeSpeedsClampToZero(): void
    {
        $s = NetAutoScale::new()->offer(-5_000, -1);
        $this->assertSame(NetAutoScale::FLOOR, $s->downloadMax());
        $this->assertSame(NetAutoScale::FLOOR, $s->uploadMax());
    }

    public function testOfferIsImmutable(): void
    {
        $a = NetAutoScale::new();
        $b = $a->offer(100_000, 100_000);
        $this->assertNotSame($a, $b);
        $this->assertSame(0, $a->downloadMax());
        $this->assertTrue($a->rescalePending());
    }

    public function testWithSync(): void
    {
        $a = NetAutoScale::new();
        $b = $a->withSync(true);
        $this->assertFalse($a->sync);
        $this->assertTrue($b->sync);
    }

    public function testRescaledFlagClearsOnQuietTick(): void
    {
        $s = NetAutoScale::new()->offer(100_000, 0);
        $this->assertTrue($s->rescaled);
        $this->assertFalse($s->offer(100_000, 0)->rescaled);
    }

    public function testMaxForAndUnknownDirection(): void
    {
        $s = NetAutoScale::new()->offer(100_000, 0);
        $this->assertSame(130_000, $s->maxFor(NetAutoScale::DOWNLOAD));
        $this->assertSame(NetAutoScale::FLOOR, $s->maxFor('upload'));
        $this->expectException(\InvalidArgumentException::class);
        $s->maxFor('sideways');
    }

    public function testCountersRejectsUnknownDirection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NetAutoScale::new()->counters('rx');
    }
}
