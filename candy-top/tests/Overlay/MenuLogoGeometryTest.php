<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Overlay;

use SugarCraft\Animation\AnsiArt;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Overlay\HelpMenu;
use SugarCraft\Top\Overlay\MainMenu;
use SugarCraft\Top\Overlay\OptionsMenu;
use SugarCraft\Top\Overlay\OverlayContext;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Logo;
use SugarCraft\Top\View\LogoFrame;
use SugarCraft\Top\View\Surface;

/**
 * The ten-row-logo contract: every painted row the banner had moves down by
 * (height - 6) and the mouse hit-rects move WITH it; when the terminal is
 * too short for the taller block the overlays fall back to the legacy
 * six-row banner, byte-for-byte.
 */
final class MenuLogoGeometryTest extends OverlayTestCase
{
    private const DELTA = 4; // 10-row synthetic art vs the 6-row Banner.

    private string $tz;

    protected function setUp(): void
    {
        parent::setUp();
        putenv(Logo::CHOICE_ENV . '=off');
        Logo::resetForTesting();
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);
    }

    public function testButtonsShiftByDeltaAndLegacyArraysStand(): void
    {
        $this->assertSame(
            ['button_0' => [50, 16, 19, 3], 'button_1' => [53, 19, 12, 3], 'button_2' => [53, 22, 12, 3]],
            MainMenu::buttons(120, 40),
            'delta-less calls stay byte-identical to the legacy rects',
        );
        $this->assertSame(
            ['button_0' => [50, 20, 19, 3], 'button_1' => [53, 23, 12, 3], 'button_2' => [53, 26, 12, 3]],
            MainMenu::buttons(120, 40, self::DELTA),
        );
    }

    public function testMainClicksLandOnTheShiftedRows(): void
    {
        $menu = MainMenu::new();
        // Row 17: legacy it is button 0 (enter -> Options pushed);
        // with the logo the whole stack moved down and 17 is dead space.
        $legacy = $menu->update(self::click(55, 17), self::ctx());
        $this->assertInstanceOf(OptionsMenu::class, $legacy->push);
        $miss = $menu->update(self::click(55, 17), self::logoCtx());
        $this->assertNull($miss->overlay, 'the old button-0 row is outside the shifted rects (click-away closes)');
        $this->assertNull($miss->push);

        // Button 0 now lives on row 20; its enter still pushes Options.
        $shifted = $menu->update(self::click(55, 20), self::logoCtx());
        $this->assertInstanceOf(OptionsMenu::class, $shifted->push);

        // Buttons 1/2 moved to rows 23/26 and still select (no push).
        $one = $menu->update(self::click(55, 23), self::logoCtx());
        $this->assertNotSame($menu, $one->overlay);
        $this->assertNull($one->push);
        $two = $menu->update(self::click(55, 26), self::logoCtx());
        $this->assertNotSame($menu, $two->overlay);
        $this->assertNull($two->push);

        // And the legacy dead row stays dead in both worlds.
        $legacyBelow = $menu->update(self::click(55, 26), self::ctx());
        $this->assertNull($legacyBelow->overlay, 'row 26 never belonged to the legacy rects either');
    }

    public function testMainPaintPutsArtWhereTheBannerWasAndShiftsEntries(): void
    {
        $logo = self::painted(self::logoCtx());
        $legacy = self::painted(self::ctx());
        // The synthetic art occupies rows top-1 .. top-1+9 = 9..18.
        $this->assertStringContainsString('ABCD1234', $logo[9], 'art starts at the banner slot');
        $this->assertStringContainsString('abcdefgh', $logo[16]);
        $this->assertStringContainsString('JKLMNOPQ', $logo[18]);
        // The first entry row slid from 16 to 20 with the delta.
        $this->assertSame($legacy[16], $logo[20], 'entries ride the same shift as the rects');
    }

    public function testTooTallForTheTerminalFallsBackToLegacyPaint(): void
    {
        $tall = AnsiArt::fromString(implode("\n", array_fill(0, 20, 'ZZ')));
        $this->assertSame(
            self::painted(self::frameCtx(80, 24, null)),
            self::painted(self::frameCtx(80, 24, $tall)),
            'the fit predicate owes the byte-identical banner frame',
        );
    }

    public function testHelpGeometryShrinksAndStaysBottomAnchored(): void
    {
        [$x0, $y0, $h0, $p0] = HelpMenu::geometry(80, 24);
        [$x4, $y4, $h4, $p4] = HelpMenu::geometry(80, 24, self::DELTA);
        $this->assertSame($x0, $x4);
        $this->assertSame($y0, $y4, 'the box top is logo-relative and unchanged');
        $this->assertSame($h0 - self::DELTA, $h4, 'the box absorbs the growth from above');
        $this->assertGreaterThanOrEqual($p0, $p4, 'a shorter window cannot show more per page');
    }

    public function testOptionsGeometryCarriesDeltaAndEveryRectRidesIt(): void
    {
        $menu = OptionsMenu::new();
        $plain = $menu->geometry(self::ctx());
        $logo = $menu->geometry(self::logoCtx());
        $this->assertSame(0, $plain['delta']);
        $this->assertSame(self::DELTA, $logo['delta']);
        $this->assertSame($plain['height'] - self::DELTA, $logo['height']);
        $this->assertSame($plain['x'], $logo['x']);
        $this->assertSame($plain['y'], $logo['y']);

        $plainButtons = $menu->buttons(self::ctx());
        $logoButtons = $menu->buttons(self::logoCtx());
        $this->assertSameArrayKeys($plainButtons, $logoButtons);
        foreach ($plainButtons as $key => $rect) {
            $this->assertSame($rect[1] + self::DELTA, $logoButtons[$key][1], "rect {$key} must slide with the logo");
        }
    }

    public function testOptionsBottomDecorationsRideTheDeltaOntoTheBorderRow(): void
    {
        // The '┴' tab seam and the 'page' counter are drawn ON the box's
        // bottom border; before the delta was threaded they stayed at the
        // legacy row while the border slid down, so the logo frame rendered
        // with no seam and no page counter at all.
        $lines = self::optionsPainted(self::logoCtx());
        $border = self::indexOfFirst($lines, '╰');
        $this->assertNotNull($border, 'the options box must paint a bottom border');
        $this->assertStringContainsString('┴', $lines[$border], "'┴' seam belongs on the border row");
        $this->assertStringContainsString('↑ page', $lines[$border], 'the page counter belongs on the border row');
        $this->assertSame(
            $border,
            self::indexOfFirst($lines, '↑ page'),
            'exactly one page counter and it is the border row',
        );
    }

    public function testHelpPageRowRidesTheDeltaOntoTheBorderRow(): void
    {
        // Section frames ALSO draw bottom borders inside the box, so the
        // ride is pinned from the bottom: the lowest `╰` and the `↑ page`
        // counter must be the same row — the outer box's bottom border.
        $lines = self::helpPainted(self::logoCtx());
        $border = self::indexOfLast($lines, '↑ page');
        $this->assertNotNull($border);
        $this->assertStringContainsString('╰', $lines[$border], 'the page counter embeds into a bottom border');
        $this->assertSame($border, self::indexOfLast($lines, '╰'), 'the help page counter rides the OUTER bottom border — every frame closes above it');
    }

    public function testHelpJustFitsAtExactBottomRowKeepsTheLogo(): void
    {
        // 8-row art (delta 2) at 120x40: the box bottom lands ON row 40, the
        // last screen row. blockFits uses <=, so exact is a pass, not a
        // fallback — the legacy-floor check alone would also pass; this pins
        // the equality boundary itself.
        $c = self::frameCtx(120, 40, AnsiArt::fromString(implode("\n", array_map(
            static fn(int $i): string => 'EIGHT-' . $i,
            range(1, 8),
        ))));
        $this->assertSame(2, HelpMenu::logoDelta($c));
    }

    public function testHelpOverflowFallsBackToLegacyEvenPastTheFloorCheck(): void
    {
        // 120x72: the interior floor (72-6-4 >= 6) passes with delta 4, but
        // the centred y lands at row 2 and the content-driven height pushes
        // the bottom border to row 73 — off screen. The true-extents gate
        // must veto the delta and the frame must be the legacy one byte-wise.
        $c = self::frameCtx(120, 72, self::art());
        $this->assertSame(0, HelpMenu::logoDelta($c), 'off-screen bottom border owes a delta of 0');
        $this->assertSame(
            self::helpPainted(self::frameCtx(120, 72, null)),
            self::helpPainted($c),
            'the vetoed paint is the byte-identical legacy frame',
        );
    }

    public function testMainMenuExactBottomRowKeepsLogoOneRowPastLegacyVeto(): void
    {
        // 21-row art (delta 15) at 120x39 puts the last entry row exactly on
        // row 39 — blockFits' <= keeps the logo; one row shorter the same
        // block would clip, so the fallback must be byte-identical.
        $tall = AnsiArt::fromString(implode("\n", array_map(
            static fn(int $i): string => 'TALL' . $i,
            range(1, 21),
        )));
        $kept = self::painted(self::frameCtx(120, 39, $tall));
        $this->assertStringContainsString('TALL1', $kept[8], 'the logo paints from its shifted top');
        $this->assertNotSame(self::painted(self::frameCtx(120, 39, null)), $kept);
        $this->assertSame(
            self::painted(self::frameCtx(120, 38, null)),
            self::painted(self::frameCtx(120, 38, $tall)),
            'at 38 rows the same art clips and owes the legacy frame',
        );
    }

    /** @param list<string> $lines */
    private static function indexOfFirst(array $lines, string $needle): ?int
    {
        foreach ($lines as $i => $line) {
            if (str_contains($line, $needle)) {
                return $i;
            }
        }

        return null;
    }

    /** @param list<string> $lines */
    private static function indexOfLast(array $lines, string $needle): ?int
    {
        $found = null;
        foreach ($lines as $i => $line) {
            if (str_contains($line, $needle)) {
                $found = $i;
            }
        }

        return $found;
    }

    /** @return list<string> */
    private static function optionsPainted(OverlayContext $c): array
    {
        $surface = Surface::new($c->cols, $c->rows);
        (OptionsMenu::new())->paint($surface, $c);

        return $surface->plainLines();
    }

    /** @return list<string> */
    private static function helpPainted(OverlayContext $c): array
    {
        $surface = Surface::new($c->cols, $c->rows);
        (HelpMenu::new())->paint($surface, $c);

        return $surface->plainLines();
    }

    /** @param array<array-key, array> $a @param array<array-key, array> $b */
    private static function assertSameArrayKeys(array $a, array $b): void
    {
        self::assertSame(array_keys($a), array_keys($b));
    }

    private static function art(): AnsiArt
    {
        return AnsiArt::fromString(implode("\n", [
            'ABCD1234', 'EFGH2345', 'IJKL3456', 'MNOP4567', 'QRST5678',
            'UVWX6789', 'YZ017890', 'abcdefgh', 'ijklmnop', 'JKLMNOPQ',
        ]));
    }

    private static function logoCtx(): OverlayContext
    {
        return self::frameCtx(120, 40, self::art());
    }

    private static function frameCtx(int $cols, int $rows, ?AnsiArt $art): OverlayContext
    {
        return new OverlayContext(
            Config::new(),
            $cols,
            $rows,
            Ink::new(ThemeConfig::new()),
            [],
            false,
            null,
            $art === null ? null : new LogoFrame($art, 0),
        );
    }

    /** @return list<string> */
    private static function painted(OverlayContext $c): array
    {
        $surface = Surface::new($c->cols, $c->rows);
        (MainMenu::new())->paint($surface, $c);

        return $surface->plainLines();
    }
}
