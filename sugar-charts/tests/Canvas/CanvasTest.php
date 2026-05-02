<?php

declare(strict_types=1);

namespace CandyCore\Charts\Tests\Canvas;

use CandyCore\Charts\Canvas\Canvas;
use CandyCore\Sprinkles\Style;
use CandyCore\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class CanvasTest extends TestCase
{
    public function testEmptyCanvasRendersBlankRows(): void
    {
        $c = new Canvas(4, 2);
        // rtrim trims trailing spaces; empty rows collapse to ''.
        $this->assertSame("\n", $c->view());
    }

    public function testZeroDimensionsRender(): void
    {
        $this->assertSame('', (new Canvas(0, 0))->view());
    }

    public function testSetAndGetCell(): void
    {
        $c = new Canvas(3, 2);
        $c->setCell(1, 0, 'X');
        $this->assertSame('X', $c->getCell(1, 0)->rune);
        $this->assertSame(' ', $c->getCell(0, 0)->rune);
    }

    public function testOutOfBoundsSetIsNoOp(): void
    {
        $c = new Canvas(2, 2);
        $c->setCell(5, 5, 'X');
        $c->setCell(-1, 0, 'X');
        $this->assertSame(' ', $c->getCell(0, 0)->rune);
    }

    public function testRenderPlainContent(): void
    {
        $c = new Canvas(3, 2);
        $c->setCell(0, 0, 'a');
        $c->setCell(1, 0, 'b');
        $c->setCell(2, 0, 'c');
        $c->setCell(0, 1, 'd');
        $this->assertSame("abc\nd", $c->view());
    }

    public function testStyledCellWrapsWithSgr(): void
    {
        $c = new Canvas(1, 1);
        $c->setCell(0, 0, 'X', Style::new()->foreground(Color::hex('#ff0000')));
        $this->assertStringContainsString("\x1b[", $c->view());
        $this->assertStringContainsString('X', $c->view());
    }

    public function testClearResetsCells(): void
    {
        $c = new Canvas(2, 1);
        $c->setCell(0, 0, 'X');
        $c->clear();
        $this->assertSame(' ', $c->getCell(0, 0)->rune);
    }

    public function testNegativeDimensionsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Canvas(-1, 1);
    }
}
