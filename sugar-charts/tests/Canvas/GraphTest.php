<?php

declare(strict_types=1);

namespace CandyCore\Charts\Tests\Canvas;

use CandyCore\Charts\Canvas\Canvas;
use CandyCore\Charts\Canvas\Graph;
use PHPUnit\Framework\TestCase;

final class GraphTest extends TestCase
{
    public function testDrawHLine(): void
    {
        $c = new Canvas(5, 3);
        Graph::drawHLine($c, 1, 0, 4);
        $this->assertSame('─', $c->getCell(0, 1)->rune);
        $this->assertSame('─', $c->getCell(4, 1)->rune);
        $this->assertSame(' ', $c->getCell(0, 0)->rune);
    }

    public function testDrawHLineSwapsBounds(): void
    {
        $c = new Canvas(5, 1);
        Graph::drawHLine($c, 0, 4, 0);
        $this->assertSame('─', $c->getCell(0, 0)->rune);
        $this->assertSame('─', $c->getCell(4, 0)->rune);
    }

    public function testDrawVLine(): void
    {
        $c = new Canvas(3, 5);
        Graph::drawVLine($c, 1, 0, 4);
        $this->assertSame('│', $c->getCell(1, 0)->rune);
        $this->assertSame('│', $c->getCell(1, 4)->rune);
    }

    public function testDrawXYAxis(): void
    {
        $c = new Canvas(8, 6);
        Graph::drawXYAxis($c, 1, 4, 5, 3);
        // Corner.
        $this->assertSame('└', $c->getCell(1, 4)->rune);
        // X axis runs to the right of the corner.
        $this->assertSame('─', $c->getCell(2, 4)->rune);
        $this->assertSame('─', $c->getCell(6, 4)->rune);
        // Y axis runs above the corner.
        $this->assertSame('│', $c->getCell(1, 1)->rune);
        $this->assertSame('│', $c->getCell(1, 3)->rune);
    }

    public function testDrawXYAxisLabel(): void
    {
        $c = new Canvas(20, 8);
        Graph::drawXYAxis($c, 4, 6, 12, 4);
        Graph::drawXYAxisLabel($c, 4, 6, 12, 4, ['0', '50', '100'], ['10', '5', '0']);
        // X-axis labels appear below the axis at row 7.
        $this->assertSame('0', $c->getCell(4, 7)->rune);
        // First Y label drawn left of the axis.
        $this->assertSame('1', $c->getCell(1, 2)->rune);
        $this->assertSame('0', $c->getCell(2, 2)->rune);
    }

    public function testDrawString(): void
    {
        $c = new Canvas(10, 1);
        Graph::drawString($c, 0, 0, 'hello');
        $this->assertSame('h', $c->getCell(0, 0)->rune);
        $this->assertSame('o', $c->getCell(4, 0)->rune);
    }

    public function testDrawLineDiagonal(): void
    {
        $c = new Canvas(5, 5);
        Graph::drawLine($c, 0, 0, 4, 4);
        // Bresenham fills the diagonal cells.
        $this->assertSame('·', $c->getCell(0, 0)->rune);
        $this->assertSame('·', $c->getCell(2, 2)->rune);
        $this->assertSame('·', $c->getCell(4, 4)->rune);
    }

    public function testFillRect(): void
    {
        $c = new Canvas(4, 3);
        Graph::fillRect($c, 0, 0, 2, 1, '#');
        $this->assertSame('#', $c->getCell(0, 0)->rune);
        $this->assertSame('#', $c->getCell(2, 0)->rune);
        $this->assertSame('#', $c->getCell(2, 1)->rune);
        $this->assertSame(' ', $c->getCell(2, 2)->rune);
    }

    public function testDrawColumn(): void
    {
        $c = new Canvas(2, 5);
        Graph::drawColumn($c, 0, 1, 3);
        $this->assertSame('█', $c->getCell(0, 1)->rune);
        $this->assertSame('█', $c->getCell(0, 3)->rune);
        $this->assertSame(' ', $c->getCell(0, 0)->rune);
    }

    public function testGetCirclePointsCount(): void
    {
        $pts = Graph::getCirclePoints(0, 0, 3, 16);
        $this->assertCount(16, $pts);
        // First point should be approximately (3, 0) for theta=0
        $this->assertSame(3, $pts[0][0]);
        $this->assertSame(0, $pts[0][1]);
    }

    public function testDrawLinePointsConnects(): void
    {
        $c = new Canvas(10, 5);
        Graph::drawLinePoints($c, [[0, 4], [3, 1], [6, 3], [9, 0]]);
        // Endpoints overwrite to the connector glyph used between them
        // (here the negative-slope diagonal '╱'); we only assert that
        // they're rendered as line glyphs, not blank.
        $this->assertNotSame(' ', $c->getCell(0, 4)->rune);
        $this->assertNotSame(' ', $c->getCell(9, 0)->rune);
        // A middle vertex stays on a connector glyph too.
        $this->assertNotSame(' ', $c->getCell(3, 1)->rune);
    }

    public function testOutOfBoundsClamps(): void
    {
        $c = new Canvas(3, 3);
        // Should silently truncate, not throw.
        Graph::drawHLine($c, 5, 0, 10);
        Graph::drawVLine($c, 5, 0, 10);
        $this->assertTrue(true); // didn't throw
    }
}
