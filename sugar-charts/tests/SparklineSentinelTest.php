<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\Sparkline\Sparkline;
use SugarCraft\Style\Style;

/**
 * CH-15 (sugar-charts-104, -106): the width sentinel rule must match at
 * both doors (new() used to swallow any negative into auto-fit while
 * withWidth threw), the setter-shaped aliases must not ArgumentCountError
 * when called bare, and a styled floor value must render visibly instead
 * of the invisible blank that made the shipped example look like dropout.
 */
final class SparklineSentinelTest extends TestCase
{
    private static function plain(string $s): string
    {
        return preg_replace('/\x1b\[[0-9;]*m/', '', $s) ?? '';
    }

    public function testExplicitNegativeWidthThrowsAtBothDoors(): void
    {
        try {
            Sparkline::new([1.0, 2.0], -5);
            self::fail('new() swallowed an explicit negative width into auto-fit');
        } catch (InvalidArgumentException $e) {
            self::assertNotSame('', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        Sparkline::new([1.0, 2.0])->withWidth(-5);
    }

    public function testMinusOneSentinelStillAutoFits(): void
    {
        $s = Sparkline::new([1.0, 2.0, 3.0], -1);
        self::assertSame(3, $s->width);
        $d = Sparkline::new([1.0, 2.0]);
        self::assertSame(2, $d->width);
    }

    public function testZeroWidthKeepsItsDocumentedEmptyMeaning(): void
    {
        self::assertSame('', Sparkline::new([1.0, 2.0], 0)->view());
    }

    public function testAliasesReadBareAndWriteWithArgument(): void
    {
        $chart = Sparkline::new([1.0, 2.0, 3.0])->width(10);
        self::assertSame(10, $chart->width());
        self::assertSame([1.0, 2.0, 3.0], $chart->data());
        self::assertNull($chart->min());
        self::assertNull($chart->max());
        $pinned = $chart->min(0.0)->max(5.0);
        self::assertSame(0.0, $pinned->min());
        self::assertSame(5.0, $pinned->max());
        // Property and accessor agree.
        self::assertSame($pinned->width, $pinned->width());
    }

    public function testStyledFloorRendersVisibleBlock(): void
    {
        // Single-bar floor: unstyled keeps the documented blank, styled
        // promotes to the lowest ramp cell.
        self::assertSame(
            ' █',
            self::plain(Sparkline::new([2.0, 8.0], 2)->view()),
        );
        self::assertSame(
            '▁█',
            self::plain(Sparkline::new([2.0, 8.0], 2)->withStyle(Style::new()->bold())->view()),
            'styled min-floor rendered invisible blank',
        );
    }

    public function testStyledPromotionLeavesMissingCellsBlank(): void
    {
        // Width 5 over 3 points: two leading MISSING cells stay blank even
        // under a style — only the data floor promotes.
        $out = self::plain(
            Sparkline::new([2.0, 8.0, 8.0], 5)->withStyle(Style::new()->bold())->view()
        );
        self::assertSame('  ▁██', $out);
    }

    public function testEmptyDataBlankRowUnaffectedByPromotion(): void
    {
        // The empty-data contract renders the blank glyph, not the floor.
        $out = self::plain(Sparkline::new([], 4)->withStyle(Style::new()->bold())->view());
        self::assertSame('    ', $out);
    }
}
