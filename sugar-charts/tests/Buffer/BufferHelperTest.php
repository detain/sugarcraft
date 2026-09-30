<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Buffer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Style as BufferStyle;
use SugarCraft\Charts\Buffer\BufferHelper;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Style as SprinklesStyle;

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
        $sprinkles = SprinklesStyle::new();
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
        $sprinkles = SprinklesStyle::new()->bold();
        $buffer = BufferHelper::toBufferStyle($sprinkles);

        $this->assertTrue($buffer->hasBold());
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::toBufferStyle
     */
    public function testToBufferStyleWithItalic(): void
    {
        $sprinkles = SprinklesStyle::new()->italic();
        $buffer = BufferHelper::toBufferStyle($sprinkles);

        $this->assertTrue($buffer->hasItalic());
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::toBufferStyle
     */
    public function testToBufferStyleWithUnderline(): void
    {
        $sprinkles = SprinklesStyle::new()->underline();
        $buffer = BufferHelper::toBufferStyle($sprinkles);

        $this->assertTrue($buffer->hasUnderline());
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::toBufferStyle
     */
    public function testToBufferStyleWithStrikethrough(): void
    {
        $sprinkles = SprinklesStyle::new()->strikethrough();
        $buffer = BufferHelper::toBufferStyle($sprinkles);

        $this->assertTrue($buffer->hasStrike());
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::toBufferStyle
     */
    public function testToBufferStyleWithFaint(): void
    {
        $sprinkles = SprinklesStyle::new()->faint();
        $buffer = BufferHelper::toBufferStyle($sprinkles);

        $this->assertTrue($buffer->hasFaint());
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::toBufferStyle
     */
    public function testToBufferStyleWithBlink(): void
    {
        $sprinkles = SprinklesStyle::new()->blink();
        $buffer = BufferHelper::toBufferStyle($sprinkles);

        $this->assertTrue($buffer->hasBlink());
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::toBufferStyle
     */
    public function testToBufferStyleWithReverse(): void
    {
        $sprinkles = SprinklesStyle::new()->reverse();
        $buffer = BufferHelper::toBufferStyle($sprinkles);

        $this->assertTrue($buffer->hasReverse());
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::toBufferStyle
     */
    public function testToBufferStyleWithOverline(): void
    {
        $sprinkles = SprinklesStyle::new()->overline();
        $buffer = BufferHelper::toBufferStyle($sprinkles);

        $this->assertTrue($buffer->hasOverline());
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::toBufferStyle
     */
    public function testToBufferStyleWithInvisible(): void
    {
        $sprinkles = SprinklesStyle::new()->invisible();
        $buffer = BufferHelper::toBufferStyle($sprinkles);

        $this->assertTrue($buffer->hasInvisible());
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::toBufferStyle
     */
    public function testToBufferStyleWithForegroundColor(): void
    {
        $sprinkles = SprinklesStyle::new()->foreground(Color::rgb(255, 0, 0));
        $buffer = BufferHelper::toBufferStyle($sprinkles);

        $this->assertSame(0xff0000, $buffer->fg());
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::toBufferStyle
     */
    public function testToBufferStyleWithBackgroundColor(): void
    {
        $sprinkles = SprinklesStyle::new()->background(Color::rgb(0, 0, 255));
        $buffer = BufferHelper::toBufferStyle($sprinkles);

        $this->assertSame(0x0000ff, $buffer->bg());
    }

    /**
     * @covers \SugarCraft\Charts\Buffer\BufferHelper::toBufferStyle
     */
    public function testToBufferStyleWithMultipleAttributes(): void
    {
        $sprinkles = SprinklesStyle::new()
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
     * Audit F6: width is answered by the shared candy-core table, not a
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
}
