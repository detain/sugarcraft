<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Tui\Pane;
use PHPUnit\Framework\TestCase;

final class PaneTest extends TestCase
{
    /**
     * @testdox All 8 enum cases exist and have correct string values
     */
    public function testEnumCasesExistWithCorrectStringValues(): void
    {
        $this->assertSame('chat', Pane::Chat->value);
        $this->assertSame('input', Pane::Input->value);
        $this->assertSame('skills', Pane::Skills->value);
        $this->assertSame('agents', Pane::Agents->value);
        $this->assertSame('files', Pane::Files->value);
        $this->assertSame('tools', Pane::Tools->value);
        $this->assertSame('settings', Pane::Settings->value);
        $this->assertSame('help', Pane::Help->value);
    }

    /**
     * @testdox label() returns correct human-readable string for each pane
     */
    public function testLabelReturnsCorrectString(): void
    {
        $this->assertSame('Chat', Pane::Chat->label());
        $this->assertSame('Input', Pane::Input->label());
        $this->assertSame('Skills', Pane::Skills->label());
        $this->assertSame('Agents', Pane::Agents->label());
        $this->assertSame('Files', Pane::Files->label());
        $this->assertSame('Tools', Pane::Tools->label());
        $this->assertSame('Settings', Pane::Settings->label());
        $this->assertSame('Help', Pane::Help->label());
    }

    /**
     * @testdox next() cycles exactly the panes the tab strip advertises
     * Cycle: Chat → Files → Tools → Skills → Agents → Settings → Chat
     *
     * This once walked all eight cases, so Tab stopped on Input, Settings
     * and Help — none of which appeared in MenuBar::PANE_TABS and none of
     * which had a renderer, stranding the user on a pane the UI never
     * offered and that drew nothing. Settings has a renderer now
     * (SettingsPane) and is back in the strip, so Tab reaches it again.
     */
    public function testNextCyclesCorrectly(): void
    {
        $this->assertSame(Pane::Files, Pane::Chat->next());
        $this->assertSame(Pane::Tools, Pane::Files->next());
        $this->assertSame(Pane::Skills, Pane::Tools->next());
        $this->assertSame(Pane::Agents, Pane::Skills->next());
        $this->assertSame(Pane::Settings, Pane::Agents->next());
        $this->assertSame(Pane::Chat, Pane::Settings->next());
    }

    /** Panes outside the strip are not somewhere Tab can leave you. */
    public function testNonTabPanesReturnToChat(): void
    {
        $this->assertSame(Pane::Chat, Pane::Input->next());
        $this->assertSame(Pane::Chat, Pane::Help->next());
        $this->assertSame(Pane::Chat, Pane::Menu->next());
    }

    /**
     * @testdox Complete cycle through all panes returns to Chat
     */
    public function testCompleteCycleReturnsToChat(): void
    {
        $pane = Pane::Chat;

        // 6 transitions: one per advertised tab
        for ($i = 0; $i < 6; $i++) {
            $pane = $pane->next();
        }

        $this->assertSame(Pane::Chat, $pane);
    }

    /**
     * @testdox Each pane can be created from its string value with from()
     */
    public function testFromReturnsCorrectPane(): void
    {
        $this->assertSame(Pane::Chat, Pane::from('chat'));
        $this->assertSame(Pane::Input, Pane::from('input'));
        $this->assertSame(Pane::Skills, Pane::from('skills'));
        $this->assertSame(Pane::Agents, Pane::from('agents'));
        $this->assertSame(Pane::Files, Pane::from('files'));
        $this->assertSame(Pane::Tools, Pane::from('tools'));
        $this->assertSame(Pane::Settings, Pane::from('settings'));
        $this->assertSame(Pane::Help, Pane::from('help'));
    }

    /**
     * @testdox from() throws ValueError for invalid string value
     */
    public function testFromThrowsOnInvalidValue(): void
    {
        $this->expectException(\ValueError::class);
        Pane::from('invalid');
    }

    /**
     * @testdox from() throws ValueError for empty string
     */
    public function testFromThrowsOnEmptyString(): void
    {
        $this->expectException(\ValueError::class);
        Pane::from('');
    }

    /**
     * @testdox The six framed panes carry the adopted glyphs
     *
     * The table IS the design record: swapping a glyph for a lookalike is a
     * visual change a reader of the frame cannot audit without this pin.
     */
    public function testFramedPaneIconsAreTheAdoptedGlyphs(): void
    {
        $this->assertSame("\u{25A2}", Pane::Chat->icon());
        $this->assertSame("\u{25EB}", Pane::Files->icon());
        $this->assertSame("\u{2692}", Pane::Tools->icon());
        $this->assertSame("\u{2726}", Pane::Skills->icon());
        $this->assertSame("\u{2756}", Pane::Agents->icon());
        $this->assertSame("\u{2699}", Pane::Settings->icon());
    }

    /**
     * Unicode-16 East Asian Width whitelist — one row per adopted frame
     * icon, the value being the EAW property that codepoint carries in the
     * authoritative table as adjudicated at adoption time.
     *
     * Source: https://www.unicode.org/Public/16.0.0/ucd/EastAsianWidth.txt
     * (retrieved 2026-09-24). The U+2630 TRIGRAM FOR HEAVEN row the Files
     * pane used to carry is why this exists: property N through Unicode
     * 15.1, reclassified W in 16.0 — PHP 8.4's mb_strwidth (oniguruma with
     * the Unicode-16 tables) then counted a pinned 120-column chrome line as
     * 121 while 8.3's older bundled table stayed green, so only the 8.4 CI
     * leg could see the drift.
     *
     * Every row must read 'N': F/W break mb_strwidth on Unicode-16 runtimes
     * outright, and A (Ambiguous) breaks East-Asian-locale terminals that
     * render Ambiguous wide even though mb_strwidth never counts it as 2.
     * The staleness arm at the bottom of
     * {@see self::testEveryFramedPaneIconIsWidthOneAndDistinct()} compares
     * icon()'s codepoints against these keys, so adopting a 7th icon — or
     * respelling one — without adjudicating its property here first reddens
     * the suite.
     */
    private const ICON_EAW_WHITELIST = [
        "\u{25A2}" => 'N', // Chat — WHITE SQUARE WITH ROUNDED CORNERS
        "\u{25EB}" => 'N', // Files — WHITE SQUARE WITH VERTICAL BISECTING LINE
        "\u{2692}" => 'N', // Tools — HAMMER AND PICK
        "\u{2726}" => 'N', // Skills — BLACK FOUR POINTED STAR
        "\u{2756}" => 'N', // Agents — BLACK DIAMOND MINUS WHITE X
        "\u{2699}" => 'N', // Settings — GEAR
    ];

    /**
     * @testdox Every framed-pane icon is one BMP codepoint of display width 1 under BOTH width oracles, and all six differ
     *
     * The width law from Pane::icon()'s contract, executed: a double-width
     * or combining picture in a border title pushes the closing corner off
     * the pane's column budget, so the glyph set may never grow one.
     *
     * Two oracles run because they disagreed in the field: Width::of is the
     * project's own static table, mb_strwidth is what BootstrapTest's
     * frame-budget loop measures with, and PHP 8.4 ships it on Unicode-16
     * East Asian Width tables where several older lookalikes went wide.
     */
    public function testEveryFramedPaneIconIsWidthOneAndDistinct(): void
    {
        $icons = [];
        foreach ([Pane::Chat, Pane::Files, Pane::Tools, Pane::Skills, Pane::Agents, Pane::Settings] as $pane) {
            $icon = $pane->icon();
            $this->assertNotSame('', $icon, $pane->name . ' lost its frame icon');
            $this->assertSame(1, mb_strlen($icon), $pane->name . "'s icon must be a single codepoint");
            $this->assertLessThanOrEqual(0xFFFF, mb_ord($icon), $pane->name . "'s icon must stay in the BMP");
            $this->assertSame(1, Width::of($icon), $pane->name . "'s icon is not display-width 1");
            $this->assertSame(1, mb_strwidth($icon), $pane->name . "'s icon counts double-width to mbstring (EAW W/F) — the 8.4 chrome-line budget breaks");
            $icons[$pane->name] = $icon;
        }

        $this->assertCount(6, array_unique($icons), 'two framed panes advertise the same picture');

        $this->assertSame(
            array_keys(self::ICON_EAW_WHITELIST),
            array_values($icons),
            'the framed-pane icon set drifted from the adjudicated EAW whitelist — re-run the Unicode table check before extending it',
        );
        foreach (self::ICON_EAW_WHITELIST as $glyph => $property) {
            $this->assertSame('N', $property, "whitelisted icon " . mb_ord($glyph, 'UTF-8') . " is not property N");
        }
    }

    /**
     * @testdox Input, Help and Menu — the chrome-only surfaces — carry no picture
     */
    public function testChromeOnlyPanesCarryNoIcon(): void
    {
        $this->assertSame('', Pane::Input->icon());
        $this->assertSame('', Pane::Help->icon());
        $this->assertSame('', Pane::Menu->icon());
    }
}
