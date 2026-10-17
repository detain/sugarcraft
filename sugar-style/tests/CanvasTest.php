<?php

declare(strict_types=1);

namespace SugarCraft\Style\Tests;

use SugarCraft\Style\Canvas;
use SugarCraft\Style\Layer;
use PHPUnit\Framework\TestCase;

final class CanvasTest extends TestCase
{
    public function testEmptyCanvasReturnsEmpty(): void
    {
        $this->assertSame('', Canvas::new()->render());
    }

    public function testSingleLayerRoundTrips(): void
    {
        $c = Canvas::new()->addLayer(Layer::new("hello\nworld"));
        $this->assertSame("hello\nworld", $c->render());
    }

    public function testOverlayPastesAtPosition(): void
    {
        $base    = Layer::new("....................\n....................\n....................");
        $popover = Layer::new("XX")->withX(5)->withY(1)->withZ(1);
        $out     = Canvas::new()->addLayer($base)->addLayer($popover)->render();
        $rows    = explode("\n", $out);

        $this->assertCount(3, $rows);
        $this->assertSame('....................', $rows[0]);
        // Row 1 has the overlay at col 5 — surrounding dots preserved.
        $this->assertStringContainsString('.....', $rows[1]);
        $this->assertStringContainsString('XX', $rows[1]);
        $this->assertStringContainsString('.............', $rows[1]); // 13 trailing dots
        $this->assertSame('....................', $rows[2]);
    }

    public function testZOrderRespected(): void
    {
        // Both layers paint at the same spot; higher z wins.
        $low  = Layer::new('LOW ')->withX(0)->withY(0)->withZ(0);
        $high = Layer::new('HI')->withX(1)->withY(0)->withZ(1);
        $out  = Canvas::new()->addLayer($low)->addLayer($high)->render();
        $this->assertStringContainsString('HI', $out);
    }

    public function testInsertionOrderBreaksZTie(): void
    {
        $a = Layer::new('AA')->withX(0)->withY(0);
        $b = Layer::new('BB')->withX(0)->withY(0);
        $out = Canvas::new()->addLayer($a)->addLayer($b)->render();
        // 'BB' was added second with same z → wins.
        $this->assertStringStartsWith('BB', $out);
    }

    public function testOverlayPreservesAnsiOnBothSides(): void
    {
        $base    = Layer::new("\x1b[31mAAAAAAAAAA\x1b[0m"); // 10 red A's
        $popover = Layer::new('XYZ')->withX(3)->withY(0)->withZ(1);
        $out     = Canvas::new()->addLayer($base)->addLayer($popover)->render();

        // ANSI start of base preserved.
        $this->assertStringContainsString("\x1b[31m", $out);
        // Overlay text present.
        $this->assertStringContainsString('XYZ', $out);
        // First 3 A's preserved on the left.
        $this->assertStringContainsString('AAA', $out);
    }

    public function testLayerImmutableSetters(): void
    {
        $a = Layer::new('hi');
        $b = $a->withX(5)->withY(2)->withZ(7);
        $this->assertSame(0, $a->x);
        $this->assertSame(5, $b->x);
        $this->assertSame(2, $b->y);
        $this->assertSame(7, $b->z);
        $this->assertSame('hi', $b->content);
    }

    public function testWidthAndHeightOfLayer(): void
    {
        $l = Layer::new("hello\nworld!\nhi");
        $this->assertSame(6, $l->width());
        $this->assertSame(3, $l->height());
    }

    public function testCanvasGrowsForOutOfBoundsLayer(): void
    {
        $base = Layer::new("aaa");
        $tag  = Layer::new('TAG')->withX(10)->withY(0);
        $out  = Canvas::new()->addLayer($base)->addLayer($tag)->render();
        // Base extended right with spaces, then TAG at column 10.
        $this->assertSame('aaa       TAG', $out);
    }

    public function testCanvasGrowsBelow(): void
    {
        $base = Layer::new('row0');
        $down = Layer::new('row3')->withX(0)->withY(3);
        $out  = Canvas::new()->addLayer($base)->addLayer($down)->render();
        $rows = explode("\n", $out);
        $this->assertCount(4, $rows);
        $this->assertSame('row0', $rows[0]);
        $this->assertSame('',     $rows[1]);
        $this->assertSame('',     $rows[2]);
        $this->assertSame('row3', $rows[3]);
    }

    public function testIsImmutable(): void
    {
        $c1 = Canvas::new();
        $c2 = $c1->addLayer(Layer::new('x'));
        $this->assertSame('',  $c1->render());
        $this->assertSame('x', $c2->render());
    }
}
