<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Renderer as LiveRenderer;
use SugarCraft\Crush\Tui\Components\MenuBar;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;
use SugarCraft\Layout\Dock\Side;
use SugarCraft\Mouse\Zone;

/**
 * Docking L2 feature 1 — a completed click on a menu-bar pane-tab label
 * toggles that pane's docked visibility, through the ONE dock entry point.
 *
 * The clicks are driven end-to-end (render the frame → read the zone the
 * chrome scan recorded → press/release at its painted cell) rather than by
 * calling `App::dispatchChromeClick()` directly, because the wiring IS the
 * feature: a marked zone nobody can hit, or a hit that bypasses
 * {@see App::togglePaneDocking()} (and with it the seed-shares first-mutation
 * rule and the persist-once law), would both pass a call-the-private-method
 * test while shipping a dead label.
 *
 * Focus semantics pinned here: docking focuses the pane that just appeared;
 * undocking the focused pane drops focus to Chat (togglePaneDocking's own
 * rule); Chat's label only ever moves focus — the center column has no dock
 * slot to toggle away, and the layout bytes prove it stays untouched.
 */
final class MenuBarPaneTabClickTest extends TestCase
{
    private const COLS = 120;

    private const ROWS = 40;

    private ProviderInterface $provider;

    /** @var list<array<string, mixed>> */
    private array $manifests = [];

    private string|false $originalDisableMouse;

    private string|false $originalDisableClicks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDisableMouse = getenv('SUGARCRUSH_DISABLE_MOUSE');
        $this->originalDisableClicks = getenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS');
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
        putenv(
            $this->originalDisableMouse === false
                ? 'SUGARCRUSH_DISABLE_MOUSE'
                : 'SUGARCRUSH_DISABLE_MOUSE=' . $this->originalDisableMouse,
        );
        putenv(
            $this->originalDisableClicks === false
                ? 'SUGARCRUSH_DISABLE_MOUSE_CLICKS'
                : 'SUGARCRUSH_DISABLE_MOUSE_CLICKS=' . $this->originalDisableClicks,
        );

        TuiRenderer::chromeScanner()->clear();
        LiveRenderer::scanner()->clear();
        LiveRenderer::setZoneOrigin(0, 0);
        App::resetPaneDragController();
        $this->resetChromeTracker();
        TuiRenderer::setSize(200, 60);

        parent::tearDown();
    }

    public function testClickOnAnUndockedLabelDocksItOnItsHomeSideAndFocusesIt(): void
    {
        $app = $this->app();
        self::assertFalse($app->isDocked(Pane::Tools), 'fixture: Tools starts undocked');

        $app = $this->tapPaneTab($app, Pane::Tools);

        self::assertTrue($app->isDocked(Pane::Tools), 'click docked the pane');
        self::assertSame(
            ['files', 'tools'],
            array_map(static fn ($slot): string => $slot->paneId, $app->dock()->slots(Side::Left)),
            'it joined Files on its home (left) side, appended',
        );
        self::assertSame(Pane::Tools, $app->pane, 'docking focuses the pane that just appeared');
        self::assertCount(1, $this->manifests, 'persist-once: exactly one manifest for the dock');
    }

    public function testClickOnADockedLabelUndocksItAndDropsAFocusedPaneToChat(): void
    {
        $app = $this->app()->withPane(Pane::Files);
        self::assertTrue($app->isDocked(Pane::Files), 'fixture: Files is the default dock');

        $app = $this->tapPaneTab($app, Pane::Files);

        self::assertFalse($app->isDocked(Pane::Files), 'click undocked the pane');
        self::assertSame(Pane::Chat, $app->pane, 'undocking the FOCUSED pane drops focus to Chat');
        self::assertCount(1, $this->manifests, 'persist-once: exactly one manifest for the undock');
    }

    public function testClickOnADockedLabelThatDoesNotHoldFocusKeepsFocusWhereItWas(): void
    {
        $app = $this->app()->setPaneSide(Pane::Tools, Side::Left);
        self::assertSame(Pane::Chat, $app->pane, 'fixture: focus is Chat, Tools is docked');

        $app = $this->tapPaneTab($app, Pane::Tools);

        self::assertFalse($app->isDocked(Pane::Tools));
        self::assertSame(Pane::Chat, $app->pane, 'untouched focus — only the visibility flipped');
    }

    public function testClickOnTheChatLabelFocusesChatAndTogglesNothing(): void
    {
        $app = $this->app()->withPane(Pane::Files);
        $before = $app->dock()->toArray();

        $app = $this->tapPaneTab($app, Pane::Chat);

        self::assertSame(Pane::Chat, $app->pane, 'Chat label = focus move');
        self::assertSame($before, $app->dock()->toArray(), 'the layout bytes are untouched');
        self::assertTrue($app->isDocked(Pane::Files), 'Files stays docked');
        self::assertCount(0, $this->manifests, 'a focus move writes nothing to disk');
    }

    public function testClickingEveryDockedLabelOffLeavesTheCentreFullWidth(): void
    {
        $app = $this->app();
        // Default dock: Files left, right empty — one label-off click empties
        // the whole dock.
        $app = $this->tapPaneTab($app, Pane::Files);

        self::assertSame([], $app->dock()->slots(Side::Left));
        self::assertSame([], $app->dock()->slots(Side::Right));

        // End-to-end degradation: the resolve() no-side case already has unit
        // cover in the geometry plumbing tests; what is pinned HERE is that a
        // click-driven walk to the empty dock still renders, and the centre
        // band the renderer recorded spans every column of the frame.
        $body = $this->render($app);
        self::assertNotEmpty($body);
        $frame = TuiRenderer::lastDockFrame();
        self::assertNotNull($frame, 'fixture: the cast frame published its dock geometry');
        self::assertSame(0, $frame['centerFrom']);
        self::assertSame($frame['bandCols'] - 1, $frame['centerTo'], 'centre owns the whole band');
        self::assertSame(self::COLS, $frame['bandCols'], 'no divider columns remain');
    }

    public function testSeedSharesRuleRidesTheClickPathUnchanged(): void
    {
        // The untouched default carries the library 1/3 shares; the FIRST
        // mutation must snapshot the drawn frame instead — the same law the
        // keyboard/command surfaces pin, proven here for the mouse door so
        // the click path cannot drift from them. The seed reads App::$cols
        // (the last WindowSizeMsg), per seedSharesFromFrame's own docblock.
        $app = $this->app();
        self::assertSame(
            ['num' => 1, 'denom' => 3],
            $app->dock()->columnShare(Side::Right),
            'fixture: untouched default shares on the side the click does not target',
        );

        $app = $this->tapPaneTab($app, Pane::Tools);

        $share = $app->dock()->columnShare(Side::Left);
        self::assertSame(
            [max(20, intdiv(self::COLS, 4)), self::COLS],
            [$share['num'], $share['denom']],
            'the click seeded the last-known window width, exactly once',
        );
    }

    /**
     * Press+release at the painted cell of the label's chrome zone and feed
     * both messages through {@see App::update()} — the live routing, from the
     * scan the last {@see TuiRenderer::renderView()} published.
     */
    private function tapPaneTab(App $app, Pane $pane): App
    {
        $this->render($app);
        $zone = $this->paneTabZone($pane);

        [$app] = $app->update(new MouseClickMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Press));
        [$app] = $app->update(new MouseReleaseMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Release));

        return $app;
    }

    private function paneTabZone(Pane $pane): Zone
    {
        $zone = TuiRenderer::chromeScanner()->get(MenuBar::PANE_TAB_ZONE_PREFIX . $pane->value);
        self::assertNotNull($zone, 'fixture: the painted bar marked panetab:' . $pane->value);

        return $zone;
    }

    private function render(App $app): string
    {
        return TuiRenderer::renderView($app, self::COLS, self::ROWS)->body;
    }

    private function app(): App
    {
        // WindowSizeMsg first: it is the live path's only writer of App::$cols,
        // and seedSharesFromFrame refuses to snapshot until the App has a
        // measured width (`0` seeds nothing).
        [$app] = App::new($this->provider, 'test-model')
            ->withChat(new Chat())
            ->withOnLayoutChange(function (array $manifest): void {
                $this->manifests[] = $manifest;
            })
            ->update(new WindowSizeMsg(self::COLS, self::ROWS));

        return $app;
    }

    private function resetChromeTracker(): void
    {
        (new \ReflectionProperty(App::class, 'chromeClickTracker'))->setValue(null, null);
    }
}
