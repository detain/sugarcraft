<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use SugarCraft\Crush\Theme;
use PHPUnit\Framework\TestCase;

final class ThemeTest extends TestCase
{
    public function testByNameResolvesEveryAdvertisedName(): void
    {
        foreach (Theme::names() as $name) {
            $theme = Theme::byName($name);
            $this->assertSame($name, $theme->name);
        }
    }

    public function testByNameIsCaseInsensitive(): void
    {
        $this->assertSame('tokyoNight', Theme::byName('tokyonight')->name);
        $this->assertSame('dark', Theme::byName('DARK')->name);
    }

    public function testByNameThrowsOnUnknownName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown theme/');
        Theme::byName('nonexistent');
    }

    public function testDefaultIsDark(): void
    {
        $this->assertSame('dark', Theme::default()->name);
    }

    public function testDistinctThemesHaveDistinctColors(): void
    {
        $dark = Theme::byName('dark');
        $light = Theme::byName('light');
        $this->assertNotSame($dark->border->toHex(), $light->border->toHex());
    }
}
