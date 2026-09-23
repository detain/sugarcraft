<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseMotionMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\App\DockPaneMsg;
use SugarCraft\Crush\App\LayoutResetMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Renderer as LiveRenderer;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\PaneDragController;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;
use SugarCraft\Layout\Dock\Side;

/**
 * The gesture phase driven end-to-end: scripted MouseMsg sequences through
 * the REAL {@see App::update()}, against zones and band geometry a real
 * {@see TuiRenderer::renderView()} painted — the wiring face of
 * {@see PaneDragController}'s arithmetic.
 *
 * The cast: default dock plus Tools and Skills genuinely docked (Left holds
 * Files+Tools, Right holds Skills), which stacks both bands, paints grabbable
 * `divider:` columns on each, and stamps a `pane:` header row per docked pane
 * — the two grip surfaces plus the click surface the phase must not disturb.
 *
 * Persistence is counted through the `onLayoutChange` hook the drag law
 * governs: previews ride the model, releases commit exactly ONE manifest,
 * cancels and plain clicks commit zero.
 *
 * @see \SugarCraft\Crush\Tui\PaneDragController
 * @see App::handleShellMouse()
 */
final class PaneDragIntegrationTest extends TestCase
{
    private const CAST_COLS = 120;

    /** Both bands are active in the cast, so two divider columns leave the content space. */
    private const USABLE = self::CAST_COLS - 2;

    private const CAST_ROWS = 40;

    private ProviderInterface $provider;

    /** @var list<array<string, mixed>> */
    private array $manifests = [];

    protected function setUp(): void
    {
        parent::setUp();

        putenv('SUGARCRUSH_DISABLE_MOUSE');
        putenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS');

        TuiRenderer::setSize(200, 60);
        TuiRenderer::chromeScanner()->clear();
        LiveRenderer::scanner()->clear();
        LiveRenderer::setZoneOrigin(0, 0);
        App::resetPaneDragController();
        $this->resetChromeTracker();

        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('TestProvider');
    }

    protected function tearDown(): void
    {
        putenv('SUGARCRUSH_DISABLE_MOUSE');
        putenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS');

        TuiRenderer::chromeScanner()->clear();
        LiveRenderer::scanner()->clear();
        LiveRenderer::setZoneOrigin(0, 0);
        App::resetPaneDragController();
        $this->resetChromeTracker();
        TuiRenderer::setSize(200, 60);

        parent::tearDown();
    }

    // =========================================================================
    // Divider resize
    // =========================================================================

    public function testADividerDragPreviewsOnMotionAndCommitsOneManifestOnRelease(): void
    {
        $app = $this->app();
        $this->render($app);

        $divider = $this->dividerZone('left');
        $before = $app->dock()->columnShare(Side::Left);
        self::assertSame(['num' => 1, 'denom' => 3], $before, 'untouched default until the drag moves');

        [$app] = $app->update($this->press($divider->startCol, $divider->startRow));
        self::assertTrue(App::paneDragController()->isResizing());
        $startWidth = self::sideWidthPx($app, Side::Left);

        [$app] = $app->update($this->motion($divider->startCol + 10, $divider->startRow));

        // The preview is the pointer's stated measurement: the grabbed
        // divider's travel translated onto the side's current content width,
        // over the usable budget (band minus one divider per ACTIVE side).
        // No persist rode the motion.
        self::assertSame(
            ['num' => $startWidth + 10, 'denom' => self::USABLE],
            $app->dock()->columnShare(Side::Left),
        );
        self::assertSame([], $this->manifests, 'motion previews, never persists');

        [$app] = $app->update($this->release($divider->startCol + 10, $divider->startRow));

        self::assertSame(
            ['num' => $startWidth + 10, 'denom' => self::USABLE],
            $app->dock()->columnShare(Side::Left),
            'the release re-states the final width at the pointer (same cell, same answer)',
        );
        self::assertCount(1, $this->manifests);
        self::assertSame(
            [$startWidth + 10, self::USABLE],
            $this->manifests[0]['columnShare']['left'],
            'the one committed manifest carries the final share',
        );
        self::assertTrue(App::paneDragController()->isIdle());
    }

    public function testADragShrinkingPastTheSideFloorCommitsTheFloor(): void
    {
        $app = $this->app();
        $this->render($app);

        $divider = $this->dividerZone('left');

        [$app] = $app->update($this->press($divider->startCol, $divider->startRow));
        [$app] = $app->update($this->motion($divider->startCol - 20, $divider->startRow));
        [$app] = $app->update($this->release($divider->startCol - 20, $divider->startRow));

        // The pointer asked for 19 columns; the side floor answers 20.
        self::assertSame(['num' => 20, 'denom' => self::USABLE], $app->dock()->columnShare(Side::Left));
        self::assertCount(1, $this->manifests);
    }

    public function testADragGrowingPastTheCentreFloorCommitsTheCeilingAndResolveSurvives(): void
    {
        $app = $this->app();
        $this->render($app);

        $divider = $this->dividerZone('left');
        $releaseX = self::CAST_COLS;

        [$app] = $app->update($this->press($divider->startCol, $divider->startRow));
        [$app] = $app->update($this->motion($releaseX, $divider->startRow));
        [$app, ] = $app->update($this->release($releaseX, $divider->startRow));

        // The centre (40 content columns) can only spare 16 above its floor:
        // the ceiling pins the request even though the pointer ran the whole
        // band out.
        self::assertSame(['num' => 55, 'denom' => self::USABLE], $app->dock()->columnShare(Side::Left));
        self::assertCount(1, $this->manifests);

        // And the impossible-request path stays paintable: both sides claim
        // near half, resolve() degrades instead of throwing.
        $geometry = $app->dock()->resolve(new \SugarCraft\Layout\Region(0, 0, self::CAST_COLS, self::CAST_ROWS - 4));
        self::assertNotNull($geometry->regionFor('chat'));
    }

    public function testAStationaryClickOnTheDividerChangesNothingAndPersistsNothing(): void
    {
        $app = $this->app();
        $this->render($app);

        $divider = $this->dividerZone('left');

        [$app] = $app->update($this->press($divider->startCol, $divider->startRow));
        [$app] = $app->update($this->release($divider->startCol, $divider->startRow));

        self::assertSame(['num' => 1, 'denom' => 3], $app->dock()->columnShare(Side::Left));
        self::assertSame([], $this->manifests);
        self::assertTrue(App::paneDragController()->isIdle());
    }

    /**
     * The one release that cannot measure. Rendering a full-band
     * dashboard/overlay frame nulls Tui\Renderer::$lastDockFrame; if the
     * pointer is mid-divider-drag when that happens, the release lands with no
     * band to re-state against, so `previewColumnResize` hands back the model
     * untouched and persisting would freeze the last previewed width as a
     * manifest for nothing. The release path skips the write when the frame is
     * gone (a FRAME-PRESENCE test — the ordinary commit also returns the model
     * by identity, so object identity could not distinguish them).
     */
    public function testAModeSwitchMidDragNullsTheFrameAndTheReleasePersistsNothing(): void
    {
        $app = $this->app();
        $this->render($app);

        $divider = $this->dividerZone('left');

        [$app] = $app->update($this->press($divider->startCol, $divider->startRow));
        [$app] = $app->update($this->motion($divider->startCol + 12, $divider->startRow));

        // The preview genuinely moved the model (anti-vacuity: without a real
        // preview the skip would prove nothing) and a frame existed to measure
        // it against.
        self::assertNotSame(
            ['num' => 1, 'denom' => 3],
            $app->dock()->columnShare(Side::Left),
            'fixture: the motion previewed a real width change',
        );
        self::assertNotNull(TuiRenderer::lastDockFrame(), 'fixture: a dock band frame is live before the mode switch');

        // Simulate the mode switch: a dashboard frame renders and drops the
        // dock frame, exactly as Tui\Renderer's dashboard tail does.
        (new \ReflectionProperty(TuiRenderer::class, 'lastDockFrame'))->setValue(null, null);

        [$app] = $app->update($this->release($divider->startCol + 12, $divider->startRow));

        self::assertSame([], $this->manifests, 'an unmeasurable release must not write a manifest');
        self::assertTrue(App::paneDragController()->isIdle(), 'the gesture still ends idle');
    }

    /**
     * Step 0 (b2), pinned empirically: candy-core's Program repaints from its
     * periodic timer, so a motion event that mutates the dock is VISIBLE on
     * the very next frame with no extra signal — the live preview needs no
     * dirty flag. The divider column moving ten cells is the proof.
     */
    public function testAMotionPreviewIsVisibleInNextFrameWithoutAnyExtraSignal(): void
    {
        $app = $this->app();
        $this->render($app);
        $before = TuiRenderer::lastDockFrame();
        self::assertNotNull($before);

        $divider = $this->dividerZone('left');
        [$app] = $app->update($this->press($divider->startCol, $divider->startRow));
        [$app] = $app->update($this->motion($divider->startCol + 10, $divider->startRow));

        // Nothing but a repaint tick happened between the motion and this
        // render — exactly what the loop does every frame on its own.
        $this->render($app);
        $after = TuiRenderer::lastDockFrame();
        self::assertNotNull($after);

        self::assertSame($before['centerFrom'] + 10, $after['centerFrom'], 'the centre moved with the grabbed divider');
    }

    // =========================================================================
    // Header click-to-focus (regression: unchanged)
    // =========================================================================

    public function testAPressAndReleaseOnADockedHeaderStillFocusesThePaneWithoutADrag(): void
    {
        $app = $this->app();
        self::assertNotSame(Pane::Tools, $app->pane);
        $this->render($app);

        $header = $this->headerZone('tools');
        $cell = [$header->startCol + 1, $header->startRow];

        [$app] = $app->update($this->press(...$cell));
        [$app] = $app->update($this->release(...$cell));

        self::assertSame(Pane::Tools, $app->pane, 'the click-to-focus contract is untouched');
        self::assertTrue(App::paneDragController()->isIdle());
        self::assertSame(['num' => 1, 'denom' => 3], $app->dock()->columnShare(Side::Left));
        self::assertSame([], $this->manifests, 'a focus click never writes the layout');
    }

    // =========================================================================
    // Dock drag
    // =========================================================================

    public function testAnArmedHeaderDragReleasedEastOfTheCentreRedocksThePaneRight(): void
    {
        $app = $this->app();
        $this->render($app);
        $frame = TuiRenderer::lastDockFrame();
        self::assertNotNull($frame);

        $header = $this->headerZone('files');
        [$app] = $app->update($this->press($header->startCol + 1, $header->startRow));
        self::assertFalse(App::paneDragController()->isArmed(), 'a fresh grab is still a potential click');

        [$app] = $app->update($this->motion($header->startCol + 4, $header->startRow));
        self::assertTrue(App::paneDragController()->isArmed());

        // Release two cells past the centre's last column, one row below the
        // right band's only slot top: append behind Skills.
        $releaseX = $frame['centerTo'] + 3;
        [$app] = $app->update($this->release($releaseX, $header->startRow + 1));

        self::assertSame(
            ['tools'],
            self::slotIds($app, Side::Left),
        );
        self::assertSame(
            ['skills', 'files'],
            self::slotIds($app, Side::Right),
        );
        self::assertCount(1, $this->manifests, 'the drop commits exactly once');
        self::assertTrue(App::paneDragController()->isIdle());
    }

    public function testAnArmedHeaderDragReleasedInsideTheCentreCancels(): void
    {
        $app = $this->app();
        $this->render($app);
        $frame = TuiRenderer::lastDockFrame();
        self::assertNotNull($frame);

        $header = $this->headerZone('files');
        [$app] = $app->update($this->press($header->startCol + 1, $header->startRow));
        [$app] = $app->update($this->motion($header->startCol + 3, $header->startRow));

        $centreX = intdiv($frame['centerFrom'] + $frame['centerTo'], 2) + 1;
        [$app] = $app->update($this->release($centreX, $header->startRow));

        self::assertSame(
            ['files', 'tools'],
            self::slotIds($app, Side::Left),
        );
        self::assertSame(
            ['skills'],
            self::slotIds($app, Side::Right),
            'the right band keeps exactly what it had: the drop cancelled',
        );
        self::assertSame([], $this->manifests, 'a cancelled drag writes nothing');
        self::assertTrue(App::paneDragController()->isIdle());
    }

    public function testEscapeMidResizeRestoresThePreviewedWidthAndPersistsNothing(): void
    {
        $app = $this->app();
        $this->render($app);

        $divider = $this->dividerZone('left');
        [$app] = $app->update($this->press($divider->startCol, $divider->startRow));
        [$app] = $app->update($this->motion($divider->startCol + 12, $divider->startRow));

        // The preview really did ride the model — otherwise this test would
        // pass vacuously on a preview that never applied.
        self::assertSame(
            ['num' => 51, 'denom' => self::USABLE], // 39 content columns + 12 columns of travel
            $app->dock()->columnShare(Side::Left),
        );

        [$app] = $app->update(new KeyMsg(KeyType::Escape));

        self::assertSame(['num' => 1, 'denom' => 3], $app->dock()->columnShare(Side::Left), 'the snapshot rode back');
        self::assertTrue(App::paneDragController()->isIdle());
        self::assertSame([], $this->manifests);
    }

    public function testEscapeMidDockDragEndsTheGestureWithoutMovingTheDock(): void
    {
        $app = $this->app();
        $this->render($app);

        $header = $this->headerZone('files');
        [$app] = $app->update($this->press($header->startCol + 1, $header->startRow));
        [$app] = $app->update($this->motion($header->startCol + 5, $header->startRow + 3));

        [$app] = $app->update(new KeyMsg(KeyType::Escape));

        self::assertTrue(App::paneDragController()->isIdle());
        self::assertSame(
            ['files', 'tools'],
            self::slotIds($app, Side::Left),
        );
        self::assertSame([], $this->manifests);
    }

    // =========================================================================
    // Command path (the keyboard twins of the gestures)
    // =========================================================================

    public function testTheShellMessageDocksTheFocusedPaneAndPersistsOnce(): void
    {
        $app = $this->app()->withPane(Pane::Tools);

        [$app] = $app->update(new DockPaneMsg('right'));

        self::assertContainsSlot($app, Side::Right, 'tools');
        self::assertCount(1, $this->manifests);
        self::assertStringContainsString('docked', (string) $app->status);
    }

    public function testTheShellMessageNamesAPaneExplicitlyAndRejectsBadSides(): void
    {
        $app = $this->app();

        [$app] = $app->update(new DockPaneMsg('sideways', 'skills'));
        self::assertStringContainsString('must be left or right', (string) $app->error);

        [$app] = $app->update(new DockPaneMsg('left', 'chat'));
        self::assertStringContainsString('not a dockable pane', (string) $app->error);

        [$app] = $app->update(new DockPaneMsg('right', 'skills'));
        self::assertContainsSlot($app, Side::Right, 'skills');
    }

    /**
     * `toggle` command twin: a docked pane (Tools on Left here) frees from
     * the slot it holds, and the removal persists exactly once.
     */
    public function testTheToggleMessageUndocksADockedPaneAndPersistsOnce(): void
    {
        $app = $this->app();
        self::assertContainsSlot($app, Side::Left, 'tools');

        [$app] = $app->update(new DockPaneMsg('toggle', 'tools'));

        self::assertSame(['files'], self::slotIds($app, Side::Left), 'only the untouched sibling survives on Left');
        self::assertCount(1, $this->manifests, 'the undock commits exactly once');
        self::assertStringContainsString('undocked', (string) $app->status);
    }

    /**
     * The other half of `toggle`: a dockable pane with no slot lands on its
     * HOME side (Agents → Right), never an arbitrary one.
     */
    public function testTheToggleMessageDocksAnUndockedPaneOntoItsHomeSide(): void
    {
        $app = $this->app();
        self::assertNotContains('agents', self::slotIds($app, Side::Right), 'fixture: agents is dockable but free');

        [$app] = $app->update(new DockPaneMsg('toggle', 'agents'));

        self::assertContainsSlot($app, Side::Right, 'agents');
        self::assertCount(1, $this->manifests);
        self::assertStringContainsString('docked', (string) $app->status);
    }

    /**
     * No name follows the focused pane (Tools → undock), and a non-dockable
     * name is refused in words — the same subject rules the dock arms obey.
     */
    public function testTheToggleMessageFollowsTheFocusedPaneAndRejectsNonDockableNames(): void
    {
        $app = $this->app()->withPane(Pane::Tools);
        [$app] = $app->update(new DockPaneMsg('toggle'));
        self::assertSame(['files'], self::slotIds($app, Side::Left), 'the focused docked pane was freed');

        [$app] = $app->update(new DockPaneMsg('toggle', 'chat'));
        self::assertStringContainsString('not a dockable pane', (string) $app->error);
    }

    /**
     * The Chat text layer that routes `/pane …` into the App command above —
     * proven here (reflection over the private handler) because no drag or
     * App-level test reaches the parser. Accepted spellings must carry the
     * right verb and name; a malformed verb is answered with usage and NO
     * command at all (nothing reaches the model).
     */
    public function testThePaneParserRoutesToggleAndDockVerbsToTheShellMessage(): void
    {
        $parse = new \ReflectionMethod(Chat::class, 'handlePaneCommand');
        $chat = new Chat(backend: new \SugarCraft\Crush\Backend\EchoBackend());

        [$next, $cmd] = $parse->invoke($chat, '/pane toggle files');
        self::assertNotNullCmd($cmd, '/pane toggle files');
        $msg = $cmd();
        self::assertInstanceOf(DockPaneMsg::class, $msg);
        self::assertSame('toggle', $msg->action);
        self::assertSame('files', $msg->paneName);
        self::assertSame('', $next->inputBuf, 'the buffer clears on a routed command');

        [, $cmd] = $parse->invoke($chat, '/pane toggle');
        self::assertNotNull($cmd, 'a bare /pane toggle (focused pane) is still a command');
        self::assertNull((self::msgOf($cmd))->paneName, 'no name means the focused pane travels as null');
        self::assertSame('toggle', (self::msgOf($cmd))->action);

        [, $cmd] = $parse->invoke($chat, '/pane dock left tools');
        self::assertSame('left', (self::msgOf($cmd))->action);
        self::assertSame('tools', (self::msgOf($cmd))->paneName);

        [, $cmd] = $parse->invoke($chat, '/pane sideways');
        self::assertNull($cmd, 'an unknown verb is usage-only, never a model prompt');
    }

    /**
     * @param ?\Closure $cmd
     */
    private static function assertNotNullCmd(?\Closure $cmd, string $text): void
    {
        self::assertNotNull($cmd, "{$text} must emit a command");
    }

    /**
     * @param \Closure():object $cmd
     */
    private static function msgOf(\Closure $cmd): DockPaneMsg
    {
        $msg = $cmd();
        self::assertInstanceOf(DockPaneMsg::class, $msg);

        return $msg;
    }

    public function testTheLayoutResetMessageRestoresTheDefaultManifest(): void
    {
        $app = $this->app();
        [$app] = $app->update(new DockPaneMsg('right', 'tools'));
        self::assertCount(1, $this->manifests);

        [$app] = $app->update(new LayoutResetMsg());

        self::assertSame(
            ['files'],
            self::slotIds($app, Side::Left),
        );
        self::assertSame([], $app->dock()->slots(Side::Right));
        self::assertCount(2, $this->manifests, 'the reset persists its own manifest');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Files+Tools docked Left and Skills docked Right — set BEFORE the hook
     * is installed, so every manifest count below belongs to the gesture
     * under test alone.
     */
    private function app(): App
    {
        $app = App::new($this->provider, 'test-model')
            ->withChat(new Chat())
            ->setPaneSide(Pane::Tools, Side::Left);

        $dock = $app->dock()->withSlotAdded(Side::Right, 'skills');

        return $app
            ->withDock($dock)
            ->withOnLayoutChange(function (array $manifest): void {
                $this->manifests[] = $manifest;
            });
    }

    private function render(App $app): string
    {
        return TuiRenderer::renderView($app, self::CAST_COLS, self::CAST_ROWS)->body;
    }

    /**
     * The middle row of the named side's per-row divider zones — all carry
     * the same column; the middle keeps the press off gap rows (stackdiv).
     *
     * @return \SugarCraft\Mouse\Zone
     */
    private function dividerZone(string $side): \SugarCraft\Mouse\Zone
    {
        $zones = TuiRenderer::chromeScanner()->prefixed(LiveRenderer::DIVIDER_ZONE_PREFIX . $side . ':');
        self::assertNotEmpty($zones, "the {$side} band must be stacked for a divider to exist");

        $rows = array_values($zones);
        usort($rows, static fn($a, $b): int => $a->startRow <=> $b->startRow);

        return $rows[intdiv(count($rows) - 1, 2)];
    }

    /** @return \SugarCraft\Mouse\Zone */
    private function headerZone(string $paneId): \SugarCraft\Mouse\Zone
    {
        $zones = TuiRenderer::chromeScanner()->prefixed(LiveRenderer::PANE_ZONE_PREFIX);
        self::assertArrayHasKey('pane:' . $paneId, $zones);

        return $zones['pane:' . $paneId];
    }

    private function press(int $x, int $y): MouseClickMsg
    {
        return new MouseClickMsg($x, $y, MouseButton::Left, MouseAction::Press);
    }

    private function motion(int $x, int $y): MouseMotionMsg
    {
        return new MouseMotionMsg($x, $y, MouseButton::Left, MouseAction::Motion);
    }

    private function release(int $x, int $y): MouseReleaseMsg
    {
        return new MouseReleaseMsg($x, $y, MouseButton::Left, MouseAction::Release);
    }

    private function resetChromeTracker(): void
    {
        (new \ReflectionProperty(App::class, 'chromeClickTracker'))->setValue(null, null);
    }

    /**
     * Content columns the side currently claims at the 120x40 cast with both
     * bands active: usable 118 split 39/39 by the untouched 1/3 shares.
     */
    private static function sideWidthPx(App $app, Side $side): int
    {
        $slots = $app->dock()->slots($side);
        self::assertNotSame([], $slots);
        $region = $app->dock()->resolve(new \SugarCraft\Layout\Region(0, 0, self::CAST_COLS, self::CAST_ROWS - 4))
            ->regionFor($slots[0]->paneId);
        self::assertNotNull($region);

        return $region->width;
    }

    /**
     * The pane ids docked on one side, in stack order.
     *
     * @return list<string>
     */
    private static function slotIds(App $app, Side $side): array
    {
        return array_map(static fn(\SugarCraft\Layout\Dock\DockSlot $slot): string => $slot->paneId, $app->dock()->slots($side));
    }

    private function assertContainsSlot(App $app, Side $side, string $paneId): void
    {
        $ids = self::slotIds($app, $side);

        self::assertContains($paneId, $ids);
    }
}
