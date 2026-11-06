<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Foundation;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Dashboard\Foundation\ColorMode;
use SugarCraft\Dashboard\Foundation\Theme;

/**
 * Theme::fg()/bg() route through the SAME process ColorMode capability as
 * every other renderer in the library (finding M-7: they used to detect on
 * their own and split-brain against the funnel) — NO_COLOR must silence
 * them, and an explicit profile must still win over the funnel.
 */
final class ThemeProfileTest extends TestCase
{
    public function testNoColorSilencesForegroundAndBackground(): void
    {
        $prior = getenv('NO_COLOR');
        putenv('NO_COLOR=1');
        ColorMode::resetForTesting();
        try {
            $theme = Theme::dark();
            $this->assertSame('', $theme->fg());
            $this->assertSame('', $theme->bg());
        } finally {
            putenv($prior === false ? 'NO_COLOR' : 'NO_COLOR=' . $prior);
            ColorMode::resetForTesting();
        }
    }

    public function testDefaultProfileIsTheDetectedProfile(): void
    {
        $theme = Theme::dark();
        ColorMode::resetForTesting();
        $detected = ColorMode::profile();

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
