<?php

declare(strict_types=1);

namespace CandyCore\Gloss\Tests;

use CandyCore\Core\Util\Color;
use CandyCore\Core\Util\ColorProfile;
use CandyCore\Gloss\Align;
use CandyCore\Gloss\Border;
use CandyCore\Gloss\Style;
use PHPUnit\Framework\TestCase;

final class StyleTest extends TestCase
{
    public function testPlainStyleRendersUnchanged(): void
    {
        $this->assertSame('hello', Style::new()->render('hello'));
    }

    public function testBoldEmitsSgr1(): void
    {
        $this->assertSame("\x1b[1mhello\x1b[0m", Style::new()->bold()->render('hello'));
    }

    public function testForegroundTrueColor(): void
    {
        $out = Style::new()
            ->foreground(Color::hex('#ff8000'))
            ->render('hi');
        $this->assertSame("\x1b[38;2;255;128;0mhi\x1b[0m", $out);
    }

    public function testForegroundDownsamplesToAnsi16(): void
    {
        $out = Style::new()
            ->colorProfile(ColorProfile::Ansi)
            ->foreground(Color::hex('#ff0000'))
            ->render('hi');
        $this->assertSame("\x1b[91mhi\x1b[0m", $out);
    }

    public function testNoColorProfileEmitsNoSgrEvenWithColor(): void
    {
        $out = Style::new()
            ->colorProfile(ColorProfile::Ascii)
            ->foreground(Color::hex('#ff0000'))
            ->render('hi');
        $this->assertSame('hi', $out);
    }

    public function testCombinedAttributesAndColor(): void
    {
        $out = Style::new()
            ->bold()
            ->underline()
            ->foreground(Color::hex('#00ff00'))
            ->render('x');
        $this->assertSame("\x1b[1;4m\x1b[38;2;0;255;0mx\x1b[0m", $out);
    }

    public function testImmutability(): void
    {
        $a = Style::new();
        $b = $a->bold();
        $this->assertNotSame($a, $b);
        $this->assertSame('hello', $a->render('hello'));
        $this->assertSame("\x1b[1mhello\x1b[0m", $b->render('hello'));
    }

    public function testInvokeIsAlias(): void
    {
        $s = Style::new()->bold();
        $this->assertSame($s->render('x'), $s('x'));
    }

    public function testHorizontalPadding(): void
    {
        $out = Style::new()->padding(0, 2)->render('hi');
        $this->assertSame('  hi  ', $out);
    }

    public function testVerticalPaddingProducesBlankLines(): void
    {
        $out = Style::new()->padding(1, 0)->render('hi');
        $this->assertSame("  \nhi\n  ", $out);
    }

    public function testPaddingAlignsToMaxLineWidth(): void
    {
        $out = Style::new()->padding(0, 1)->render("ab\nfoobar");
        $expected = " ab     \n foobar ";
        $this->assertSame($expected, $out);
    }

    public function testPaddingFourSides(): void
    {
        $out = Style::new()->padding(1, 2, 1, 2)->render('hi');
        $expected = "      \n  hi  \n      ";
        $this->assertSame($expected, $out);
    }

    public function testPaddingArityValidated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Style::new()->padding(1, 2, 3);
    }

    public function testPaddingNegativeRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Style::new()->padding(-1);
    }

    public function testPerSideSetters(): void
    {
        $out = Style::new()
            ->paddingTop(1)
            ->paddingLeft(2)
            ->render('x');
        $this->assertSame("   \n  x", $out);
    }

    public function testRenderWithColorAndPaddingWrapsEachLine(): void
    {
        $out = Style::new()
            ->foreground(Color::hex('#ff0000'))
            ->padding(0, 1)
            ->render('hi');
        $this->assertSame("\x1b[38;2;255;0;0m hi \x1b[0m", $out);
    }

    public function testEmptyStringStillRendersWithPadding(): void
    {
        $out = Style::new()->padding(1, 1)->render('');
        $expected = "  \n  \n  ";
        $this->assertSame($expected, $out);
    }

    // ---- width / alignment ------------------------------------------------

    public function testWidthRightPadsLine(): void
    {
        $this->assertSame('hi   ', Style::new()->width(5)->render('hi'));
    }

    public function testWidthTruncatesOverflow(): void
    {
        $this->assertSame('hel', Style::new()->width(3)->render('hello'));
    }

    public function testAlignRight(): void
    {
        $this->assertSame('   hi', Style::new()->width(5)->align(Align::Right)->render('hi'));
    }

    public function testAlignCenterEvenExtra(): void
    {
        $this->assertSame(' hi ', Style::new()->width(4)->align(Align::Center)->render('hi'));
    }

    public function testAlignCenterOddExtra(): void
    {
        $this->assertSame(' hi  ', Style::new()->width(5)->align(Align::Center)->render('hi'));
    }

    public function testWidthZeroEmpties(): void
    {
        $this->assertSame('', Style::new()->width(0)->render('hello'));
    }

    public function testWidthRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Style::new()->width(-1);
    }

    // ---- height -----------------------------------------------------------

    public function testHeightPadsBelow(): void
    {
        $this->assertSame("hi\n  \n  ", Style::new()->height(3)->render('hi'));
    }

    public function testHeightTruncatesExtraLines(): void
    {
        $this->assertSame("a\nb", Style::new()->height(2)->render("a\nb\nc\nd"));
    }

    // ---- margin -----------------------------------------------------------

    public function testMarginAroundContent(): void
    {
        $expected = "      \n  hi  \n      ";
        $this->assertSame($expected, Style::new()->margin(1, 2)->render('hi'));
    }

    public function testMarginIsUnstyled(): void
    {
        $out = Style::new()
            ->background(Color::hex('#ff0000'))
            ->margin(0, 2)
            ->render('hi');
        $this->assertSame("  \x1b[48;2;255;0;0mhi\x1b[0m  ", $out);
    }

    // ---- borders ----------------------------------------------------------

    public function testNormalBorderAroundContent(): void
    {
        $expected = "┌──┐\n│hi│\n└──┘";
        $this->assertSame($expected, Style::new()->border(Border::normal())->render('hi'));
    }

    public function testRoundedBorderWithPadding(): void
    {
        $expected = "╭────╮\n│ hi │\n╰────╯";
        $this->assertSame(
            $expected,
            Style::new()->border(Border::rounded())->padding(0, 1)->render('hi'),
        );
    }

    public function testBorderTopOnly(): void
    {
        $expected = "──\nhi";
        $this->assertSame(
            $expected,
            Style::new()->border(Border::normal(), true, false, false, false)->render('hi'),
        );
    }

    public function testBorderTwoArgShorthand(): void
    {
        // border(b, vertical=true, horizontal=false) → top+bottom only
        $expected = "──\nhi\n──";
        $this->assertSame(
            $expected,
            Style::new()->border(Border::normal(), true, false)->render('hi'),
        );
    }

    public function testBorderColored(): void
    {
        $out = Style::new()
            ->border(Border::normal())
            ->borderForeground(Color::hex('#ff0000'))
            ->render('x');
        $expected =
            "\x1b[38;2;255;0;0m┌─┐\x1b[0m\n"
          . "\x1b[38;2;255;0;0m│\x1b[0mx\x1b[38;2;255;0;0m│\x1b[0m\n"
          . "\x1b[38;2;255;0;0m└─┘\x1b[0m";
        $this->assertSame($expected, $out);
    }

    public function testBorderWithMargin(): void
    {
        $expected =
            "      \n"
          . " ┌──┐ \n"
          . " │hi│ \n"
          . " └──┘ \n"
          . "      ";
        $out = Style::new()
            ->border(Border::normal())
            ->margin(1, 1)
            ->render('hi');
        $this->assertSame($expected, $out);
    }

    public function testBorderRemoval(): void
    {
        $a = Style::new()->border(Border::normal());
        $b = $a->border(null);
        $this->assertSame('hi', $b->render('hi'));
    }

    public function testAsciiBorder(): void
    {
        $expected = "+-+\n|x|\n+-+";
        $this->assertSame($expected, Style::new()->border(Border::ascii())->render('x'));
    }
}
