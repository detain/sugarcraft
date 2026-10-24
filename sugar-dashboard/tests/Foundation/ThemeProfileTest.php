<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Foundation;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Dashboard\Foundation\Theme;

/**
 * Theme::fg()/bg() route through the SAME detected capability as the braille
 * plot renderers — NO_COLOR must silence them, and an explicit profile must
 * still win over detection.
 */
final class ThemeProfileTest extends TestCase
{
    public function testNoColorSilencesForegroundAndBackground(): void
    {
        $prior = getenv('NO_COLOR');
        putenv('NO_COLOR=1');
        try {
            $theme = Theme::dark();
            $this->assertSame('', $theme->fg());
            $this->assertSame('', $theme->bg());
        } finally {
            putenv($prior === false ? 'NO_COLOR' : 'NO_COLOR=' . $prior);
        }
    }

    public function testDefaultProfileIsTheDetectedProfile(): void
    {
        $theme = Theme::dark();
        $detected = ColorProfile::detect();

        $this->assertSame($theme->fg($detected), $theme->fg());
        $this->assertSame($theme->bg($detected), $theme->bg());
    }

    public function testExplicitTrueColorProfileStillEmits24Bit(): void
    {
        $theme = Theme::dark();

        $this->assertStringContainsString('38;2;', $theme->fg(ColorProfile::TrueColor));
        $this->assertStringContainsString('48;2;', $theme->bg(ColorProfile::TrueColor));
    }
}
