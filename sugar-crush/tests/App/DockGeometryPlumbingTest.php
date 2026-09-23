<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;
use SugarCraft\Layout\Dock\DockLayout;
use SugarCraft\Layout\Dock\Side;

/**
 * The docking layer's geometry plumbing, pinned below the frame.
 *
 * Phase 2 of pane-docking hands {@see TuiRenderer::renderSide()} a LIST of
 * panes per side — dock slots first, then the transiently-focused pane — and
 * stacks them at heights the candy-layout solver resolves. This file pins the
 * two private-static decisions that shape every stacked frame: which panes a
 * side paints, in what order, and how tall each stack slot is — including the
 * even-split fallback that runs when the degradation ladder has dropped the
 * side entirely at the frame's width.
 *
 * The private methods are driven through reflection deliberately: the
 * frame-level outcomes they produce are pinned by
 * {@see HostedFrameReadsThePaneTest()} and {@see DockDividerZoneTest}, but
 * "which heights did the stack rule answer" is invisible in the painted bytes
 * whenever the two rules happen to agree, and the fallback branch is
 * otherwise unreachable from a public entry point at any test-legal width.
 */
final class DockGeometryPlumbingTest extends TestCase
{
    private ProviderInterface $provider;

    protected function setUp(): void
    {
        parent::setUp();
        TuiRenderer::setSize(200, 60); // deterministic default (round-61 flake; see tests/bootstrap.php)
        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('TestProvider');
    }

    protected function tearDown(): void
    {
        TuiRenderer::setSize(200, 60);
        parent::tearDown();
    }

    public function testSidePanesListsTheDockedSlotsFirstThenTheTransientFocus(): void
    {
        // Files is docked left by the default; focusing Tools appends Tools
        // TRANSIENTLY after it — order is dock order, then focus, never the
        // other way around, so the stable box does not jump under the cursor.
        $app = $this->app()->withPane(Pane::Tools);

        self::assertSame([Pane::Files, Pane::Tools], self::invoke('sidePanes', $app, Side::Left));
        self::assertSame([], self::invoke('sidePanes', $app, Side::Right));
    }

    public function testASideNeverPaintsTheFocusedPaneTwiceWhenItIsAlreadyDocked(): void
    {
        $app = $this->app()->withPane(Pane::Files);

        self::assertSame([Pane::Files], self::invoke('sidePanes', $app, Side::Left));
    }

    public function testSidePanesDropsDockedSlotsForPanesWithNoHomeOnThatSide(): void
    {
        // A manifest written by another build could name a right-side pane in
        // a left slot; the renderer reads Pane::dockSide() rather than the
        // slot's placement, so such a slot paints nowhere instead of wrong.
        $dock = DockLayout::new('chat')->withSlotAdded(Side::Left, 'skills');
        $app = $this->app()->withDock($dock)->withPane(Pane::Chat);

        self::assertSame([], self::invoke('sidePanes', $app, Side::Left));
    }

    public function testStackHeightsSplitTheResolvedRegionsBetweenStackedSlots(): void
    {
        // Two equal left slots over a 38-row band: resolve() owns the split,
        // and the two slots plus the one gap row must fill the band exactly.
        $dock = App::defaultDock()->withSlotAdded(Side::Left, 'tools');
        $app = $this->app()->withDock($dock)->withPane(Pane::Chat);

        $heights = self::invoke('stackHeights', $app, [Pane::Files, Pane::Tools], 120, 38);

        self::assertSame([18, 19], $heights, '38 rows minus one gap row, floor-first with the residual to the last slot');
    }

    public function testStackHeightsFallBackToAnEvenSplitWhenTheSideWasDropped(): void
    {
        // Width 0 makes resolve() answer empty geometry — the documented
        // signal that the degradation ladder dropped this side — and the
        // local rule then splits rows - gaps evenly, residual to the LAST
        // slot: 40 rows, 2 slots -> budget 39 -> [19, 20].
        $heights = self::invoke('stackHeights', $this->app(), [Pane::Files, Pane::Tools], 0, 40);

        self::assertSame([19, 20], $heights);
    }

    public function testStackedLeftSidePaintsBothBoxesWithAGapRowBetweenAndNoOverlap(): void
    {
        // End-to-end through the frame: default dock + Tools focus stacks
        // both boxes in one column, separated by exactly one ─ row closed
        // by the side's │ divider, in a 120x40 terminal.
        $app = $this->app()->withChat(new Chat())->withPane(Pane::Tools);
        $frame = explode("\n", Ansi::strip(TuiRenderer::renderView($app, 120, 40)->body));

        $filesRow = null;
        $toolsRow = null;
        foreach ($frame as $i => $line) {
            if ($filesRow === null && str_starts_with($line, '╭ files ')) {
                $filesRow = $i;
            }
            if ($toolsRow === null && str_starts_with($line, '╭ tools ')) {
                $toolsRow = $i;
            }
        }

        self::assertNotNull($filesRow, 'the docked Files box header must be painted');
        self::assertNotNull($toolsRow, 'the transiently focused Tools box header must be painted');
        self::assertGreaterThan($filesRow, $toolsRow, 'Tools stacks BELOW Files, after the gap row');

        // The row directly above the Tools header is the single ─ gap, closed
        // on its right by the side's │ divider column.
        $gap = $frame[$toolsRow - 1];
        self::assertMatchesRegularExpression('/^\x{2500}+\x{2502}/u', $gap, 'gap row is the ─ run closed by the side divider │');

        // Between the two headers every row is Files-box content or its own
        // closing border — the Tools box cannot have started early and
        // overlapped it.
        for ($r = $filesRow; $r < $toolsRow - 1; $r++) {
            self::assertStringNotContainsString('╭ tools ', $frame[$r]);
        }

        // No row carries two box headers — stacked, never overlaid.
        foreach ($frame as $line) {
            self::assertLessThan(2, substr_count($line, '╭ '), 'one box header per row at most');
        }
    }

    private function app(): App
    {
        return App::new($this->provider, 'test-model');
    }

    /**
     * @return mixed
     */
    private static function invoke(string $method, mixed ...$args)
    {
        return (new \ReflectionMethod(TuiRenderer::class, $method))->invoke(null, ...$args);
    }
}
