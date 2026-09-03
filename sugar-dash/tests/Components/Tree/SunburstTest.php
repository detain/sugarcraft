<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Components\Tree\Sunburst;
use SugarCraft\Dash\Components\Tree\SunburstSegment;

final class SunburstTest extends TestCase
{
    public function testNewCreatesDefaultInstance(): void
    {
        $sunburst = Sunburst::new();
        $this->assertInstanceOf(Sunburst::class, $sunburst);
    }

    public function testSetSizeReturnsSizerInterface(): void
    {
        $sunburst = Sunburst::new();
        $result = $sunburst->setSize(50, 25);
        $this->assertInstanceOf(\SugarCraft\Dash\Foundation\Sizer::class, $result);
    }

    public function testRenderReturnsNonEmptyString(): void
    {
        $sunburst = Sunburst::new()->setSize(50, 25);
        $rendered = $sunburst->render();
        $this->assertNotEmpty($rendered);
    }

    public function testRenderContainsBorderCharacters(): void
    {
        $sunburst = Sunburst::new()->setSize(50, 25);
        $rendered = $sunburst->render();
        $this->assertStringContainsString('╭', $rendered);
        $this->assertStringContainsString('╮', $rendered);
        $this->assertStringContainsString('╰', $rendered);
        $this->assertStringContainsString('╯', $rendered);
    }

    public function testWithSegment(): void
    {
        $sunburst = Sunburst::new();
        $segment = new SunburstSegment('s1', 'Segment 1', 100);
        $result = $sunburst->withSegment($segment);
        $this->assertInstanceOf(Sunburst::class, $result);
    }

    public function testAddSegment(): void
    {
        $sunburst = Sunburst::new();
        $result = $sunburst->addSegment('s1', 'Segment 1', 100);
        $this->assertInstanceOf(Sunburst::class, $result);
    }

    public function testWithSegments(): void
    {
        $sunburst = Sunburst::new();
        $segments = [
            new SunburstSegment('s1', 'Segment 1', 100),
            new SunburstSegment('s2', 'Segment 2', 200),
        ];
        $result = $sunburst->withSegments($segments);
        $this->assertInstanceOf(Sunburst::class, $result);
    }

    public function testWithCenterLabel(): void
    {
        $sunburst = Sunburst::new();
        $result = $sunburst->withCenterLabel('Total');
        $this->assertInstanceOf(Sunburst::class, $result);
    }

    public function testWithShowLabels(): void
    {
        $sunburst = Sunburst::new();
        $result = $sunburst->withShowLabels(false);
        $this->assertInstanceOf(Sunburst::class, $result);
    }

    public function testWithShowValues(): void
    {
        $sunburst = Sunburst::new();
        $result = $sunburst->withShowValues(true);
        $this->assertInstanceOf(Sunburst::class, $result);
    }

    public function testWithMaxDepth(): void
    {
        $sunburst = Sunburst::new();
        $result = $sunburst->withMaxDepth(5);
        $this->assertInstanceOf(Sunburst::class, $result);
    }

    public function testWithStyle(): void
    {
        $sunburst = Sunburst::new();
        $result = $sunburst->withStyle('bold');
        $this->assertInstanceOf(Sunburst::class, $result);
    }

    public function testGetInnerSize(): void
    {
        $sunburst = Sunburst::new()->setSize(50, 25);
        $size = $sunburst->getInnerSize();
        $this->assertIsArray($size);
        $this->assertCount(2, $size);
        $this->assertEquals(50, $size[0]);
        $this->assertEquals(25, $size[1]);
    }

    public function testSmallDimensionsReturnEmpty(): void
    {
        $sunburst = Sunburst::new()->setSize(10, 5);
        $rendered = $sunburst->render();
        $this->assertSame('', $rendered);
    }

    public function testWithSegmentColor(): void
    {
        $sunburst = Sunburst::new();
        $result = $sunburst->withSegmentColor(\SugarCraft\Core\Util\Color::hex('#FF0000'));
        $this->assertInstanceOf(Sunburst::class, $result);
    }

    public function testWithTextColor(): void
    {
        $sunburst = Sunburst::new();
        $result = $sunburst->withTextColor(\SugarCraft\Core\Util\Color::hex('#00FF00'));
        $this->assertInstanceOf(Sunburst::class, $result);
    }

    public function testWithCenterColor(): void
    {
        $sunburst = Sunburst::new();
        $result = $sunburst->withCenterColor(\SugarCraft\Core\Util\Color::hex('#0000FF'));
        $this->assertInstanceOf(Sunburst::class, $result);
    }

    public function testSunburstSegmentWithChildren(): void
    {
        $parent = new SunburstSegment('p', 'Parent', 100);
        $child = new SunburstSegment('c', 'Child', 50);
        $parentWithChildren = $parent->withChildren([$child]);

        $this->assertCount(1, $parentWithChildren->children);
        $this->assertEquals('Child', $parentWithChildren->children[0]->label);
    }

    public function testSunburstSegmentWithColor(): void
    {
        $segment = new SunburstSegment('s1', 'Segment', 100);
        $colored = $segment->withColor(\SugarCraft\Core\Util\Color::hex('#FF0000'));

        $this->assertNotNull($colored->color);
    }

    public function testSunburstSegmentGetTotalValue(): void
    {
        $parent = new SunburstSegment('p', 'Parent', 100);
        $child = new SunburstSegment('c', 'Child', 50);

        $parentWithChildren = $parent->withChildren([$child]);

        $this->assertEquals(150.0, $parentWithChildren->getTotalValue());
    }

    public function testSunburstSegmentGetTotalValueSingle(): void
    {
        $segment = new SunburstSegment('s1', 'Single', 75);
        $this->assertEquals(75.0, $segment->getTotalValue());
    }

    public function testMultipleSegmentsRender(): void
    {
        $sunburst = Sunburst::new()
            ->addSegment('s1', 'Alpha', 100)
            ->addSegment('s2', 'Beta', 200)
            ->addSegment('s3', 'Gamma', 150)
            ->setSize(50, 25);

        $rendered = $sunburst->render();
        $this->assertNotEmpty($rendered);
        $this->assertStringContainsString('Alpha', $rendered);
        $this->assertStringContainsString('Beta', $rendered);
        $this->assertStringContainsString('Gamma', $rendered);
    }

    public function testNestedSegmentsRender(): void
    {
        $child = new SunburstSegment('c1', 'Child 1', 50);
        $child2 = new SunburstSegment('c2', 'Child 2', 30);
        $parent = (new SunburstSegment('p1', 'Parent', 100))->withChildren([$child, $child2]);

        $sunburst = Sunburst::new()
            ->withSegment($parent)
            ->setSize(50, 25);

        $rendered = $sunburst->render();
        $this->assertNotEmpty($rendered);
    }

    /**
     * C3 byte-identity pin: the pure-ASCII center path rendered at HEAD
     * (f2c7b9822, pre codepoint-window fix) — same component, same bytes.
     * sha1 captured via /tmp/c3-capture.php BEFORE the fix was applied.
     */
    public function testCenterLabelAsciiDefaultByteIdentity(): void
    {
        $rendered = self::asciiBase()->withShowLabels(false)->render();

        $this->assertSame('2543b593a01417702c4b06d2de11c2cf821021ce', sha1($rendered));
        $this->assertTrue(mb_check_encoding($rendered, 'UTF-8'));
    }

    /** C3 CASE 2 anchor: 16-char ASCII label in a 3-codepoint window renders 'ABC' byte-identically. */
    public function testCenterLabelAsciiLongLabelWindowByteIdentity(): void
    {
        $rendered = self::asciiBase()->withCenterLabel('ABCDEFGHIJKLMNOP')->withShowLabels(false)->render();

        $this->assertSame('85d10f7ac0de19a575e5d2f1d8ab5cf48354bd5a', sha1($rendered));
        $this->assertStringContainsString('ABC', self::stripSgr($rendered));
        $this->assertStringNotContainsString('D', self::stripSgr($rendered));
    }

    /** D4 (ex C3 CASE 1): a CJK center label fills the 3-cell window by DISPLAY CELLS — '日' (2 cells) leads, '本' would straddle the budget so it is dropped whole, never split. */
    public function testCjkCenterLabelFitsCellWindow(): void
    {
        $rendered = self::asciiBase()->withCenterLabel('日本語テスト')->render();

        $this->assertTrue(mb_check_encoding($rendered, 'UTF-8'));
        $stripped = self::stripSgr($rendered);
        // Window is 2*intval(5/4)+1 = 3 cells: '日' occupies cells 0-1 (its
        // trailing cell paints nothing), cell 2 blanks — the rim box stays aligned.
        $this->assertStringContainsString('●日 ●', $stripped);
        $this->assertStringNotContainsString('本', $stripped);
        $this->assertSame(1, preg_match_all('/[日本語]/u', $stripped));
    }

    /** D4: the cell budget is honored exactly — 'ab日' fits 'ab' (2 cells) but drops whole-width '日' (would need cells 2-3 of a 3-cell window). */
    public function testCenterWindowBudgetNeverSplitsWideGlyph(): void
    {
        $rendered = self::asciiBase()->withCenterLabel('ab日')->render();

        $stripped = self::stripSgr($rendered);
        $this->assertStringContainsString('ab', $stripped);
        $this->assertStringNotContainsString('日', $stripped);
        $this->assertTrue(mb_check_encoding($rendered, 'UTF-8'));
    }

    /** D4: zero-width combining marks fold onto their base cell instead of eating window budget; content stays whole. */
    public function testCombiningMarkCenterLabelFoldsOntoBaseCell(): void
    {
        $rendered = self::asciiBase()->withCenterLabel("e\u{0301}xY")->render();

        $this->assertTrue(mb_check_encoding($rendered, 'UTF-8'));
        $stripped = self::stripSgr($rendered);
        // 3-cell window: 'é' (base+mark, 1 cell) then 'x' then 'Y'.
        $this->assertStringContainsString("\u{0301}xY", $stripped);
    }

    /** C3 CASE 3: 'X'-offset label used to slice through a codepoint (invalid UTF-8); now whole runes. D4: the cell window renders 'X日' (1+2 cells) and drops '本' whole. */
    public function testStraddlingCenterLabelStaysValidUtf8(): void
    {
        $rendered = self::asciiBase()->withCenterLabel('X日本語テスト')->render();

        $this->assertTrue(mb_check_encoding($rendered, 'UTF-8'));
        $stripped = self::stripSgr($rendered);
        $this->assertStringContainsString('X日', $stripped);
        $this->assertStringNotContainsString('本', $stripped);
        $this->assertStringNotContainsString("\xEF\xBF\xBD", $rendered);
    }

    /** C3/R5(ii): an over-long legend entry truncates to the budget instead of vanishing whole (CASE 1 legend). */
    public function testLegendOverlongEntryTruncatedNotDropped(): void
    {
        $rendered = Sunburst::new()
            ->setSize(60, 25)
            ->withCenterLabel('日本語テスト')
            ->addSegment('a', str_repeat('a', 59), 40)
            ->addSegment('b', 'b', 10)
            ->render();

        $stripped = self::stripSgr($rendered);
        // Budget: legendX=2, width=60 → 60-3-2 = 55 CELLS (= codepoints here,
        // pure ASCII); '▪ ' + 59 a's (61 cells) → hard cut to '▪ ' + 53 a's.
        $this->assertStringContainsString('▪ ' . str_repeat('a', 53), $stripped);
        $this->assertStringNotContainsString('▪ a' . str_repeat('a', 53), $stripped);
        // Budget exhausted by the truncated entry — the degenerate guard stops the loop.
        $this->assertSame(1, substr_count($stripped, '▪'));
    }

    /** D4: a wide-legend entry truncates to the remaining CELL budget — the 35-cell cut lands at 16×日 (34 cells; the 17th would straddle), and the cell advance then trips the degenerate guard. */
    public function testWideLegendEntryTruncatesToCells(): void
    {
        $stripped = self::stripSgr(Sunburst::new()
            ->setSize(40, 14)
            ->addSegment('a', str_repeat('日', 20), 30)
            ->addSegment('b', 'Beta', 20)
            ->render());

        // '▪ ' (2 cells) + 16×日 (32) = 34 of the 35-cell budget; a codepoint
        // cut would have kept 17 日 + one spare cell — the difference is pinned.
        $this->assertSame(16, preg_match_all('/日/u', $stripped));
        // Advance counted cells (34+2 → legendX=38; available=40-3-38 < 1) so
        // '▪ Beta' never renders — guard, not silence.
        $this->assertSame(1, substr_count($stripped, '▪'));
        $this->assertTrue(mb_check_encoding($stripped, 'UTF-8'));
    }

    /** D4: '日本' ADVANCES 4 cells, not 2 codepoints — the next entry's budget shrinks accordingly. */
    public function testWideEntryAdvancesCellsNotCodepoints(): void
    {
        $stripped = self::stripSgr(Sunburst::new()
            ->setSize(60, 25)
            ->addSegment('a', '日本', 10)
            ->addSegment('b', str_repeat('y', 48), 10)
            ->render());

        // Entry '▪ 日本' = 6 CELLS (4 codepoints). Cell advance: legendX=2+6+2=10
        // → available=60-3-10=47 → '▪ ' cut to 45 y's. The old codepoint advance
        // (legendX=8 → available 49) would have kept 47 y's — 45 pins cells won.
        $this->assertStringContainsString('▪ 日本  ▪ ' . str_repeat('y', 45), $stripped);
        $this->assertStringNotContainsString(str_repeat('y', 46), $stripped);
        $this->assertSame(2, substr_count($stripped, '▪'));
    }

    /** C3/D4: legend right border aligns by VISIBLE CELLS — embedded SGR must not count (CASE 1c), and wide glyphs count their columns. */
    public function testLegendPaddingAlignsByVisibleWidth(): void
    {
        // ASCII fitting legend (cells == codepoints — byte-identical to the old math).
        $ascii = self::stripSgr(self::asciiBase()->render());
        $asciiLegendRow = self::rowContaining($ascii, '▪ Alpha');
        $this->assertSame(60, mb_strlen($asciiLegendRow));
        $this->assertStringStartsWith('│', $asciiLegendRow);
        $this->assertStringEndsWith('│', $asciiLegendRow);
        $this->assertStringContainsString('▪ Alpha  ▪ Beta', $asciiLegendRow);

        // CJK + truecolor legend: padded by DISPLAY CELLS (Core\Util\Width) —
        // the row measures exactly its width in cells while carrying fewer codepoints.
        $cjk = Sunburst::new()->setSize(60, 25)
            ->addSegment('a', '日本語', 50)
            ->addSegment('b', 'テスト', 50)
            ->render();
        $this->assertStringContainsString("\x1b[38;2;", $cjk);
        $cjkLegendRow = self::rowContaining(self::stripSgr($cjk), '▪ 日本語');
        $this->assertSame(60, Width::string($cjkLegendRow));
        $this->assertSame(54, mb_strlen($cjkLegendRow));
        $this->assertStringEndsWith('│', $cjkLegendRow);
    }

    /**
     * D4 ASCII byte-identity pins (Q5 discipline): legend ON with the '▪'
     * swatch (U+25AA — live-probed Width 1), advance, truncation, and
     * degenerate-guard paths rendered at HEAD 69f6d3450 BEFORE the
     * Core\Util\Width wiring, captured via /tmp/d4-capture.php
     * (double-replay sha1-identical). Width::string ≡ mb_strlen for
     * BMP-narrow runs, so these renders must not move a single byte.
     */
    public function testAsciiLegendPathsByteIdentityPins(): void
    {
        // A: two ASCII segments — swatch + advance + pad.
        $this->assertSame('8e8a03fce50b6efbd4efe02395fff8ff84ffc7f7', sha1(self::asciiBase()->render()));

        // B: over-long entry → truncate branch to the last cell, then guard break.
        $this->assertSame('bca109d896088ca029c831c57e51d85e190e7cc9', sha1(Sunburst::new()
            ->setSize(40, 14)
            ->addSegment('a', str_repeat('x', 60), 30)
            ->addSegment('b', 'Beta', 20)
            ->render()));

        // C: tight 24-col box, 4 segments → partial truncation + guard.
        $this->assertSame('e304f4d900c5f003ef4faf2386967092f4f57d68', sha1(Sunburst::new()
            ->setSize(24, 14)
            ->addSegment('a', 'Alpha', 10)
            ->addSegment('b', 'Beta', 10)
            ->addSegment('c', 'Gamma', 10)
            ->addSegment('d', 'Delta', 10)
            ->render()));
    }

    private static function asciiBase(): Sunburst
    {
        return Sunburst::new()
            ->setSize(60, 25)
            ->addSegment('a', 'Alpha', 30)
            ->addSegment('b', 'Beta', 20);
    }

    /** Strip truecolor/SGR sequences so rows can be measured/pinned by visible content (house regex). */
    private static function stripSgr(string $rendered): string
    {
        return (string) preg_replace('/\x1b\[[0-9;]*m/', '', $rendered);
    }

    private static function rowContaining(string $strippedRender, string $needle): string
    {
        foreach (explode("\n", $strippedRender) as $row) {
            if (str_contains($row, $needle)) {
                return $row;
            }
        }

        self::fail("No row containing '{$needle}' in stripped render.");
    }
}
