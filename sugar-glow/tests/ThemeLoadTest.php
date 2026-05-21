<?php

declare(strict_types=1);

namespace SugarCraft\Glow\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Shine\Theme;

final class ThemeLoadTest extends TestCase
{
    public function testSolarizedThemeLoads(): void
    {
        $theme = Theme::fromJson(__DIR__ . '/../themes/solarized.json');
        $this->assertInstanceOf(Theme::class, $theme);
    }

    public function testMonokaiThemeLoads(): void
    {
        $theme = Theme::fromJson(__DIR__ . '/../themes/monokai.json');
        $this->assertInstanceOf(Theme::class, $theme);
    }

    public function testGitHubThemeLoads(): void
    {
        $theme = Theme::fromJson(__DIR__ . '/../themes/github.json');
        $this->assertInstanceOf(Theme::class, $theme);
    }
}
