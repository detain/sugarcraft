<?php

declare(strict_types=1);

namespace SugarCraft\Freeze\Tests;

use SugarCraft\Freeze\Theme;
use SugarCraft\Freeze\WindowStyle;
use PHPUnit\Framework\TestCase;

final class ThemeTest extends TestCase
{
    public function testDarkThemeHasCorrectBackground(): void
    {
        $theme = Theme::dark();
        $this->assertSame('#0d1117', $theme->background);
    }

    public function testDarkThemeHasCorrectForeground(): void
    {
        $theme = Theme::dark();
        $this->assertSame('#c9d1d9', $theme->foreground);
    }

    public function testDarkThemeHasCorrectBorder(): void
    {
        $theme = Theme::dark();
        $this->assertSame('#30363d', $theme->border);
    }

    public function testDarkThemeHasCorrectShadow(): void
    {
        $theme = Theme::dark();
        $this->assertSame('rgba(0, 0, 0, 0.5)', $theme->shadow);
    }

    public function testDarkThemeHasCorrectLineNumber(): void
    {
        $theme = Theme::dark();
        $this->assertSame('#6e7681', $theme->lineNumber);
    }

    public function testDarkThemeHasCorrectWindowColors(): void
    {
        $theme = Theme::dark();
        $this->assertSame('#ff5f56', $theme->windowRed);
        $this->assertSame('#ffbd2e', $theme->windowYellow);
        $this->assertSame('#27c93f', $theme->windowGreen);
    }

    public function testDarkThemeHasDefaultFontFamily(): void
    {
        $theme = Theme::dark();
        $this->assertSame('Hack, "JetBrains Mono", Menlo, Consolas, monospace', $theme->fontFamily);
    }

    public function testDarkThemeHasDefaultFontSize(): void
    {
        $theme = Theme::dark();
        $this->assertSame(14.0, $theme->fontSize);
    }

    public function testDarkThemeHasDefaultLineHeight(): void
    {
        $theme = Theme::dark();
        $this->assertSame(1.4, $theme->lineHeight);
    }

    public function testDarkThemeHasMacosWindowStyle(): void
    {
        $theme = Theme::dark();
        $this->assertSame(WindowStyle::Macos, $theme->windowStyle);
    }

    public function testLightThemeHasCorrectColors(): void
    {
        $theme = Theme::light();
        $this->assertSame('#f6f8fa', $theme->background);
        $this->assertSame('#24292f', $theme->foreground);
        $this->assertSame('#d0d7de', $theme->border);
        $this->assertSame('rgba(0, 0, 0, 0.15)', $theme->shadow);
        $this->assertSame('#8c959f', $theme->lineNumber);
        $this->assertSame('#ff5f56', $theme->windowRed);
        $this->assertSame('#ffbd2e', $theme->windowYellow);
        $this->assertSame('#27c93f', $theme->windowGreen);
    }

    public function testDraculaThemeUsesDraculaPalette(): void
    {
        $theme = Theme::dracula();
        $this->assertSame('#282a36', $theme->background);
        $this->assertSame('#f8f8f2', $theme->foreground);
        $this->assertSame('#44475a', $theme->border);
        $this->assertSame('#ff5555', $theme->windowRed);
        $this->assertSame('#f1fa8c', $theme->windowYellow);
        $this->assertSame('#50fa7b', $theme->windowGreen);
    }

    public function testTokyoNightThemeHasCorrectColors(): void
    {
        $theme = Theme::tokyoNight();
        $this->assertSame('#1a1b26', $theme->background);
        $this->assertSame('#a9b1d6', $theme->foreground);
        $this->assertSame('#414868', $theme->border);
        $this->assertSame('rgba(0, 0, 0, 0.5)', $theme->shadow);
        $this->assertSame('#565f89', $theme->lineNumber);
        $this->assertSame('#f7768e', $theme->windowRed);
        $this->assertSame('#e0af68', $theme->windowYellow);
        $this->assertSame('#9ece6a', $theme->windowGreen);
    }

    public function testNordThemeHasCorrectColors(): void
    {
        $theme = Theme::nord();
        $this->assertSame('#2e3440', $theme->background);
        $this->assertSame('#d8dee9', $theme->foreground);
        $this->assertSame('#4c566a', $theme->border);
        $this->assertSame('rgba(0, 0, 0, 0.4)', $theme->shadow);
        $this->assertSame('#4c566a', $theme->lineNumber);
        $this->assertSame('#bf616a', $theme->windowRed);
        $this->assertSame('#ebcb8b', $theme->windowYellow);
        $this->assertSame('#a3be8c', $theme->windowGreen);
    }

    public function testAllPresetsHaveReadonlyProperties(): void
    {
        $reflection = new \ReflectionClass(Theme::class);
        foreach (['background', 'foreground', 'border', 'shadow', 'lineNumber',
                     'windowRed', 'windowYellow', 'windowGreen',
                     'fontFamily', 'fontSize', 'lineHeight', 'windowStyle'] as $prop) {
            $property = $reflection->getProperty($prop);
            $this->assertTrue($property->isReadOnly(), "Property {$prop} should be readonly");
        }
    }
}
