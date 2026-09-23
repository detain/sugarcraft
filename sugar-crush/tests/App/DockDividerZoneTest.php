<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Renderer as LiveRenderer;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;
use SugarCraft\Layout\Dock\DockLayout;
use SugarCraft\Layout\Dock\Side;

/**
 * The divider click targets the stacked sides stamp into the chrome scanner.
 *
 * Phase 2's contract with the (future) gesture phase: every rendered row of a
 * stacked side's divider column carries its OWN single-cell
 * `divider:<side>:r<absRow>` zone — never one multi-row zone, because
 * `renderView()` drops whole leading lines under height clipping and a
 * zone split across the drop would desynchronise the scanner's row
 * bookkeeping for the entire frame (the invariant documented on
 * {@see LiveRenderer::DIVIDER_ZONE_PREFIX}). An intra-stack gap row instead
 * carries the whole-width `stackdiv:<side>:<slotIndex>:r<absRow>` zone, and
 * strictly one zone per line: the divider column skips gap rows.
 *
 * A single-pane side carries the same per-row divider click zones a stacked
 * side does: Fix 2 overturned the Phase-3 seam that had left a lone pane with
 * no grabbable divider — which made resize unusable out of the box, since the
 * default layout puts one pane per occupied side. No divider COLUMN is painted
 * for a single pane, so the frame stays byte-identical to the shipped flush
 * sidebar; the zones ride scanner-scratch rows that never join the frame.
 * Intra-stack gap rows stay stacked-only — a single pane stacks nothing.
 */
final class DockDividerZoneTest extends TestCase
{
    private ProviderInterface $provider;

    private string|false $originalDisableMouse;

    private string|false $originalDisableClicks;

    protected function setUp(): void
    {
        parent::setUp();
        TuiRenderer::setSize(200, 60); // deterministic default (round-61 flake; see tests/bootstrap.php)
        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('TestProvider');

        // Zones are only stamped while clicks are on; pin the switch off-state
        // so a developer's environment cannot decide the outcome.
        $this->originalDisableMouse = getenv('SUGARCRUSH_DISABLE_MOUSE');
        $this->originalDisableClicks = getenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS');
        putenv('SUGARCRUSH_DISABLE_MOUSE');
        putenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS');

        TuiRenderer::chromeScanner()->clear();
    }

    protected function tearDown(): void
    {
        TuiRenderer::chromeScanner()->clear();
        $this->restoreEnv('SUGARCRUSH_DISABLE_MOUSE', $this->originalDisableMouse);
        $this->restoreEnv('SUGARCRUSH_DISABLE_MOUSE_CLICKS', $this->originalDisableClicks);
        TuiRenderer::setSize(200, 60);
        parent::tearDown();
    }

    public function testASinglePaneSideCarriesPerRowDividerZonesButNoStackDivZones(): void
    {
        // Fix 2: default dock, Files focused — one pane, the pre-docking flush
        // frame. It now stamps a grabbable `divider:` column so a lone side is
        // resizable, but never a stacked-only `stackdiv:` gap zone.
        $this->render($this->app()->withPane(Pane::Files));

        $zones = TuiRenderer::chromeScanner()->prefixed(LiveRenderer::DIVIDER_ZONE_PREFIX);
        self::assertNotEmpty($zones, 'a single-pane side must offer a grabbable divider');

        $rows = [];
        foreach ($zones as $id => $zone) {
            self::assertMatchesRegularExpression('/\Adivider:left:r\d+\z/', $id);
            self::assertSame(1, $zone->width(), 'a single-pane divider zone wraps exactly one cell');
            self::assertSame(1, $zone->height(), 'one zone per rendered row, never a multi-row block');
            $rows[] = $zone->startRow;
        }

        self::assertSame(array_values(array_unique($rows)), $rows, 'each rendered row carries its OWN divider zone');

        self::assertSame(
            [],
            TuiRenderer::chromeScanner()->prefixed(LiveRenderer::STACK_DIVIDER_ZONE_PREFIX),
            'a single pane stacks nothing, so there is no intra-stack gap row',
        );
    }

    public function testStackedSideStampsOneSingleCellZonePerRenderedDividerRow(): void
    {
        $this->render($this->app()->withPane(Pane::Tools)); // Files docked + Tools transient -> stack

        $zones = TuiRenderer::chromeScanner()->prefixed(LiveRenderer::DIVIDER_ZONE_PREFIX);

        self::assertNotEmpty($zones);

        $rows = [];
        foreach ($zones as $id => $zone) {
            self::assertMatchesRegularExpression('/\Adivider:left:r\d+\z/', $id);
            self::assertSame($id, $zone->id);
            self::assertSame(1, $zone->width(), 'every divider zone wraps exactly one cell');
            self::assertSame(1, $zone->height(), 'every divider zone wraps exactly one row');
            self::assertSame($id, TuiRenderer::chromeScanner()->hit($zone->startCol, $zone->startRow)?->id);
            self::assertSame((int) substr($id, strrpos($id, 'r') + 1) + 1, $zone->startRow, 'id names the 0-based frame row; scanner stores the 1-based terminal row');
            $rows[] = $zone->startRow;
        }

        self::assertSame(array_values(array_unique($rows)), $rows, 'each row carries its OWN zone id — no multi-row divider zone');
        self::assertGreaterThanOrEqual(2, count($rows), 'the per-row shape only means something with at least two rows hit');
    }

    public function testANonDividerCellHitsNoDividerZone(): void
    {
        $this->render($this->app()->withPane(Pane::Tools));

        $zones = TuiRenderer::chromeScanner()->prefixed(LiveRenderer::DIVIDER_ZONE_PREFIX);
        $first = reset($zones);
        self::assertNotFalse($first);

        // Same row, a few cells into the chat column: a click there belongs
        // to the chat, never to the divider.
        self::assertNull(TuiRenderer::chromeScanner()->hit($first->startCol + 4, $first->startRow));
        // One row BELOW the band top, inside the Files box: a plain content
        // cell. (The band's TOP row is no longer empty inside the box — phase
        // 3 stamps the docked pane's header there as the drag press target —
        // so the "divider column is one cell wide" probe must step off that
        // row, not sit on it.)
        self::assertNull(TuiRenderer::chromeScanner()->hit($first->startCol - 4, $first->startRow + 1));
    }

    public function testTheGapRowCarriesTheWholeWidthStackDivZoneAndNoDividerZone(): void
    {
        $body = $this->render($this->app()->withPane(Pane::Tools));

        $stack = TuiRenderer::chromeScanner()->prefixed(LiveRenderer::STACK_DIVIDER_ZONE_PREFIX);
        self::assertCount(1, $stack, 'two stacked slots produce exactly one gap');

        $gap = reset($stack);
        self::assertMatchesRegularExpression('/\Astackdiv:left:0:r\d+\z/', $gap->id, 'slotIndex is the slot ABOVE the gap');
        self::assertSame(35, $gap->width(), 'the gap zone spans the WHOLE painted row — legacy 30 plus the widgets\' 4-column box chrome plus the divider cell — the harmonised one-rule width both sides share');

        // The id names the 0-based FRAME row; the scanner stores the 1-based
        // terminal row mouse reports arrive in. Pinned, not assumed.
        self::assertMatchesRegularExpression('/\Ar\d+\z/', substr($gap->id, strlen('stackdiv:left:0:')));
        $frameRow = (int) substr($gap->id, strrpos($gap->id, 'r') + 1);
        self::assertSame($frameRow + 1, $gap->startRow);

        // One zone per line, strictly: no divider zone may share the gap row.
        foreach (TuiRenderer::chromeScanner()->prefixed(LiveRenderer::DIVIDER_ZONE_PREFIX) as $divider) {
            self::assertNotSame($divider->startRow, $gap->startRow);
        }

        // And the painted row the id names is the ─ run followed by ITS
        // divider cell — the zone now crosses the full row (round-1 review
        // harmonisation), so width-1 columns of rule then the │.
        $frameLine = explode("\n", Ansi::strip($body))[$frameRow];
        self::assertSame(str_repeat("\u{2500}", $gap->width() - 1) . "\u{2502}", mb_substr($frameLine, 0, $gap->width()));
    }

    public function testTheStackedRightSideStampsItsOwnDividerZones(): void
    {
        $dock = App::defaultDock()
            ->withSlotAdded(Side::Right, 'skills')
            ->withSlotAdded(Side::Right, 'settings');
        $plain = Ansi::strip($this->render($this->app()->withDock($dock)->withPane(Pane::Chat)));

        // Stripped: the border glyph and the title run carry separate SGR
        // sequences in the raw body, so the readable string only exists once
        // colours are gone (same discipline as the gap-row pin above).
        self::assertStringContainsString('╭ ' . Pane::Skills->icon() . ' skills ', $plain);
        self::assertStringContainsString('╭ ' . Pane::Settings->icon() . ' settings ', $plain);

        $left = TuiRenderer::chromeScanner()->prefixed(LiveRenderer::DIVIDER_ZONE_PREFIX . 'left:');
        $right = TuiRenderer::chromeScanner()->prefixed(LiveRenderer::DIVIDER_ZONE_PREFIX . 'right:');

        // Fix 2 made the lone Left pane grabbable too, so `left` is no longer
        // the empty control it was under the Phase-3 seam; the point of this
        // test — that the RIGHT stacked side stamps its OWN zones on the far
        // column — is the `right` assertions below.
        self::assertNotEmpty($left, 'a single-pane left side now stamps divider zones too');
        self::assertNotEmpty($right);

        // The harmonised rule mirrored on the right: the gap zone STARTS on
        // its leading divider cell and crosses the full ─ run — painted+1
        // cells, the same whole-row span the left side pins above.
        $rightStack = TuiRenderer::chromeScanner()->prefixed(LiveRenderer::STACK_DIVIDER_ZONE_PREFIX . 'right:');
        self::assertCount(1, $rightStack);
        $rightGap = reset($rightStack);
        self::assertNotFalse($rightGap);
        $rightFrameRow = (int) substr($rightGap->id, strrpos($rightGap->id, 'r') + 1);
        $rightLine = explode("\n", $plain)[$rightFrameRow];
        self::assertSame(
            "\u{2502}" . str_repeat("\u{2500}", $rightGap->width() - 1),
            mb_substr($rightLine, $rightGap->startCol - 1, $rightGap->width()),
            'startCol is the 1-based divider cell; the zone spans rule+divider'
        );
    }

    public function testClicksDisabledClearsEveryDividerZone(): void
    {
        putenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS=1');
        $this->render($this->app()->withPane(Pane::Tools));

        self::assertSame([], TuiRenderer::chromeScanner()->prefixed(LiveRenderer::DIVIDER_ZONE_PREFIX));
        self::assertSame([], TuiRenderer::chromeScanner()->prefixed(LiveRenderer::STACK_DIVIDER_ZONE_PREFIX));
    }

    private function render(App $app): string
    {
        return TuiRenderer::renderView($app->withChat(new Chat()), 120, 40)->body;
    }

    private function app(): App
    {
        return App::new($this->provider, 'test-model');
    }

    private function restoreEnv(string $key, string|false $original): void
    {
        if ($original === false) {
            putenv($key);

            return;
        }

        putenv($key . '=' . $original);
    }
}
