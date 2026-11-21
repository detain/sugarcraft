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
     * A force variable overrules the dumb veto whether or not the chain is
     * installed. The veto must not depend on the sibling set: the launcher's
     * `TERM=dumb CLICOLOR_FORCE=1` picker keeps its rich frame either way.
     */
    public function testForceLiftsDumbAboveTheVeto(): void
    {
        $this->assertNotSame(ColorProfile::NoTty, ColorProfile::detectTerminal(['TERM' => 'dumb', 'FORCE_COLOR' => '1']));
        $this->assertNotSame(ColorProfile::NoTty, ColorProfile::detectTerminal(['TERM' => 'dumb', 'CLICOLOR_FORCE' => '1']));
        if (self::chainPresent()) {
            $this->assertTrue(ColorProfile::detectTerminal(['TERM' => 'dumb', 'FORCE_COLOR' => '1'])->supportsAnsi());
        }
    }

    /** A zero or empty force value is a colour-off word, not a force: the veto holds. */
    public function testZeroOrEmptyForceDoesNotLiftTheVeto(): void
    {
        $this->assertSame(ColorProfile::NoTty, ColorProfile::detectTerminal(['TERM' => 'dumb', 'CLICOLOR_FORCE' => '0']));
        $this->assertSame(ColorProfile::NoTty, ColorProfile::detectTerminal(['TERM' => 'dumb', 'FORCE_COLOR' => '0']));
        $this->assertSame(ColorProfile::NoTty, ColorProfile::detectTerminal(['TERM' => 'dumb', 'CLICOLOR_FORCE' => '']));
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
