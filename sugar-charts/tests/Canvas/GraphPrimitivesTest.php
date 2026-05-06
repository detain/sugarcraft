<?php

declare(strict_types=1);

namespace CandyCore\Charts\Tests\Canvas;

use CandyCore\Charts\Canvas\Canvas;
use CandyCore\Charts\Canvas\Graph;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the new Graph primitives added in audit #15:
 * Braille runes, drawColumns / drawRows, drawCandlestick, line/circle
 * point samplers with limits, Vertical/HorizontalLine direction
 * helpers.
 */
final class GraphPrimitivesTest extends TestCase
{
    public function testDrawBrailleRuneEmpty(): void
    {
        $c = new Canvas(2, 1);
        Graph::drawBrailleRune($c, 0, 0, []);
        // U+2800 is the empty Braille pattern.
        $this->assertStringContainsString("\u{2800}", $c->view());
    }

    public function testDrawBrailleRuneWithDot(): void
    {
        $c = new Canvas(2, 1);
        // Single dot at column 0, row 0 → bit 0x01 → U+2801.
        Graph::drawBrailleRune($c, 0, 0, [[0, 0]]);
        $this->assertStringContainsString("\u{2801}", $c->view());
    }

    public function testDrawBraillePatternsPlacesGlyphs(): void
    {
        $c = new Canvas(3, 1);
        Graph::drawBraillePatterns($c, 0, 0, [
            [[0, 0]],
            [[1, 1]],
            [[1, 3]],
        ]);
        // Three Braille glyphs in a row.
        $this->assertSame(3, mb_strlen($c->view(), 'UTF-8'));
    }

    public function testDrawColumns(): void
    {
        $c = new Canvas(5, 5);
        Graph::drawColumns($c, 0, 4, [1, 3, 5]);
        $rows = explode("\n", $c->view());
        // Top row has only the tallest column (height 5 → rows 0-4 filled).
        $this->assertStringContainsString('█', $rows[0]);
        // Bottom row has all three columns filled.
        $this->assertStringContainsString('███', $rows[4]);
    }

    public function testDrawRows(): void
    {
        $c = new Canvas(5, 3);
        Graph::drawRows($c, 0, 0, [3, 5, 1]);
        $rows = explode("\n", $c->view());
        $this->assertSame('███',   rtrim($rows[0]));
        $this->assertSame('█████', rtrim($rows[1]));
        $this->assertSame('█',     rtrim($rows[2]));
    }

    public function testDrawCandlestickRendersWickAndBody(): void
    {
        $c = new Canvas(3, 6);
        // wick high=0 low=5; body open=2 close=4
        Graph::drawCandlestick($c, 1, high: 0, open: 2, close: 4, low: 5);
        $rows = explode("\n", $c->view());
        // Every row in 0..5 should have '│' at column 1.
        for ($y = 0; $y <= 5; $y++) {
            $this->assertSame('│', mb_substr($rows[$y], 1, 1, 'UTF-8'));
        }
    }

    public function testGetCirclePointsWithLimit(): void
    {
        $pts = Graph::getCirclePointsWithLimit(0, 0, 5, limit: 4, samples: 32);
        $this->assertCount(4, $pts);
    }

    public function testGetLinePointsWithLimit(): void
    {
        $pts = Graph::getLinePointsWithLimit(0, 0, 10, 0, limit: 5);
        $this->assertCount(5, $pts);
        $this->assertSame([0, 0], $pts[0]);
        $this->assertSame([4, 0], $pts[4]);
    }

    public function testDrawVerticalLineUpDownAreEquivalent(): void
    {
        $a = new Canvas(2, 4);
        $b = new Canvas(2, 4);
        Graph::drawVerticalLineUp($a,   0, 1, 3);
        Graph::drawVerticalLineDown($b, 0, 1, 3);
        $this->assertSame($a->view(), $b->view());
    }

    public function testDrawHorizontalLineLeftRightAreEquivalent(): void
    {
        $a = new Canvas(5, 2);
        $b = new Canvas(5, 2);
        Graph::drawHorizontalLineLeft($a,  0, 1, 3);
        Graph::drawHorizontalLineRight($b, 0, 1, 3);
        $this->assertSame($a->view(), $b->view());
    }
}
