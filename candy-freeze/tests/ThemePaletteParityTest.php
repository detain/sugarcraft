<?php

declare(strict_types=1);

namespace SugarCraft\Freeze\Tests;

use SugarCraft\Core\Util\Palettes;
use SugarCraft\Freeze\Theme;
use PHPUnit\Framework\TestCase;

/**
 * Guards that {@see Theme::dracula()} stays byte-identical to the
 * candy-core {@see Palettes::DRACULA} single-source-of-truth it is
 * sourced from, so the hex literals can never silently drift apart.
 */
final class ThemePaletteParityTest extends TestCase
{
    public function testDraculaChromeMatchesCorePalette(): void
    {
        $theme = Theme::dracula();

        $this->assertSame(Palettes::DRACULA['background'], $theme->background);
        $this->assertSame(Palettes::DRACULA['foreground'], $theme->foreground);
        $this->assertSame(Palettes::DRACULA['currentLine'], $theme->border);
        $this->assertSame(Palettes::DRACULA['comment'], $theme->lineNumber);
        $this->assertSame(Palettes::DRACULA['red'], $theme->windowRed);
        // Freeze deliberately binds Dracula 'yellow' (not 'orange') to the button.
        $this->assertSame(Palettes::DRACULA['yellow'], $theme->windowYellow);
        $this->assertSame(Palettes::DRACULA['green'], $theme->windowGreen);
    }

    public function testDraculaShadowStaysLiteral(): void
    {
        // Shadow is an rgba() compositing value, not a palette colour.
        $this->assertSame('rgba(0, 0, 0, 0.5)', Theme::dracula()->shadow);
    }

    public function testDraculaHexValuesUnchanged(): void
    {
        $theme = Theme::dracula();

        // Byte-identical regression pins against the pre-refactor literals.
        $this->assertSame('#282a36', $theme->background);
        $this->assertSame('#f8f8f2', $theme->foreground);
        $this->assertSame('#44475a', $theme->border);
        $this->assertSame('#6272a4', $theme->lineNumber);
        $this->assertSame('#ff5555', $theme->windowRed);
        $this->assertSame('#f1fa8c', $theme->windowYellow);
        $this->assertSame('#50fa7b', $theme->windowGreen);
    }
}
