<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Renderer as LiveRenderer;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Tui\Components\MenuBar;
use SugarCraft\Crush\Tui\Components\MenuSelectedMsg;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;
use SugarCraft\Mouse\Sentinel;
use SugarCraft\Mouse\Zone;

/**
 * crush_feat.md §8 (mouse integration) for the one surface its E-list never
 * reached: the menu bar. The user report is literal — "clicking the menu up
 * top with a mouse doesnt work". F10 opened it and the arrows walked it, but
 * the titles carried no click zone at all.
 *
 * The coordinate invariant is what these assertions are really about.
 * {@see LiveRenderer} records the hosted chat's zones PANE-LOCAL and
 * {@see Chat::zoneAt()} rebases an absolute pointer report by
 * {@see LiveRenderer::zoneOrigin()} before hit-testing. The menu bar is shell
 * chrome ABOVE that origin, so its zones live in a registry of their own
 * ({@see TuiRenderer::chromeScanner()}) in terminal-absolute space — which is
 * checked here by clicking the cells the title is actually painted at in the
 * composed frame, never the cells a layout calculation says it should be at.
 *
 * @see TuiRenderer::chromeZoneAt()
 * @see MenuBar::renderMarked()
 * @see App::chromeClickTracker()
 */
final class MenuBarMouseTest extends TestCase
{
    private const VARS = ['SUGARCRUSH_DISABLE_MOUSE', 'SUGARCRUSH_DISABLE_MOUSE_CLICKS'];

    private ProviderInterface $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reset();

        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('TestProvider');
    }

    protected function tearDown(): void
    {
        $this->reset();
        parent::tearDown();
    }

    // =========================================================================
    // The marked copy is scan-only: it must never reach the terminal.
    // =========================================================================

    public function testTheMarkedBarHasTheSameLayoutAsThePaintedBar(): void
    {
        $app = $this->app();

        self::assertSame(
            MenuBar::render($app, 100),
            $this->unmark(MenuBar::renderMarked($app, 100)),
            'the scanned copy and the painted copy must agree cell for cell',
        );
    }

    public function testTheComposedFrameCarriesNoZoneSentinels(): void
    {
        MenuBar::openMenu(1);
        $frame = TuiRenderer::renderView($this->app(), 100, 30)->body;

        self::assertStringNotContainsString(Sentinel::OPEN, $frame);
        self::assertStringNotContainsString(Sentinel::CLOSE, $frame);
    }

    // =========================================================================
    // Where the zones are, in the space a mouse report actually arrives in.
    // =========================================================================

    /**
     * The invariant in one assertion: the title's zone box is the cells the
     * frame paints the title at, with no pane origin subtracted — unlike the
     * hosted chat's, whose origin is non-zero in the very same frame.
     */
    public function testTheMenuTitleZoneCoversTheCellsTheTitleIsPaintedAt(): void
    {
        $frame = TuiRenderer::renderView($this->app(), 100, 30)->body;

        [$col, $row] = $this->locate($frame, $this->menuName(1));
        $zone = TuiRenderer::chromeScanner()->get(MenuBar::MENU_TITLE_ZONE_PREFIX . '1');

        self::assertInstanceOf(Zone::class, $zone);
        self::assertSame($row, $zone->startRow);
        self::assertGreaterThanOrEqual($zone->startCol, $col);
        self::assertLessThanOrEqual($zone->endCol, $col);
        self::assertNotSame([0, 0], LiveRenderer::zoneOrigin(), 'the chat pane IS offset in this frame');
    }

    public function testEveryPaintedMenuTitleGetsItsOwnZone(): void
    {
        $frame = TuiRenderer::renderView($this->app(), 100, 30)->body;

        $marked = TuiRenderer::chromeScanner()->prefixed(MenuBar::MENU_TITLE_ZONE_PREFIX);
        self::assertGreaterThan(1, count($marked), 'the bar draws more than one menu at 100 columns');

        foreach ($marked as $id => $zone) {
            $index = (int) substr($id, strlen(MenuBar::MENU_TITLE_ZONE_PREFIX));
            [$col, $row] = $this->locate($frame, $this->menuName($index));

            self::assertSame($row, $zone->startRow, $id);
            self::assertGreaterThanOrEqual($zone->startCol, $col, $id);
            self::assertLessThanOrEqual($zone->endCol, $col, $id);
        }
    }

    // =========================================================================
    // Clicking, end to end through the Model contract.
    // =========================================================================

    /**
     * The reported bug. Before the fix a press+release on the painted title
     * left `getActiveMenu()` at 0 — nothing recorded a zone there, so nothing
     * could be dispatched.
     */
    public function testAClickWhereAMenuTitleIsPaintedOpensThatMenu(): void
    {
        $app = $this->app();
        $frame = TuiRenderer::renderView($app, 100, 30)->body;
        [$col, $row] = $this->locate($frame, $this->menuName(2));

        [$app] = $this->click($app, $col, $row);

        self::assertInstanceOf(App::class, $app);
        self::assertSame(2, MenuBar::getActiveMenu());
    }

    public function testClickingTheOpenMenusTitleAgainClosesIt(): void
    {
        $app = $this->app();
        $frame = TuiRenderer::renderView($app, 100, 30)->body;
        [$col, $row] = $this->locate($frame, $this->menuName(1));

        [$app] = $this->click($app, $col, $row);
        self::assertSame(1, MenuBar::getActiveMenu());

        TuiRenderer::renderView($app, 100, 30);
        $this->click($app, $col, $row);

        self::assertSame(0, MenuBar::getActiveMenu());
    }

    /**
     * A press on a title followed by a release somewhere else is a drag, not
     * a click — candy-mouse's tracker pairs the two and this must dispatch
     * nothing.
     */
    public function testAPressOnATitleReleasedElsewhereOpensNothing(): void
    {
        $app = $this->app();
        $frame = TuiRenderer::renderView($app, 100, 30)->body;
        [$col, $row] = $this->locate($frame, $this->menuName(1));

        [$app] = $app->update(new MouseClickMsg($col, $row, MouseButton::Left, MouseAction::Press));
        $app->update(new MouseReleaseMsg($col, $row + 6, MouseButton::Left, MouseAction::Release));

        self::assertSame(0, MenuBar::getActiveMenu());
    }

    // =========================================================================
    // The dropdown rows.
    // =========================================================================

    public function testDropdownRowZonesExistOnlyWhileAMenuIsOpen(): void
    {
        TuiRenderer::renderView($this->app(), 100, 30);
        self::assertSame([], TuiRenderer::chromeScanner()->prefixed(MenuBar::MENU_ITEM_ZONE_PREFIX));

        MenuBar::openMenu(1);
        TuiRenderer::renderView($this->app(), 100, 30);

        self::assertNotSame([], TuiRenderer::chromeScanner()->prefixed(MenuBar::MENU_ITEM_ZONE_PREFIX));
    }

    public function testTheDropdownRowZoneCoversTheCellsItsLabelIsPaintedAt(): void
    {
        MenuBar::openMenu(1);
        $frame = TuiRenderer::renderView($this->app(), 100, 30)->body;

        $label = MenuBar::getMenuItems($this->menuName(1))[1];
        [$col, $row] = $this->locate($frame, $label);
        $zone = TuiRenderer::chromeScanner()->get(MenuBar::MENU_ITEM_ZONE_PREFIX . '1');

        self::assertInstanceOf(Zone::class, $zone);
        self::assertSame($row, $zone->startRow);
        self::assertGreaterThanOrEqual($zone->startCol, $col);
        self::assertLessThanOrEqual($zone->endCol, $col);
    }

    /**
     * §8 E6 asks for a clicked row to dispatch what Enter dispatches. Proven
     * by the side effect only {@see App::consumeShellCmd()} has on that path:
     * the menu is closed by {@see App::dispatchMenuSelection()}, and no
     * "no command matches" error is raised — which is what would happen if
     * the click named a row the registry cannot map back.
     */
    public function testClickingADropdownRowRunsTheCommandItNames(): void
    {
        $app = $this->app();
        MenuBar::openMenu(1);
        $frame = TuiRenderer::renderView($app, 100, 30)->body;

        [$col, $row] = $this->locate($frame, MenuBar::getMenuItems($this->menuName(1))[1]);
        [$app] = $this->click($app, $col, $row);

        self::assertInstanceOf(App::class, $app);
        self::assertSame(0, MenuBar::getActiveMenu(), 'running a menu row closes the menu');
        self::assertNull($app->error);
    }

    public function testSelectItemMovesTheSameCursorEnterReads(): void
    {
        MenuBar::openMenu(1);

        $selected = MenuBar::selectItem(1);

        self::assertInstanceOf(MenuSelectedMsg::class, $selected);
        self::assertSame($this->menuName(1), $selected->menu);
        self::assertSame(MenuBar::getMenuItems($this->menuName(1))[1], $selected->item);
        self::assertSame(1, MenuBar::getActiveItem());
    }

    public function testSelectItemRefusesRowsAndMenusThatDoNotExist(): void
    {
        self::assertNull(MenuBar::selectItem(0), 'no menu is open');

        MenuBar::openMenu(1);

        self::assertNull(MenuBar::selectItem(-1));
        self::assertNull(MenuBar::selectItem(9999));
        self::assertSame(0, MenuBar::getActiveItem(), 'a refused row must not move the cursor');
    }

    // =========================================================================
    // What the new shell mouse arm must NOT take away.
    // =========================================================================

    /**
     * The hosted chat still owns every click outside the chrome — the shell
     * gets first refusal, not exclusive ownership. Same gesture as
     * {@see ShellMouseZoneTest}, driven through the arm added for the menu.
     */
    public function testAClickOnTheTranscriptStillReachesTheHostedChat(): void
    {
        $app = $this->app($this->chatWithTool());
        $frame = TuiRenderer::renderView($app, 100, 30)->body;
        [$col, $row] = $this->locate($frame, 'tool: bash');

        [$app] = $this->click($app, $col, $row);

        self::assertInstanceOf(App::class, $app);
        self::assertNotNull($app->chat);
        self::assertTrue($app->chat->isToolOutputExpanded('call_1'));
    }

    public function testDisablingClicksSuppressesTheChromeHitTest(): void
    {
        $frame = TuiRenderer::renderView($this->app(), 100, 30)->body;
        [$col, $row] = $this->locate($frame, $this->menuName(1));

        self::assertNotNull(TuiRenderer::chromeZoneAt($col, $row));

        putenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS=1');

        self::assertNull(TuiRenderer::chromeZoneAt($col, $row));
    }

    /**
     * The dashboard drops the hosted chat's zones but paints the bar, so its
     * titles have to stay clickable there too.
     */
    public function testTheAgentDashboardFrameStillRecordsTheMenuTitles(): void
    {
        $app = $this->app()->withPane(Pane::Agents);
        $frame = TuiRenderer::renderView($app, 100, 30)->body;

        [$col, $row] = $this->locate($frame, $this->menuName(1));
        $zone = TuiRenderer::chromeZoneAt($col, $row);

        self::assertInstanceOf(Zone::class, $zone);
        self::assertSame(MenuBar::MENU_TITLE_ZONE_PREFIX . '1', $zone->id);
    }

    /**
     * A frame too short to hold its own chrome loses rows off the TOP
     * (`clipTail` keeps the bottom), so the bar is not on screen and a stale
     * box would make whatever replaced it clickable.
     */
    public function testAFrameThatClipsTheBarOffTheTopRecordsNoChromeZones(): void
    {
        TuiRenderer::renderView($this->app(), 100, 30);
        self::assertNotSame([], TuiRenderer::chromeScanner()->all());

        TuiRenderer::renderView($this->app(), 100, 3);

        self::assertSame([], TuiRenderer::chromeScanner()->all());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function reset(): void
    {
        foreach (self::VARS as $var) {
            putenv($var);
        }

        MenuBar::closeMenu();
        TuiRenderer::resetSizeCache();
        TuiRenderer::chromeScanner()->clear();
        LiveRenderer::scanner()->clear();
        LiveRenderer::setZoneOrigin(0, 0);

        (new \ReflectionProperty(App::class, 'chromeClickTracker'))->setValue(null, null);
        (new \ReflectionProperty(Chat::class, 'clickTracker'))->setValue(null, null);
    }

    private function app(?Chat $chat = null): App
    {
        return App::new($this->provider, 'test-model')
            ->withChat($chat ?? (new Chat(history: [Message::user('hi')]))->withSize(100, 30));
    }

    private function chatWithTool(): Chat
    {
        return new Chat(history: [
            Message::user('hi'),
            Message::assistant('ok')->withToolResults([ToolResult::ok('bash', 'out', 'call_1')]),
        ]);
    }

    /**
     * A complete left click: press then release on the same cell.
     *
     * @return array{0: App, 1: ?\Closure}
     */
    private function click(App $app, int $col, int $row): array
    {
        [$app] = $app->update(new MouseClickMsg($col, $row, MouseButton::Left, MouseAction::Press));

        /** @var array{0: App, 1: ?\Closure} $result */
        $result = $app->update(new MouseReleaseMsg($col, $row, MouseButton::Left, MouseAction::Release));

        return $result;
    }

    /** The display name of the $index-th (1-based) menu the bar derives. */
    private function menuName(int $index): string
    {
        $names = [];
        foreach (CommandRegistry::all() as $spec) {
            $names[$spec->category] = true;
        }

        return array_keys($names)[$index - 1];
    }

    /** Strip candy-mouse zone sentinels, leaving the painted bytes. */
    private function unmark(string $marked): string
    {
        return (string) preg_replace(
            '/\x{E000}\/?[A-Za-z0-9._:-]*\x{E001}/u',
            '',
            $marked,
        );
    }

    /**
     * The 1-based display column and row of $needle in a rendered frame —
     * the coordinate space an SGR mouse report and {@see Zone} both use.
     *
     * @return array{0: int, 1: int}
     */
    private function locate(string $frame, string $needle): array
    {
        foreach (explode("\n", $frame) as $index => $line) {
            $plain = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $line);
            $at = mb_strpos($plain, $needle);
            if ($at === false) {
                continue;
            }

            return [Width::string(mb_substr($plain, 0, $at)) + 1, $index + 1];
        }

        self::fail("frame does not contain '{$needle}'");
    }
}
