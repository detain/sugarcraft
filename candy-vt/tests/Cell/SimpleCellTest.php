<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Cell;

/**
 * Tests for {@see SugarCraft\Vt\Cell} (root-level, renderer path cell).
 *
 * This is distinct from SugarCraft\Vt\Cell\Cell which is the full Cell with SGR.
 */
final class SimpleCellTest extends TestCase
{
    public function testEmptyCellHasDefaultValues(): void
    {
        $c = Cell::empty();

        $this->assertSame(' ', $c->char);
        $this->assertSame(7, $c->fg);
        $this->assertSame(0, $c->bg);
        $this->assertSame(0, $c->attrs);
    }

    public function testConstructorWithCustomValues(): void
    {
        $c = new Cell(char: 'X', fg: 1, bg: 2, attrs: Cell::ATTR_BOLD);

        $this->assertSame('X', $c->char);
        $this->assertSame(1, $c->fg);
        $this->assertSame(2, $c->bg);
        $this->assertSame(Cell::ATTR_BOLD, $c->attrs);
    }

    public function testConstructorDefaults(): void
    {
        $c = new Cell();

        $this->assertSame(' ', $c->char);
        $this->assertSame(7, $c->fg);
        $this->assertSame(0, $c->bg);
        $this->assertSame(0, $c->attrs);
    }

    public function testWithFgReturnsNewInstance(): void
    {
        $original = new Cell(char: 'A', fg: 7);
        $updated = $original->withFg(1);

        $this->assertSame(7, $original->fg);
        $this->assertSame(1, $updated->fg);
        $this->assertSame('A', $updated->char);
    }

    public function testWithBgReturnsNewInstance(): void
    {
        $original = new Cell(char: 'B', bg: 0);
        $updated = $original->withBg(3);

        $this->assertSame(0, $original->bg);
        $this->assertSame(3, $updated->bg);
        $this->assertSame('B', $updated->char);
    }

    public function testWithAttrsReturnsNewInstance(): void
    {
        $original = new Cell(attrs: 0);
        $updated = $original->withAttrs(Cell::ATTR_BOLD | Cell::ATTR_ITALIC);

        $this->assertSame(0, $original->attrs);
        $this->assertSame(Cell::ATTR_BOLD | Cell::ATTR_ITALIC, $updated->attrs);
    }

    public function testEqualsReturnsTrueForIdenticalCells(): void
    {
        $a = new Cell(char: 'X', fg: 1, bg: 2, attrs: Cell::ATTR_BOLD);
        $b = new Cell(char: 'X', fg: 1, bg: 2, attrs: Cell::ATTR_BOLD);

        $this->assertTrue($a->equals($b));
    }

    public function testEqualsReturnsFalseForDifferentChar(): void
    {
        $a = new Cell(char: 'A');
        $b = new Cell(char: 'B');

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsReturnsFalseForDifferentFg(): void
    {
        $a = new Cell(fg: 1);
        $b = new Cell(fg: 2);

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsReturnsFalseForDifferentBg(): void
    {
        $a = new Cell(bg: 1);
        $b = new Cell(bg: 2);

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsReturnsFalseForDifferentAttrs(): void
    {
        $a = new Cell(attrs: Cell::ATTR_BOLD);
        $b = new Cell(attrs: Cell::ATTR_ITALIC);

        $this->assertFalse($a->equals($b));
    }

    /**
     * Pins the public bit-flag values — renumbering a flag would silently
     * corrupt every consumer's combined attrs mask. Values travel through
     * a provider typed as plain `int` so static analysis cannot constant
     * -fold both sides into the same literal.
     *
     * @return list<array{int, int}>
     */
    public static function attributeConstants(): array
    {
        return [
            [1 << 0, Cell::ATTR_BOLD],
            [1 << 1, Cell::ATTR_ITALIC],
            [1 << 2, Cell::ATTR_UNDERLINE],
            [1 << 3, Cell::ATTR_INVERSE],
            [1 << 4, Cell::ATTR_STRIKETHROUGH],
        ];
    }

    /**
     * @dataProvider attributeConstants
     */
    public function testAttributeConstants(int $expected, int $actual): void
    {
        $this->assertSame($expected, $actual);
    }

    public function testMultipleAttributeFlags(): void
    {
        $c = new Cell(attrs: Cell::ATTR_BOLD | Cell::ATTR_UNDERLINE | Cell::ATTR_INVERSE);

        $this->assertTrue((bool) ($c->attrs & Cell::ATTR_BOLD));
        $this->assertTrue((bool) ($c->attrs & Cell::ATTR_UNDERLINE));
        $this->assertTrue((bool) ($c->attrs & Cell::ATTR_INVERSE));
        $this->assertFalse((bool) ($c->attrs & Cell::ATTR_ITALIC));
        $this->assertFalse((bool) ($c->attrs & Cell::ATTR_STRIKETHROUGH));
    }

    public function testWithFgPreservesOtherFields(): void
    {
        $c = new Cell(char: 'X', fg: 7, bg: 5, attrs: Cell::ATTR_BOLD);
        $updated = $c->withFg(3);

        $this->assertSame('X', $updated->char);
        $this->assertSame(3, $updated->fg);
        $this->assertSame(5, $updated->bg);
        $this->assertSame(Cell::ATTR_BOLD, $updated->attrs);
    }

    public function testWithBgPreservesOtherFields(): void
    {
        $c = new Cell(char: 'Y', fg: 2, bg: 0, attrs: Cell::ATTR_ITALIC);
        $updated = $c->withBg(6);

        $this->assertSame('Y', $updated->char);
        $this->assertSame(2, $updated->fg);
        $this->assertSame(6, $updated->bg);
        $this->assertSame(Cell::ATTR_ITALIC, $updated->attrs);
    }

    public function testWithAttrsPreservesOtherFields(): void
    {
        $c = new Cell(char: 'Z', fg: 4, bg: 3, attrs: 0);
        $updated = $c->withAttrs(Cell::ATTR_STRIKETHROUGH);

        $this->assertSame('Z', $updated->char);
        $this->assertSame(4, $updated->fg);
        $this->assertSame(3, $updated->bg);
        $this->assertSame(Cell::ATTR_STRIKETHROUGH, $updated->attrs);
    }
}
