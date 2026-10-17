<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Theme;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * ISS-104's theme-picker half: entering the Ctrl+P themes list must open on the
 * theme in use (not always NAMES[0]) and must keep the active theme legible with
 * a ● even after the ▸ cursor moves off it.
 */
final class PaletteThemePickerTest extends TestCase
{
    use HomeSandboxTrait;

    private string $homeSandbox = '';

    protected function setUp(): void
    {
        // Constructing a Chat walks the skill trees under HOME; sandbox both
        // spellings so nothing here depends on what this machine has installed.
        $this->homeSandbox = $this->useHomeSandbox(
            sys_get_temp_dir() . '/crush_palette_theme_home_' . bin2hex(random_bytes(6)),
        );
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        @rmdir($this->homeSandbox);

        parent::tearDown();
    }

    /**
     * Open the palette directly on the themes list for a Chat using $theme,
     * through the same SwitchTheme root action Ctrl+P runs.
     */
    private function openThemePicker(string $theme): Chat
    {
        $chat = (new Chat())->withThemeName($theme);
        [$opened] = $chat->runPaletteAction(Lang::t('cmd.theme.label'));

        self::assertNotNull($opened->palette(), 'fixture: Switch theme must open the palette');
        self::assertSame('themes', $opened->palette()->mode, 'fixture: it must land on the themes list');

        return $opened;
    }

    public function testTheThemePickerOpensOnTheThemeInUse(): void
    {
        $names = Theme::names();
        $index = array_search('dracula', $names, true);
        self::assertIsInt($index, 'fixture: dracula must be a known theme');
        self::assertGreaterThan(0, $index, 'fixture: the seeded index must differ from the old reset-to-zero');

        $picker = $this->openThemePicker('dracula');

        self::assertSame($index, $picker->palette()->selectedIndex, 'the picker must highlight the active theme row');
        self::assertSame('dracula', $picker->paletteMatches()[$picker->palette()->selectedIndex]);
        self::assertSame('dracula', $picker->activeThemeName());
    }

    public function testTheThemePickerSeedsEveryNonDefaultThemeToItsOwnRow(): void
    {
        $names = Theme::names();
        foreach ($names as $expectedIndex => $name) {
            $picker = $this->openThemePicker($name);
            self::assertSame($expectedIndex, $picker->palette()->selectedIndex, "the picker opens on {$name}");
            self::assertSame($name, $picker->activeThemeName());
        }
    }

    public function testTheDefaultThemeStillSeedsTheFirstRow(): void
    {
        // The regression guard for the other half of the change: the reset-to-zero
        // behaviour is still correct when zero IS the active theme.
        $picker = $this->openThemePicker('dark');

        self::assertSame(0, $picker->palette()->selectedIndex);
        self::assertSame('dark', $picker->activeThemeName());
    }

    public function testTheActiveThemeStaysMarkedAfterTheCursorMoves(): void
    {
        $picker = $this->openThemePicker('dracula');
        [$moved] = $picker->update(new KeyMsg(KeyType::Down, ''));

        self::assertNotSame($picker->palette()->selectedIndex, $moved->palette()->selectedIndex,
            'fixture: Down must move the ▸ cursor off the active theme');

        $plain = $this->paintThemes($moved);

        self::assertStringContainsString('● dracula', $plain, 'the active theme keeps its ● marker once unselected');
        self::assertStringContainsString('▸ ' . Theme::names()[$moved->palette()->selectedIndex], $plain,
            'the ▸ cursor moved to the next row');
        self::assertStringNotContainsString('● ' . Theme::names()[$moved->palette()->selectedIndex], $plain,
            'only the active theme carries the ●, never the merely-selected row');
    }

    /**
     * The themes overlay as {@see Renderer::renderPalette()} paints it, SGR runs
     * stripped so the assertion talks about cells rather than colour-run offsets.
     */
    private function paintThemes(Chat $chat): string
    {
        $overlay = (new ReflectionMethod(Renderer::class, 'renderPalette'))
            ->invoke(null, $chat, $chat->theme());
        self::assertIsString($overlay);

        $plain = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $overlay) ?? $overlay;
        self::assertStringContainsString('dracula', $plain, 'fixture: the fixture must reach the builder on the themes list');

        return $plain;
    }
}
