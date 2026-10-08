<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Theme;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\Theme\TtyTheme;

final class TtyThemeTest extends TestCase
{
    public function testCoversExactlyTheDefaultKeySet(): void
    {
        self::assertSame(
            array_keys(ThemeConfig::DEFAULT_THEME),
            array_keys(TtyTheme::TTY_THEME),
        );
    }

    public function testVerbatimSgrAndPaletteSlots(): void
    {
        $t = TtyTheme::new();
        self::assertSame('TTY', $t->name());
        self::assertSame("\x1b[0;40m", $t->sgr('main_bg'));
        self::assertSame(0, $t->color('main_bg')?->ansiIndex);
        self::assertSame(7, $t->color('main_fg')?->ansiIndex);
        self::assertSame(15, $t->color('title')?->ansiIndex);
        self::assertSame(9, $t->color('hi_fg')?->ansiIndex);
        self::assertSame(1, $t->color('selected_bg')?->ansiIndex);
        self::assertSame(8, $t->color('inactive_fg')?->ansiIndex);
        self::assertSame(4, $t->color('followed_bg')?->ansiIndex);
        self::assertNull($t->color('free_mid'));
        self::assertSame('', $t->sgr('free_mid'));
    }

    public function testThreeBandStepWithMid(): void
    {
        $t = TtyTheme::new();
        // cpu: 92 (slot 10) / 93 (11) / 91 (9); bands 0-33, 34-66, 67-100.
        foreach ([0 => 10, 33 => 10, 34 => 11, 66 => 11, 67 => 9, 100 => 9] as $p => $slot) {
            self::assertSame($slot, $t->at('cpu', $p)?->ansiIndex, "cpu@$p");
        }
    }

    public function testTwoBandStepWithoutMid(): void
    {
        $t = TtyTheme::new();
        // free: 32 (slot 2) / 92 (10); bands 0-50, 51-100.
        foreach ([0 => 2, 50 => 2, 51 => 10, 100 => 10] as $p => $slot) {
            self::assertSame($slot, $t->at('free', $p)?->ansiIndex, "free@$p");
        }
        self::assertSame(2, $t->at('free', -10)?->ansiIndex, 'clamped');
        self::assertSame(10, $t->at('free', 900)?->ansiIndex, 'clamped');
    }

    public function testOnlyTheNineFamiliesHaveGradients(): void
    {
        $t = TtyTheme::new();
        $names = $t->gradientNames();
        sort($names);
        $families = ThemeConfig::FAMILIES;
        sort($families);
        self::assertSame($families, $names);
        self::assertFalse($t->hasGradient('proc'));
        self::assertCount(101, $t->gradient('upload'));
        $this->expectException(\OutOfBoundsException::class);
        $t->at('proc_color', 10);
    }

    public function testThemeBackgroundOff(): void
    {
        $t = TtyTheme::new()->withThemeBackground(false);
        self::assertSame(TtyTheme::DEFAULT_BG, $t->sgr('main_bg'));
        self::assertNull($t->color('main_bg'));
        self::assertFalse($t->themeBackground());
        self::assertTrue(TtyTheme::new()->themeBackground());
    }

    public function testToColorMapping(): void
    {
        self::assertSame(3, TtyTheme::toColor("\x1b[43m")?->ansiIndex);
        self::assertSame(13, TtyTheme::toColor("\x1b[105m")?->ansiIndex);
        self::assertNull(TtyTheme::toColor("\x1b[49m"));
        self::assertNull(TtyTheme::toColor(''));
    }

    public function testUnknownKeyThrows(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        TtyTheme::new()->color('nope');
    }
}
