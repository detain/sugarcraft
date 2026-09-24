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
 * The divider click targets the docked sides stamp into the chrome scanner.
 *
 * Every band row of an occupied side carries its OWN
 * `divider:<side>:r<absRow>` zone — never one multi-row zone, because
 * `renderView()` drops whole leading lines under height clipping and a
 * zone split across the drop would desynchronise the scanner's row
 * bookkeeping for the entire frame (the invariant documented on
 * {@see LiveRenderer::DIVIDER_ZONE_PREFIX}).
 *
 * Each row's zone covers the side's whole painted SEAM: the side box's own
 * border, the stacked divider column when there is one, and the centre
 * pane's border — on every band row, not only the rows the side's boxes
 * fill. The live report ("ctrl-click+dragging the sides should resize
 * them … it's not working, I've tried several combinations") was a
 * one-cell target sitting between two look-alike `│` columns on the few
 * rows at the top of the frame, while the centre border the eye reads as
 * "the side" ran inert down the rest of the screen.
 *
 * An intra-stack gap row carries a `stackdiv:<side>:<slotIndex>:r<absRow>`
 * zone over its ─ run but yields the seam cells to the divider zone, so a
 * press on the seam resizes on that row too. A single-pane side paints no
 * divider COLUMN, so its frame stays byte-identical to the shipped flush
 * sidebar; the zones ride scanner-scratch rows that never join the frame.
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
            self::assertSame(2, $zone->width(), 'a single-pane seam is the box border plus the centre border');
            self::assertSame(1, $zone->height(), 'one zone per rendered row, never a multi-row block');
            $rows[] = $zone->startRow;
        }

        self::assertSame(array_values(array_unique($rows)), $rows, 'each rendered row carries its OWN divider zone');

        $frame = TuiRenderer::lastDockFrame();
        self::assertNotNull($frame);
        self::assertCount($frame['paneRows'], $rows, 'the seam is grabbable down the WHOLE band, not just the rows the box fills');

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
            self::assertSame(3, $zone->width(), 'a stacked seam is box border + divider column + centre border');
            self::assertSame(1, $zone->height(), 'every divider zone wraps exactly one row');
            self::assertSame($id, TuiRenderer::chromeScanner()->hit($zone->startCol, $zone->startRow)?->id);
            self::assertSame((int) substr($id, strrpos($id, 'r') + 1) + 1, $zone->startRow, 'id names the 0-based frame row; scanner stores the 1-based terminal row');
            $rows[] = $zone->startRow;
        }

        self::assertSame(array_values(array_unique($rows)), $rows, 'each row carries its OWN zone id — no multi-row divider zone');
        self::assertGreaterThanOrEqual(2, count($rows), 'the per-row shape only means something with at least two rows hit');
    }

    public function testTheStackedSeamCoversTheThreePaintedBorderColumnsOnEveryBandRow(): void
    {
        $plain = explode("\n", Ansi::strip($this->render($this->app()->withPane(Pane::Tools))));
        $frame = TuiRenderer::lastDockFrame();
        self::assertNotNull($frame);

        $zones = TuiRenderer::chromeScanner()->prefixed(LiveRenderer::DIVIDER_ZONE_PREFIX . 'left:');
        self::assertCount($frame['paneRows'], $zones);

        $belowTheBoxes = 0;

        foreach ($zones as $zone) {
            $cells = mb_substr($plain[$zone->startRow - 1], $zone->startCol - 1, $zone->width());

            // Last cell is always the centre pane's own left border — the
            // full-height line that reads as "the side".
            self::assertContains(mb_substr($cells, 2, 1), ["\u{2502}", "\u{250C}", "\u{2514}"], 'the seam ends on the centre border (│, or its ┌/└ corner)');

            if (mb_substr($cells, 0, 2) === '  ') {
                $belowTheBoxes++;
            }
        }

        self::assertGreaterThan(0, $belowTheBoxes, 'rows past the stacked boxes still carry the seam');
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

    public function testTheGapRowCarriesTheRuleRunStackDivZoneAndYieldsTheSeamToTheDivider(): void
    {
        $body = $this->render($this->app()->withPane(Pane::Tools));

        $stack = TuiRenderer::chromeScanner()->prefixed(LiveRenderer::STACK_DIVIDER_ZONE_PREFIX);
        self::assertCount(1, $stack, 'two stacked slots produce exactly one gap');

        $gap = reset($stack);
        self::assertMatchesRegularExpression('/\Astackdiv:left:0:r\d+\z/', $gap->id, 'slotIndex is the slot ABOVE the gap');
        self::assertSame(33, $gap->width(), 'the gap zone spans the ─ run up to the seam — the painted 34-column row minus the box-border cell the seam owns');

        // The id names the 0-based FRAME row; the scanner stores the 1-based
        // terminal row mouse reports arrive in. Pinned, not assumed.
        self::assertMatchesRegularExpression('/\Ar\d+\z/', substr($gap->id, strlen('stackdiv:left:0:')));
        $frameRow = (int) substr($gap->id, strrpos($gap->id, 'r') + 1);
        self::assertSame($frameRow + 1, $gap->startRow);

        // The seam keeps its divider zone on the gap row, butted against the
        // gap zone: a press on the seam resizes here like on every other row
        // instead of being swallowed by the (still no-op) gap drag.
        $seam = TuiRenderer::chromeScanner()->hit($gap->startCol + $gap->width(), $gap->startRow);
        self::assertSame('divider:left:r' . $frameRow, $seam?->id);

        // The painted row: the gap zone is all rule; the seam starts on the
        // rule's last cell and crosses the divider │ onto the centre border.
        $frameLine = explode("\n", Ansi::strip($body))[$frameRow];
        self::assertSame(str_repeat("\u{2500}", $gap->width()), mb_substr($frameLine, 0, $gap->width()));
        self::assertSame("\u{2500}\u{2502}\u{2502}", mb_substr($frameLine, $seam->startCol - 1, $seam->width()));
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

        // Mirrored on the right: the seam (centre border, divider │, and the
        // rule's first cell) leads the row, and the gap zone takes the rest
        // of the ─ run.
        $rightStack = TuiRenderer::chromeScanner()->prefixed(LiveRenderer::STACK_DIVIDER_ZONE_PREFIX . 'right:');
        self::assertCount(1, $rightStack);
        $rightGap = reset($rightStack);
        self::assertNotFalse($rightGap);
        $rightFrameRow = (int) substr($rightGap->id, strrpos($rightGap->id, 'r') + 1);
        $rightLine = explode("\n", $plain)[$rightFrameRow];
        self::assertSame(
            str_repeat("\u{2500}", $rightGap->width()),
            mb_substr($rightLine, $rightGap->startCol - 1, $rightGap->width()),
            'the gap zone is all rule, starting past the seam'
        );

        $seam = TuiRenderer::chromeScanner()->hit($rightGap->startCol - 1, $rightGap->startRow);
        self::assertSame('divider:right:r' . $rightFrameRow, $seam?->id);
        self::assertSame(3, $seam->width());
        self::assertSame("\u{2502}\u{2502}\u{2500}", mb_substr($rightLine, $seam->startCol - 1, 3), 'centre border, divider, then the rule the seam claims');
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
