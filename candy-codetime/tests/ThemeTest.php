<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Style\Theme as StyleTheme;
use SugarCraft\CodeTime\Theme;

final class ThemeTest extends TestCase
{
    public function testDark(): void
    {
        $theme = Theme::dark();
        $inner = $theme->inner();
        $this->assertInstanceOf(StyleTheme::class, $inner);
    }

    public function testLight(): void
    {
        $theme = Theme::light();
        $inner = $theme->inner();
        $this->assertInstanceOf(StyleTheme::class, $inner);
    }

    public function testDefaultIsDark(): void
    {
        $theme = new Theme();
        $this->assertInstanceOf(StyleTheme::class, $theme->inner());
    }

    public function testInnerMethod(): void
    {
        $sprinklesTheme = StyleTheme::dracula();
        $theme = new Theme($sprinklesTheme);
        $this->assertSame($sprinklesTheme, $theme->inner());
    }

    public function testDarkAndLightReturnDistinctThemes(): void
    {
        $dark = Theme::dark()->inner();
        $light = Theme::light()->inner();

        // They have different background colours
        $darkBg = $dark->background;
        $lightBg = $light->background;
        $this->assertNotSame(
            $darkBg->r . '-' . $darkBg->g . '-' . $darkBg->b,
            $lightBg->r . '-' . $lightBg->g . '-' . $lightBg->b,
        );
    }

    public function testInnerIsConsumedFromSprinkles(): void
    {
        $sprinkles = StyleTheme::tokyoNight();
        $theme = new Theme($sprinkles);
        $this->assertSame($sprinkles, $theme->inner());
    }
}
