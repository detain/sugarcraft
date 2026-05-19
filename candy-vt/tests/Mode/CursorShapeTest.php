<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Mode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\CursorShape;
use SugarCraft\Vt\Handler\ScreenHandler;
use SugarCraft\Vt\Mode\Mode;
use SugarCraft\Vt\Parser\Parser;

/**
 * Tests for DECSCUSR (DEC Set Cursor Style) — CSI Ps SP q.
 */
final class CursorShapeTest extends TestCase
{
    private function handler(int $cols = 20, int $rows = 5): ScreenHandler
    {
        return new ScreenHandler(new Buffer($cols, $rows));
    }

    private function feed(string $bytes, int $cols = 20, int $rows = 5): ScreenHandler
    {
        $h = $this->handler($cols, $rows);
        (new Parser($h))->feed($bytes);
        return $h;
    }

    // ─── CursorShape enum ──────────────────────────────────────────────────

    public function testCursorShapeDefaultsToZero(): void
    {
        $m = new Mode();
        $this->assertSame(0, $m->cursorShape);
    }

    public function testWithCursorShapeReturnsNewInstance(): void
    {
        $m = new Mode();
        $m2 = $m->withCursorShape(2);
        $this->assertSame(0, $m->cursorShape);
        $this->assertSame(2, $m2->cursorShape);
    }

    public function testCursorShapeIncludedInEquals(): void
    {
        $a = (new Mode())->withCursorShape(3);
        $b = new Mode();
        $this->assertFalse($a->equals($b));
        $this->assertTrue($a->equals($a));
        $this->assertTrue($b->equals($b));
    }

    // ─── CSI Ps SP q ─────────────────────────────────────────────────────

    public function testCsiZeroSpqSetsBlinkingBlock(): void
    {
        $h = $this->handler();
        $this->assertSame(0, $h->cursor->shape);
        (new Parser($h))->feed("\x1b[0\x20q");
        $this->assertSame(0, $h->cursor->shape);
        $this->assertSame(0, $h->mode->cursorShape);
    }

    public function testCsiTwoSpqSetsSteadyBlock(): void
    {
        $h = $this->handler();
        (new Parser($h))->feed("\x1b[2\x20q");
        $this->assertSame(2, $h->cursor->shape);
        $this->assertSame(2, $h->mode->cursorShape);
    }

    public function testCsiThreeSpqSetsBlinkingUnderline(): void
    {
        $h = $this->handler();
        (new Parser($h))->feed("\x1b[3\x20q");
        $this->assertSame(3, $h->cursor->shape);
        $this->assertSame(3, $h->mode->cursorShape);
    }

    public function testCsiFourSpqSetsSteadyUnderline(): void
    {
        $h = $this->handler();
        (new Parser($h))->feed("\x1b[4\x20q");
        $this->assertSame(4, $h->cursor->shape);
        $this->assertSame(4, $h->mode->cursorShape);
    }

    public function testCsiFiveSpqSetsBlinkingBar(): void
    {
        $h = $this->handler();
        (new Parser($h))->feed("\x1b[5\x20q");
        $this->assertSame(5, $h->cursor->shape);
        $this->assertSame(5, $h->mode->cursorShape);
    }

    public function testCsiSixSpqSetsSteadyBar(): void
    {
        $h = $this->handler();
        (new Parser($h))->feed("\x1b[6\x20q");
        $this->assertSame(6, $h->cursor->shape);
        $this->assertSame(6, $h->mode->cursorShape);
    }

    // ─── CursorShape enum values ───────────────────────────────────────────

    public function testCursorShapeEnumFromIntBlinkingBlock(): void
    {
        $this->assertSame(CursorShape::BlinkingBlock, CursorShape::fromInt(0));
        $this->assertSame(CursorShape::BlinkingBlock, CursorShape::fromInt(1));
    }

    public function testCursorShapeEnumFromIntSteadyBlock(): void
    {
        $this->assertSame(CursorShape::SteadyBlock, CursorShape::fromInt(2));
    }

    public function testCursorShapeEnumFromIntBlinkingUnderline(): void
    {
        $this->assertSame(CursorShape::BlinkingUnderline, CursorShape::fromInt(3));
    }

    public function testCursorShapeEnumFromIntSteadyUnderline(): void
    {
        $this->assertSame(CursorShape::SteadyUnderline, CursorShape::fromInt(4));
    }

    public function testCursorShapeEnumFromIntBlinkingBar(): void
    {
        $this->assertSame(CursorShape::BlinkingBar, CursorShape::fromInt(5));
    }

    public function testCursorShapeEnumFromIntSteadyBar(): void
    {
        $this->assertSame(CursorShape::SteadyBar, CursorShape::fromInt(6));
    }

    public function testCursorShapeEnumFromIntUnknownDefaultsToBlinkingBlock(): void
    {
        $this->assertSame(CursorShape::BlinkingBlock, CursorShape::fromInt(99));
    }

    public function testCursorShapeEnumToInt(): void
    {
        $this->assertSame(0, CursorShape::BlinkingBlock->toInt());
        $this->assertSame(2, CursorShape::SteadyBlock->toInt());
        $this->assertSame(3, CursorShape::BlinkingUnderline->toInt());
        $this->assertSame(4, CursorShape::SteadyUnderline->toInt());
        $this->assertSame(5, CursorShape::BlinkingBar->toInt());
        $this->assertSame(6, CursorShape::SteadyBar->toInt());
    }
}
