<?php

declare(strict_types=1);

namespace CandyCore\Core\Tests\Util;

use CandyCore\Core\Util\ColorProfile;
use PHPUnit\Framework\TestCase;

final class ColorProfileTest extends TestCase
{
    public function testDumbTermIsAscii(): void
    {
        $this->assertSame(ColorProfile::Ascii, ColorProfile::detect(['TERM' => 'dumb']));
    }

    public function testEmptyTermIsAscii(): void
    {
        $this->assertSame(ColorProfile::Ascii, ColorProfile::detect([]));
    }

    public function testNoColorOverridesEverything(): void
    {
        $this->assertSame(
            ColorProfile::Ascii,
            ColorProfile::detect(['NO_COLOR' => '1', 'COLORTERM' => 'truecolor', 'TERM' => 'xterm-256color']),
        );
    }

    public function testColorTermTruecolor(): void
    {
        $this->assertSame(
            ColorProfile::TrueColor,
            ColorProfile::detect(['COLORTERM' => 'truecolor', 'TERM' => 'xterm']),
        );
    }

    public function testTerm256(): void
    {
        $this->assertSame(
            ColorProfile::Ansi256,
            ColorProfile::detect(['TERM' => 'xterm-256color']),
        );
    }

    public function testPlainXtermIsAnsi16(): void
    {
        $this->assertSame(ColorProfile::Ansi, ColorProfile::detect(['TERM' => 'xterm']));
    }

    public function testCapabilityHelpers(): void
    {
        $this->assertTrue(ColorProfile::TrueColor->supportsTrueColor());
        $this->assertTrue(ColorProfile::TrueColor->supports256());
        $this->assertTrue(ColorProfile::TrueColor->supportsAnsi());

        $this->assertFalse(ColorProfile::Ansi->supportsTrueColor());
        $this->assertFalse(ColorProfile::Ansi->supports256());
        $this->assertTrue(ColorProfile::Ansi->supportsAnsi());

        $this->assertFalse(ColorProfile::Ascii->supportsAnsi());
    }
}
