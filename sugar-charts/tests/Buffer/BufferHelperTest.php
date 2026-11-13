<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Buffer;

use PHPUnit\Framework\TestCase;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\CellBuffer\Style as BufferStyle;
use SugarCraft\Charts\Buffer\BufferHelper;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Style as SugarStyle;

/**
 * @covers \SugarCraft\Charts\Buffer\BufferHelper
 */
final class BufferHelperTest extends TestCase
{
    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::graphemeWidth
     */
    public function testGraphemeWidthEmptyString(): void
    {
        $this->assertSame(0, BufferHelper::graphemeWidth(''));
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::graphemeWidth
     */
    public function testGraphemeWidthAsciiCharacter(): void
    {
        $this->assertSame(1, BufferHelper::graphemeWidth('a'));
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::graphemeWidth
     */
    public function testGraphemeWidthWideEastAsian(): void
    {
        $this->assertSame(2, BufferHelper::graphemeWidth('日'));
        $this->assertSame(2, BufferHelper::graphemeWidth('あ'));
        $this->assertSame(2, BufferHelper::graphemeWidth('한'));
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::graphemeWidth
     */
    public function testGraphemeWidthHiragana(): void
    {
        $this->assertSame(2, BufferHelper::graphemeWidth('あ'));
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::graphemeWidth
     */
    public function testGraphemeWidthZeroWidthSpace(): void
    {
        $this->assertSame(0, BufferHelper::graphemeWidth("\xE2\x80\x8B"));
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::placeString
     */
    public function testPlaceStringBasic(): void
    {
        $width = 10;
        $height = 1;
        $grid = array_fill(0, $width * $height, Cell::new(' '));
        $result = BufferHelper::placeString($grid, $width, $height, 0, 0, 'abc');

        $this->assertSame('a', $result[0]->rune());
               $this->assertSame('b', $result[1]->rune());
        $this->assertSame('c', $result[2]->rune());
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::placeString
     */
    public function testPlaceStringWithOffset(): void
    {
        $width = 10;
        $height = 1;
        $grid = array_fill(0, $width * $height, Cell::new(' '));
        $result = BufferHelper::placeString($grid, $width, $height, 3, 0, 'xy');

        $this->assertSame('x', $result[3]->rune());
        $this->assertSame('y', $result[4]->rune());
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::placeString
     */
    public function testPlaceStringWideCharacterCreatesContinuation(): void
    {
        $width = 10;
        $height = 1;
        $grid = array_fill(0, $width * $height, Cell::new(' '));
        $result = BufferHelper::placeString($grid, $width, $height, 0, 0, '日');

        $this->assertSame('日', $result[0]->rune());
        $this->assertSame(2, $result[0]->width());
        $this->assertSame('', $result[1]->rune());
        $this->assertSame(0, $result[1]->width());
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::placeString
     */
    public function testPlaceStringTruncatesAtWidth(): void
    {
        $width = 3;
        $height = 1;
        $grid = array_fill(0, $width * $height, Cell::new(' '));
        $result = BufferHelper::placeString($grid, $width, $height, 0, 0, 'abcdef');

        $this->assertSame('a', $result[0]->rune());
        $this->assertSame('b', $result[1]->rune());
        $this->assertSame('c', $result[2]->rune());
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::placeString
     */
    public function testPlaceStringWithStyle(): void
    {
        $width = 5;
        $height = 1;
        $grid = array_fill(0, $width * $height, Cell::new(' '));
        $style = BufferStyle::new(0xff0000);
        $result = BufferHelper::placeString($grid, $width, $height, 1, 0, 'ab', $style);

        $this->assertSame('a', $result[1]->rune());
        $this->assertSame($style, $result[1]->style());
        $this->assertSame('b', $result[2]->rune());
        $this->assertSame($style, $result[2]->style());
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::placeString
     */
    public function testPlaceStringWideCharAtEndOfWidth(): void
    {
        $width = 3;
        $height = 1;
        $grid = array_fill(0, $width * $height, Cell::new(' '));
        $result = BufferHelper::placeString($grid, $width, $height, 2, 0, '日');

        $this->assertSame('日', $result[2]->rune());
        $this->assertSame(2, $result[2]->width());
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::toBufferStyle
     */
    public function testToBufferStyleEmptyStyle(): void
    {
        $sprinkles = SugarStyle::new();
        $buffer = BufferHelper::toBufferStyle($sprinkles);

        $this->assertNull($buffer->fg());
        $this->assertNull($buffer->bg());
        $this->assertSame(0, $buffer->attrs());
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::toBufferStyle
     */
    public function testToBufferStyleWithBold(): void
    {
        $sprinkles = SugarStyle::new()->bold();
        $buffer = BufferHelper::toBufferStyle($sprinkles);

        $this->assertTrue($buffer->hasBold());
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::toBufferStyle
     */
    public function testToBufferStyleWithItalic(): void
    {
        $sprinkles = SugarStyle::new()->italic();
        $buffer = BufferHelper::toBufferStyle($sprinkles);

        $this->assertTrue($buffer->hasItalic());
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::toBufferStyle
     */
    public function testToBufferStyleWithUnderline(): void
    {
        $sprinkles = SugarStyle::new()->underline();
        $buffer = BufferHelper::toBufferStyle($sprinkles);

        $this->assertTrue($buffer->hasUnderline());
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::toBufferStyle
     */
    public function testToBufferStyleWithStrikethrough(): void
    {
        $sprinkles = SugarStyle::new()->strikethrough();
        $buffer = BufferHelper::toBufferStyle($sprinkles);

        $this->assertTrue($buffer->hasStrike());
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::toBufferStyle
     */
    public function testToBufferStyleWithFaint(): void
    {
        $sprinkles = SugarStyle::new()->faint();
        $buffer = BufferHelper::toBufferStyle($sprinkles);

        $this->assertTrue($buffer->hasFaint());
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::toBufferStyle
     */
    public function testToBufferStyleWithBlink(): void
    {
        $sprinkles = SugarStyle::new()->blink();
        $buffer = BufferHelper::toBufferStyle($sprinkles);

        $this->assertTrue($buffer->hasBlink());
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::toBufferStyle
     */
    public function testToBufferStyleWithReverse(): void
    {
        $sprinkles = SugarStyle::new()->reverse();
        $buffer = BufferHelper::toBufferStyle($sprinkles);

        $this->assertTrue($buffer->hasReverse());
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::toBufferStyle
     */
    public function testToBufferStyleWithOverline(): void
    {
        $sprinkles = SugarStyle::new()->overline();
        $buffer = BufferHelper::toBufferStyle($sprinkles);

        $this->assertTrue($buffer->hasOverline());
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::toBufferStyle
     */
    public function testToBufferStyleWithInvisible(): void
    {
        $sprinkles = SugarStyle::new()->invisible();
        $buffer = BufferHelper::toBufferStyle($sprinkles);

        $this->assertTrue($buffer->hasInvisible());
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::toBufferStyle
     */
    public function testToBufferStyleWithForegroundColor(): void
    {
        $sprinkles = SugarStyle::new()->foreground(Color::rgb(255, 0, 0));
        $buffer = BufferHelper::toBufferStyle($sprinkles);

        $this->assertSame(0xff0000, $buffer->fg());
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::toBufferStyle
     */
    public function testToBufferStyleWithBackgroundColor(): void
    {
        $sprinkles = SugarStyle::new()->background(Color::rgb(0, 0, 255));
        $buffer = BufferHelper::toBufferStyle($sprinkles);

        $this->assertSame(0x0000ff, $buffer->bg());
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::toBufferStyle
     */
    public function testToBufferStyleWithMultipleAttributes(): void
    {
        $sprinkles = SugarStyle::new()
            ->bold()
            ->italic()
            ->underline()
            ->foreground(Color::rgb(128, 64, 32));

        $buffer = BufferHelper::toBufferStyle($sprinkles);

        $this->assertTrue($buffer->hasBold());
        $this->assertTrue($buffer->hasItalic());
        $this->assertTrue($buffer->hasUnderline());
        $this->assertSame(0x804020, $buffer->fg());
    }

    /**
     * Audit F6: width is answered by the shared sugar-core table, not a
     * forked private one. Delegation parity — every sample must agree
     * with Width::of() exactly.
     */
    public static function widthCanonSamples(): iterable
    {
        yield 'ascii'            => ['a'];
        yield 'cjk'              => ["\u{65E5}"];
        yield 'hangul'           => ["\u{D55C}"];
        yield 'zero-width-space' => ["\u{200B}"];
        yield 'combining'        => ["\u{0301}"];
        yield 'zwj'              => ["\u{200D}"];
        yield 'plane1-emoji'     => ["\u{1F300}"];
        yield 'braille-dot'      => ["\u{2800}"];
        yield 'box-drawing'      => ["\u{2500}"];
    }

    /**
     * @dataProvider widthCanonSamples
     */
    public function testGraphemeWidthDelegatesToSharedCanon(string $cluster): void
    {
        $this->assertSame(Width::of($cluster), BufferHelper::graphemeWidth($cluster));
    }

    /**
     * The plane-1 emoji the old private table silently missed (its
     * docblock claimed "emoji count as 2") must now report 2.
     */
    public function testGraphemeWidthCountsPlaneOneEmojiAsWide(): void
    {
        $this->assertSame(2, BufferHelper::graphemeWidth("\u{1F300}"));
    }

    /**
     * SSOT pin: the forked width tables must not come back.
     */
    public function testForkedWidthInternalsWereRemoved(): void
    {
        $reflector = new \ReflectionClass(BufferHelper::class);
        foreach (['firstCodepoint', 'isZeroWidth', 'isWide'] as $gone) {
            $this->assertFalse($reflector->hasMethod($gone), $gone . ' must not exist');
        }
        $this->assertFalse($reflector->hasConstant('ZERO_WIDTH'), 'ZERO_WIDTH table must not exist');
    }

    /**
     * Zero-width decoration must merge into the base rune instead of
     * taking (and later losing) the slot behind it (sugar-charts-094).
     * The NUL is its own cluster under both split canons, so the arm
     * fires identically on intl-less hosts.
     *
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::placeString
     */
    public function testPlaceStringMergesZeroWidthIntoBase(): void
    {
        $width = 4;
        $grid = array_fill(0, $width, Cell::new(' '));
        $result = BufferHelper::placeString($grid, $width, 1, 0, 0, "a\x00b");

        $this->assertSame("a\x00", $result[0]->rune(), 'the decoration must ride the base cell, not trail it as a stray');
        $this->assertSame(1, $result[0]->width());
        $this->assertSame('b', $result[1]->rune());
        foreach ($result as $i => $cell) {
            $this->assertNotSame(0, $cell->width(), "no zero-width ghost cell may survive at slot {$i}");
        }
    }

    /**
     * A mark arriving before any base has nothing to decorate: it is
     * dropped, and the first real rune still lands at $x.
     *
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::placeString
     */
    public function testPlaceStringDropsLeadingZeroWidthWithoutBase(): void
    {
        $width = 4;
        $grid = array_fill(0, $width, Cell::new(' '));
        $result = BufferHelper::placeString($grid, $width, 1, 0, 0, "\x00xy");

        $this->assertSame('x', $result[0]->rune());
        $this->assertSame('y', $result[1]->rune());
        $this->assertSame(' ', $result[2]->rune());
    }

    /**
     * A wide rune's continuation half is not a base: a decoration that
     * arrives past it is dropped, never merged onto the ghost cell.
     *
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::placeString
     */
    public function testPlaceStringRefusesToMergeOntoContinuation(): void
    {
        $width = 6;
        $grid = array_fill(0, $width, Cell::new(' '));
        $result = BufferHelper::placeString($grid, $width, 1, 0, 0, "日\x00");

        $this->assertSame('日', $result[0]->rune());
        $this->assertSame(2, $result[0]->width());
        $this->assertSame('', $result[1]->rune(), 'continuation cell must stay untouched');
        $this->assertSame(0, $result[1]->width());
        $this->assertSame(' ', $result[2]->rune(), 'the stranded decoration is dropped, not parked in slot 2');
    }

    /**
     * Pre-filled grids: a decoration merging onto the current slot when
     * it already carries a caller-placed base (Canvas::setCell parity).
     *
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::placeString
     */
    public function testPlaceStringMergesOntoPreplacedBase(): void
    {
        $width = 4;
        $grid = array_fill(0, $width, Cell::new(' '));
        $grid[1] = new Cell('k', null, null, 1);
        $result = BufferHelper::placeString($grid, $width, 1, 1, 0, "\x00");

        $this->assertSame("k\x00", $result[1]->rune(), 'the mark at x=1 merges onto the base occupying that slot');
        $this->assertSame(' ', $result[2]->rune());
    }
}
