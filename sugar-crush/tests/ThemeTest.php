<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use SugarCraft\Core\Msg\BackgroundColorMsg;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\TerminalBackground;
use PHPUnit\Framework\TestCase;

final class ThemeTest extends TestCase
{
    protected function tearDown(): void
    {
        TerminalBackground::forget();
    }

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

    // =========================================================================
    // crush_code.md Phase 8 item 5: the `adaptive` preset
    // =========================================================================

    /**
     * The whole point of the preset: on a light terminal it must produce the
     * light palette without the user having typed `/theme light`.
     */
    public function testAdaptiveFollowsTheDetectedTerminalBackground(): void
    {
        // Each named theme is resolved WHILE its own background is the observed
        // one, because W3 made token resolution depend on that background:
        // `border` escalates when the palette's own value is illegible against
        // what the terminal actually paints, so `byName('light')` measured under
        // a black terminal is a different (and correct) colour from the same
        // name measured under a white one. Comparing across backgrounds would
        // assert that the escalation does not happen.
        TerminalBackground::observe(new BackgroundColorMsg(255, 255, 255));
        $light = Theme::byName('adaptive');
        $namedLight = Theme::byName('light');

        TerminalBackground::observe(new BackgroundColorMsg(0, 0, 0));
        $dark = Theme::byName('adaptive');
        $namedDark = Theme::byName('dark');

        $this->assertSame($namedLight->border->toHex(), $light->border->toHex());
        $this->assertSame($namedDark->border->toHex(), $dark->border->toHex());
        $this->assertNotSame($light->border->toHex(), $dark->border->toHex());
    }

    /**
     * The chrome half and the markdown half must always come from the same side
     * of the light/dark split - a mismatched pairing is exactly why `adaptive`
     * was kept out of the picker until now.
     */
    public function testAdaptivePairsChromeAndMarkdownFromTheSameSide(): void
    {
        TerminalBackground::observe(new BackgroundColorMsg(255, 255, 255));

        $adaptive = Theme::byName('adaptive');
        $light = Theme::byName('light');

        $this->assertEquals($light->markdown, $adaptive->markdown);
        $this->assertSame($light->userLabel->toHex(), $adaptive->userLabel->toHex());
        $this->assertSame($light->assistantLabel->toHex(), $adaptive->assistantLabel->toHex());
        $this->assertSame($light->systemLabel->toHex(), $adaptive->systemLabel->toHex());
    }

    /**
     * The name has to survive resolution, not collapse to `dark`/`light`:
     * config persistence stores the name, and a stored `adaptive` must re-probe
     * on the next launch instead of freezing the first detection.
     */
    public function testAdaptiveKeepsItsOwnNameSoItReResolvesOnReload(): void
    {
        TerminalBackground::observe(new BackgroundColorMsg(255, 255, 255));

        $this->assertSame('adaptive', Theme::byName('adaptive')->name);
        $this->assertSame('adaptive', Theme::adaptive()->name);
        $this->assertContains('adaptive', Theme::names());
    }
}
