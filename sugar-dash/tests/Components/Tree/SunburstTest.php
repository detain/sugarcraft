<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Tree;

use PHPUnit\Framework\TestCase;
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

    /** C3 CASE 1: a CJK center label fills the 3-cell window with whole codepoints (was: only '日', mid-codepoint bytes). */
    public function testCjkCenterLabelRendersCodepointWindow(): void
    {
        $rendered = self::asciiBase()->withCenterLabel('日本語テスト')->render();

        $this->assertTrue(mb_check_encoding($rendered, 'UTF-8'));
        $stripped = self::stripSgr($rendered);
        $this->assertStringContainsString('日本語', $stripped);
        // Window is exactly 3 cells: the 4th+ codepoints do not render.
        $this->assertStringNotContainsString('テ', $stripped);
        $this->assertSame(3, preg_match_all('/[日本語]/u', $stripped));
    }

    /** C3 CASE 3: 'X'-offset label used to slice through a codepoint (invalid UTF-8); now whole runes. */
    public function testStraddlingCenterLabelStaysValidUtf8(): void
    {
        $rendered = self::asciiBase()->withCenterLabel('X日本語テスト')->render();

        $this->assertTrue(mb_check_encoding($rendered, 'UTF-8'));
        $stripped = self::stripSgr($rendered);
        $this->assertStringContainsString('X日本', $stripped);
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
        // Budget: legendX=2, width=60 → 60-3-2 = 55 codepoints; '▪ ' + 59 a's (61) → hard cut to '▪ ' + 53 a's.
        $this->assertStringContainsString('▪ ' . str_repeat('a', 53), $stripped);
        $this->assertStringNotContainsString('▪ a' . str_repeat('a', 53), $stripped);
        // Budget exhausted by the truncated entry — the degenerate guard stops the loop.
        $this->assertSame(1, substr_count($stripped, '▪'));
    }

    /** C3: legend right border aligns by VISIBLE codepoints — embedded SGR must not count (CASE 1c). */
    public function testLegendPaddingAlignsByVisibleWidth(): void
    {
        // ASCII fitting legend.
        $ascii = self::stripSgr(self::asciiBase()->render());
        $asciiLegendRow = self::rowContaining($ascii, '▪ Alpha');
        $this->assertSame(60, mb_strlen($asciiLegendRow));
        $this->assertStringStartsWith('│', $asciiLegendRow);
        $this->assertStringEndsWith('│', $asciiLegendRow);
        $this->assertStringContainsString('▪ Alpha  ▪ Beta', $asciiLegendRow);

        // CJK + truecolor legend: padded by codepoint count (double-width columns are a documented limit, Parked (c)).
        $cjk = Sunburst::new()->setSize(60, 25)
            ->addSegment('a', '日本語', 50)
            ->addSegment('b', 'テスト', 50)
            ->render();
        $this->assertStringContainsString("\x1b[38;2;", $cjk);
        $cjkLegendRow = self::rowContaining(self::stripSgr($cjk), '▪ 日本語');
        $this->assertSame(60, mb_strlen($cjkLegendRow));
        $this->assertStringEndsWith('│', $cjkLegendRow);
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
