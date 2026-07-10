<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Tests\Bar;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Bar\Segment;
use SugarCraft\Sprinkles\Style;

final class SegmentTest extends TestCase
{
    // ─── Factories ──────────────────────────────────────────────────────

    public function testNewIsEmpty(): void
    {
        $s = Segment::new();
        $this->assertSame('', $s->text());
        $this->assertNull($s->style());
        $this->assertTrue($s->isEmpty());
        $this->assertSame(0, $s->width());
    }

    public function testOfTextOnly(): void
    {
        $s = Segment::of('hello');
        $this->assertSame('hello', $s->text());
        $this->assertNull($s->style());
        $this->assertFalse($s->isEmpty());
    }

    public function testOfWithStyle(): void
    {
        $style = Style::new()->bold();
        $s = Segment::of('hi', $style);
        $this->assertSame('hi', $s->text());
        $this->assertSame($style, $s->style());
    }

    public function testPublicReadonlyProps(): void
    {
        $s = Segment::of('x');
        $this->assertSame('x', $s->text);
        $this->assertNull($s->style);
    }

    // ─── Withers + immutability ─────────────────────────────────────────

    public function testWithTextReturnsNewInstance(): void
    {
        $a = Segment::of('a');
        $b = $a->withText('b');
        $this->assertNotSame($a, $b);
        $this->assertSame('a', $a->text());
        $this->assertSame('b', $b->text());
    }

    public function testWithStyleReturnsNewInstance(): void
    {
        $style = Style::new()->italic();
        $a = Segment::of('a');
        $b = $a->withStyle($style);
        $this->assertNotSame($a, $b);
        $this->assertNull($a->style());
        $this->assertSame($style, $b->style());
    }

    public function testWithStyleNullClears(): void
    {
        $s = Segment::of('a', Style::new()->bold())->withStyle(null);
        $this->assertNull($s->style());
    }

    // ─── width() ────────────────────────────────────────────────────────

    public function testWidthCountsVisibleCells(): void
    {
        $this->assertSame(5, Segment::of('abcde')->width());
    }

    public function testWidthIgnoresStyleSgr(): void
    {
        // A styled segment measures the visible text, not the SGR overhead.
        $s = Segment::of('ab', Style::new()->foreground(Color::hex('#ff0000')));
        $this->assertSame(2, $s->width());
    }

    // ─── render() ───────────────────────────────────────────────────────

    public function testRenderRawWhenNoStyle(): void
    {
        $this->assertSame('plain', Segment::of('plain')->render());
    }

    public function testRenderAppliesStyleBytes(): void
    {
        $s = Segment::of('ok', Style::new()->foreground(Color::hex('#6ee7b7')));
        $this->assertSame("\x1b[38;2;110;231;183mok\x1b[0m", $s->render());
    }

    public function testRenderEmptyStaysEmpty(): void
    {
        $this->assertSame('', Segment::new()->render());
    }
}
