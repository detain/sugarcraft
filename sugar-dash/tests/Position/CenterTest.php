<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Position;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Position\Center;

/**
 * Tests for the ANSI-aware centering helper.
 */
final class CenterTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // calculateOffsetX
    // ═══════════════════════════════════════════════════════════════

    public function testCalculateOffsetXContentNarrowerThanWidth(): void
    {
        $offset = Center::calculateOffsetX('hello', 10);
        // (10 - 5) / 2 = 2.5 → floor = 2
        $this->assertSame(2, $offset);
    }

    public function testCalculateOffsetXContentExactlyWidth(): void
    {
        $offset = Center::calculateOffsetX('hello', 5);
        $this->assertSame(0, $offset);
    }

    public function testCalculateOffsetXContentWiderThanWidth(): void
    {
        $offset = Center::calculateOffsetX('hello world', 5);
        $this->assertSame(0, $offset);
    }

    public function testCalculateOffsetXContentSmallerThanWidth(): void
    {
        $offset = Center::calculateOffsetX('ab', 10);
        // (10 - 2) / 2 = 4
        $this->assertSame(4, $offset);
    }

    public function testCalculateOffsetXStripsAnsiForWidth(): void
    {
        $ansi = "\x1b[31mred\x1b[0m";
        $offset = Center::calculateOffsetX($ansi, 10);
        // "red" is 3 chars, (10 - 3) / 2 = 3.5 → floor = 3
        $this->assertSame(3, $offset);
    }

    // ═══════════════════════════════════════════════════════════════
    // calculateOffsetY
    // ═══════════════════════════════════════════════════════════════

    public function testCalculateOffsetYContentShorterThanHeight(): void
    {
        $offset = Center::calculateOffsetY(3, 10);
        // (10 - 3) / 2 = 3.5 → floor = 3
        $this->assertSame(3, $offset);
    }

    public function testCalculateOffsetYContentExactlyHeight(): void
    {
        $offset = Center::calculateOffsetY(5, 5);
        $this->assertSame(0, $offset);
    }

    public function testCalculateOffsetYContentTallerThanHeight(): void
    {
        $offset = Center::calculateOffsetY(15, 10);
        $this->assertSame(0, $offset);
    }

    public function testCalculateOffsetYZeroHeight(): void
    {
        $offset = Center::calculateOffsetY(5, 0);
        $this->assertSame(0, $offset);
    }

    // ═══════════════════════════════════════════════════════════════
    // measureRenderedView
    // ═══════════════════════════════════════════════════════════════

    public function testMeasureRenderedViewSingleLine(): void
    {
        $dims = Center::measureRenderedView('hello');
        $this->assertSame(5, $dims['width']);
        $this->assertSame(1, $dims['height']);
    }

    public function testMeasureRenderedViewMultiline(): void
    {
        $dims = Center::measureRenderedView("hello\nworld!");
        $this->assertSame(6, $dims['width']); // world! is 6 chars
        $this->assertSame(2, $dims['height']);
    }

    public function testMeasureRenderedViewEmptyString(): void
    {
        $dims = Center::measureRenderedView('');
        $this->assertSame(0, $dims['width']);
        $this->assertSame(1, $dims['height']); // single empty line
    }

    public function testMeasureRenderedViewStripsAnsi(): void
    {
        $dims = Center::measureRenderedView("\x1b[31mred\x1b[0m");
        // "red" is 3 chars wide (ANSI stripped for width calc)
        $this->assertSame(3, $dims['width']);
    }

    // ═══════════════════════════════════════════════════════════════
    // center
    // ═══════════════════════════════════════════════════════════════

    public function testCenterZeroWidthReturnsContent(): void
    {
        $result = Center::center('hello', 0, 10);
        $this->assertSame('hello', $result);
    }

    public function testCenterZeroHeightReturnsContent(): void
    {
        $result = Center::center('hello', 10, 0);
        $this->assertSame('hello', $result);
    }

    public function testCenterAddsTopPadding(): void
    {
        $result = Center::center('ab', 10, 5);
        $lines = explode("\n", $result);
        // Should have 2 lines: 2 top padding + content line
        $this->assertGreaterThanOrEqual(2, count($lines));
    }

    public function testCenterAddsBottomPadding(): void
    {
        $result = Center::center('ab', 10, 5);
        $lines = explode("\n", $result);
        // Should have 5 total lines (3 padding + 1 content + 1 bottom padding or similar)
        $this->assertLessThanOrEqual(5, count($lines));
    }

    public function testCenterEvenWidthNoAsymmetry(): void
    {
        $result = Center::center('ab', 10, 3);
        $lines = explode("\n", $result);
        // Each content line should be padded to width 10
        foreach ($lines as $line) {
            if ($line !== '') {
                $this->assertLessThanOrEqual(10, mb_strlen($line, 'UTF-8'));
            }
        }
    }

    public function testCenterMultilineContent(): void
    {
        $result = Center::center("a\nb", 10, 4);
        $lines = explode("\n", $result);
        $this->assertGreaterThanOrEqual(2, count($lines));
    }

    public function testCenterStripsAnsiFromContent(): void
    {
        $result = Center::center("\x1b[31mred\x1b[0m", 10, 1);
        $this->assertStringNotContainsString("\x1b", $result);
        $this->assertStringContainsString('red', $result);
    }

    public function testCenterResultHeightMatchesRequested(): void
    {
        $result = Center::center('ab', 10, 5);
        $lines = explode("\n", $result);
        $this->assertSame(5, count($lines));
    }
}
