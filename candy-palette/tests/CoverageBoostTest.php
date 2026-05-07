<?php

declare(strict_types=1);

namespace SugarCraft\Palette\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Palette\Color;
use SugarCraft\Palette\Palette;
use SugarCraft\Palette\Profile;

/**
 * Coverage-push tests filling gaps in Profile / Palette / Color so the
 * lib's line coverage approaches 80%+. Each test exercises a path the
 * existing test files don't reach.
 */
final class CoverageBoostTest extends TestCase
{
    // --- Profile ---------------------------------------------------------

    public function testEveryProfileHasAStableLabel(): void
    {
        $this->assertSame('TrueColor', Profile::TrueColor->label());
        $this->assertSame('ANSI 256', Profile::ANSI256->label());
        $this->assertSame('ANSI', Profile::ANSI->label());
        $this->assertSame('ASCII', Profile::Ascii->label());
        $this->assertSame('No TTY', Profile::NoTTY->label());
    }

    public function testEveryProfileHasADescription(): void
    {
        $this->assertNotEmpty(Profile::TrueColor->description());
        $this->assertNotEmpty(Profile::ANSI256->description());
        $this->assertNotEmpty(Profile::ANSI->description());
        $this->assertNotEmpty(Profile::Ascii->description());
        $this->assertNotEmpty(Profile::NoTTY->description());
    }

    // --- Palette public surface -----------------------------------------

    public function testProfileAndWithProfileRoundTrip(): void
    {
        // Force NoTTY via NO_COLOR so __construct lands deterministically.
        $p = new Palette(env: ['NO_COLOR' => '1']);
        $this->assertSame(Profile::NoTTY, $p->profile());

        $upgraded = $p->withProfile(Profile::TrueColor);
        $this->assertSame(Profile::TrueColor, $upgraded->profile());
        // Original palette unchanged (immutable).
        $this->assertSame(Profile::NoTTY, $p->profile());
    }

    public function testStaticDetectShortcut(): void
    {
        $this->assertSame(Profile::NoTTY, Palette::detect(env: ['NO_COLOR' => '1']));
    }

    public function testInstanceConvertDelegatesToProfile(): void
    {
        $p = (new Palette(env: ['NO_COLOR' => '1']))->withProfile(Profile::ANSI);
        $color = Color::parse('#ff5f87');
        $converted = $p->convert($color);
        // ANSI profile → 16 colors, so the result should differ from the
        // raw TrueColor input.
        $this->assertNotEquals($color, $converted);
    }

    public function testStaticToProfileShortcut(): void
    {
        $color = Color::parse('#ff5f87');
        $direct = Color::parse('#ff5f87')->convert(Profile::ANSI);
        $shortcut = Palette::toProfile($color, Profile::ANSI);
        $this->assertEquals($direct, $shortcut);
    }

    public function testDegradeStripsForNoTTY(): void
    {
        $p = (new Palette(env: ['NO_COLOR' => '1']))->withProfile(Profile::NoTTY);
        $this->assertSame('hi', $p->degrade("\x1b[31mhi\x1b[0m"));
    }

    public function testDegradePassesTrueColorThrough(): void
    {
        $p = (new Palette(env: ['NO_COLOR' => '1']))->withProfile(Profile::TrueColor);
        $in = "\x1b[38;2;255;0;0mhi\x1b[0m";
        $this->assertSame($in, $p->degrade($in));
    }

    public function testDescribeMentionsLabelAndDescription(): void
    {
        $p = (new Palette(env: ['NO_COLOR' => '1']))->withProfile(Profile::ANSI256);
        $out = $p->describe();
        $this->assertStringContainsString('ANSI 256', $out);
        $this->assertStringContainsString('256-color', $out);
    }

    public function testCommentForEveryProfile(): void
    {
        $base = new Palette(env: ['NO_COLOR' => '1']);
        $this->assertSame('fancy',       $base->withProfile(Profile::TrueColor)->comment());
        $this->assertSame('1990s fancy', $base->withProfile(Profile::ANSI256)->comment());
        $this->assertSame('normcore',    $base->withProfile(Profile::ANSI)->comment());
        $this->assertSame('ancient',     $base->withProfile(Profile::Ascii)->comment());
        $this->assertSame('naughty!',    $base->withProfile(Profile::NoTTY)->comment());
    }

    public function testStaticStripAnsiHandlesCsiAndOsc(): void
    {
        $in  = "\x1b[31mfoo\x1b[0m\x1b]8;;https://x\x1b\\bar\x1b]8;;\x1b\\";
        $out = Palette::stripAnsi($in);
        $this->assertStringNotContainsString("\x1b", $out);
        $this->assertStringContainsString('foo', $out);
        $this->assertStringContainsString('bar', $out);
    }

    // --- Color ----------------------------------------------------------

    public function testColorEquals(): void
    {
        $a = new Color(255, 0, 128);
        $b = new Color(255, 0, 128);
        $c = new Color(255, 0, 129);
        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function testColorToHex(): void
    {
        $this->assertSame('#ff0080', (new Color(255, 0, 128))->toHex());
    }

    public function testColorToAnsi16IndexMatchesNearestStandard(): void
    {
        // Pure red should land on ANSI 9 (bright red) or 1 (red).
        $idx = (new Color(255, 0, 0))->toAnsi16Index();
        $this->assertContains($idx, [1, 9]);
    }

    public function testColorAnsi16ForegroundEscape(): void
    {
        $out = (new Color(255, 0, 0))->toAnsi16Foreground();
        $this->assertStringContainsString("\x1b[", $out);
        $this->assertStringContainsString('m', $out);
    }

    public function testColorAnsi256ForegroundEscape(): void
    {
        $out = (new Color(0, 0, 255))->toAnsi256Foreground();
        $this->assertStringStartsWith("\x1b[38;5;", $out);
    }

    public function testColorBackgroundEscapes(): void
    {
        $out = (new Color(0, 255, 0))->toAnsiBackground();
        $this->assertStringStartsWith("\x1b[48;", $out);
    }
}
