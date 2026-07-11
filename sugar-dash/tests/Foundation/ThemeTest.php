<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Foundation;

use SugarCraft\Dash\Foundation\Theme;
use SugarCraft\Dash\Plot\Chart\Bar;
use SugarCraft\Dash\Components\Card\Text;
use SugarCraft\Dash\Layout\HAlign;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Palettes;
use PHPUnit\Framework\TestCase;

final class ThemeTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Factory methods
    // ═══════════════════════════════════════════════════════════════

    public function testDarkTheme(): void
    {
        $theme = Theme::dark();
        $this->assertSame('dark', $theme->getName());
    }

    public function testDraculaTheme(): void
    {
        $theme = Theme::dracula();
        $this->assertSame('dracula', $theme->getName());
    }

    public function testOneDarkTheme(): void
    {
        $theme = Theme::oneDark();
        $this->assertSame('one-dark', $theme->getName());
    }

    public function testGitHubDarkTheme(): void
    {
        $theme = Theme::githubDark();
        $this->assertSame('github-dark', $theme->getName());
    }

    public function testLightTheme(): void
    {
        $theme = Theme::light();
        $this->assertSame('light', $theme->getName());
    }

    // ═══════════════════════════════════════════════════════════════
    // Color accessors
    // ═══════════════════════════════════════════════════════════════

    public function testForeground(): void
    {
        $theme = Theme::dark();
        $this->assertInstanceOf(Color::class, $theme->foreground());
    }

    public function testBackground(): void
    {
        $theme = Theme::dark();
        $this->assertInstanceOf(Color::class, $theme->background());
    }

    public function testPrimary(): void
    {
        $theme = Theme::dark();
        $this->assertInstanceOf(Color::class, $theme->primary());
    }

    public function testSecondary(): void
    {
        $theme = Theme::dark();
        $this->assertInstanceOf(Color::class, $theme->secondary());
    }

    public function testAccent(): void
    {
        $theme = Theme::dark();
        $this->assertInstanceOf(Color::class, $theme->accent());
    }

    public function testError(): void
    {
        $theme = Theme::dark();
        $this->assertInstanceOf(Color::class, $theme->error());
    }

    public function testWarning(): void
    {
        $theme = Theme::dark();
        $this->assertInstanceOf(Color::class, $theme->warning());
    }

    public function testSuccess(): void
    {
        $theme = Theme::dark();
        $this->assertInstanceOf(Color::class, $theme->success());
    }

    public function testHighlight(): void
    {
        $theme = Theme::dark();
        $this->assertInstanceOf(Color::class, $theme->highlight());
    }

    // ═══════════════════════════════════════════════════════════════
    // Color lookup
    // ═══════════════════════════════════════════════════════════════

    public function testColorByName(): void
    {
        $theme = Theme::dark();
        $fg = $theme->color('foreground');
        $bg = $theme->color('background');
        $primary = $theme->color('primary');

        $this->assertInstanceOf(Color::class, $fg);
        $this->assertInstanceOf(Color::class, $bg);
        $this->assertInstanceOf(Color::class, $primary);
    }

    public function testColorByShortName(): void
    {
        $theme = Theme::dark();
        $fg = $theme->color('fg');
        $bg = $theme->color('bg');

        $this->assertInstanceOf(Color::class, $fg);
        $this->assertInstanceOf(Color::class, $bg);
    }

    public function testColorUnknownReturnsNull(): void
    {
        $theme = Theme::dark();
        $unknown = $theme->color('unknown-color');

        $this->assertNull($unknown);
    }

    // ═══════════════════════════════════════════════════════════════
    // Component factories
    // ═══════════════════════════════════════════════════════════════

    public function testBar(): void
    {
        $theme = Theme::dark();
        $bar = $theme->bar('Status');

        $this->assertInstanceOf(Bar::class, $bar);
        $this->assertStringContainsString('Status', $bar->render());
    }

    public function testBarWithAlignment(): void
    {
        $theme = Theme::dark();
        $bar = $theme->bar('Centered', HAlign::Center);

        $this->assertInstanceOf(Bar::class, $bar);
    }

    public function testText(): void
    {
        $theme = Theme::dark();
        $text = $theme->text('Hello');

        $this->assertInstanceOf(Text::class, $text);
    }

    public function testTextWithAlignment(): void
    {
        $theme = Theme::dark();
        $text = $theme->text('Right', HAlign::Right);

        $this->assertInstanceOf(Text::class, $text);
    }

    // ═══════════════════════════════════════════════════════════════
    // ANSI sequences
    // ═══════════════════════════════════════════════════════════════

    public function testFgReturnsAnsiSequence(): void
    {
        $theme = Theme::dark();
        $fg = $theme->fg();

        $this->assertMatchesRegularExpression('/\x1b\[/', $fg);
    }

    public function testBgReturnsAnsiSequence(): void
    {
        $theme = Theme::dark();
        $bg = $theme->bg();

        $this->assertMatchesRegularExpression('/\x1b\[/', $bg);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers
    // ═══════════════════════════════════════════════════════════════

    public function testWithName(): void
    {
        $theme = Theme::dark();
        $modified = $theme->withName('custom');

        $this->assertSame('custom', $modified->getName());
        // Original should be unchanged
        $this->assertSame('dark', $theme->getName());
    }

    public function testWithForeground(): void
    {
        $theme = Theme::dark();
        $newColor = Color::hex('#FF0000');
        $modified = $theme->withForeground($newColor);

        $this->assertNotSame($theme, $modified);
        $this->assertSame($newColor, $modified->foreground());
    }

    public function testWithBackground(): void
    {
        $theme = Theme::dark();
        $newColor = Color::hex('#FFFFFF');
        $modified = $theme->withBackground($newColor);

        $this->assertNotSame($theme, $modified);
        $this->assertSame($newColor, $modified->background());
    }

    public function testWithPrimary(): void
    {
        $theme = Theme::dark();
        $newColor = Color::hex('#00FF00');
        $modified = $theme->withPrimary($newColor);

        $this->assertNotSame($theme, $modified);
        $this->assertSame($newColor, $modified->primary());
    }

    // ═══════════════════════════════════════════════════════════════
    // Immutability
    // ═══════════════════════════════════════════════════════════════

    public function testThemeIsImmutable(): void
    {
        $theme = Theme::dark();
        $original = $theme->withName('changed');

        // Original should be unchanged
        $this->assertSame('dark', $theme->getName());
        $this->assertNotSame($theme, $original);
    }

    // ═══════════════════════════════════════════════════════════════
    // Different themes have different colors
    // ═══════════════════════════════════════════════════════════════

    public function testDifferentThemesHaveDifferentColors(): void
    {
        $dark = Theme::dark();
        $light = Theme::light();

        // Backgrounds should be different
        $darkBg = $dark->background()->toHex();
        $lightBg = $light->background()->toHex();

        $this->assertNotSame($darkBg, $lightBg);
    }

    public function testAllFactoryMethodsReturnDifferentThemes(): void
    {
        $themes = [
            Theme::dark(),
            Theme::dracula(),
            Theme::oneDark(),
            Theme::githubDark(),
            Theme::light(),
        ];

        $names = array_map(fn($t) => $t->getName(), $themes);
        $uniqueNames = array_unique($names);

        $this->assertCount(count($themes), $uniqueNames);
    }

    // ═══════════════════════════════════════════════════════════════
    // Palettes SSOT parity (drift guard)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Each migrated slot must equal its candy-core {@see Palettes} source.
     * Compared via the rendered/normalised hex ({@see Color::toHex}) so the
     * cosmetic uppercase→lowercase change is transparent and the assertion
     * fails loudly if either the palette or the mapping ever drifts.
     *
     * @dataProvider paletteParityProvider
     */
    public function testFactoryReSourcesFromPalettesSsot(
        Theme $theme,
        string $slot,
        string $expectedHex,
    ): void {
        $color = $theme->color($slot);
        $this->assertInstanceOf(Color::class, $color);
        $this->assertSame(
            strtolower(Color::hex($expectedHex)->toHex()),
            strtolower($color->toHex()),
        );
    }

    /**
     * @return array<string, array{Theme, string, string}>
     */
    public static function paletteParityProvider(): array
    {
        $dracula = Palettes::DRACULA;
        $oneDark = Palettes::ONE_DARK;
        $github  = Palettes::GITHUB_DARK;

        return [
            // Dracula: primary/highlight→purple, secondary/success→green, accent→pink, warning→orange.
            'dracula fg'         => [Theme::dracula(), 'foreground', $dracula['foreground']],
            'dracula bg'         => [Theme::dracula(), 'background', $dracula['background']],
            'dracula primary'    => [Theme::dracula(), 'primary', $dracula['purple']],
            'dracula secondary'  => [Theme::dracula(), 'secondary', $dracula['green']],
            'dracula accent'     => [Theme::dracula(), 'accent', $dracula['pink']],
            'dracula error'      => [Theme::dracula(), 'error', $dracula['red']],
            'dracula warning'    => [Theme::dracula(), 'warning', $dracula['orange']],
            'dracula success'    => [Theme::dracula(), 'success', $dracula['green']],
            'dracula highlight'  => [Theme::dracula(), 'highlight', $dracula['purple']],

            // One Dark: primary/highlight→blue, secondary/success→green, accent→magenta, warning→yellow.
            'oneDark fg'         => [Theme::oneDark(), 'foreground', $oneDark['foreground']],
            'oneDark bg'         => [Theme::oneDark(), 'background', $oneDark['background']],
            'oneDark primary'    => [Theme::oneDark(), 'primary', $oneDark['blue']],
            'oneDark secondary'  => [Theme::oneDark(), 'secondary', $oneDark['green']],
            'oneDark accent'     => [Theme::oneDark(), 'accent', $oneDark['magenta']],
            'oneDark error'      => [Theme::oneDark(), 'error', $oneDark['red']],
            'oneDark warning'    => [Theme::oneDark(), 'warning', $oneDark['yellow']],
            'oneDark success'    => [Theme::oneDark(), 'success', $oneDark['green']],
            'oneDark highlight'  => [Theme::oneDark(), 'highlight', $oneDark['blue']],

            // GitHub Dark: primary/highlight→blue, secondary/success→green, accent→pink, warning→yellow.
            'githubDark fg'        => [Theme::githubDark(), 'foreground', $github['foreground']],
            'githubDark bg'        => [Theme::githubDark(), 'background', $github['background']],
            'githubDark primary'   => [Theme::githubDark(), 'primary', $github['blue']],
            'githubDark secondary' => [Theme::githubDark(), 'secondary', $github['green']],
            'githubDark accent'    => [Theme::githubDark(), 'accent', $github['pink']],
            'githubDark error'     => [Theme::githubDark(), 'error', $github['red']],
            'githubDark warning'   => [Theme::githubDark(), 'warning', $github['yellow']],
            'githubDark success'   => [Theme::githubDark(), 'success', $github['green']],
            'githubDark highlight' => [Theme::githubDark(), 'highlight', $github['blue']],
        ];
    }
}
