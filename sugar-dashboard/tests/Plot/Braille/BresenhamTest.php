<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Plot\Braille;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Plot\Braille\Bresenham;
use SugarCraft\Dashboard\Plot\Canvas\CanvasPoint;

/**
 * Exhaustive octant coverage for Bresenham (finding 086: only dx >= 0
 * cases were tested, so any signed-progression drift in the other half
 * of the plane was invisible).
 */
final class BresenhamTest extends TestCase
{
    /**
     * One segment per octant: |dx| vs |dy| crossed with both x signs and
     * both y signs, plus the axes and a degenerate point.
     *
     * @return array<string, array{0:int,1:int,2:int,3:int}>
     */
    public static function octantSegments(): array
    {
        return [
            'east' => [0, 0, 8, 0],
            'northeast-shallow' => [0, 0, 8, 4],
            'north-steep' => [0, 0, 2, 8],
            'north' => [0, 0, 0, 8],
            'northwest-steep' => [0, 0, -2, 8],
            'northwest-shallow' => [0, 0, -8, 4],
            'west' => [0, 0, -8, 0],
            'southwest-shallow' => [0, 0, -8, -4],
            'southwest-steep' => [0, 0, -2, -8],
            'south' => [0, 0, 0, -8],
            'southeast-steep' => [0, 0, 2, -8],
            'southeast-shallow' => [0, 0, 8, -4],
            'diagonal-45' => [1, 2, 9, 10],
            'steep-negative' => [3, -5, 4, -23],
            'point' => [5, 5, 5, 5],
            'offset-east' => [10, 7, 16, 7],
        ];
    }

    /**
     * @param array<string, array{0:int,1:int,2:int,3:int}> $segments
     */
    #[DataProvider('octantSegments')]
    public function testLineStructuralProperties(int $x1, int $y1, int $x2, int $y2): void
    {
        $points = Bresenham::line($x1, $y1, $x2, $y2);

        $this->assertNotEmpty($points);

        $first = array_key_first($points);
        $last = array_key_last($points);
        $this->assertSame($x1, $points[$first]->x, 'walk starts at (x1,y1)');
        $this->assertSame($y1, $points[$first]->y);
        $this->assertSame($x2, $points[$last]->x, 'walk ends at (x2,y2)');
        $this->assertSame($y2, $points[$last]->y);

        $dx = abs($x2 - $x1);
        $dy = abs($y2 - $y1);
        $this->assertCount(max($dx, $dy) + 1, $points, 'one pixel per dominant-axis step');

        $minX = min($x1, $x2);
        $maxX = max($x1, $x2);
        $minY = min($y1, $y2);
        $maxY = max($y1, $y2);
        $seen = [];
        foreach ($points as $i => $point) {
            $this->assertInstanceOf(CanvasPoint::class, $point);
            $this->assertGreaterThanOrEqual($minX, $point->x, 'pixels stay inside the bbox');
            $this->assertLessThanOrEqual($maxX, $point->x);
            $this->assertGreaterThanOrEqual($minY, $point->y);
            $this->assertLessThanOrEqual($maxY, $point->y);

            $key = $point->x . ',' . $point->y;
            $this->assertArrayNotHasKey($key, $seen, 'no pixel is painted twice');
            $seen[$key] = $i;

            if ($i === $last) {
                break;
            }
            $next = $points[$i + 1];
            $this->assertLessThanOrEqual(
                1,
                abs($next->x - $point->x),
                'dominant and stepping axes advance at most one cell per step',
            );
            $this->assertLessThanOrEqual(1, abs($next->y - $point->y));
        }
    }

    #[DataProvider('exactLineSegments')]
    public function testEveryPixelIsCollinearForExactSegments(
        int $x1,
        int $y1,
        int $x2,
        int $y2,
    ): void {
        foreach (Bresenham::line($x1, $y1, $x2, $y2) as $point) {
            $this->assertTrue(
                Bresenham::isOnLine($point->x, $point->y, $x1, $y1, $x2, $y2),
                sprintf(
                    'pixel (%d,%d) drifted off the line (%d,%d)-(%d,%d)',
                    $point->x,
                    $point->y,
                    $x1,
                    $y1,
                    $x2,
                    $y2,
                ),
            );
        }
    }

    /**
     * Segments whose slope Bresenham must reproduce without any error
     * term: axes and 45-degree diagonals. (Shallow ratios quantize by up
     * to one cell - pinned as a bound by the data-provider test below.)
     *
     * @return array<string, array{0:int,1:int,2:int,3:int}>
     */
    public static function exactLineSegments(): array
    {
        return [
            'diagonal-ne' => [0, 0, 6, 6],
            'diagonal-nw' => [0, 0, -6, 6],
            'diagonal-se' => [0, 0, 6, -6],
            'diagonal-sw' => [0, 0, -6, -6],
            'axis-h' => [-3, 4, 9, 4],
            'axis-v' => [2, -7, 2, 5],
        ];
    }

    #[DataProvider('octantSegments')]
    public function testEveryPixelHugsTheAlgebraicLineWithinOneCell(
        int $x1,
        int $y1,
        int $x2,
        int $y2,
    ): void {
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        $bound = max(abs($dx), abs($dy));
        $points = Bresenham::line($x1, $y1, $x2, $y2);

        foreach ($points as $i => $point) {
            $cross = abs(($point->x - $x1) * $dy - ($point->y - $y1) * $dx);
            if ($i === 0 || $i === count($points) - 1) {
                $this->assertSame(0, $cross, 'endpoints sit exactly on the line');
                continue;
            }
            $this->assertLessThanOrEqual(
                $bound,
                $cross,
                sprintf(
                    'pixel %d (%d,%d) strayed more than one cell off (%d,%d)-(%d,%d)',
                    $i,
                    $point->x,
                    $point->y,
                    $x1,
                    $y1,
                    $x2,
                    $y2,
                ),
            );
        }
    }

    public function testCircleArcReturnsWholeRingByDefaultAndSlicesByOctant(): void
    {
        $full = Bresenham::circleArc(10, 10, 5);
        $this->assertNotEmpty($full);

        foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $octant) {
            $slice = Bresenham::circleArc(10, 10, 5, $octant);
            $this->assertNotEmpty($slice, "octant {$octant} paints points");
            $this->assertLessThan(count($full), count($slice), 'a slice is smaller than the ring');
            foreach ($slice as $point) {
                $this->assertTrue(
                    self::isOnFullRing($point, $full),
                    "octant {$octant} pixel ({$point->x},{$point->y}) belongs to the ring",
                );
            }
        }
    }

    public function testCircleArcRadiusZeroIsTheCenterPoint(): void
    {
        $points = Bresenham::circleArc(4, 9, 0);
        $this->assertCount(1, $points);
        $this->assertSame([4, 9], [$points[0]->x, $points[0]->y]);
    }

    #[DataProvider('invalidOctants')]
    public function testCircleArcRejectsOctantsOutsideTheTable(int $octant): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Bresenham::circleArc(0, 0, 5, $octant);
    }

    /**
     * @return array<string, array{0:int}>
     */
    public static function invalidOctants(): array
    {
        return [
            'one-past-the-table' => [9],
            'far-past' => [42],
            'negative' => [-1],
        ];
    }

    /**
     * @param list<CanvasPoint> $ring
     */
    private static function isOnFullRing(CanvasPoint $point, array $ring): bool
    {
        foreach ($ring as $candidate) {
            if ($candidate->x === $point->x && $candidate->y === $point->y) {
                return true;
            }
        }

        return false;
    }
}
