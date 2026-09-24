<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\InputReader;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Renderer as LiveRenderer;
use SugarCraft\Crush\Tui\Components\MenuBar;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\PaneDragController;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;
use SugarCraft\Layout\Dock\Side;

/**
 * The docking gestures driven through the REAL byte path: raw SGR mouse
 * sequences decoded by the production {@see InputReader()}, then fed into the
 * production {@see App::update()} — no constructed MouseMsg objects anywhere.
 *
 * Why this file exists: the live report "dragging isnt working ... i click on
 * the pane header and try to drag it but nothing happens" was invisible to
 * every constructed-message test. The bar's pane tabs (docking L2) became the
 * header the eye reaches first, yet only the frame header (`pane:`) armed the
 * drag — a press-drag-release on `panetab:` was a cancelled click with zero
 * observable effect. These pins ride the bytes a terminal actually sends, and
 * aim at the cells the frame actually PAINTS (located off the rendered body,
 * independent of the scanner), so the two coordinate systems can never again
 * drift apart silently: an icon inserted into a label without the zone
 * following it reddens the alignment asserts here, not just the gesture ones.
 *
 * Cast: the default dock plus Tools docked Left (stacking that band so its
 * divider column exists) and Skills docked Right — the drop side already
 * holds one pane, so a landed Files is observable as an APPEND behind it.
 *
 * @see App::beginPaneDrag()
 * @see PaneDragController
 */
final class DockDragBytePathTest extends TestCase
{
    private const BARE_COLS = 120;

    private const BARE_ROWS = 40;

    /** Both bands are active in the cast, so two divider columns leave the content space. */
    private const CONTENT_COLS = self::BARE_COLS - 2;

    private ProviderInterface $provider;

    /** @var list<array<string, mixed>> */
    private array $writes = [];

    private string|false $ambientDisableMouse;

    private string|false $ambientDisableClicks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ambientDisableMouse = getenv('SUGARCRUSH_DISABLE_MOUSE');
        $this->ambientDisableClicks = getenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS');
        putenv('SUGARCRUSH_DISABLE_MOUSE');
        putenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS');

        TuiRenderer::setSize(200, 60);
        TuiRenderer::chromeScanner()->clear();
        LiveRenderer::scanner()->clear();
        LiveRenderer::setZoneOrigin(0, 0);
        App::resetPaneDragController();
        $this->clearChromeTracker();
        $this->writes = [];

        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('TestProvider');
    }

    protected function tearDown(): void
    {
        $this->restoreMouseEnvironment();

        TuiRenderer::chromeScanner()->clear();
        LiveRenderer::scanner()->clear();
        LiveRenderer::setZoneOrigin(0, 0);
        App::resetPaneDragController();
        $this->clearChromeTracker();
        TuiRenderer::setSize(200, 60);

        parent::tearDown();
    }

    // =========================================================================
    // The reported dead path: dragging the menu-bar pane tab
    // =========================================================================

    public function testARawByteDragFromThePaintedMenuBarTabMovesTheDockedPane(): void
    {
        $app = $this->castApp();
        $body = $this->paintBody($app);

        [$tabX, $tabY] = $this->paintedTabCell($body, 'Files');

        // Alignment first: the cell the user AIMED at by reading the painted
        // label must be the cell whose chrome zone names that pane's tab.
        $zone = TuiRenderer::chromeZoneAt($tabX, $tabY);
        self::assertSame(MenuBar::PANE_TAB_ZONE_PREFIX . Pane::Files->value, $zone?->id);

        $frame = TuiRenderer::lastDockFrame();
        self::assertNotNull($frame);

        $app = $this->driveBytes($app, $this->dragBurst($tabX, $tabY, $frame['centerTo'] + 2, $tabY + 2));

        self::assertSame(['tools'], $this->slotIdsOn($app, Side::Left), 'Files left the band it was dragged out of');
        self::assertSame(['skills', 'files'], $this->slotIdsOn($app, Side::Right), 'Files landed in the band under the release');
        self::assertCount(1, $this->writes, 'the drop commits exactly one manifest');
        self::assertTrue(App::paneDragController()->isIdle(), 'the release retires the gesture');
    }

    public function testTheTabDragArmsOnlyAfterThePointerLeavesTheTolerance(): void
    {
        $app = $this->castApp();
        $body = $this->paintBody($app);
        [$tabX, $tabY] = $this->paintedTabCell($body, 'Files');

        // One cell of travel: still inside the tolerance, so the gesture
        // stays a POTENTIAL drag — a release now must complete the plain
        // click-to-toggle instead of moving anything.
        $app = $this->driveBytes($app, "\x1b[<0;{$tabX};{$tabY}M\x1b[<32;" . ($tabX + 1) . ";{$tabY}M");
        self::assertTrue(App::paneDragController()->isDockDragging());
        self::assertFalse(App::paneDragController()->isArmed(), 'one cell is a click, not a drag');

        // Two cells total: the tolerance is passed, the drag is real now.
        $app = $this->driveBytes($app, "\x1b[<32;" . ($tabX + 2) . ";{$tabY}M");
        self::assertTrue(App::paneDragController()->isArmed());
    }

    public function testAStationaryRawByteClickOnTheTabOfADockedPaneStillTogglesItUndocked(): void
    {
        $app = $this->castApp();
        $body = $this->paintBody($app);
        [$tabX, $tabY] = $this->paintedTabCell($body, 'Files');

        // The press arms the controller (it cannot know the release is
        // coming without travel); the UNARMED release falls through to the
        // click the tab always completed.
        $app = $this->driveBytes($app, "\x1b[<0;{$tabX};{$tabY}M\x1b[<0;{$tabX};{$tabY}m");

        self::assertSame(['tools'], $this->slotIdsOn($app, Side::Left), 'the stationary click toggled Files away');
        self::assertCount(1, $this->writes, 'the toggle persists, once, as before the drag existed');
        self::assertTrue(App::paneDragController()->isIdle(), 'a fall-through release leaves no gesture behind');
    }

    public function testARawByteTabDragReleasedInsideTheCentreCancelsWithoutWriting(): void
    {
        $app = $this->castApp();
        $body = $this->paintBody($app);
        [$tabX, $tabY] = $this->paintedTabCell($body, 'Files');

        $frame = TuiRenderer::lastDockFrame();
        self::assertNotNull($frame);
        $centreX = intdiv($frame['centerFrom'] + $frame['centerTo'], 2) + 1;

        $app = $this->driveBytes($app, $this->dragBurst($tabX, $tabY, $centreX, $tabY + 2));

        self::assertSame(['files', 'tools'], $this->slotIdsOn($app, Side::Left), 'a centre drop is the universal cancel');
        self::assertSame(['skills'], $this->slotIdsOn($app, Side::Right));
        self::assertSame([], $this->writes, 'a cancelled drag writes nothing');
        self::assertTrue(App::paneDragController()->isIdle());
    }

    public function testADragBurstFromTheTabOfAnUndockedPaneArmsNothingAndWritesNothing(): void
    {
        $app = $this->castApp();
        $this->paintBody($app);
        // Undock Tools first (keyboard path, one write), then repaint so the
        // tab carries the muted state the press will be tested against.
        $app = $app->togglePaneDocking(Pane::Tools);
        $this->writes = [];
        $body = $this->paintBody($app);

        [$tabX, $tabY] = $this->paintedTabCell($body, 'Tools');

        // A press-drag-release FROM the undocked tab: nothing to move, so no
        // gesture arms and the burst is behaviourally a cancelled click —
        // the dock route stays the plain click (next test), not a drag.
        $frame = TuiRenderer::lastDockFrame();
        self::assertNotNull($frame);
        $app = $this->driveBytes($app, $this->dragBurst($tabX, $tabY, $frame['centerTo'] + 2, $tabY + 2));

        self::assertTrue(App::paneDragController()->isIdle(), 'an undocked tab offers no drag to arm');
        self::assertSame(['files'], $this->slotIdsOn($app, Side::Left), 'the far release cancelled the click; nothing docked');
        self::assertSame([], $this->writes, 'and nothing was written');
    }

    public function testTheUndockedTabStillClicksToDockOnItsHomeSide(): void
    {
        $app = $this->castApp();
        $this->paintBody($app);
        $app = $app->togglePaneDocking(Pane::Tools);
        $this->writes = [];
        $body = $this->paintBody($app);

        [$tabX, $tabY] = $this->paintedTabCell($body, 'Tools');
        $app = $this->driveBytes($app, "\x1b[<0;{$tabX};{$tabY}M\x1b[<0;{$tabX};{$tabY}m");

        self::assertSame(['files', 'tools'], $this->slotIdsOn($app, Side::Left), 'click-to-dock semantics unchanged');
        self::assertCount(1, $this->writes);
    }

    // =========================================================================
    // The surfaces that already worked: keep them riding the bytes too
    // =========================================================================

    public function testARawByteDragFromThePaintedFrameHeaderStillMovesTheDockedPane(): void
    {
        $app = $this->castApp();
        $body = $this->paintBody($app);

        [$headerX, $headerY] = $this->paintedHeaderCell($body, Pane::Files->value);

        $zone = TuiRenderer::chromeZoneAt($headerX, $headerY);
        self::assertSame(LiveRenderer::PANE_ZONE_PREFIX . Pane::Files->value, $zone?->id, 'the box-title row the user sees must be the row the zone covers');

        $frame = TuiRenderer::lastDockFrame();
        self::assertNotNull($frame);

        $app = $this->driveBytes($app, $this->dragBurst($headerX, $headerY, $frame['centerTo'] + 2, $headerY + 1));

        self::assertSame(['tools'], $this->slotIdsOn($app, Side::Left));
        self::assertSame(['skills', 'files'], $this->slotIdsOn($app, Side::Right));
        self::assertCount(1, $this->writes);
        self::assertTrue(App::paneDragController()->isIdle());
    }

    public function testARawByteDividerDragPreviewsInNextFrameResizesAndPersistsOnce(): void
    {
        $app = $this->castApp();
        $before = $this->paintBody($app);

        $divider = $this->stackedDividerCell();
        [$col, $row] = $divider;

        $startColumns = $this->bandColumns($app, Side::Left);

        // Press alone: the resize arms at the grab by definition...
        $app = $this->driveBytes($app, "\x1b[<0;{$col};{$row}M");
        self::assertTrue(App::paneDragController()->isResizing());
        $pressFrame = $this->paintBody($app);

        // ...and the motion both previews on the model and PAINTS it into the
        // very next frame — the live "something follows my pointer" proof.
        $app = $this->driveBytes($app, "\x1b[<32;" . ($col + 10) . ";{$row}M");
        $motionFrame = $this->paintBody($app);
        self::assertNotSame($pressFrame, $motionFrame, 'the drag preview must be visible without any extra signal');
        self::assertSame(
            ['num' => $startColumns + 10, 'denom' => self::CONTENT_COLS],
            $app->dock()->columnShare(Side::Left),
        );
        self::assertSame([], $this->writes, 'motion previews, never persists');

        $app = $this->driveBytes($app, "\x1b[<0;" . ($col + 10) . ";{$row}m");

        self::assertSame(
            ['num' => $startColumns + 10, 'denom' => self::CONTENT_COLS],
            $app->dock()->columnShare(Side::Left),
            'the release re-states the pointer measurement',
        );
        self::assertCount(1, $this->writes, 'the release persists exactly once');
        self::assertTrue(App::paneDragController()->isIdle());
    }

    // =========================================================================
    // Harness
    // =========================================================================

    /**
     * Decode a raw byte burst with the production reader and dispatch every
     * resulting Msg through the production update(), repainting after each —
     * the exact sequence the Program loop runs per stdin event.
     */
    private function driveBytes(App $app, string $bytes): App
    {
        $messages = (new InputReader())->parse($bytes);
        self::assertNotEmpty($messages, 'a burst of SGR mouse bytes must decode to at least one Msg');

        foreach ($messages as $message) {
            [$app] = $app->update($message);
            $this->paintBody($app);
        }

        return $app;
    }

    private function castApp(): App
    {
        $app = App::new($this->provider, 'test-model')
            ->withChat(new Chat())
            ->setPaneSide(Pane::Tools, Side::Left);

        $dock = $app->dock()->withSlotAdded(Side::Right, Pane::Skills->value);

        return $app
            ->withDock($dock)
            ->withOnLayoutChange(function (array $manifest): void {
                $this->writes[] = $manifest;
            });
    }

    /**
     * @return string the painted frame body (markers still in it; readers of
     *                the painted text strip them first)
     */
    private function paintBody(App $app): string
    {
        return TuiRenderer::renderView($app, self::BARE_COLS, self::BARE_ROWS)->body;
    }

    /**
     * A full drag as raw bytes: press, six motion frames travelling east in
     * 20-cell steps (well past the 1-cell tolerance, across the centre), and
     * the release at the asked cell.
     */
    private function dragBurst(int $x, int $y, int $releaseX, int $releaseY): string
    {
        $bytes = "\x1b[<0;{$x};{$y}M";

        for ($step = 1; $step <= 6; $step++) {
            $travel = $x + $step * 20;

            if ($travel >= $releaseX) {
                break;
            }

            $bytes .= "\x1b[<32;{$travel};{$y}M";
        }

        return $bytes . "\x1b[<0;{$releaseX};{$releaseY}m";
    }

    /**
     * The 1-based terminal cell of a menu-bar tab label, found by READING
     * the painted frame (line 1, marker- and SGR-free) — never by asking the
     * scanner, so the zone-vs-paint alignment is part of the assertion.
     *
     * @return array{0: int, 1: int} col, row (1-based)
     */
    private function paintedTabCell(string $body, string $label): array
    {
        return $this->paintedCellOnLine($this->plainLine($body, 0), $label, 1);
    }

    /**
     * The 1-based terminal cell of a docked pane's painted frame-header word
     * (the box-title row), located by reading the frame below the menu bar.
     *
     * @return array{0: int, 1: int} col, row (1-based)
     */
    private function paintedHeaderCell(string $body, string $paneWord): array
    {
        $lines = explode("\n", $body);

        for ($i = 1; $i < count($lines); $i++) {
            $plain = $this->plainText($lines[$i]);

            if (mb_strpos($plain, $paneWord) !== false) {
                return $this->paintedCellOnLine($plain, $paneWord, $i + 1);
            }
        }

        self::fail("the painted frame never showed a header word '{$paneWord}' below the menu bar");
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function paintedCellOnLine(string $plainLine, string $needle, int $oneBasedRow): array
    {
        $position = mb_strpos($plainLine, $needle);
        self::assertNotFalse($position, "painted line does not carry '{$needle}'");

        return [Width::of(mb_substr($plainLine, 0, $position)) + 1, $oneBasedRow];
    }

    private function plainLine(string $body, int $zeroBasedIndex): string
    {
        return $this->plainText(explode("\n", $body)[$zeroBasedIndex]);
    }

    /**
     * Drop the zero-width zone sentinels and SGR runs so what is left is the
     * visible cell grid, exactly as wide as the terminal lays it out.
     */
    private function plainText(string $line): string
    {
        // (?: ... ) grouping is deliberate: an ungrouped `*` here lexes as a
        // glob-shaped literal into the GlobDialectDifferentialTest corpus and
        // drifts the PathGlob pair-count figure (round-67/+fj law).
        $unzoned = (string) preg_replace('/\x{e000}(?:[^\x{e001}]*)\x{e001}/u', '', $line);

        return (string) preg_replace('/\x1b\[[0-9;]*m/', '', $unzoned);
    }

    /**
     * The middle painted cell of the left band's per-row divider zones — the
     * middle keeps the press off gap rows (stackdiv), exactly like the
     * constructed-message suite does, except every consumer downstream here
     * takes raw bytes.
     *
     * @return array{0: int, 1: int} col, row (1-based)
     */
    private function stackedDividerCell(): array
    {
        $zones = array_values(TuiRenderer::chromeScanner()->prefixed(LiveRenderer::DIVIDER_ZONE_PREFIX . 'left:'));
        self::assertNotEmpty($zones, 'the left band must be stacked for a divider column to exist');

        usort($zones, static fn($a, $b): int => $a->startRow <=> $b->startRow);
        $middle = $zones[intdiv(count($zones) - 1, 2)];

        return [$middle->startCol, $middle->startRow];
    }

    /**
     * Content columns the left band claims right now at the cast size.
     */
    private function bandColumns(App $app, Side $side): int
    {
        $slots = $app->dock()->slots($side);
        self::assertNotSame([], $slots);
        $region = $app->dock()->resolve(new \SugarCraft\Layout\Region(0, 0, self::BARE_COLS, self::BARE_ROWS - 4))
            ->regionFor($slots[0]->paneId);
        self::assertNotNull($region);

        return $region->width;
    }

    /**
     * @return list<string>
     */
    private function slotIdsOn(App $app, Side $side): array
    {
        return array_map(static fn($slot): string => $slot->paneId, $app->dock()->slots($side));
    }

    private function clearChromeTracker(): void
    {
        (new \ReflectionProperty(App::class, 'chromeClickTracker'))->setValue(null, null);
    }

    /**
     * Restore the two mouse env switches to exactly the ambient process had,
     * so nothing here leaks a disabled-mouse environment into a sibling.
     */
    private function restoreMouseEnvironment(): void
    {
        $ambient = [
            'SUGARCRUSH_DISABLE_MOUSE' => $this->ambientDisableMouse,
            'SUGARCRUSH_DISABLE_MOUSE_CLICKS' => $this->ambientDisableClicks,
        ];

        foreach ($ambient as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }
}
