<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Border\TitleAnchor;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Sprinkles\UnderlineStyle;

/**
 * Profile semantics pinned against charmbracelet/colorprofile's Writer:
 * `Ascii` drops colour only (attributes + OSC 8 pass through), `NoTty`
 * runs the whole output through `ansi.Strip`.
 */
final class StyleColorProfileTest extends TestCase
{
    private static function decorated(ColorProfile $p): Style
    {
        return Style::new()
            ->bold()->italic()->faint()->underline()->strikethrough()
            ->blink()->reverse()->overline()
            ->foreground(Color::hex('#ff0000'))
            ->background(Color::hex('#0000ff'))
            ->underlineColor(Color::hex('#00ff00'))
            ->hyperlink('https://example.test', 'id1')
            ->colorProfile($p);
    }

    public function testAsciiKeepsAttributesAndHyperlinkButDropsColour(): void
    {
        $out = self::decorated(ColorProfile::Ascii)->render('hi');

        $this->assertSame(
            "\x1b]8;id=id1;https://example.test\x1b\\"
            . "\x1b[1;2;3;4;5;7;9;53mhi\x1b[0m"
            . "\x1b]8;;\x1b\\",
            $out,
        );
    }

    public function testNoTtyEmitsNoEscapeForAttributesColoursOrHyperlink(): void
    {
        $this->assertSame('hi', self::decorated(ColorProfile::NoTty)->render('hi'));
    }

    public function testNoTtySuppressesSubStyledUnderline(): void
    {
        $out = Style::new()->underline()->underlineStyle(UnderlineStyle::Curly)
            ->colorProfile(ColorProfile::NoTty)
            ->render('x');
        $this->assertSame('x', $out);
    }

    public function testNoTtyKeepsLayoutWhileDroppingEscapes(): void
    {
        $out = Style::new()
            ->bold()
            ->foreground(Color::hex('#ff0000'))
            ->border(Border::normal())
            ->borderForeground(Color::hex('#00ff00'))
            ->padding(0, 1)
            ->marginLeft(2)
            ->marginBackground(Color::hex('#0000ff'))
            ->colorProfile(ColorProfile::NoTty)
            ->render('ab');

        $this->assertSame(
            "  ┌────┐\n"
            . "  │ ab │\n"
            . "  └────┘",
            $out,
        );
    }

    public function testNoTtyStripsEscapesCarriedInByPreStyledContent(): void
    {
        $inner = Style::new()->bold()->foreground(Color::hex('#ff0000'))
            ->hyperlink('https://example.test')
            ->render('red');

        $out = Style::new()->colorProfile(ColorProfile::NoTty)->render("[{$inner}]");

        $this->assertSame('[red]', $out);
    }

    public function testNoTtyStripsEscapesInjectedByTransformAndBorderTitle(): void
    {
        $out = Style::new()
            ->border(Border::normal()->withTitle("\x1b[1mT\x1b[0m", TitleAnchor::TopLeft))
            ->transform(static fn(string $s): string => str_replace('x', "\x1b[7mx\x1b[0m", $s))
            ->colorProfile(ColorProfile::NoTty)
            ->render('xy');

        $this->assertStringNotContainsString("\x1b", $out);
        $this->assertStringContainsString('T', $out);
        $this->assertStringContainsString('│xy│', $out);
    }

    public function testAsciiLeavesPreStyledContentUntouched(): void
    {
        // Style downsamples only its own colours; Ascii is not a stripping
        // profile, so foreign escapes pass through as at every richer tier.
        $out = Style::new()->colorProfile(ColorProfile::Ascii)->render("\x1b[1mb\x1b[0m");
        $this->assertSame("\x1b[1mb\x1b[0m", $out);
    }

    public function testNoTtyRenderIsMemoSafeAcrossProfileChanges(): void
    {
        $base = Style::new()->bold();
        $this->assertSame("\x1b[1mz\x1b[0m", $base->render('z'));
        $this->assertSame('z', $base->colorProfile(ColorProfile::NoTty)->render('z'));
        $this->assertSame(
            "\x1b[1mz\x1b[0m",
            $base->colorProfile(ColorProfile::NoTty)->colorProfile(ColorProfile::Ascii)->render('z'),
        );
    }
}
