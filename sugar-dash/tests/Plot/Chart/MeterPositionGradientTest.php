<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Chart;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Dash\Plot\Chart\Meter;

/**
 * btop Draw::Meter law (btop_draw.cpp:397-419): each `■` cell is colored at
 * its own position y = round(i*100/width) while value >= y; the first
 * unfilled cell paints the whole tail in meter_bg; per-value memo.
 */
final class MeterPositionGradientTest extends TestCase
{
    private const BG = "\x1b[38;2;64;64;64m";
    private const RESET = "\x1b[0m";

    protected function setUp(): void
    {
        Meter::resetMemo();
    }

    /** @return list<Color> */
    private static function cyanToRed(): array
    {
        return [Color::rgb(0, 255, 255), Color::rgb(255, 0, 0)];
    }

    private static function fg(int $r, int $g, int $b): string
    {
        return "\x1b[38;2;{$r};{$g};{$b}m";
    }

    private static function bar(float $ratio, int $width = 10): Meter
    {
        return Meter::new($ratio)->withWidth($width)->withGradient(self::cyanToRed(), positionWise: true);
    }

    public function testFilledCellsTakeTheirOwnPositionColorAndTailIsMeterBg(): void
    {
        // Ramp entries 10..50 by btop's truncating law: r = 255y/100,
        // g = b = 255 - 255y/100 (truncated toward zero).
        $expected = self::fg(25, 230, 230) . '■'
            . self::fg(51, 204, 204) . '■'
            . self::fg(76, 179, 179) . '■'
            . self::fg(102, 153, 153) . '■'
            . self::fg(127, 128, 128) . '■'
            . self::BG . '■■■■■'
            . self::RESET;

        $this->assertSame($expected, self::bar(0.5)->render());
    }

    public function testInvertFlipsRampPolarity(): void
    {
        $expected = self::fg(229, 26, 26) . '■'
            . self::fg(204, 51, 51) . '■'
            . self::fg(178, 77, 77) . '■'
            . self::fg(153, 102, 102) . '■'
            . self::fg(127, 128, 128) . '■'
            . self::BG . '■■■■■'
            . self::RESET;

        $this->assertSame($expected, self::bar(0.5)->withInvert()->render());
    }

    public function testZeroValueIsAllTail(): void
    {
        $this->assertSame(self::BG . str_repeat('■', 10) . self::RESET, self::bar(0.0)->render());
    }

    public function testFullValueHasNoTail(): void
    {
        $out = self::bar(1.0)->render();
        $this->assertStringNotContainsString(self::BG, $out);
        $this->assertSame(10, mb_substr_count($out, '■'));
        $this->assertStringEndsWith(self::fg(255, 0, 0) . '■' . self::RESET, $out);
    }

    public function testCellThresholdsUseRoundedPositions(): void
    {
        // width 3 → y = 33, 67, 100: 66% fills one cell, 67% fills two.
        $one = self::bar(0.66, 3)->render();
        $two = self::bar(0.67, 3)->render();
        $this->assertSame(self::BG . '■■' . self::RESET, substr($one, strpos($one, self::BG)));
        $this->assertSame(self::BG . '■' . self::RESET, substr($two, strpos($two, self::BG)));
    }

    public function testMemoRendersEachValueOnceThenServesHits(): void
    {
        $base = self::bar(0.0);
        $first = [];
        for ($v = 0; $v <= 100; $v++) {
            $first[$v] = $base->withRatio($v / 100)->render();
        }
        $stats = Meter::memoStats();
        $this->assertSame(array_sum(array_map('strlen', $first)), $stats['bytes']);
        unset($stats['bytes']);
        $this->assertSame(['keys' => 1, 'entries' => 101, 'hits' => 0, 'misses' => 101], $stats);

        for ($v = 0; $v <= 100; $v++) {
            $this->assertSame($first[$v], $base->withRatio($v / 100)->render());
        }
        $stats = Meter::memoStats();
        unset($stats['bytes']);
        $this->assertSame(['keys' => 1, 'entries' => 101, 'hits' => 101, 'misses' => 101], $stats);
    }

    public function testRampIsExpandedOnceAtAttachAndCarriedByWithers(): void
    {
        $base = self::bar(0.5);
        $ramp = $base->gradient();
        $this->assertIsArray($ramp);
        $this->assertCount(101, $ramp);

        $moved = $base->withRatio(0.2)->withShowLabel(false)->withHeight(9)->withMeterColor(null);
        $this->assertSame($ramp, $moved->gradient());
        $this->assertTrue($moved->positionWise());
    }

    public function testMemoKeyCountIsBounded(): void
    {
        for ($w = 1; $w <= 80; $w++) {
            Meter::new(0.5)->setSize($w, 1)->withGradient(self::cyanToRed(), true)->render();
        }
        $this->assertSame(64, Meter::memoStats()['keys']);
    }

    public function testMemoEvictsLeastRecentlyUsedShape(): void
    {
        $hot = Meter::new(0.5)->setSize(1, 1)->withGradient(self::cyanToRed(), true);
        $hot->render();
        for ($w = 2; $w <= 64; $w++) {
            Meter::new(0.5)->setSize($w, 1)->withGradient(self::cyanToRed(), true)->render();
        }
        $hot->render(); // hit: width 1 becomes most recent, width 2 now oldest
        Meter::new(0.5)->setSize(65, 1)->withGradient(self::cyanToRed(), true)->render();

        $before = Meter::memoStats();
        $hot->render();
        $this->assertSame($before['hits'] + 1, Meter::memoStats()['hits'], 'touched shape survived eviction');

        Meter::new(0.5)->setSize(2, 1)->withGradient(self::cyanToRed(), true)->render();
        $this->assertSame($before['misses'] + 1, Meter::memoStats()['misses'], 'oldest shape was evicted');
    }

    public function testMemoByteCountShrinksOnEviction(): void
    {
        for ($w = 1; $w <= 70; $w++) {
            Meter::new(0.5)->setSize($w, 1)->withGradient(self::cyanToRed(), true)->render();
        }
        $expected = 0;
        for ($w = 7; $w <= 70; $w++) {
            $expected += strlen(Meter::new(0.5)->setSize($w, 1)->withGradient(self::cyanToRed(), true)->render());
        }
        $this->assertSame($expected, Meter::memoStats()['bytes']);
    }

    public function testWidthOneFillsOnlyAtFullValue(): void
    {
        $this->assertSame(self::BG . '■' . self::RESET, self::bar(0.99, 1)->render());
        $this->assertSame(self::fg(255, 0, 0) . '■' . self::RESET, self::bar(1.0, 1)->render());
        $this->assertSame([1, 1], self::bar(1.0, 1)->getInnerSize());
    }

    public function testWidthTwoSplitsAtFifty(): void
    {
        $this->assertSame(self::BG . '■■' . self::RESET, self::bar(0.49, 2)->render());
        $this->assertSame(self::fg(127, 128, 128) . '■' . self::BG . '■' . self::RESET, self::bar(0.5, 2)->render());
    }

    public function testNonPositiveWidthClampsToOneCellInPositionMode(): void
    {
        $this->assertSame(1, mb_substr_count(self::bar(1.0, 0)->render(), '■'));
    }

    public function testAnalogFaceKeepsItsMinimumWidth(): void
    {
        $this->assertSame([3, 13], Meter::new(0.5)->withWidth(1)->getInnerSize());
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function exactThresholds(): iterable
    {
        // [width, first cell threshold y = round(100/width)] — 8 hits .5.
        yield 'width 4' => [4, 25];
        yield 'width 7' => [7, 14];
        yield 'width 8 (12.5 rounds up)' => [8, 13];
        yield 'width 10' => [10, 10];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('exactThresholds')]
    public function testFirstCellFillsExactlyAtItsRoundedThreshold(int $width, int $y): void
    {
        $below = self::bar(($y - 1) / 100, $width)->render();
        $at = self::bar($y / 100, $width)->render();
        $this->assertStringStartsWith(self::BG, $below);
        $this->assertSame($width, mb_substr_count($below, '■'));
        $this->assertStringStartsNotWith(self::BG, $at);
        $this->assertSame(1, substr_count($at, '■' . self::BG));
    }

    public function testSecondCellThresholdAboveWidthThree(): void
    {
        // width 7: y2 = round(28.57) = 29 — 28% fills one cell, 29% two.
        $this->assertSame(6, mb_substr_count(strstr(self::bar(0.28, 7)->render(), self::BG), '■'));
        $this->assertSame(5, mb_substr_count(strstr(self::bar(0.29, 7)->render(), self::BG), '■'));
    }

    public function testCacheKeySeparatesEveryByteShapingInput(): void
    {
        $stops = self::cyanToRed();
        $base = Meter::cacheKey($stops, 10, false);
        $this->assertSame($base, Meter::cacheKey($stops, 10, false, '■', Color::hex('#404040')));
        $this->assertNotSame($base, Meter::cacheKey($stops, 11, false));
        $this->assertNotSame($base, Meter::cacheKey($stops, 10, true));
        $this->assertNotSame($base, Meter::cacheKey($stops, 10, false, '█'));
        $this->assertNotSame($base, Meter::cacheKey($stops, 10, false, '■', Color::hex('#000000')));
        $this->assertNotSame($base, Meter::cacheKey(array_reverse($stops), 10, false));
    }

    public function testAllocatedWidthWinsOverWithWidth(): void
    {
        $meter = self::bar(1.0)->setSize(4, 1);
        $this->assertSame(4, mb_substr_count($meter->render(), '■'));
        $this->assertSame([4, 1], $meter->getInnerSize());
        $this->assertSame([10, 1], self::bar(1.0)->getInnerSize());
    }

    public function testZeroAllocatedWidthRendersNothing(): void
    {
        $this->assertSame('', self::bar(0.5)->setSize(0, 1)->render());
    }

    public function testWithGlyphReplacesFilledAndTailGlyph(): void
    {
        $out = self::bar(0.5)->withGlyph('█')->render();
        $this->assertSame(10, mb_substr_count($out, '█'));
        $this->assertStringNotContainsString('■', $out);
        $this->assertSame('█', self::bar(0.5)->withGlyph('█')->glyph());
        $this->assertSame('■', self::bar(0.5)->withGlyph()->glyph());
    }

    public function testEmptyGlyphThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::bar(0.5)->withGlyph('');
    }

    public function testWithMeterBgRecolorsTail(): void
    {
        $meter = self::bar(0.0, 3)->withMeterBg(Color::rgb(1, 2, 3));
        $this->assertSame(self::fg(1, 2, 3) . '■■■' . self::RESET, $meter->render());
        $this->assertSame('#010203', strtolower($meter->meterBg()->toHex()));
        $this->assertSame('#404040', strtolower($meter->withMeterBg(null)->meterBg()->toHex()));
    }

    public function testWithGradientRejectsTooFewStops(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Meter::new(0.5)->withGradient([Color::rgb(0, 0, 0)], true);
    }

    public function testValueWiseGradientColorsAnalogBodyByValue(): void
    {
        $out = Meter::new(0.5)->withGradient(self::cyanToRed())->render();
        // Ramp[50] replaces the flat purple on the active body.
        $this->assertStringContainsString(self::fg(127, 128, 128) . '█', $out);
        $this->assertStringNotContainsString('■', $out);
        $this->assertFalse(Meter::new(0.5)->withGradient(self::cyanToRed())->positionWise());
    }

    public function testWithoutGradientRestoresFlatAnalogBytes(): void
    {
        $plain = Meter::new(0.5)->render();
        $this->assertSame($plain, self::bar(0.5)->withWidth(5)->withoutGradient()->render());
        $this->assertNull(self::bar(0.5)->withoutGradient()->gradient());
    }

    public function testAccessorDefaults(): void
    {
        $m = Meter::new(0.5);
        $this->assertNull($m->gradient());
        $this->assertFalse($m->positionWise());
        $this->assertSame('■', $m->glyph());
        $this->assertFalse($m->invert());
        $this->assertTrue($m->withInvert()->invert());
    }
}
