<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\LineChart;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Charts\MarkLine;

/**
 * MarkLine overlay rendering (B2) — horizontal reference annotations wired
 * into LineChart's render pass. Snapshot style: every expectation pins the
 * exact rendered runes/bytes of the affected rows (per LineChartTest /
 * BarChartTest precedent), and the default [] path is pinned byte-identical.
 */
final class LineChartMarkLineTest extends TestCase
{
    /** Base chart used by most cases: [0,10] on 5x3 → rows 2..0, `/` connectors. */
    private static function base(): LineChart
    {
        return LineChart::new([0, 10], 5, 3);
    }

    public function testDefaultEmptyMarkLinesRenderByteIdentical(): void
    {
        $chart = self::base();
        $this->assertSame([], $chart->markLines, 'markLines must default to the empty list');

        $plain = $chart->view();
        $this->assertSame($plain, $chart->withMarkLines([])->view(), 'empty mark list must not move a single byte');

        // Opt-in is immutable: setting then clearing returns to the plain bytes,
        // and the original instance is never mutated by a wither.
        $marked  = $chart->withMarkLines([MarkLine::at(5.0, '', MarkLine::STYLE_SOLID)]);
        $cleared = $marked->withMarkLines([]);
        $this->assertNotSame($chart, $marked);
        $this->assertNotSame($plain, $marked->view(), 'sanity: marks must actually paint');
        $this->assertSame($plain, $cleared->view(), 'withMarkLines([]) must thread through lineChartCopy');
        $this->assertSame($plain, $chart->view(), 'original instance must stay pristine');
    }

    public function testSolidMarkPaintsRunAndReplacesSeriesCells(): void
    {
        // [0,10] 5x3: row0 "    *", row1 "  //" (connectors), row2 "*/".
        $expected = "    *\n─────\n*/"; // U+2500 solid run over the whole middle row
        $this->assertSame(
            $expected,
            self::base()->withMarkLines([MarkLine::at(5.0, '', MarkLine::STYLE_SOLID)])->view(),
            'solid mark at 5.0 must map to row 1 and paint the full plot-width run',
        );

        // Annotations ON TOP: a mark on the series row replaces the point cell.
        $peaks = LineChart::new([0, 10, 0], 3, 3); // " *", "", "* *" (no connectors between adjacent cols)
        $this->assertSame(
            "───\n\n* *",
            $peaks->withMarkLines([MarkLine::at(10.0, '', MarkLine::STYLE_SOLID)])->view(),
            'a mark at max=10 replaces the peak point cells on its row',
        );
    }

    public function testDashedAndDottedGlyphRunes(): void
    {
        $this->assertSame(
            "    *\n╌╌╌╌╌\n*/", // U+254C
            self::base()->withMarkLines([MarkLine::at(5.0, '', MarkLine::STYLE_DASHED)])->view(),
            'dashed style must render U+254C across the row',
        );
        $this->assertSame(
            "    *\n┄┄┄┄┄\n*/", // U+2504
            self::base()->withMarkLines([MarkLine::at(5.0, '', MarkLine::STYLE_DOTTED)])->view(),
            'dotted style must render U+2504 across the row',
        );
        // MarkLine::at() defaults to dashed — the no-style path must match.
        $this->assertSame(
            "    *\n╌╌╌╌╌\n*/",
            self::base()->withMarkLines([MarkLine::at(5.0)])->view(),
            'MarkLine::at() default style is dashed',
        );
    }

    public function testLabelPlacementRightAlignedOrOmittedWhenWide(): void
    {
        // 9 wide: label "max" (3 cells) fits (9 > 3+2) → rightmost 3 cells become the
        // label, the run continues beneath: 6×U+2500 then "max".
        $wide = LineChart::new([0, 10], 9, 3);
        $this->assertSame(
            "       /*\n──────max\n*//",
            $wide->withMarkLines([MarkLine::at(5.0, 'max', MarkLine::STYLE_SOLID)])->view(),
            'label must overwrite exactly the rightmost label-width cells of its row',
        );

        // Multibyte safety: "été" is 3 codepoints but 5 UTF-8 bytes — placement is
        // codepoint-per-cell, so 4×U+2500 + "été" on a 7-wide row (7 > 3+2).
        $mb = LineChart::new([0, 10], 7, 3);
        $this->assertSame(
            "     /*\n────été\n*/",
            $mb->withMarkLines([MarkLine::at(5.0, 'été', MarkLine::STYLE_SOLID)])->view(),
            'label width must be measured in codepoints, not bytes',
        );

        // 5 wide with a 3-cell label: 5 > 3+2 is FALSE (strict) → label omitted,
        // full glyph run stays.
        $narrow = self::base()->withMarkLines([MarkLine::at(5.0, 'abc', MarkLine::STYLE_SOLID)]);
        $this->assertSame(
            "    *\n─────\n*/",
            $narrow->view(),
            'label must be omitted when plot width is not > label width + 2',
        );
        $this->assertStringNotContainsString('abc', $narrow->view());
    }

    public function testMarksOutsideResolvedRangeSkipped(): void
    {
        $plain = self::base()->view(); // "    *\n  //\n*/"
        $this->assertSame(
            $plain,
            self::base()->withMarkLines([
                MarkLine::at(11.0, '', MarkLine::STYLE_SOLID),
                MarkLine::at(-0.5, '', MarkLine::STYLE_SOLID),
            ])->view(),
            'marks outside [min, max] must paint nothing',
        );

        // Closed interval: the exact min/max endpoints DO render (rows 2 and 0).
        $this->assertSame(
            "─────\n  //\n*/",
            self::base()->withMarkLines([MarkLine::at(10.0, '', MarkLine::STYLE_SOLID)])->view(),
            'mark at max must render on row 0',
        );
        $this->assertSame(
            "    *\n  //\n─────",
            self::base()->withMarkLines([MarkLine::at(0.0, '', MarkLine::STYLE_SOLID)])->view(),
            'mark at min must render on the bottom row',
        );
    }

    public function testInvalidMarkLineInputFailsFast(): void
    {
        try {
            self::base()->withMarkLines([MarkLine::at(5.0), 'nope']);
            $this->fail('withMarkLines must reject non-MarkLine list elements');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('MarkLine instances, string given', $e->getMessage());
        }

        // The VO accepts any style string, so the render pass is the fail-fast point
        // for glyphs it cannot draw.
        $bogus = MarkLine::at(5.0, '', 'wavy');
        try {
            self::base()->withMarkLines([$bogus])->view();
            $this->fail('unknown MarkLine style must throw at render time');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Invalid MarkLine style: wavy', $e->getMessage());
        }
    }
}
