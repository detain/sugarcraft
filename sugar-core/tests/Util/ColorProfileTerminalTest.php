<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\ColorProfile;

/**
 * detectTerminal() must give the same full-screen veto whether the legacy
 * ladder (NoTty) or sugar-colorprofile's chain (Ascii) answered dumb.
 */
final class ColorProfileTerminalTest extends TestCase
{
    public function testDumbTerminalIsNoTtyOnEitherLadder(): void
    {
        $this->assertSame(ColorProfile::NoTty, ColorProfile::detectTerminal(['TERM' => 'dumb']));
        $this->assertSame(ColorProfile::NoTty, ColorProfile::detectTerminal(['TERM' => 'DUMB']));
    }

    public function testForceLiftsDumbAboveTheVeto(): void
    {
        $this->assertTrue(ColorProfile::detectTerminal(['TERM' => 'dumb', 'FORCE_COLOR' => '1'])->supportsAnsi());
        $this->assertNotSame(ColorProfile::NoTty, ColorProfile::detectTerminal(['TERM' => 'dumb', 'CLICOLOR_FORCE' => '1']));
    }

    public function testNonDumbAnswersAreUntouched(): void
    {
        $this->assertSame(ColorProfile::TrueColor, ColorProfile::detectTerminal(['TERM' => 'xterm', 'COLORTERM' => 'truecolor']));
        $this->assertSame(ColorProfile::Ascii, ColorProfile::detectTerminal(['TERM' => 'xterm', 'NO_COLOR' => '1']));
        $this->assertSame(ColorProfile::Ascii, ColorProfile::detectTerminal(['TERM' => 'vt100-unknown']));
    }

    public function testNonTtyStdoutIsStillNoTty(): void
    {
        $f = fopen('php://memory', 'r+');
        $this->assertSame(ColorProfile::NoTty, ColorProfile::detectTerminal(['TERM' => 'xterm-256color'], $f));
        fclose($f);
    }
}
