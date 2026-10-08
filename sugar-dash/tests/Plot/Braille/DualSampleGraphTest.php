<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Braille;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Foundation\SizedItem;
use SugarCraft\Dash\Plot\Braille\DualSampleGraph;

/**
 * Expected glyphs are worked out by hand from btop's law, not read back
 * from the implementation:
 *
 *  - height 1 (mod 0.3, band = round(v*4/100 + 0.3)): bands 1/2/3/4 start
 *    at v = 5 / 30 / 55 / 80 (4 → 0.46 → 0, 5 → 0.5 → 1, …).
 *  - height 2 (mod 0.1): top row spans 50..100, bottom 0..50.
 *  - braille glyphs are derived from dot bits, block glyphs from quadrants,
 *    block2 glyphs from sextant cell bits.
 *  - block2 (btop PR #1783): bands 0..3, height 1 band = round(v*3/100 + 0.6)
 *    so bands 1/2/3 start at v = 1 / 30 / 64 (29 → 1.47, 31 → 1.53,
 *    63 → 2.49, 64 → 2.52); taller graphs use mod 0.2.
 */
final class DualSampleGraphTest extends TestCase
{
    private const ESC = "\x1b[";
    private const RESET = "\x1b[0m";

    /** Ramp whose entry k is rgb(k,0,0), so a color index reads off the SGR. */
    private static function redRamp(): array
    {
        return [Color::rgb(0, 0, 0), Color::rgb(100, 0, 0)];
    }

    private static function fg(int $r, int $g = 0, int $b = 0): string
    {
        return self::ESC . "38;2;{$r};{$g};{$b}m";
    }

    private static function plain(DualSampleGraph $g): string
    {
        return $g->render(ColorProfile::NoTty);
    }

    // ═══════════════════════════════════════════════════════════════
    // Glyph tables (independent derivation)
    // ═══════════════════════════════════════════════════════════════

    public function testBrailleTablesMatchDotPacking(): void
    {
        // Left column dots bottom→top: 7,3,2,1; right: 8,6,5,4.
        $leftUp = [0x40, 0x04, 0x02, 0x01];
        $rightUp = [0x80, 0x20, 0x10, 0x08];
        $leftDown = [0x01, 0x02, 0x04, 0x40];
        $rightDown = [0x08, 0x10, 0x20, 0x80];
        for ($prev = 0; $prev <= 4; $prev++) {
            for ($cur = 0; $cur <= 4; $cur++) {
                $up = array_sum(array_slice($leftUp, 0, $prev)) + array_sum(array_slice($rightUp, 0, $cur));
                $down = array_sum(array_slice($leftDown, 0, $prev)) + array_sum(array_slice($rightDown, 0, $cur));
                $expUp = $up === 0 ? ' ' : mb_chr(0x2800 + $up);
                $expDown = $down === 0 ? ' ' : mb_chr(0x2800 + $down);
                $this->assertSame($expUp, DualSampleGraph::SYMBOLS['braille_up'][$prev * 5 + $cur], "up $prev,$cur");
                $this->assertSame($expDown, DualSampleGraph::SYMBOLS['braille_down'][$prev * 5 + $cur], "down $prev,$cur");
            }
        }
    }

    public function testBlockTablesCollapseBandsOntoQuadrants(): void
    {
        // Bands 1-2 light the near half of a column, 3-4 the whole column.
        $level = static fn(int $band): int => $band === 0 ? 0 : ($band <= 2 ? 1 : 2);
        // Quadrant bits: UL=1 UR=2 LL=4 LR=8.
        $glyph = [
            0 => ' ', 1 => '▘', 2 => '▝', 3 => '▀', 4 => '▖', 5 => '▌', 6 => '▞', 7 => '▛',
            8 => '▗', 9 => '▚', 10 => '▐', 11 => '▜', 12 => '▄', 13 => '▙', 14 => '▟', 15 => '█',
        ];
        $bits = static function (int $lvl, int $near, int $far): int {
            return ($lvl >= 1 ? $near : 0) | ($lvl >= 2 ? $far : 0);
        };
        for ($prev = 0; $prev <= 4; $prev++) {
            for ($cur = 0; $cur <= 4; $cur++) {
                $up = $bits($level($prev), 4, 1) | $bits($level($cur), 8, 2);
                $down = $bits($level($prev), 1, 4) | $bits($level($cur), 2, 8);
                $this->assertSame($glyph[$up], DualSampleGraph::SYMBOLS['block_up'][$prev * 5 + $cur], "up $prev,$cur");
                $this->assertSame($glyph[$down], DualSampleGraph::SYMBOLS['block_down'][$prev * 5 + $cur], "down $prev,$cur");
            }
        }
    }

    public function testTtyTablesAreBtopVerbatimAndShadeOnly(): void
    {
        $btop = [
            ' ', '░', '░', '▒', '▒',
            '░', '░', '▒', '▒', '█',
            '░', '▒', '▒', '▒', '█',
            '▒', '▒', '▒', '█', '█',
            '▒', '█', '█', '█', '█',
        ];
        $this->assertSame($btop, DualSampleGraph::SYMBOLS['tty_up']);
        $this->assertSame($btop, DualSampleGraph::SYMBOLS['tty_down']);
        $this->assertNotContains('▓', $btop);
    }

    public function testBlock2TablesMatchSextantPacking(): void
    {
        // Sextant cell bits: TL=1 TR=2 ML=4 MR=8 BL=16 BR=32. U+1FB00.. holds
        // patterns 1..62 minus 21 (left column, ▌) and 42 (right column, ▐).
        $glyph = static function (int $bits): string {
            return match ($bits) {
                0 => ' ',
                21 => '▌',
                42 => '▐',
                63 => '█',
                default => mb_chr(0x1FB00 + $bits - 1 - ($bits > 21 ? 1 : 0) - ($bits > 42 ? 1 : 0)),
            };
        };
        // Band b lights b cells of a column, from the bottom (up) or top (down).
        $leftUp = [16, 4, 1];
        $rightUp = [32, 8, 2];
        $leftDown = [1, 4, 16];
        $rightDown = [2, 8, 32];
        for ($prev = 0; $prev <= 4; $prev++) {
            for ($cur = 0; $cur <= 4; $cur++) {
                $i = $prev * 5 + $cur;
                if ($prev === 4 || $cur === 4) {
                    // Unreachable with clamp_max 3: btop pads the 5×5 shape with spaces.
                    $this->assertSame(' ', DualSampleGraph::SYMBOLS['block2_up'][$i], "up pad $prev,$cur");
                    $this->assertSame(' ', DualSampleGraph::SYMBOLS['block2_down'][$i], "down pad $prev,$cur");
                    continue;
                }
                $up = array_sum(array_slice($leftUp, 0, $prev)) + array_sum(array_slice($rightUp, 0, $cur));
                $down = array_sum(array_slice($leftDown, 0, $prev)) + array_sum(array_slice($rightDown, 0, $cur));
                $this->assertSame($glyph($up), DualSampleGraph::SYMBOLS['block2_up'][$i], "up $prev,$cur");
                $this->assertSame($glyph($down), DualSampleGraph::SYMBOLS['block2_down'][$i], "down $prev,$cur");
            }
        }
    }

    public function testSextantGlyphsAreOneCellWide(): void
    {
        foreach (['block2_up', 'block2_down'] as $name) {
            foreach (DualSampleGraph::SYMBOLS[$name] as $i => $g) {
                $this->assertSame(1, Width::string($g), "{$name}[{$i}]");
            }
        }
    }

    public function testEveryTableHas25Entries(): void
    {
        $this->assertCount(8, DualSampleGraph::SYMBOLS);
        foreach (DualSampleGraph::FAMILIES as $family) {
            $this->assertArrayHasKey($family . '_up', DualSampleGraph::SYMBOLS);
            $this->assertArrayHasKey($family . '_down', DualSampleGraph::SYMBOLS);
        }
        foreach (DualSampleGraph::SYMBOLS as $name => $table) {
            $this->assertCount(25, $table, $name);
            $this->assertSame(' ', $table[0], $name);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Band transitions, every family
    // ═══════════════════════════════════════════════════════════════

    /** @return iterable<string, array{string, bool, string}> */
    public static function heightOneFamilies(): iterable
    {
        // Pairs (4,5)=(0,1) (29,30)=(1,2) (54,55)=(2,3) (79,80)=(3,4) (100,0)=(4,0).
        yield 'braille up' => ['braille', false, '⢀⣠⣴⣾⡇'];
        yield 'braille down' => ['braille', true, '⠈⠙⠻⢿⡇'];
        yield 'block up' => ['block', false, '▗▄▟█▌'];
        yield 'block down' => ['block', true, '▝▀▜█▌'];
    }

    /** @return iterable<string, array{bool, string}> */
    public static function block2HeightOne(): iterable
    {
        // Pairs (0,1)=(0,1) (29,31)=(1,2) (63,64)=(2,3) (100,0)=(3,0) (1,100)=(1,3).
        yield 'up' => [false, '🬞🬵🬻▌🬷'];
        yield 'down' => [true, '🬁🬊🬬▌🬨'];
    }

    #[DataProvider('block2HeightOne')]
    public function testBlock2HeightOneBandEdges(bool $invert, string $expected): void
    {
        $g = DualSampleGraph::new(5, 1, DualSampleGraph::FAMILY_BLOCK2, $invert)
            ->withData(0, 1, 29, 31, 63, 64, 100, 0, 1, 100);
        $this->assertSame($expected, self::plain($g));
    }

    public function testBlock2RoundingBiasLiftsSmallValues(): void
    {
        // v=1: braille round(0.04 + 0.3) = 0 (blank); block2 round(0.03 + 0.6) = 1.
        $this->assertSame(' ', self::plain(DualSampleGraph::new(1, 1)->withData(1, 1)));
        $this->assertSame('🬭', self::plain(DualSampleGraph::new(1, 1, 'block2')->withData(1, 1)));
    }

    public function testBlock2HeightTwoUsesModPointTwo(): void
    {
        // Bottom row 0..50: 25 → 1.5 + 0.2 → 2; 100 saturates at 3. Top row: 25 → 0.
        $up = DualSampleGraph::new(1, 2, 'block2')->withData(25, 100);
        $this->assertSame("▐\n🬻", self::plain($up));
        // Invert: down table, rows reversed.
        $down = DualSampleGraph::new(1, 2, 'block2', true)->withData(25, 100);
        $this->assertSame("🬬\n▐", self::plain($down));
    }

    public function testBlock2TallGraph(): void
    {
        // Rows of 20: 100 saturates every row (3); 50 → 0, 0, round(10*3/20 + 0.2) = 2, 3, 3.
        $g = DualSampleGraph::new(1, 5, 'block2')->withData(100, 50);
        $this->assertSame("▌\n▌\n🬺\n█\n█", self::plain($g));
        $inv = DualSampleGraph::new(1, 5, 'block2', true)->withData(100, 50);
        $this->assertSame("█\n█\n🬝\n▌\n▌", self::plain($inv));
    }

    public function testBlock2NoZeroFloorIsBandOne(): void
    {
        // no_zero lifts the bottom row to band 1 per half: (1,1) = 🬭, the graph_bg glyph.
        $this->assertSame('🬭', self::plain(DualSampleGraph::new(1, 1, 'block2', noZero: true)->withData(0, 0)));
        $this->assertSame(" \n🬭", self::plain(DualSampleGraph::new(1, 2, 'block2', noZero: true)->withData(0, 0)));
    }

    #[DataProvider('heightOneFamilies')]
    public function testHeightOneBandEdges(string $family, bool $invert, string $expected): void
    {
        $g = DualSampleGraph::new(5, 1, $family, $invert)->withData(4, 5, 29, 30, 54, 55, 79, 80, 100, 0);
        $this->assertSame($expected, self::plain($g));
    }

    public function testTtyIsOneSamplePerCellKeyedOnPredecessor(): void
    {
        // 10 samples, width 5: last seeded from 54, cells pair (54,55) (55,79)
        // (79,80) (80,100) (100,0) = idx 13,18,19,24,20.
        $g = DualSampleGraph::new(5, 1, DualSampleGraph::FAMILY_TTY)->withData(4, 5, 29, 30, 54, 55, 79, 80, 100, 0);
        $this->assertSame('▒███▒', self::plain($g));
        // Inverting tty swaps to the identical down table.
        $this->assertSame('▒███▒', self::plain(DualSampleGraph::new(5, 1, 'tty', true)->withData(4, 5, 29, 30, 54, 55, 79, 80, 100, 0)));
    }

    public function testHeightTwoBands(): void
    {
        // Top row 50..100: (100,50)=(4,0) ⡇, (75,0)=(2,0) ⡄.
        // Bottom 0..50:    (100,50)=(4,4) ⣿, (75,0)=(4,0) ⡇.
        $g = DualSampleGraph::new(2, 2)->withData(100, 50, 75, 0);
        $this->assertSame("⡇⡄\n⣿⡇", self::plain($g));
    }

    public function testHeightTwoInvertReversesRowsAndUsesDownTable(): void
    {
        $g = DualSampleGraph::new(2, 2, 'braille', true)->withData(100, 50, 75, 0);
        $this->assertSame("⣿⡇\n⡇⠃", self::plain($g));
    }

    public function testHeightTwoModPointOneThresholds(): void
    {
        // Top row band = round((v-50)*4/50 + 0.1): 54→0 55→1 67→1 68→2 79→2 80→3 92→3 93→4.
        $g = DualSampleGraph::new(4, 2)->withData(54, 55, 67, 68, 79, 80, 92, 93);
        [$top] = explode("\n", self::plain($g));
        $this->assertSame('⢀⣠⣴⣾', $top);
    }

    // ═══════════════════════════════════════════════════════════════
    // Coercion: offset / max_value / no_zero
    // ═══════════════════════════════════════════════════════════════

    public function testMaxValueScalesAndClamps(): void
    {
        // max 200: -50 → -25 → 0, 100 → 50 (band 2), 400 → 100, 0 → 0.
        $g = DualSampleGraph::new(2, 1, maxValue: 200)->withData(-50, 100, 400, 0);
        $this->assertSame('⢠⡇', self::plain($g));
    }

    public function testPercentScalingTruncatesLikeCpp(): void
    {
        // 6*100/11 = 54.5 → 54 (band 2); rounding would give 55 (band 3, ⢰).
        $g = DualSampleGraph::new(1, 1, maxValue: 11)->withData(0, 6);
        $this->assertSame('⢠', self::plain($g));
    }

    public function testPositiveOffsetImpliesMaxValueHundred(): void
    {
        $g = DualSampleGraph::new(1, 1, offset: 10);
        $this->assertSame(100, $g->maxValue());
        // (-10+10)*100/100 = 0, (90+10) = 100.
        $this->assertSame('⢸', self::plain($g->withData(-10, 90)));
        $this->assertSame(0, DualSampleGraph::new(1, 1, offset: -5)->maxValue());
    }

    public function testRawValuesOutsideRangeSaturateBands(): void
    {
        $g = DualSampleGraph::new(1, 1)->withData(-5, 150);
        $this->assertSame('⢸', self::plain($g));
    }

    public function testNoZeroLiftsBottomRowOnly(): void
    {
        $this->assertSame('  ', self::plain(DualSampleGraph::new(2, 1)->withData(0, 0, 0, 0)));
        $this->assertSame('⣀⣀', self::plain(DualSampleGraph::new(2, 1, noZero: true)->withData(0, 0, 0, 0)));
        $this->assertSame(" \n⣀", self::plain(DualSampleGraph::new(1, 2, noZero: true)->withData(0, 0)));
    }

    public function testNoZeroExemptsFirstLeftSampleInTty(): void
    {
        // tty keeps one buffer, so the exemption on (i == data_offset, ai == 0)
        // shows: (0→0, 30→2) = ░ instead of (1,2) = ▒.
        $g = DualSampleGraph::new(2, 1, 'tty', noZero: true)->withData(30, 30);
        $this->assertSame('░▒', self::plain($g));
        // Seeded predecessor (offset > 0) is exempted too: (0→0, 0→1), (1, 2).
        $this->assertSame('░▒', self::plain(DualSampleGraph::new(2, 1, 'tty', noZero: true)->withData(0, 0, 30)));
    }

    public function testFloatsRoundAndNonFiniteThrows(): void
    {
        // 4.6 → 5 → band 1.
        $this->assertSame([0, 5], DualSampleGraph::new(1, 1)->withData(0, 4.6)->samples());
        $this->expectException(\InvalidArgumentException::class);
        DualSampleGraph::new(1, 1)->push(NAN);
    }

    public function testInvalidGeometryAndFamilyThrow(): void
    {
        foreach ([[0, 1, 'braille'], [1, 0, 'braille'], [1, 1, 'dots']] as [$w, $h, $f]) {
            try {
                DualSampleGraph::new($w, $h, $f);
                $this->fail("expected throw for {$w}x{$h} {$f}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Data window / push
    // ═══════════════════════════════════════════════════════════════

    public function testShortOddHistoryPairsFirstSampleWithZero(): void
    {
        // 3 samples, width 3: offset -1 → cells (0,100) (100,100) + 1 pad.
        $this->assertSame(' ⢸⣿', self::plain(DualSampleGraph::new(3, 1)->withData(100, 100, 100)));
        // Height>1 pads with opaque spaces at the left.
        $this->assertSame(" ⢸⣿\n ⢸⣿", self::plain(DualSampleGraph::new(3, 2)->withData(100, 100, 100)));
    }

    public function testLongHistorySeedsPredecessor(): void
    {
        // Braille width 1 windows the final pair (100,0) = ⡇.
        $this->assertSame('⡇', self::plain(DualSampleGraph::new(1, 1)->withData(0, 0, 0, 100, 0)));
        // tty width 2 over [100,0,0]: offset 1 seeds last = 100, so the first
        // cell is (100,0) = ▒; unseeded it would be blank.
        $this->assertSame('▒ ', self::plain(DualSampleGraph::new(2, 1, 'tty')->withData(100, 0, 0)));
    }

    public function testHistoryCapIsIndependentOfWidth(): void
    {
        $floor = 2 * DualSampleGraph::HISTORY_CELLS + 1;
        $this->assertCount($floor, DualSampleGraph::new(1, 1)->withData(...range(1, 3000))->samples());
        $this->assertSame(3000, DualSampleGraph::new(1, 1)->withData(...range(1, 3000))->samples()[$floor - 1]);
        $this->assertCount(4001, DualSampleGraph::new(2000, 1)->withData(...range(1, 5000))->samples());
    }

    public function testShrinkThenGrowKeepsHistory(): void
    {
        $values = [100, 0, 30, 55, 80, 5, 0, 100, 42, 7];
        foreach (['braille', 'block', 'tty'] as $family) {
            $wide = DualSampleGraph::new(5, 2, $family)->withData(...$values);
            $roundTrip = $wide->setSize(1, 2)->setSize(5, 2);
            $this->assertSame(self::plain($wide), self::plain($roundTrip), $family);
            $this->assertSame($values, $roundTrip->samples());
        }
        // A width beyond the floor is remembered once seen.
        $big = DualSampleGraph::new(1, 1)->setSize(1500, 1)->setSize(1, 1)->withData(...range(1, 4000));
        $this->assertCount(3001, $big->samples());
    }

    public function testIntegerExtremesSaturateInsteadOfOverflowing(): void
    {
        $g = DualSampleGraph::new(1, 1, maxValue: 100, offset: PHP_INT_MAX)->withData(PHP_INT_MIN, PHP_INT_MAX);
        $this->assertSame(DualSampleGraph::SAMPLE_LIMIT, $g->offset());
        $this->assertSame([-DualSampleGraph::SAMPLE_LIMIT, DualSampleGraph::SAMPLE_LIMIT], $g->samples());
        // (-L + L)*100/100 = 0 → band 0; (L + L)*100/100 → 100 → band 4.
        $this->assertSame('⢸', self::plain($g));
        $neg = DualSampleGraph::new(1, 1, maxValue: 100, offset: PHP_INT_MIN)->withData(PHP_INT_MAX, PHP_INT_MIN);
        $this->assertSame(-DualSampleGraph::SAMPLE_LIMIT, $neg->offset());
        $this->assertSame(' ', self::plain($neg));
    }

    public function testHugeFiniteFloatsClampToSampleLimit(): void
    {
        $g = DualSampleGraph::new(1, 1, maxValue: 10)->withData(-1e30, 1e30);
        $this->assertSame([-DualSampleGraph::SAMPLE_LIMIT, DualSampleGraph::SAMPLE_LIMIT], $g->samples());
        $this->assertSame('⢸', self::plain($g));
        $this->assertSame([DualSampleGraph::SAMPLE_LIMIT], DualSampleGraph::new(1, 1)->push(PHP_INT_MAX + 1.0)->samples());
    }

    public function testEmptyGraphRendersBlankFrame(): void
    {
        $this->assertSame('   ', self::plain(DualSampleGraph::new(3, 1)));
        $this->assertSame("  \n  ", self::plain(DualSampleGraph::new(2, 2)));
    }

    public function testPushScrollsAndEqualsWithData(): void
    {
        $values = [0, 100, 30, 55, 80, 5, 0, 100, 42, 7, 99];
        foreach (DualSampleGraph::FAMILIES as $family) {
            $pushed = DualSampleGraph::new(4, 3, $family);
            foreach ($values as $v) {
                $pushed = $pushed->push($v);
            }
            $whole = DualSampleGraph::new(4, 3, $family)->withData(...$values);
            $this->assertSame(self::plain($whole), self::plain($pushed), $family);
            $this->assertSame($whole->samples(), $pushed->samples());
        }
        // Half a cell per sample: the newest pair is always rightmost.
        $g = DualSampleGraph::new(2, 1)->withData(100, 100);
        $this->assertSame(' ⣿', self::plain($g));
        // [100,100,0] is odd: cells re-pair as (0,100) (100,0).
        $this->assertSame('⢸⡇', self::plain($g->push(0)));
        $this->assertSame('⣿⢸', self::plain($g->push(0)->push(100)));
    }

    public function testWithersAreImmutable(): void
    {
        $base = DualSampleGraph::new(2, 1);
        $pushed = $base->push(1, 2);
        $colored = $base->withGradient(self::redRamp());
        $under = $base->withUnderlay(Color::rgb(1, 1, 1));
        $this->assertSame([], $base->samples());
        $this->assertSame([1, 2], $pushed->samples());
        $this->assertNull($base->gradient());
        $this->assertCount(101, $colored->gradient());
        $this->assertNull($colored->withoutGradient()->gradient());
        $this->assertNull($base->underlayColor());
        $this->assertNull($under->withoutUnderlay()->underlayColor());
    }

    public function testAccessorsAndSizing(): void
    {
        $g = DualSampleGraph::new(3, 2, 'block', true, true, 50, -5);
        $this->assertInstanceOf(SizedItem::class, $g);
        $this->assertSame([3, 2, 'block', true, true, 50, -5], [
            $g->width(), $g->height(), $g->family(), $g->inverted(), $g->noZero(), $g->maxValue(), $g->offset(),
        ]);
        $this->assertSame([3, 2], $g->getInnerSize());
        $resized = $g->withData(...range(1, 20))->setSize(2, 0);
        $this->assertSame([2, 1], $resized->getInnerSize());
        $this->assertSame(range(1, 20), $resized->samples());
    }

    // ═══════════════════════════════════════════════════════════════
    // Coloring polarity
    // ═══════════════════════════════════════════════════════════════

    public function testHeightOneColorsOnlyDrawnCellsByMaxOfPair(): void
    {
        // (0,0) blank → no color; (80,20) → max 80; (0,100) → 100.
        $g = DualSampleGraph::new(3, 1)->withGradient(self::redRamp())->withData(0, 0, 80, 20, 0, 100);
        $this->assertSame(
            ' ' . self::fg(80) . '⣇' . self::fg(100) . '⢸' . self::RESET,
            $g->render(ColorProfile::TrueColor),
        );
    }

    public function testTallGraphColorsPerRowByPosition(): void
    {
        $g = DualSampleGraph::new(2, 2)->withGradient(self::redRamp())->withData(100, 50, 75, 0);
        $this->assertSame(
            self::fg(100) . '⡇⡄' . self::RESET . "\n" . self::fg(50) . '⣿⡇' . self::RESET,
            $g->render(ColorProfile::TrueColor),
        );
        // 3 rows: 100 - (i-1)*100/3 → 100, 67, 34; inverted i*100/3 → 33, 66, 100.
        $rowColor = static fn(string $out): array => array_map(
            static fn(string $line): int => (int) explode(';', $line)[2],
            explode("\n", $out),
        );
        $three = DualSampleGraph::new(1, 3)->withGradient(self::redRamp())->withData(50, 50);
        $this->assertSame([100, 67, 34], $rowColor($three->render(ColorProfile::TrueColor)));
        $inv = DualSampleGraph::new(1, 3, invert: true)->withGradient(self::redRamp())->withData(50, 50);
        $this->assertSame([33, 66, 100], $rowColor($inv->render(ColorProfile::TrueColor)));
    }

    public function testNoGradientEmitsNoSgr(): void
    {
        $g = DualSampleGraph::new(2, 2)->withData(100, 50, 75, 0);
        $this->assertSame("⡇⡄\n⣿⡇", $g->render(ColorProfile::TrueColor));
    }

    public function testNoTtyProfileStripsAllColor(): void
    {
        $g = DualSampleGraph::new(2, 1)
            ->withGradient(self::redRamp())
            ->withUnderlay(Color::rgb(9, 9, 9))
            ->withData(0, 0, 0, 100);
        $this->assertSame('⣀⢸', $g->render(ColorProfile::NoTty));
    }

    // ═══════════════════════════════════════════════════════════════
    // graph_bg underlay
    // ═══════════════════════════════════════════════════════════════

    public function testUnderlayGlyphIsUpTableIndexSix(): void
    {
        $this->assertSame('⣀', DualSampleGraph::new(1, 1, 'braille', true)->underlayGlyph());
        $this->assertSame('▄', DualSampleGraph::new(1, 1, 'block')->underlayGlyph());
        $this->assertSame('░', DualSampleGraph::new(1, 1, 'tty')->underlayGlyph());
        $this->assertSame('🬭', DualSampleGraph::new(1, 1, DualSampleGraph::FAMILY_BLOCK2, true)->underlayGlyph());
    }

    public function testStaticUnderlayStrip(): void
    {
        $this->assertSame(self::fg(1, 2, 3) . '▄▄▄' . self::RESET, DualSampleGraph::underlay('block', 3, Color::rgb(1, 2, 3), ColorProfile::TrueColor));
        $this->assertSame('⣀⣀', DualSampleGraph::underlay('braille', 2, Color::rgb(1, 2, 3), ColorProfile::NoTty));
        $this->assertSame('', DualSampleGraph::underlay('tty', 0, Color::rgb(1, 2, 3), ColorProfile::TrueColor));
        $this->assertSame('🬭🬭🬭', DualSampleGraph::underlay('block2', 3, Color::rgb(1, 2, 3), ColorProfile::NoTty));
        $this->expectException(\InvalidArgumentException::class);
        DualSampleGraph::underlay('dots', 1, Color::rgb(1, 2, 3));
    }

    public function testUnderlayFillsTransparentCellsThenGraphOverdraws(): void
    {
        // Pad cell + blank (0,0) cell show the underlay; drawn cell keeps its ramp color.
        $g = DualSampleGraph::new(3, 1)
            ->withGradient(self::redRamp())
            ->withUnderlay(Color::rgb(9, 9, 9))
            ->withData(0, 0, 0, 100);
        $this->assertSame(
            self::fg(9, 9, 9) . '⣀⣀' . self::fg(100) . '⢸' . self::RESET,
            $g->render(ColorProfile::TrueColor),
        );
    }

    public function testUncoloredGlyphAfterUnderlayIsReset(): void
    {
        $g = DualSampleGraph::new(2, 1)->withUnderlay(Color::rgb(9, 9, 9))->withData(0, 0, 0, 100);
        $this->assertSame(self::fg(9, 9, 9) . '⣀' . self::RESET . '⢸', $g->render(ColorProfile::TrueColor));
        // Underlay run ending the row is closed.
        $tail = DualSampleGraph::new(2, 1)->withUnderlay(Color::rgb(9, 9, 9));
        $this->assertSame(self::fg(9, 9, 9) . '⣀⣀' . self::RESET, $tail->render(ColorProfile::TrueColor));
    }

    public function testTallGraphsNeverShowUnderlay(): void
    {
        // btop prints taller graphs' blanks as opaque spaces.
        $g = DualSampleGraph::new(2, 2)->withUnderlay(Color::rgb(9, 9, 9))->withData(0, 0);
        $this->assertSame("  \n  ", $g->render(ColorProfile::TrueColor));
    }

    // ═══════════════════════════════════════════════════════════════
    // Cell widths
    // ═══════════════════════════════════════════════════════════════

    public function testEveryRowIsExactlyWidthCells(): void
    {
        $data = [-5, 0, 3, 50, 99, 100, 250, 12, 66];
        foreach (DualSampleGraph::FAMILIES as $family) {
            foreach ([1, 2, 4] as $height) {
                foreach ([0, 1, 4, 9] as $len) {
                    $g = DualSampleGraph::new(6, $height, $family, noZero: true)
                        ->withGradient(self::redRamp())
                        ->withUnderlay(Color::rgb(9, 9, 9))
                        ->withData(...array_slice($data, 0, $len));
                    $lines = explode("\n", $g->render(ColorProfile::TrueColor));
                    $this->assertCount($height, $lines);
                    foreach ($lines as $line) {
                        $this->assertSame(6, Width::string($line), "$family h$height n$len");
                    }
                }
            }
        }
    }
}
