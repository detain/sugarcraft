<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Cursor;

/**
 * Tests for {@see SugarCraft\Vt\Cursor} (root-level, renderer path cursor).
 *
 * This is distinct from SugarCraft\Vt\Cursor\Cursor which has save/restore.
 */
final class SimpleCursorTest extends TestCase
{
    public function testDefaultConstructorValues(): void
    {
        $c = new Cursor();

        $this->assertSame(0, $c->row);
        $this->assertSame(0, $c->col);
        $this->assertSame(0, $c->shape);
        $this->assertTrue($c->visible);
    }

    public function testConstructorWithCustomValues(): void
    {
        $c = new Cursor(row: 10, col: 20, shape: 2, visible: false);

        $this->assertSame(10, $c->row);
        $this->assertSame(20, $c->col);
        $this->assertSame(2, $c->shape);
        $this->assertFalse($c->visible);
    }

    public function testAtSetsRowAndCol(): void
    {
        $c = (new Cursor())->at(5, 15);

        $this->assertSame(5, $c->row);
        $this->assertSame(15, $c->col);
    }

    public function testAtReturnsNewInstance(): void
    {
        $original = new Cursor(row: 1, col: 1);
        $updated = $original->at(5, 10);

        $this->assertSame(1, $original->row);
        $this->assertSame(1, $original->col);
        $this->assertSame(5, $updated->row);
        $this->assertSame(10, $updated->col);
    }

    public function testWithShapeReturnsNewInstance(): void
    {
        $original = new Cursor(shape: 0);
        $updated = $original->withShape(2);

        $this->assertSame(0, $original->shape);
        $this->assertSame(2, $updated->shape);
    }

    public function testWithShapePreservesOtherFields(): void
    {
        $c = new Cursor(row: 5, col: 10, shape: 1, visible: false);
        $updated = $c->withShape(2);

        $this->assertSame(5, $updated->row);
        $this->assertSame(10, $updated->col);
        $this->assertSame(2, $updated->shape);
        $this->assertFalse($updated->visible);
    }

    public function testHiddenSetsVisibleFalse(): void
    {
        $c = (new Cursor())->hidden();

        $this->assertFalse($c->visible);
    }

    public function testHiddenReturnsNewInstance(): void
    {
        $original = new Cursor(visible: true);
        $updated = $original->hidden();

        $this->assertTrue($original->visible);
        $this->assertFalse($updated->visible);
    }

    public function testShownSetsVisibleTrue(): void
    {
        $c = (new Cursor(visible: false))->shown();

        $this->assertTrue($c->visible);
    }

    public function testShownReturnsNewInstance(): void
    {
        $original = new Cursor(visible: false);
        $updated = $original->shown();

        $this->assertFalse($original->visible);
        $this->assertTrue($updated->visible);
    }

    public function testShownPreservesOtherFields(): void
    {
        $c = new Cursor(row: 3, col: 7, shape: 1, visible: false);
        $updated = $c->shown();

        $this->assertSame(3, $updated->row);
        $this->assertSame(7, $updated->col);
        $this->assertSame(1, $updated->shape);
        $this->assertTrue($updated->visible);
    }

    public function testEqualsReturnsTrueForIdenticalCursors(): void
    {
        $a = new Cursor(row: 5, col: 10, shape: 2, visible: true);
        $b = new Cursor(row: 5, col: 10, shape: 2, visible: true);

        $this->assertTrue($a->equals($b));
    }

    public function testEqualsReturnsFalseForDifferentRow(): void
    {
        $a = new Cursor(row: 1);
        $b = new Cursor(row: 2);

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsReturnsFalseForDifferentCol(): void
    {
        $a = new Cursor(col: 1);
        $b = new Cursor(col: 2);

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsReturnsFalseForDifferentShape(): void
    {
        $a = new Cursor(shape: 0);
        $b = new Cursor(shape: 1);

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsReturnsFalseForDifferentVisible(): void
    {
        $a = new Cursor(visible: true);
        $b = new Cursor(visible: false);

        $this->assertFalse($a->equals($b));
    }

    public function testAtChainingWithOtherMethods(): void
    {
        $c = (new Cursor())->at(5, 5)->withShape(2)->hidden();

        $this->assertSame(5, $c->row);
        $this->assertSame(5, $c->col);
        $this->assertSame(2, $c->shape);
        $this->assertFalse($c->visible);
    }

    public function testCursorShapeConstants(): void
    {
        $c = new Cursor();
        // Just verify shape is an int
        $this->assertIsInt($c->shape);
    }
}
