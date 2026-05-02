<?php

declare(strict_types=1);

namespace CandyCore\Sprinkles\Tests;

use CandyCore\Core\Util\Color;
use CandyCore\Core\Util\ColorProfile;
use CandyCore\Sprinkles\AdaptiveColor;
use CandyCore\Sprinkles\Align;
use CandyCore\Sprinkles\Border;
use CandyCore\Sprinkles\LightDark;
use CandyCore\Sprinkles\Style;
use CandyCore\Sprinkles\VAlign;
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

    // ---- vertical alignment ----------------------------------------------

    public function testHeightTopAlignDefault(): void
    {
        $this->assertSame("hi\n  \n  ", Style::new()->height(3)->render('hi'));
    }

    public function testHeightBottomAlign(): void
    {
        $out = Style::new()->height(3)->verticalAlign(VAlign::Bottom)->render('hi');
        $this->assertSame("  \n  \nhi", $out);
    }

    public function testHeightMiddleAlignEvenExtra(): void
    {
        $out = Style::new()->height(4)->verticalAlign(VAlign::Middle)->render('hi');
        // 2 lines of fill split evenly: 1 above, 1 below.
        $this->assertSame("  \nhi\n  \n  ", $out);
    }

    public function testHeightMiddleAlignOddExtra(): void
    {
        $out = Style::new()->height(3)->verticalAlign(VAlign::Middle)->render('hi');
        $this->assertSame("  \nhi\n  ", $out);
    }

    public function testHeightTruncatesFromBottomWhenTopAligned(): void
    {
        $out = Style::new()->height(2)->render("a\nb\nc\nd");
        $this->assertSame("a\nb", $out);
    }

    public function testHeightTruncatesFromTopWhenBottomAligned(): void
    {
        $out = Style::new()->height(2)->verticalAlign(VAlign::Bottom)->render("a\nb\nc\nd");
        $this->assertSame("c\nd", $out);
    }

    // ---- inherit ----------------------------------------------------------

    public function testInheritCopiesParentForUnsetChildProps(): void
    {
        $parent = Style::new()->bold()->foreground(Color::hex('#ff0000'));
        $child  = Style::new();
        $merged = $child->inherit($parent);
        $this->assertSame("\x1b[1m\x1b[38;2;255;0;0mhi\x1b[0m", $merged->render('hi'));
    }

    public function testInheritChildOverridesParent(): void
    {
        $parent = Style::new()->bold()->foreground(Color::hex('#ff0000'));
        $child  = Style::new()->foreground(Color::hex('#00ff00'));
        $merged = $child->inherit($parent);
        // Parent's bold survives; child's green fg wins.
        $this->assertSame("\x1b[1m\x1b[38;2;0;255;0mhi\x1b[0m", $merged->render('hi'));
    }

    public function testInheritExplicitOffWinsOverParentOn(): void
    {
        $parent = Style::new()->bold(true);
        $child  = Style::new()->bold(false);
        $merged = $child->inherit($parent);
        $this->assertSame('hi', $merged->render('hi'));
    }

    public function testInheritMergesPaddingAndBorder(): void
    {
        $parent = Style::new()->padding(1)->border(Border::normal());
        $child  = Style::new()->padding(0, 1);
        $merged = $child->inherit($parent);
        // Child padding (0,1) wins; parent border survives.
        $expected = "┌────┐\n│ hi │\n└────┘";
        $this->assertSame($expected, $merged->render('hi'));
    }

    public function testChainedInheritLetsLaterParentSupplyDefaults(): void
    {
        // First inherit pulls bold + red from $a; that should leave the
        // intermediate's *explicit* propsSet empty, so the second inherit
        // can fully replace those values with $b's.
        $a = Style::new()->bold()->foreground(Color::hex('#ff0000'));
        $b = Style::new()->italic()->foreground(Color::hex('#00ff00'));

        $merged = Style::new()->inherit($a)->inherit($b);

        // $b's italic + green fg now apply; $a's bold should NOT linger.
        $this->assertSame("\x1b[3m\x1b[38;2;0;255;0mhi\x1b[0m", $merged->render('hi'));
    }

    public function testForegroundNullClearsColor(): void
    {
        $s = Style::new()
            ->foreground(Color::hex('#ff0000'))
            ->foreground(null);
        $this->assertSame('hi', $s->render('hi'));
    }

    public function testWidthPreservesInlineAnsi(): void
    {
        // Truncating styled content via width() must keep the inline SGR
        // codes — the user's red 'hello' should still be red, just clipped.
        $out = Style::new()->width(3)->render("\x1b[31mhello\x1b[0m");
        $this->assertSame("\x1b[31mhel\x1b[0m", $out);
    }

    // ---- adaptive colour ------------------------------------------------

    public function testForegroundAdaptiveDoesNotRenderUntilResolved(): void
    {
        // Adaptive without a concrete `foreground()` shouldn't paint
        // anything yet — the call is deferred until resolveAdaptive().
        $s = Style::new()->foregroundAdaptive(Color::hex('#000'), Color::hex('#fff'));
        $this->assertSame('hi', $s->render('hi'));
    }

    public function testResolveAdaptivePicksDarkForDarkBackground(): void
    {
        $s = Style::new()
            ->foregroundAdaptive(Color::hex('#000000'), Color::hex('#ffffff'))
            ->resolveAdaptive(isDark: true);
        // Dark mode → "dark" colour (white) → SGR 38;2;255;255;255.
        $this->assertSame("\x1b[38;2;255;255;255mhi\x1b[0m", $s->render('hi'));
    }

    public function testResolveAdaptivePicksLightForLightBackground(): void
    {
        $s = Style::new()
            ->foregroundAdaptive(Color::hex('#000000'), Color::hex('#ffffff'))
            ->resolveAdaptive(isDark: false);
        $this->assertSame("\x1b[38;2;0;0;0mhi\x1b[0m", $s->render('hi'));
    }

    public function testExplicitForegroundWinsOverAdaptive(): void
    {
        // Concrete `foreground()` always beats adaptive — same precedence
        // as lipgloss.
        $s = Style::new()
            ->foreground(Color::hex('#ff00ff'))
            ->foregroundAdaptive(Color::hex('#000'), Color::hex('#fff'))
            ->resolveAdaptive(isDark: true);
        $this->assertSame("\x1b[38;2;255;0;255mhi\x1b[0m", $s->render('hi'));
    }

    public function testBackgroundAdaptiveResolves(): void
    {
        $s = Style::new()
            ->backgroundAdaptive(Color::hex('#eeeeee'), Color::hex('#111111'))
            ->resolveAdaptive(isDark: true);
        $this->assertSame("\x1b[48;2;17;17;17mhi\x1b[0m", $s->render('hi'));
    }

    public function testInheritPropagatesAdaptive(): void
    {
        $parent = Style::new()->foregroundAdaptive(Color::hex('#000'), Color::hex('#fff'));
        $child  = Style::new()->bold();
        $merged = $child->inherit($parent)->resolveAdaptive(isDark: false);
        $this->assertSame("\x1b[1m\x1b[38;2;0;0;0mhi\x1b[0m", $merged->render('hi'));
    }

    // ---- AdaptiveColor + LightDark helpers ------------------------------

    public function testAdaptiveColorPick(): void
    {
        $light = Color::hex('#aaaaaa');
        $dark  = Color::hex('#222222');
        $a = new AdaptiveColor($light, $dark);
        $this->assertSame($light, $a->pick(false));
        $this->assertSame($dark,  $a->pick(true));
    }

    public function testLightDarkPickStatic(): void
    {
        $light = Color::hex('#aaaaaa');
        $dark  = Color::hex('#222222');
        $this->assertSame($light, LightDark::pick(false, $light, $dark));
        $this->assertSame($dark,  LightDark::pick(true,  $light, $dark));
    }

    public function testLightDarkPickerClosure(): void
    {
        $pick = LightDark::picker(true);
        $light = Color::hex('#aaaaaa');
        $dark  = Color::hex('#222222');
        $this->assertSame($dark, $pick($light, $dark));
    }
}
