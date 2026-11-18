<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\ColorProfile;

/**
 * detectTerminal() must give the same full-screen veto whether sugar-colorprofile's
 * chain answers dumb as Ascii or, with no chain, colour off answers Ascii too.
 */
final class ColorProfileTerminalTest extends TestCase
{
    private static function chainPresent(): bool
    {
        return class_exists(\SugarCraft\ColorProfile\DetectionChain::class);
    }

    public function testDumbTerminalIsNoTty(): void
    {
        $this->assertSame(ColorProfile::NoTty, ColorProfile::detectTerminal(['TERM' => 'dumb']));
        $this->assertSame(ColorProfile::NoTty, ColorProfile::detectTerminal(['TERM' => 'DUMB']));
    }

    /**
     * With the chain, a force variable lifts dumb above the veto. With no
     * chain nothing lifts Ascii, so the veto applies (declared, P1-10a).
     */
    public function testForceLiftsDumbAboveTheVeto(): void
    {
        $forced = ['TERM' => 'dumb', 'FORCE_COLOR' => '1'];
        if (self::chainPresent()) {
            $this->assertTrue(ColorProfile::detectTerminal($forced)->supportsAnsi());
            $this->assertNotSame(ColorProfile::NoTty, ColorProfile::detectTerminal(['TERM' => 'dumb', 'CLICOLOR_FORCE' => '1']));
        } else {
            $this->assertSame(ColorProfile::NoTty, ColorProfile::detectTerminal($forced));
        }
    }

    /** For a non-dumb terminal the veto changes nothing: detectTerminal() equals detect(). */
    public function testNonDumbAnswersAreUntouched(): void
    {
        foreach ([
            ['TERM' => 'xterm', 'COLORTERM' => 'truecolor'],
            ['TERM' => 'vt100-unknown'],
        ] as $env) {
            $this->assertSame(ColorProfile::detect($env), ColorProfile::detectTerminal($env), json_encode($env));
        }
        $this->assertSame(ColorProfile::Ascii, ColorProfile::detectTerminal(['TERM' => 'xterm', 'NO_COLOR' => '1']));
    }

    public function testNonTtyStdoutIsStillNoTty(): void
    {
        $f = fopen('php://memory', 'r+');
        $this->assertSame(ColorProfile::NoTty, ColorProfile::detectTerminal(['TERM' => 'xterm-256color'], $f));
        fclose($f);
    }
}
