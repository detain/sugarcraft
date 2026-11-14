<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Foundation;

use SugarCraft\Dashboard\Foundation\Theme;
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
    // Foundation purity (finding sugar-dashboard-138) + wither parity
    // ═══════════════════════════════════════════════════════════════

    /**
     * The dependency arrow must point downward: Foundation\Theme used
     * to import Plot\Chart\Bar and Components\Card\Text to hand out
     * styled widgets (and a text() whose doc promised a foreground it
     * never applied). Those factories now live with the widget that
     * can paint them (Bar::themed()); Theme itself must never learn
     * about layers built on top of it.
     */
    public function testFoundationThemeImportsNothingFromHigherLayers(): void
    {
        $source = (string) file_get_contents(
            (string) (new \ReflectionClass(Theme::class))->getFileName()
        );

        $this->assertSame(
            0,
            preg_match('/^use SugarCraft\\\\Dashboard\\\\(Plot|Components|Layout)\\\\/m', $source),
            'Foundation\\Theme must not import Plot, Components or Layout'
        );
    }

    /**
     * @return array<string, array{0:string, 1:string}> wither => accessor
     */
    public static function slotWitherProvider(): array
    {
        return [
            'foreground' => ['withForeground', 'foreground'],
            'background' => ['withBackground', 'background'],
            'primary' => ['withPrimary', 'primary'],
            'secondary' => ['withSecondary', 'secondary'],
            'accent' => ['withAccent', 'accent'],
            'error' => ['withError', 'error'],
            'warning' => ['withWarning', 'warning'],
            'success' => ['withSuccess', 'success'],
            'highlight' => ['withHighlight', 'highlight'],
        ];
    }

    /**
     * @dataProvider slotWitherProvider
     */
    public function testEveryColourSlotHasAHonestWither(string $wither, string $accessor): void
    {
        $theme = Theme::dark();
        $replacement = Color::hex('#FF00AA');

        $themed = $theme->{$wither}($replacement);

        $this->assertNotSame($theme, $themed, "{$wither}() must return a new theme");
        $this->assertSame($replacement, $themed->{$accessor}(), "{$wither}() must store its color");

        // The other slots must survive the rebuild untouched.
        foreach (self::slotWitherProvider() as $otherAccessor) {
            if ($otherAccessor[1] === $accessor) {
                continue;
            }
            $this->assertSame(
                $theme->{$otherAccessor[1]}(),
                $themed->{$otherAccessor[1]}(),
                "{$wither}() must not disturb {$otherAccessor[1]}()"
            );
        }
    }

    public function testWithNameKeepsEveryColour(): void
    {
        $theme = Theme::dark();
        $renamed = $theme->withName('midnight');

        $this->assertSame('midnight', $renamed->name());
        foreach (array_keys(self::slotWitherProvider()) as $slot) {
            $this->assertSame($theme->{$slot}(), $renamed->{$slot}());
        }
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
     * Each migrated slot must equal its sugar-core {@see Palettes} source.
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
