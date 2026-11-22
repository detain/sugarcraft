<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Mouse\Zone;
use SugarCraft\Mouse\ZoneGrid;
use SugarCraft\Mouse\Zones\Manager;
use SugarCraft\Mouse\Zones\Zone as ZonesZone;

/**
 * P2-8 (a) and (f): ONE hit rule for both layers — the smallest covering
 * area wins, equal areas go to the first-registered zone — served by one
 * bucket grid ({@see ZoneGrid}). Before this, `Scanner::hit()` returned the
 * first zone in registration order while `Manager::zoneAt()` returned the
 * smallest, so one painted frame could have two different owners.
 */
final class HitRuleTest extends TestCase
{
    /**
     * A multi-row zone closes (registers) BEFORE a smaller zone painted inside
     * its bounding box: 'big' covers cols 1-4 rows 1-2 ("AAAA" over "B"), then
     * 'small' paints cols 2-3 of row 2. Cell (2, 2) lies in both.
     */
    private static function overlappingFrame(): string
    {
        return Mark::zone('big', "AAAA\nB") . Mark::zone('small', 'CC');
    }

    public function testPointInsideTwoOverlappingZonesRoutesToTheSmallerOneInBothLayers(): void
    {
        $frame = self::overlappingFrame();

        $scanner = Scanner::new()->scan($frame);
        self::assertSame(['big', 'small'], array_keys($scanner->all()), 'big registers first');
        self::assertSame([1, 1, 4, 2], self::box($scanner->get('big')));
        self::assertSame([2, 2, 3, 2], self::box($scanner->get('small')));

        $manager = Manager::newUnprefixed();
        $manager->scan($frame);

        // The regression: registration order used to hand (2, 2) to 'big' here.
        self::assertSame('small', $scanner->hit(2, 2)?->id);
        self::assertSame('small', $manager->zoneAt(2, 2)?->id);
        self::assertSame('small', $manager->anyInBounds(self::click(2, 2))?->id);

        // Cells only the big zone covers still go to it.
        self::assertSame('big', $scanner->hit(1, 1)?->id);
        self::assertSame('big', $manager->zoneAt(4, 2)?->id);
    }

    public function testBothLayersAgreeOnEveryCellOfANestedLayout(): void
    {
        $inner = Mark::zone('btn', 'OK');
        $frame = Mark::zone('panel', "+------+\n| " . $inner . "   |\n+------+")
            . "\n" . Mark::zone('a', 'xx') . Mark::zone('b', 'yy');

        $scanner = Scanner::new()->scan($frame);
        $manager = Manager::newUnprefixed();
        $manager->scan($frame);

        for ($row = 0; $row <= 5; $row++) {
            for ($col = 0; $col <= 10; $col++) {
                self::assertSame(
                    $manager->zoneAt($col, $row)?->id,
                    $scanner->hit($col, $row)?->id,
                    "cell ({$col}, {$row})",
                );
            }
        }
        self::assertSame('btn', $scanner->hit(3, 2)?->id, 'the innermost zone owns its cells');
        self::assertSame('panel', $scanner->hit(1, 2)?->id);
    }

    public function testEqualAreasGoToTheFirstRegisteredZone(): void
    {
        $grid = ZoneGrid::of([
            Zone::new('first', 1, 1, 3, 1),
            Zone::new('second', 1, 1, 3, 1),
        ]);

        self::assertSame('first', $grid->smallestAt(2, 1)?->id);
        self::assertSame(['first', 'second'], array_map(static fn (Zone $z): string => $z->id, $grid->allAt(2, 1)));
    }

    public function testAllAtOrdersInnermostFirstWithStableTies(): void
    {
        $grid = ZoneGrid::of([
            Zone::new('outer', 1, 1, 10, 5),
            Zone::new('tieA', 2, 2, 3, 2),
            Zone::new('mid', 1, 1, 5, 3),
            Zone::new('tieB', 2, 2, 3, 2),
        ]);

        self::assertSame(
            ['tieA', 'tieB', 'mid', 'outer'],
            array_map(static fn (Zone $z): string => $z->id, $grid->allAt(2, 2)),
        );
        self::assertSame([], $grid->allAt(20, 20));
        self::assertNull($grid->smallestAt(20, 20));
        self::assertSame(4, $grid->count());
    }

    public function testGridAcceptsZonesLayerZones(): void
    {
        $grid = ZoneGrid::of(['k' => ZonesZone::new('big', 1, 1, 9, 9), 'j' => ZonesZone::new('dot', 5, 5, 5, 5)]);

        self::assertInstanceOf(ZonesZone::class, $grid->smallestAt(5, 5));
        self::assertSame('dot', $grid->smallestAt(5, 5)?->id);
        self::assertSame('big', $grid->smallestAt(1, 1)?->id);
    }

    /**
     * The grid must answer exactly what a brute-force walk of the hit rule
     * answers, across bucket boundaries on both axes (BUCKET = 32) and with
     * heavy overlap.
     */
    public function testGridMatchesABruteForceSmallestAreaWalk(): void
    {
        mt_srand(62);
        $zones = [];
        for ($n = 0; $n < 60; $n++) {
            $col = mt_rand(1, 90);
            $row = mt_rand(1, 70);
            $zones[] = Zone::new("z{$n}", $col, $row, $col + mt_rand(0, 40), $row + mt_rand(0, 30));
        }
        $grid = ZoneGrid::of($zones);

        $mismatches = 0;
        for ($row = 0; $row <= 105; $row += 3) {
            for ($col = 0; $col <= 135; $col += 3) {
                $best = null;
                foreach ($zones as $z) {
                    if ($col >= $z->startCol && $col <= $z->endCol && $row >= $z->startRow && $row <= $z->endRow
                        && ($best === null || $z->width() * $z->height() < $best->width() * $best->height())
                    ) {
                        $best = $z;
                    }
                }
                if ($grid->smallestAt($col, $row)?->id !== $best?->id) {
                    $mismatches++;
                }
            }
        }
        self::assertSame(0, $mismatches);
    }

    /**
     * Grid build cost must not scale with a zone's box AREA: one zone 25000
     * cells on a side used to fill ~611k bucket keys and exhaust a 128M limit
     * on the first click. An oversized box goes on the wide list instead, and
     * the hit rule (smallest wins, ties to the first registered) still holds
     * across both lists.
     */
    public function testAHugeZoneIsIndexedWithoutFillingEveryBucket(): void
    {
        $before = memory_get_usage();
        $grid = ZoneGrid::of([
            Zone::new('tieA', 100, 100, 101, 101),
            Zone::new('huge', 1, 1, 25000, 25000),
            Zone::new('tieB', 100, 100, 101, 101),
            Zone::new('wideTie', 1, 1, 25000, 25000),
        ]);
        self::assertLessThan(1024 * 1024, memory_get_usage() - $before);

        self::assertSame('huge', $grid->smallestAt(24999, 24999)?->id);
        self::assertSame('tieA', $grid->smallestAt(100, 100)?->id);
        self::assertSame(
            ['tieA', 'tieB', 'huge', 'wideTie'],
            array_map(static fn (Zone $z): string => $z->id, $grid->allAt(101, 101)),
        );
        self::assertSame([], $grid->allAt(25001, 1));
    }

    public function testHostileCoordinatesHitNothing(): void
    {
        $grid = ZoneGrid::of([Zone::new('z', 1, 1, 40, 40)]);

        foreach ([0, -1, -33, PHP_INT_MIN] as $hostile) {
            self::assertNull($grid->smallestAt($hostile, 1));
            self::assertSame([], $grid->allAt(1, $hostile));
        }
    }

    public function testScannerHitAllReturnsTheStack(): void
    {
        $scanner = Scanner::new()->scan(self::overlappingFrame());

        self::assertSame(['small', 'big'], array_map(static fn (Zone $z): string => $z->id, $scanner->hitAll(2, 2)));
        self::assertSame([], $scanner->hitAll(9, 9));
        self::assertSame([], Scanner::new()->hitAll(1, 1));
    }

    public function testManagerAllInBoundsUsesTheSameStack(): void
    {
        $manager = Manager::newUnprefixed();
        $manager->scan(self::overlappingFrame());

        self::assertSame(
            ['small', 'big'],
            array_map(static fn (ZonesZone $z): string => $z->id, $manager->allInBounds(self::click(2, 2))),
        );
    }

    public function testManagerIndexIsRebuiltWhenTheRegistryChanges(): void
    {
        $manager = Manager::newUnprefixed();
        $manager->scan(Mark::zone('a', 'AAAA'));
        self::assertSame('a', $manager->zoneAt(2, 1)?->id); // builds the grid

        $manager->scan(Mark::zone('b', 'BB'));               // merge: b is smaller
        self::assertSame('b', $manager->zoneAt(2, 1)?->id);

        $manager->clear('b');
        self::assertSame('a', $manager->zoneAt(2, 1)?->id);

        $manager->clear();
        self::assertNull($manager->zoneAt(2, 1));

        $manager->scan(Mark::zone('c', 'C'));
        $manager->close();
        self::assertNull($manager->zoneAt(1, 1));
    }

    /** @return array{0:int,1:int,2:int,3:int} */
    private static function box(?Zone $zone): array
    {
        self::assertNotNull($zone);
        return [$zone->startCol, $zone->startRow, $zone->endCol, $zone->endRow];
    }

    private static function click(int $x, int $y): MouseMsg
    {
        return new MouseMsg($x, $y, MouseButton::Left, MouseAction::Press);
    }
}
