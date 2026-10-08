<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\View;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\Theme\TtyTheme;
use SugarCraft\Top\View\Ink;

final class InkTest extends TestCase
{
    public function testTruecolorDefaultTheme(): void
    {
        $ink = Ink::new(ThemeConfig::new());
        // cpu_box #556d59, main_bg #00 gray, main_fg #cc gray.
        $this->assertSame("\x1b[38;2;85;109;89m", $ink->fg('cpu_box'));
        $this->assertSame("\x1b[48;2;0;0;0m\x1b[38;2;204;204;204m", $ink->base());
        $this->assertSame(ThemeConfig::new()->at('cpu', 50)?->toFg(ColorProfile::TrueColor), $ink->gradient('cpu', 50));
    }

    public function testTtyThemeEmitsPaletteSlots(): void
    {
        $ink = Ink::new(TtyTheme::new(), ColorProfile::Ansi);
        $this->assertSame("\x1b[32m", $ink->fg('cpu_box'));
        $this->assertSame("\x1b[91m", $ink->fg('hi_fg'));
        $this->assertSame("\x1b[40m\x1b[37m", $ink->base());
    }

    public function testThemeBackgroundOffLeavesTerminalBackground(): void
    {
        $ink = Ink::new(ThemeConfig::new()->withThemeBackground(false));
        $this->assertSame("\x1b[38;2;204;204;204m", $ink->base());
    }

    public function testUnknownKeyThrows(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        Ink::new(ThemeConfig::new())->fg('nope');
    }
}
