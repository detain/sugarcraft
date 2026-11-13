<?php

declare(strict_types=1);

namespace SugarCraft\Style\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Palettes;
use SugarCraft\Style\Style;
use SugarCraft\Style\Theme;

final class ThemeTest extends TestCase
{
    // ─── Factory: dark() ────────────────────────────────────────────────────

    public function testDarkHasAllSlotsPopulated(): void
    {
        $t = Theme::dark();
        $this->assertInstanceOf(Color::class, $t->foreground);
        $this->assertInstanceOf(Color::class, $t->background);
        $this->assertInstanceOf(Color::class, $t->primary);
        $this->assertInstanceOf(Color::class, $t->secondary);
        $this->assertInstanceOf(Color::class, $t->accent);
        $this->assertInstanceOf(Color::class, $t->muted);
        $this->assertInstanceOf(Color::class, $t->error);
        $this->assertInstanceOf(Color::class, $t->warning);
        $this->assertInstanceOf(Color::class, $t->success);
        $this->assertInstanceOf(Color::class, $t->info);
        $this->assertInstanceOf(Color::class, $t->border);
        $this->assertInstanceOf(Color::class, $t->separator);
        $this->assertInstanceOf(Color::class, $t->cursor);
    }

    public function testDarkForegroundIsLightOnDark(): void
    {
        $t = Theme::dark();
        // Dark theme foreground should be a light colour (luminance > 0.5).
        $this->assertGreaterThan(0.5, $t->foreground->luminance());
    }

    public function testDarkBackgroundIsDark(): void
    {
        $t = Theme::dark();
        $this->assertTrue($t->background->isDark());
    }

    // ─── Factory: light() ─────────────────────────────────────────────────

    public function testLightHasAllSlotsPopulated(): void
    {
        $t = Theme::light();
        $this->assertInstanceOf(Color::class, $t->foreground);
        $this->assertInstanceOf(Color::class, $t->background);
        $this->assertInstanceOf(Color::class, $t->primary);
        $this->assertInstanceOf(Color::class, $t->secondary);
        $this->assertInstanceOf(Color::class, $t->accent);
        $this->assertInstanceOf(Color::class, $t->muted);
        $this->assertInstanceOf(Color::class, $t->error);
        $this->assertInstanceOf(Color::class, $t->warning);
        $this->assertInstanceOf(Color::class, $t->success);
        $this->assertInstanceOf(Color::class, $t->info);
        $this->assertInstanceOf(Color::class, $t->border);
        $this->assertInstanceOf(Color::class, $t->separator);
        $this->assertInstanceOf(Color::class, $t->cursor);
    }

    public function testLightForegroundIsDarkOnLight(): void
    {
        $t = Theme::light();
        // Light theme foreground should be dark (luminance < 0.5).
        $this->assertLessThan(0.5, $t->foreground->luminance());
    }

    public function testLightBackgroundIsLight(): void
    {
        $t = Theme::light();
        $this->assertFalse($t->background->isDark());
    }

    // ─── Factory: dracula() ────────────────────────────────────────────────

    public function testDraculaHasAllSlotsPopulated(): void
    {
        $t = Theme::dracula();
        $this->assertInstanceOf(Color::class, $t->foreground);
        $this->assertInstanceOf(Color::class, $t->background);
        $this->assertInstanceOf(Color::class, $t->primary);
        $this->assertInstanceOf(Color::class, $t->secondary);
        $this->assertInstanceOf(Color::class, $t->accent);
        $this->assertInstanceOf(Color::class, $t->muted);
        $this->assertInstanceOf(Color::class, $t->error);
        $this->assertInstanceOf(Color::class, $t->warning);
        $this->assertInstanceOf(Color::class, $t->success);
        $this->assertInstanceOf(Color::class, $t->info);
        $this->assertInstanceOf(Color::class, $t->border);
        $this->assertInstanceOf(Color::class, $t->separator);
        $this->assertInstanceOf(Color::class, $t->cursor);
    }

    public function testDraculaColorsMatchPublishedSpec(): void
    {
        // Verify a representative subset of the Dracula palette against
        // the published spec: https://draculatheme.com/
        $t = Theme::dracula();

        // Background: #282A36
        $this->assertSame('#282a36', $t->background->toHex());

        // Foreground: #F8F8F2
        $this->assertSame('#f8f8f2', $t->foreground->toHex());

        // Primary (purple): #BD93F9
        $this->assertSame('#bd93f9', $t->primary->toHex());

        // Secondary = Green (comment): #50FA7B
        $this->assertSame('#50fa7b', $t->secondary->toHex());

        // Accent (pink): #FF79C6
        $this->assertSame('#ff79c6', $t->accent->toHex());

        // Error: #FF5555
        $this->assertSame('#ff5555', $t->error->toHex());

        // Warning: #FFB86C
        $this->assertSame('#ffb86c', $t->warning->toHex());

        // Success: #50FA7B (same as secondary)
        $this->assertSame('#50fa7b', $t->success->toHex());

        // Info: #8BE9FD
        $this->assertSame('#8be9fd', $t->info->toHex());

        // Border: #44475A
        $this->assertSame('#44475a', $t->border->toHex());
    }

    // ─── Factory: tokyoNight() ─────────────────────────────────────────────

    public function testTokyoNightHasAllSlotsPopulated(): void
    {
        $t = Theme::tokyoNight();
        $this->assertInstanceOf(Color::class, $t->foreground);
        $this->assertInstanceOf(Color::class, $t->primary);
        $this->assertInstanceOf(Color::class, $t->secondary);
        $this->assertInstanceOf(Color::class, $t->accent);
        $this->assertInstanceOf(Color::class, $t->muted);
        $this->assertInstanceOf(Color::class, $t->error);
        $this->assertInstanceOf(Color::class, $t->warning);
        $this->assertInstanceOf(Color::class, $t->success);
        $this->assertInstanceOf(Color::class, $t->info);
        $this->assertInstanceOf(Color::class, $t->border);
        $this->assertInstanceOf(Color::class, $t->separator);
        $this->assertInstanceOf(Color::class, $t->cursor);
    }

    // ─── Factory: oneDark() ───────────────────────────────────────────────

    public function testOneDarkHasAllSlotsPopulated(): void
    {
        $t = Theme::oneDark();
        $this->assertInstanceOf(Color::class, $t->foreground);
        $this->assertInstanceOf(Color::class, $t->background);
        $this->assertInstanceOf(Color::class, $t->primary);
        $this->assertInstanceOf(Color::class, $t->secondary);
        $this->assertInstanceOf(Color::class, $t->accent);
        $this->assertInstanceOf(Color::class, $t->muted);
        $this->assertInstanceOf(Color::class, $t->error);
        $this->assertInstanceOf(Color::class, $t->warning);
        $this->assertInstanceOf(Color::class, $t->success);
        $this->assertInstanceOf(Color::class, $t->info);
        $this->assertInstanceOf(Color::class, $t->border);
        $this->assertInstanceOf(Color::class, $t->separator);
        $this->assertInstanceOf(Color::class, $t->cursor);
    }

    public function testOneDarkBackgroundIsDark(): void
    {
        $t = Theme::oneDark();
        $this->assertTrue($t->background->isDark());
    }

    // ─── Factory: githubDark() ──────────────────────────────────────────────

    public function testGithubDarkHasAllSlotsPopulated(): void
    {
        $t = Theme::githubDark();
        $this->assertInstanceOf(Color::class, $t->foreground);
        $this->assertInstanceOf(Color::class, $t->background);
        $this->assertInstanceOf(Color::class, $t->primary);
        $this->assertInstanceOf(Color::class, $t->secondary);
        $this->assertInstanceOf(Color::class, $t->accent);
        $this->assertInstanceOf(Color::class, $t->muted);
        $this->assertInstanceOf(Color::class, $t->error);
        $this->assertInstanceOf(Color::class, $t->warning);
        $this->assertInstanceOf(Color::class, $t->success);
        $this->assertInstanceOf(Color::class, $t->info);
        $this->assertInstanceOf(Color::class, $t->border);
        $this->assertInstanceOf(Color::class, $t->separator);
        $this->assertInstanceOf(Color::class, $t->cursor);
    }

    // ─── Factory: solarizedDark() / solarizedLight() ─────────────────────

    public function testSolarizedDarkHasAllSlotsPopulated(): void
    {
        $t = Theme::solarizedDark();
        foreach (['foreground', 'background', 'primary', 'secondary',
                  'accent', 'muted', 'error', 'warning', 'success',
                  'info', 'border', 'separator', 'cursor'] as $slot) {
            $this->assertInstanceOf(Color::class, $t->{$slot}, "slot {$slot}");
        }
    }

    public function testSolarizedLightHasAllSlotsPopulated(): void
    {
        $t = Theme::solarizedLight();
        foreach (['foreground', 'background', 'primary', 'secondary',
                  'accent', 'muted', 'error', 'warning', 'success',
                  'info', 'border', 'separator', 'cursor'] as $slot) {
            $this->assertInstanceOf(Color::class, $t->{$slot}, "slot {$slot}");
        }
    }

    // ─── Factory: ansi() ───────────────────────────────────────────────────

    public function testAnsiHasAllSlotsPopulated(): void
    {
        $t = Theme::ansi();
        $this->assertInstanceOf(Color::class, $t->foreground);
        $this->assertInstanceOf(Color::class, $t->background);
        $this->assertInstanceOf(Color::class, $t->primary);
        $this->assertInstanceOf(Color::class, $t->secondary);
        $this->assertInstanceOf(Color::class, $t->accent);
        $this->assertInstanceOf(Color::class, $t->muted);
        $this->assertInstanceOf(Color::class, $t->error);
        $this->assertInstanceOf(Color::class, $t->warning);
        $this->assertInstanceOf(Color::class, $t->success);
        $this->assertInstanceOf(Color::class, $t->info);
        $this->assertInstanceOf(Color::class, $t->border);
        $this->assertInstanceOf(Color::class, $t->separator);
        $this->assertInstanceOf(Color::class, $t->cursor);
    }

    // ─── Factory: adaptive() ─────────────────────────────────────────────

    protected function tearDown(): void
    {
        putenv('COLORFGBG');
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function colorFgBgProvider(): array
    {
        return [
            'white bg (7) is light'                => ['0;7', false],
            'bright white bg (15) is light'        => ['0;15', false],
            'black bg (0) is dark'                 => ['15;0', true],
            'bright-black bg (8, dark grey) is dark' => ['7;8', true],
            '256-colour pale grey (255) is light'  => ['0;255', false],
            '256-colour near-black (232) is dark'  => ['15;232', true],
            'rxvt three-field form, light'         => ['0;default;15', false],
            'rxvt three-field form, dark'          => ['15;default;0', true],
            'surrounding whitespace tolerated'     => [' 0 ; 7 ', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('colorFgBgProvider')]
    public function testAdaptiveReadsBackgroundLuminance(string $value, bool $expectDark): void
    {
        putenv('COLORFGBG=' . $value);
        $this->assertSame($expectDark, Theme::adaptive()->background->isDark());
    }

    public function testAdaptiveFallsBackToDarkWhenEnvNotSet(): void
    {
        putenv('COLORFGBG');
        $t = Theme::adaptive();
        $this->assertTrue($t->background->isDark());
    }

    public function testAdaptiveFallsBackToDarkForMalformedValue(): void
    {
        putenv('COLORFGBG=not-a-number');
        $t = Theme::adaptive();
        $this->assertTrue($t->background->isDark());
        putenv('COLORFGBG=7'); // only one part
        $t = Theme::adaptive();
        $this->assertTrue($t->background->isDark());
        putenv('COLORFGBG=0;default'); // non-numeric background
        $this->assertTrue(Theme::adaptive()->background->isDark());
        putenv('COLORFGBG=0;300'); // out of the 256-colour range
        $this->assertTrue(Theme::adaptive()->background->isDark());
        putenv('COLORFGBG=0;'); // empty background field
        $this->assertTrue(Theme::adaptive()->background->isDark());
    }

    // ─── Fluent withers ───────────────────────────────────────────────────

    public function testWithForegroundReturnsNewInstance(): void
    {
        $a = Theme::dark();
        $b = $a->withForeground(Color::hex('#ffffff'));
        $this->assertNotSame($a, $b);
        $this->assertSame('#ffffff', $b->foreground->toHex());
        $this->assertSame($a->background->toHex(), $b->background->toHex());
    }

    public function testWithBackgroundReturnsNewInstance(): void
    {
        $a = Theme::dark();
        $b = $a->withBackground(Color::hex('#ffffff'));
        $this->assertNotSame($a, $b);
        $this->assertSame('#ffffff', $b->background->toHex());
    }

    public function testWithPrimaryReturnsNewInstance(): void
    {
        $a = Theme::dark();
        $b = $a->withPrimary(Color::hex('#ff0000'));
        $this->assertNotSame($a, $b);
        $this->assertSame('#ff0000', $b->primary->toHex());
        $this->assertSame($a->secondary->toHex(), $b->secondary->toHex());
    }

    public function testWithSecondaryReturnsNewInstance(): void
    {
        $a = Theme::dracula();
        $b = $a->withSecondary(Color::hex('#0000ff'));
        $this->assertNotSame($a, $b);
        $this->assertSame('#0000ff', $b->secondary->toHex());
    }

    public function testWithAccentReturnsNewInstance(): void
    {
        $a = Theme::dark();
        $b = $a->withAccent(Color::hex('#00ff00'));
        $this->assertNotSame($a, $b);
        $this->assertSame('#00ff00', $b->accent->toHex());
    }

    public function testWithMutedReturnsNewInstance(): void
    {
        $a = Theme::dark();
        $b = $a->withMuted(Color::hex('#888888'));
        $this->assertNotSame($a, $b);
        $this->assertSame('#888888', $b->muted->toHex());
    }

    public function testWithErrorReturnsNewInstance(): void
    {
        $a = Theme::dark();
        $b = $a->withError(Color::hex('#cc0000'));
        $this->assertNotSame($a, $b);
        $this->assertSame('#cc0000', $b->error->toHex());
    }

    public function testWithWarningReturnsNewInstance(): void
    {
        $a = Theme::dark();
        $b = $a->withWarning(Color::hex('#ddaa00'));
        $this->assertNotSame($a, $b);
        $this->assertSame('#ddaa00', $b->warning->toHex());
    }

    public function testWithSuccessReturnsNewInstance(): void
    {
        $a = Theme::dark();
        $b = $a->withSuccess(Color::hex('#00cc00'));
        $this->assertNotSame($a, $b);
        $this->assertSame('#00cc00', $b->success->toHex());
    }

    public function testWithInfoReturnsNewInstance(): void
    {
        $a = Theme::dark();
        $b = $a->withInfo(Color::hex('#0099cc'));
        $this->assertNotSame($a, $b);
        $this->assertSame('#0099cc', $b->info->toHex());
    }

    public function testWithBorderReturnsNewInstance(): void
    {
        $a = Theme::dark();
        $b = $a->withBorder(Color::hex('#333333'));
        $this->assertNotSame($a, $b);
        $this->assertSame('#333333', $b->border->toHex());
    }

    public function testWithSeparatorReturnsNewInstance(): void
    {
        $a = Theme::dark();
        $b = $a->withSeparator(Color::hex('#222222'));
        $this->assertNotSame($a, $b);
        $this->assertSame('#222222', $b->separator->toHex());
    }

    public function testWithCursorReturnsNewInstance(): void
    {
        $a = Theme::dark();
        $b = $a->withCursor(Color::hex('#aabbcc'));
        $this->assertNotSame($a, $b);
        $this->assertSame('#aabbcc', $b->cursor->toHex());
    }

    public function testWitherPreservesOtherSlots(): void
    {
        $a = Theme::dracula();
        $fgBefore = $a->foreground->toHex();
        $bgBefore = $a->background->toHex();
        $b = $a->withPrimary(Color::hex('#abcdef'));
        $this->assertSame($fgBefore, $b->foreground->toHex());
        $this->assertSame($bgBefore, $b->background->toHex());
        $this->assertSame('#abcdef', $b->primary->toHex());
    }

    public function testChainedWithersWorkCorrectly(): void
    {
        $t = Theme::dark()
            ->withForeground(Color::hex('#111111'))
            ->withBackground(Color::hex('#eeeeee'))
            ->withPrimary(Color::hex('#333333'));
        $this->assertSame('#111111', $t->foreground->toHex());
        $this->assertSame('#eeeeee', $t->background->toHex());
        $this->assertSame('#333333', $t->primary->toHex());
    }

    // ─── Catalog ───────────────────────────────────────────────────────────

    public function testCatalogIsNonEmptyListOfStrings(): void
    {
        $catalog = Theme::catalog();
        $this->assertNotEmpty($catalog);
        $this->assertSame(array_values($catalog), $catalog, 'catalog must be a list');
        foreach ($catalog as $name) {
            $this->assertIsString($name);
        }
    }

    public function testEveryCatalogEntryIsARealThemeFactory(): void
    {
        foreach (Theme::catalog() as $name) {
            $this->assertTrue(
                method_exists(Theme::class, $name),
                "catalog entry '{$name}' must be a static factory on Theme",
            );
            $theme = Theme::{$name}();
            $this->assertInstanceOf(Theme::class, $theme, "Theme::{$name}() must return a Theme");
            $this->assertInstanceOf(Color::class, $theme->foreground);
        }
    }

    // ─── Palettes SSOT drift guard ─────────────────────────────────────────

    /**
     * Every slot of the rich named themes is re-sourced from
     * {@see Palettes} in sugar-core. Pin each slot's rendered hex against the
     * corresponding palette constant so an upstream palette edit that silently
     * drifts from this port (or vice-versa) fails loudly.
     */
    public function testDraculaSlotsMatchPalettesConstant(): void
    {
        $t = Theme::dracula();
        $p = Palettes::DRACULA;
        $this->assertSame($p['foreground'], $t->foreground->toHex());
        $this->assertSame($p['background'], $t->background->toHex());
        $this->assertSame($p['purple'], $t->primary->toHex());
        $this->assertSame($p['green'], $t->secondary->toHex());
        $this->assertSame($p['pink'], $t->accent->toHex());
        $this->assertSame($p['comment'], $t->muted->toHex());
        $this->assertSame($p['red'], $t->error->toHex());
        $this->assertSame($p['orange'], $t->warning->toHex());
        $this->assertSame($p['green'], $t->success->toHex());
        $this->assertSame($p['cyan'], $t->info->toHex());
        $this->assertSame($p['currentLine'], $t->border->toHex());
        $this->assertSame($p['separator'], $t->separator->toHex());
        $this->assertSame($p['foreground'], $t->cursor->toHex());
    }

    public function testOneDarkSlotsMatchPalettesConstant(): void
    {
        $t = Theme::oneDark();
        $p = Palettes::ONE_DARK;
        $this->assertSame($p['foreground'], $t->foreground->toHex());
        $this->assertSame($p['background'], $t->background->toHex());
        $this->assertSame($p['blue'], $t->primary->toHex());
        $this->assertSame($p['green'], $t->secondary->toHex());
        $this->assertSame($p['magenta'], $t->accent->toHex());
        $this->assertSame($p['comment'], $t->muted->toHex());
        $this->assertSame($p['red'], $t->error->toHex());
        $this->assertSame($p['yellow'], $t->warning->toHex());
        $this->assertSame($p['green'], $t->success->toHex());
        $this->assertSame($p['cyan'], $t->info->toHex());
        $this->assertSame($p['currentLine'], $t->border->toHex());
        $this->assertSame($p['separator'], $t->separator->toHex());
        $this->assertSame($p['cursor'], $t->cursor->toHex());
    }

    public function testGithubDarkSlotsMatchPalettesConstant(): void
    {
        $t = Theme::githubDark();
        $p = Palettes::GITHUB_DARK;
        $this->assertSame($p['foreground'], $t->foreground->toHex());
        $this->assertSame($p['background'], $t->background->toHex());
        $this->assertSame($p['blue'], $t->primary->toHex());
        $this->assertSame($p['green'], $t->secondary->toHex());
        $this->assertSame($p['pink'], $t->accent->toHex());
        $this->assertSame($p['comment'], $t->muted->toHex());
        $this->assertSame($p['red'], $t->error->toHex());
        $this->assertSame($p['yellow'], $t->warning->toHex());
        $this->assertSame($p['green'], $t->success->toHex());
        $this->assertSame($p['cyan'], $t->info->toHex());
        $this->assertSame($p['currentLine'], $t->border->toHex());
        $this->assertSame($p['separator'], $t->separator->toHex());
        $this->assertSame($p['blue'], $t->cursor->toHex());
    }

    // ─── Wither choke (finding 037) ────────────────────────────────────────

    /**
     * @return array<string,array{string,string}>
     */
    public static function witherSlotProvider(): array
    {
        return [
            'foreground' => ['withForeground', 'foreground'],
            'background' => ['withBackground', 'background'],
            'primary' => ['withPrimary', 'primary'],
            'secondary' => ['withSecondary', 'secondary'],
            'accent' => ['withAccent', 'accent'],
            'muted' => ['withMuted', 'muted'],
            'error' => ['withError', 'error'],
            'warning' => ['withWarning', 'warning'],
            'success' => ['withSuccess', 'success'],
            'info' => ['withInfo', 'info'],
            'border' => ['withBorder', 'border'],
            'separator' => ['withSeparator', 'separator'],
            'cursor' => ['withCursor', 'cursor'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('witherSlotProvider')]
    public function testWitherReplacesItsSlotAndCarriesEveryOtherSlot(string $wither, string $slot): void
    {
        // The with() choke is the only legal copy door: a wither that drops
        // one of the other 12 fields passed the old one-field asserts while
        // silently reverting themed colours. Full-matrix carry check.
        $base = Theme::dark();
        $result = $base->{$wither}(Color::hex('#123456'));

        foreach (array_column(self::witherSlotProvider(), 1) as $name) {
            if ($name === $slot) {
                $this->assertSame('#123456', $result->$name->toHex(), "{$wither} must replace {$slot}");
            } else {
                $this->assertSame($base->$name->toHex(), $result->$name->toHex(), "{$wither} must carry {$name}");
            }
        }
    }

    public function testWithersRouteThroughTheCopyDoorNotDirectConstruction(): void
    {
        // Structural guard (house precedent: sugar-spring SPR-13): every
        // public wither must call $this->with([...]) and never re-thread
        // the constructor by hand — re-introducing `new self(` in a wither
        // re-opens the 14th-field-drop class this row closed.
        $src = (string) file_get_contents(dirname(__DIR__) . '/src/Theme.php');
        preg_match_all(
            '/public function with([A-Z]\w*)\([^)]*\): self\s*\{([^}]*)\}/s',
            $src,
            $matches,
            PREG_SET_ORDER,
        );
        $this->assertCount(14, $matches, 'expected all 14 public withers (13 colour slots + the role registry)');
        foreach ($matches as [, $name, $body]) {
            $this->assertStringContainsString(
                '$this->with([',
                $body,
                "with{$name} bypassed the copy door",
            );
            $this->assertStringNotContainsString(
                'new self(',
                $body,
                "with{$name} constructs directly — field drops would go unnoticed again",
            );
        }
    }

    // ─── Role registry + injectable darkness (finding 003) ────────────────

    public function testStyleRegistryIsDefaultEmpty(): void
    {
        $this->assertNull(Theme::dark()->style('title'));
        $this->assertSame([], Theme::dark()->styles);
    }

    public function testWithStyleBindsAndIsolates(): void
    {
        $base = Theme::dark();
        $title = Style::new()->bold(true);
        $bound = $base->withStyle('title', $title);

        $this->assertNull($base->style('title'), 'the source theme must not see the binding');
        $this->assertSame($title, $bound->style('title'));
        $this->assertSame($base->foreground->toHex(), $bound->foreground->toHex());
    }

    public function testWithStyleNullRemovesTheRole(): void
    {
        $bound = Theme::dark()->withStyle('title', Style::new());
        $removed = $bound->withStyle('title', null);
        $this->assertNull($removed->style('title'));
        $this->assertNotNull($bound->style('title'), 'removal is copy-on-write');
    }

    public function testRegistrySurvivesTheColourWithers(): void
    {
        // The registry is the 14th field — the copy door keeps it alive
        // through every wither (finding 037's trap applied to itself).
        $theme = Theme::dark()->withStyle('title', Style::new()->bold(true));
        $retouched = $theme->withPrimary(Color::hex('#ff0000'))->withBorder(Color::hex('#00ff00'));
        $this->assertNotNull($retouched->style('title'));
    }

    public function testForSelectsTheMatchingPolarityMember(): void
    {
        $this->assertSame(Theme::dark()->background->toHex(), Theme::for(true)->background->toHex());
        $this->assertSame(Theme::light()->background->toHex(), Theme::for(false)->background->toHex());
    }

    public function testAdaptiveFallbackKnobDecidesNoSignal(): void
    {
        putenv('COLORFGBG'); // absent — the old silent-dark door, now caller-chosen
        $this->assertTrue(Theme::adaptive()->background->isDark(), 'default stays dark (existing behaviour)');
        $this->assertFalse(Theme::adaptive(fallbackDark: false)->background->isDark());

        putenv('COLORFGBG=not-a-number'); // malformed routes through the same door
        $this->assertFalse(Theme::adaptive(fallbackDark: false)->background->isDark());

        // A real signal outranks the knob: a dark background stays dark.
        putenv('COLORFGBG=15;0');
        $this->assertTrue(Theme::adaptive(fallbackDark: false)->background->isDark());
        putenv('COLORFGBG');
    }
}
