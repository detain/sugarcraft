<?php

declare(strict_types=1);

namespace SugarCraft\Palette\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Palette\Color;
use SugarCraft\Palette\Palette;
use SugarCraft\Palette\Profile;

/**
 * Byte-exact degrade() / rewriteAnsi tests per target profile.
 */
final class DegradeTest extends TestCase
{
    public function testDegradeTrueColorToANSI256EmitsCorrectBytes(): void
    {
        $p = (new Palette(env: ['FORCE_COLOR' => '2']))->withProfile(Profile::ANSI256);
        $input = "\x1b[38;2;100;50;255mX\x1b[0m";
        $out = $p->degrade($input);

        $this->assertStringStartsWith("\x1b[38;5;", $out);
        $this->assertStringEndsWith("mX\x1b[0m", $out);
        $this->assertStringNotContainsString("\x1b[\x1b[", $out);
    }

    public function testDegradeTrueColorToANSIEmits4BitSGR(): void
    {
        $p = (new Palette(env: ['FORCE_COLOR' => '1']))->withProfile(Profile::ANSI);
        $input = "\x1b[38;2;100;50;255mX\x1b[0m";
        $out = $p->degrade($input);

        $this->assertStringStartsWith("\x1b[3", $out);
        $this->assertStringNotContainsString("\x1b[38;5;", $out);
        $this->assertStringNotContainsString("\x1b[\x1b[", $out);
    }

    public function testDegradeTrueColorBackgroundToANSIEmits4BitSGR(): void
    {
        $p = (new Palette(env: ['FORCE_COLOR' => '1']))->withProfile(Profile::ANSI);
        $input = "\x1b[48;2;255;0;0mX\x1b[0m";
        $out = $p->degrade($input);

        $this->assertStringStartsWith("\x1b[4", $out);
        $this->assertStringNotContainsString("\x1b[48;5;", $out);
        $this->assertStringNotContainsString("\x1b[\x1b[", $out);
    }

    public function testDegradeTrueColorToAsciiEmits4BitSGR(): void
    {
        $p = (new Palette(env: ['FORCE_COLOR' => '0']))->withProfile(Profile::Ascii);
        $input = "\x1b[38;2;0;0;0mX\x1b[0m";
        $out = $p->degrade($input);

        $this->assertStringStartsWith("\x1b[3", $out);
        $this->assertStringNotContainsString("\x1b[38;5;", $out);
        $this->assertStringNotContainsString("\x1b[\x1b[", $out);
    }

    public function testDegrade256ColorInputToANSIEmits4BitSGR(): void
    {
        $p = (new Palette(env: ['FORCE_COLOR' => '1']))->withProfile(Profile::ANSI);
        $input = "\x1b[38;5;196mX\x1b[0m";
        $out = $p->degrade($input);

        $this->assertStringStartsWith("\x1b[3", $out);
        $this->assertStringNotContainsString("\x1b[38;5;196", $out);
        $this->assertStringNotContainsString("\x1b[\x1b[", $out);
    }

    public function testDegrade256ColorInputToANSI256IsUnchanged(): void
    {
        $p = (new Palette(env: ['FORCE_COLOR' => '2']))->withProfile(Profile::ANSI256);
        $input = "\x1b[38;5;196mX\x1b[0m";
        $out = $p->degrade($input);

        $this->assertSame($input, $out);
        $this->assertStringNotContainsString("\x1b[\x1b[", $out);
    }

    public function testNoDoubledEscapedPrefix(): void
    {
        $p = (new Palette(env: ['FORCE_COLOR' => '1']))->withProfile(Profile::ANSI);
        $input = "\x1b[38;2;100;50;255mX\x1b[0m";
        $out = $p->degrade($input);

        $this->assertStringNotContainsString("\x1b[\x1b[", $out);
    }

    public function testDegradeTrueColorToANSI256BackgroundEmits256Index(): void
    {
        $p = (new Palette(env: ['FORCE_COLOR' => '2']))->withProfile(Profile::ANSI256);
        $input = "\x1b[48;2;0;0;255mX\x1b[0m";
        $out = $p->degrade($input);

        $this->assertStringStartsWith("\x1b[48;5;", $out);
        $this->assertStringNotContainsString("\x1b[\x1b[", $out);
    }

    public function testDegrade256ColorBackgroundToANSIEmits4BitSGR(): void
    {
        $p = (new Palette(env: ['FORCE_COLOR' => '1']))->withProfile(Profile::ANSI);
        $input = "\x1b[48;5;21mX\x1b[0m";
        $out = $p->degrade($input);

        $this->assertStringStartsWith("\x1b[4", $out);
        $this->assertStringNotContainsString("\x1b[48;5;", $out);
        $this->assertStringNotContainsString("\x1b[\x1b[", $out);
    }

    public function testDegradeTrueColorPassthroughForTrueColorProfile(): void
    {
        $p = (new Palette(env: ['FORCE_COLOR' => '3']))->withProfile(Profile::TrueColor);
        $input = "\x1b[38;2;100;50;255mX\x1b[0m";
        $out = $p->degrade($input);

        $this->assertSame($input, $out);
    }
}
