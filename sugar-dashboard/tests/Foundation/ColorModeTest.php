<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Foundation;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Dashboard\Foundation\ColorMode;

/**
 * Pins for the NO_COLOR / capability chain behind ColorMode::profile().
 *
 * Before this class existed, every renderer hardcoded ColorProfile::TrueColor
 * (finding A3): these pins are the red-then-green discriminators — under the
 * old code NO_COLOR and TERM=dumb changed nothing, and there was no override
 * seam at all.
 */
final class ColorModeTest extends TestCase
{
    private string|false $savedNoColor;

    private string|false $savedTerm;

    protected function setUp(): void
    {
        $this->savedNoColor = getenv('NO_COLOR');
        $this->savedTerm = getenv('TERM');
        putenv('NO_COLOR');
        putenv('TERM=xterm-256color');
        ColorMode::resetForTesting();
    }

    protected function tearDown(): void
    {
        ColorMode::resetForTesting();

        if ($this->savedNoColor === false) {
            putenv('NO_COLOR');
        } else {
            putenv('NO_COLOR=' . $this->savedNoColor);
        }
        if ($this->savedTerm === false) {
            putenv('TERM');
        } else {
            putenv('TERM=' . $this->savedTerm);
        }
    }

    public function testDefaultCapabilityIsTrueColor(): void
    {
        self::assertSame(ColorProfile::TrueColor, ColorMode::profile());
    }

    public function testNoColorOfAnyNonEmptyValueDisablesColor(): void
    {
        putenv('NO_COLOR=1');
        ColorMode::resetForTesting();
        self::assertSame(ColorProfile::Ascii, ColorMode::profile());

        // The spec is presence-with-a-value, not truthiness: "0" still disables.
        putenv('NO_COLOR=0');
        ColorMode::resetForTesting();
        self::assertSame(ColorProfile::Ascii, ColorMode::profile());
    }

    public function testEmptyNoColorDoesNotDisableColor(): void
    {
        putenv('NO_COLOR=');
        ColorMode::resetForTesting();
        self::assertSame(ColorProfile::TrueColor, ColorMode::profile());
    }

    public function testDumbTerminalDisablesColor(): void
    {
        putenv('TERM=dumb');
        ColorMode::resetForTesting();
        self::assertSame(ColorProfile::Ascii, ColorMode::profile());
    }

    public function testExplicitOverrideBeatsTheEnvironment(): void
    {
        putenv('NO_COLOR=1');
        ColorMode::use(ColorProfile::Ansi256);
        self::assertSame(ColorProfile::Ansi256, ColorMode::profile());

        ColorMode::use(null);
        self::assertSame(ColorProfile::Ascii, ColorMode::profile());
    }

    public function testAnswerIsMemoisedUntilReset(): void
    {
        self::assertSame(ColorProfile::TrueColor, ColorMode::profile());
        putenv('NO_COLOR=1');
        self::assertSame(ColorProfile::TrueColor, ColorMode::profile(), 'mid-process env flips must not desync repaints');
        ColorMode::resetForTesting();
        self::assertSame(ColorProfile::Ascii, ColorMode::profile());
    }

    public function testEgressActuallyDegradesUnderNoColor(): void
    {
        $orange = Color::hex('#ff8000');
        self::assertStringContainsString('38;2;', $orange->toFg(ColorMode::profile()));

        putenv('NO_COLOR=1');
        ColorMode::resetForTesting();
        self::assertStringNotContainsString('38;2;', $orange->toFg(ColorMode::profile()));
    }
}
