<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Tests;

use SugarCraft\Sprinkles\Layer;
use PHPUnit\Framework\TestCase;

final class LayerTest extends TestCase
{
    public function testNewCreatesLayerWithDefaultCoordinates(): void
    {
        $l = Layer::new('hello');
        $this->assertSame('hello', $l->content);
        $this->assertSame(0, $l->x);
        $this->assertSame(0, $l->y);
        $this->assertSame(0, $l->z);
    }

    public function testConstructorSetsAllProperties(): void
    {
        $l = new Layer('content', 10, 20, 3);
        $this->assertSame('content', $l->content);
        $this->assertSame(10, $l->x);
        $this->assertSame(20, $l->y);
        $this->assertSame(3, $l->z);
    }

    public function testWithXReturnsNewInstance(): void
    {
        $a = Layer::new('hello');
        $b = $a->withX(5);
        $this->assertNotSame($a, $b);
        $this->assertSame(5, $b->x);
        $this->assertSame(0, $b->y);
        $this->assertSame(0, $b->z);
        $this->assertSame('hello', $b->content);
        // Original unchanged.
        $this->assertSame(0, $a->x);
    }

    public function testWithYReturnsNewInstance(): void
    {
        $a = Layer::new('hello');
        $b = $a->withY(7);
        $this->assertNotSame($a, $b);
        $this->assertSame(0, $b->x);
        $this->assertSame(7, $b->y);
        $this->assertSame(0, $b->z);
        // Original unchanged.
        $this->assertSame(0, $a->y);
    }

    public function testWithZReturnsNewInstance(): void
    {
        $a = Layer::new('hello');
        $b = $a->withZ(2);
        $this->assertNotSame($a, $b);
        $this->assertSame(0, $b->x);
        $this->assertSame(0, $b->y);
        $this->assertSame(2, $b->z);
        // Original unchanged.
        $this->assertSame(0, $a->z);
    }

    public function testLinesSplitsMultilineContent(): void
    {
        $l = Layer::new("a\nb\nc");
        $this->assertSame(['a', 'b', 'c'], $l->lines());
    }

    public function testLinesReturnsSingleEmptyLineForEmptyContent(): void
    {
        $l = Layer::new('');
        $this->assertSame([''], $l->lines());
    }

    public function testLinesSingleLineNoTrailingNewline(): void
    {
        $l = Layer::new('hello');
        $this->assertSame(['hello'], $l->lines());
    }

    public function testWidthSingleLine(): void
    {
        $l = Layer::new('hello');
        $this->assertSame(5, $l->width());
    }

    public function testWidthMultilineReturnsMax(): void
    {
        $l = Layer::new("a\nabc\nab");
        $this->assertSame(3, $l->width());
    }

    public function testWidthEmptyContent(): void
    {
        $l = Layer::new('');
        $this->assertSame(0, $l->width());
    }

    public function testWidthTrimsTrailingNewline(): void
    {
        $l = Layer::new("hello\n");
        $this->assertSame(5, $l->width());
    }

    public function testHeightReturnsLineCount(): void
    {
        $l = Layer::new("a\nb\nc");
        $this->assertSame(3, $l->height());
    }

    public function testHeightSingleLine(): void
    {
        $l = Layer::new('hello');
        $this->assertSame(1, $l->height());
    }

    public function testHeightEmptyContent(): void
    {
        $l = Layer::new('');
        $this->assertSame(1, $l->height());
    }

    public function testImmutabilityWithX(): void
    {
        $a = Layer::new('hello');
        $b = $a->withX(10);
        $this->assertNotSame($a, $b);
        $this->assertSame(0, $a->x);
        $this->assertSame(10, $b->x);
    }

    public function testImmutabilityWithY(): void
    {
        $a = Layer::new('hello');
        $b = $a->withY(5);
        $this->assertNotSame($a, $b);
        $this->assertSame(0, $a->y);
        $this->assertSame(5, $b->y);
    }

    public function testImmutabilityWithZ(): void
    {
        $a = Layer::new('hello');
        $b = $a->withZ(1);
        $this->assertNotSame($a, $b);
        $this->assertSame(0, $a->z);
        $this->assertSame(1, $b->z);
    }

    public function testChainedWithers(): void
    {
        $l = Layer::new('hi')->withX(3)->withY(4)->withZ(2);
        $this->assertSame(3, $l->x);
        $this->assertSame(4, $l->y);
        $this->assertSame(2, $l->z);
        $this->assertSame('hi', $l->content);
    }
}
