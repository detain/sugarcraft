<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\App;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Tui\Components\MenuBar;
use SugarCraft\Code\Tui\Pane;
use SugarCraft\Code\Tui\Renderer as TuiRenderer;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;

/**
 * The UNTOUCHED default dock, swept across real terminal widths, never
 * amputates the chat's right border.
 *
 * This is the pin that C1 shipped without. sugar-constraints'
 * WidthSumInvariantSweepTest sweeps {@see \SugarCraft\Constraints\Dock\DockLayout::resolve()}
 * tiling — the exact path the untouched default frame BYPASSES via
 * {@see App::isUntouchedDefaultDock()} — so every width-sum invariant held
 * in the library while the shipped first-run frame lost its chat border on
 * every box row at 40..47 columns. Only the renderer's own frame, rendered
 * end to end in the regime a new user actually launches into, can see that
 * the `side + chrome + chat-floor` request exceeded the band and
 * {@see TuiRenderer}::clipWidth quietly ate the overflow from the right.
 *
 * Three laws per frame, band rows only (the menu bar is clipped, never
 * padded — invariant 3 of renderView):
 *  1. no row is wider than the terminal (the clip backstop holds);
 *  2. every band row is EXACTLY the terminal width — the side and the chat
 *     together paint the full band at every width that can host them;
 *  3. every band row CLOSES on the right with a box border glyph — the chat
 *     box's right edge survives, which is the difference between a narrow
 *     frame and a broken one.
 *
 * Plus the width-sum read straight from the published frame:
 * `centerTo + 1 <= cols` — the centre column ends inside the terminal.
 *
 * And the collapse itself, at its source: below the band width that can
 * host the side's minimum plus the chat's floor, the untouched side shrinks
 * CONTINUOUSLY (0..legacy) toward zero — the graceful ISS-099 answer the
 * mutated dock gets from the degradation ladder, mirrored into the bypass
 * path as a slope instead of a snap.
 */
final class UntouchedDockWidthSweepTest extends TestCase
{
    private const SWEEP_FROM = 40;
    private const SWEEP_TO = 200;
    private const BAND_ROWS = 24;

    /** The glyphs that may legally close a band row: box right borders. */
    private const CLOSING_GLYPHS = ['│', '┐', '┘'];

    protected function setUp(): void
    {
        parent::setUp();
        MenuBar::closeMenu();
        TuiRenderer::setSize(200, 60); // deterministic default (round-61 flake; see tests/bootstrap.php)
    }

    protected function tearDown(): void
    {
        MenuBar::closeMenu();
        TuiRenderer::setSize(200, 60);
        parent::tearDown();
    }

    /**
     * The heart of the gap: cols 40..200, untouched default dock, empty
     * chat. Pre-fix this reddens at every width 40..47 (right border eaten)
     * and stays green at 48+ (byte-identity of the shipped frame survives).
     */
    public function testTheUntouchedDefaultDockClosesEveryBandRowAcrossTheWidthSweep(): void
    {
        $app = $this->untouchedApp();

        for ($cols = self::SWEEP_FROM; $cols <= self::SWEEP_TO; ++$cols) {
            TuiRenderer::setSize($cols, self::BAND_ROWS);
            $frame = explode("\n", Ansi::strip(TuiRenderer::renderView($app, $cols, self::BAND_ROWS)->body));
            $band = TuiRenderer::lastDockFrame();
            self::assertNotNull($band, 'renderView publishes its dock frame for the pointer maths');

            // Law 0 (the sum, at its source): the centre column ends inside
            // the terminal — side + chrome + chat never exceed the band.
            self::assertLessThanOrEqual(
                $cols,
                $band['centerTo'] + 1,
                "cols={$cols}: the centre column runs past the terminal (centerFrom={$band['centerFrom']}, centerTo={$band['centerTo']})"
            );

            for ($row = $band['bandTop']; $row < $band['bandTop'] + $band['paneRows'] && $row < count($frame); ++$row) {
                $line = $frame[$row];
                $width = Width::of($line);

                // Law 1: never wider than the terminal.
                self::assertLessThanOrEqual($cols, $width, "cols={$cols} row={$row}: row overflows the terminal");
                // Law 2: the band paints the full width (no ragged short row).
                self::assertSame($cols, $width, "cols={$cols} row={$row}: band row is not the full terminal width: <{$line}>");
                // Law 3: the chat's right border survives — C1's signature.
                self::assertContains(
                    mb_substr(rtrim($line), -1),
                    self::CLOSING_GLYPHS,
                    "cols={$cols} row={$row}: the chat box's right border was amputated: <{$line}>"
                );
            }
        }
    }

    /**
     * The collapse is a slope, not a cliff: across the band widths that
     * cannot host the side's legacy floor beside the chat's floor, the side
     * gives up exactly one column per column the band loses, reaching zero
     * at the width where the painted chrome would be the only thing left.
     * Above the required band the legacy quarter is untouched — every
     * shipped golden lives there.
     */
    #[DataProvider('collapseLadder')]
    public function testTheUntouchedSideShrinksContinuouslyBelowTheHostableBand(int $bandCols, int $sideWidth): void
    {
        self::assertSame(
            $sideWidth,
            $this->sideWidthOfUntouchedDefault($bandCols),
            "bandCols={$bandCols}: the untouched side must answer {$sideWidth}"
        );
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function collapseLadder(): array
    {
        $ladder = [];
        // >= 48 the legacy max(20, quarter) is untouched (side + 4 chrome +
        // 24 chat floor = 48 is the narrowest hostable band); below it the
        // side shrinks one-for-one to 0 at 28.
        foreach ([26 => 0, 27 => 0, 28 => 0, 29 => 1, 32 => 4, 40 => 12, 44 => 16, 47 => 19, 48 => 20, 79 => 20, 80 => 20, 100 => 25, 127 => 31, 200 => 50] as $band => $want) {
            $ladder["band {$band}"] = [$band, $want];
        }

        return $ladder;
    }

    /**
     * Continuity law, stated as a property rather than a table: walking one
     * column narrower below the hostable band never costs more than one
     * column of side (no snap), and never goes negative.
     */
    public function testTheCollapseNeverSnapsMoreThanOneColumnPerStep(): void
    {
        $previous = $this->sideWidthOfUntouchedDefault(self::SWEEP_TO);

        for ($band = self::SWEEP_TO - 1; $band >= 20; --$band) {
            $side = $this->sideWidthOfUntouchedDefault($band);
            self::assertGreaterThanOrEqual(0, $side, "bandCols={$band}: side width went negative");
            self::assertLessThanOrEqual(1, $previous - $side, "bandCols={$band}: the side snapped by more than one column ({$previous} -> {$side})");
            $previous = $side;
        }
    }

    private function untouchedApp(): App
    {
        $provider = self::createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('stub');

        return App::new($provider, 'test-model')->withChat(new Chat());
    }

    private function sideWidthOfUntouchedDefault(int $bandCols): int
    {
        $app = $this->untouchedApp();
        self::assertTrue(
            App::isUntouchedDefaultDock($app->dock()),
            'the sweep app must never touch the dock — that is the regime under test'
        );

        return (new ReflectionMethod(TuiRenderer::class, 'sideWidth'))->invoke(null, $app, Pane::Files, $bandCols, 40);
    }
}
