<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Config\Trust\TrustTarget;
use SugarCraft\Code\Host\SessionTabState;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Renderer as LiveRenderer;
use SugarCraft\Code\Session\SessionStore;
use SugarCraft\Code\Tui\Components\MenuBar;
use SugarCraft\Code\Tui\Pane;
use SugarCraft\Code\Tui\Renderer as TuiRenderer;
use SugarCraft\Code\Tui\TrustDialog;
use SugarCraft\Code\Tui\Window\WindowStrip;
use SugarCraft\Code\Tests\Support\OpensSessionTabs;

/**
 * P-TABS.g (GAP-UI-N6): while a shell deck is up, the chrome gains ONE
 * window-strip row under the menu bar — the same {@see WindowList} the docked
 * Sessions pane shows, painted by {@see WindowStrip} and clicked through
 * {@see App::dispatchChromeClick()}.
 *
 * What is pinned here is the SHELL wiring, not the component (the component's
 * own density/zone maths rides tests/Tui/Window/WindowStripTest):
 *  - the strip paints for the deck's single tab (its `[+]` is how you open
 *    the second one), sits on row 1, and costs the centre band exactly one
 *    row — the zone origin shifts by precisely that row;
 *  - every rendered row still fits the frame;
 *  - below {@see TuiRenderer::WINDOW_STRIP_MIN_ROWS} the strip stands down
 *    entirely — no row, no zones, nothing to click;
 *  - `win:new` click opens a tab and `winx:s:<key>` on a BUSY tab only arms
 *    the two-press law, never closes the deck's last tab;
 *  - a docked Sessions pane replaces the strip (the same list, not twice);
 *  - an open modal stands the strip down entirely — every early-modal band
 *    returns before the strip block is ever reached, so the frame a modal
 *    owns is strip-free no matter how tall the terminal is;
 *  - the chat's own pre-deck tab strip ({@see LiveRenderer}'s
 *    renderSessionTabStrip) stands down once the deck hosts that chat —
 *    the window row is the single list surface then.
 */
final class WindowStripShellTest extends TestCase
{
    use OpensSessionTabs;

    private const STRIP_COLS = 120;

    private const STRIP_ROWS = 30;

    private ProviderInterface $stripProvider;

    /** @var list<string> */
    private array $stripTempDirs = [];

    private string|false $stripOriginalDisableMouse;

    private string|false $stripOriginalDisableClicks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripOriginalDisableMouse = getenv('CANDY_CODE_DISABLE_MOUSE');
        $this->stripOriginalDisableClicks = getenv('CANDY_CODE_DISABLE_MOUSE_CLICKS');
        putenv('CANDY_CODE_DISABLE_MOUSE');
        putenv('CANDY_CODE_DISABLE_MOUSE_CLICKS');

        MenuBar::closeMenu();
        TuiRenderer::setSize(self::STRIP_COLS, self::STRIP_ROWS);
        $this->clearShellScanners();

        $this->stripProvider = $this->createMock(ProviderInterface::class);
        $this->stripProvider->method('name')->willReturn('TestProvider');
    }

    protected function tearDown(): void
    {
        MenuBar::closeMenu();
        $this->clearShellScanners();
        TuiRenderer::setSize(200, 60);

        putenv(
            $this->stripOriginalDisableMouse === false
                ? 'CANDY_CODE_DISABLE_MOUSE'
                : 'CANDY_CODE_DISABLE_MOUSE=' . $this->stripOriginalDisableMouse,
        );
        putenv(
            $this->stripOriginalDisableClicks === false
                ? 'CANDY_CODE_DISABLE_MOUSE_CLICKS'
                : 'CANDY_CODE_DISABLE_MOUSE_CLICKS=' . $this->stripOriginalDisableClicks,
        );

        foreach ($this->stripTempDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
        $this->stripTempDirs = [];

        parent::tearDown();
    }

    private function clearShellScanners(): void
    {
        TuiRenderer::chromeScanner()->clear();
        LiveRenderer::scanner()->clear();
        LiveRenderer::setZoneOrigin(0, 0);
        App::resetPaneDragController();
    }

    private function stripDeckApp(): App
    {
        [$app] = App::new($this->stripProvider, 'test-model')
            ->withChat(new Chat(backend: new EchoBackend()))
            ->withTabFactory(static fn (?string $sessionId): Chat => new Chat(backend: new EchoBackend()))
            ->update(new WindowSizeMsg(self::STRIP_COLS, self::STRIP_ROWS));

        return $app;
    }

    /** @return list<string> */
    private function renderShell(App $app, ?int $rows = null): array
    {
        return explode("\n", TuiRenderer::renderView($app, self::STRIP_COLS, $rows ?? self::STRIP_ROWS)->body);
    }

    public function testOneDeckTabPaintsThePlusAndCostsTheCentreExactlyOneRow(): void
    {
        // No deck yet: the same shell renders its chrome without a strip row.
        $plain = App::new($this->stripProvider, 'test-model')
            ->withChat(new Chat(backend: new EchoBackend()));
        $this->renderShell($plain);
        $originPlain = LiveRenderer::zoneOrigin();

        $app = $this->stripDeckApp();
        $lines = $this->renderShell($app);
        $zone = TuiRenderer::chromeScanner()->get(WindowStrip::ZONE_NEW);

        self::assertNotNull($zone, 'the deck painted its plus zone');
        $painted = null;
        foreach ($lines as $index => $row) {
            if (str_contains($row, WindowStrip::PLUS_PLAIN)) {
                $painted ??= $index;
            } else {
                self::assertStringNotContainsString(WindowStrip::PLUS_PLAIN, $row);
            }
        }
        self::assertSame($painted + 1, $zone->startRow, 'mark is paint to the row (chrome zones speak 1-based terminal rows; body lines are 0-based)');
        self::assertNotNull($painted, 'the plus is on some row');

        $originDecked = LiveRenderer::zoneOrigin();
        self::assertSame(
            $originPlain[1] + 1,
            $originDecked[1],
            'the strip costs the pane band exactly one row — no more, no less',
        );

        foreach ($lines as $index => $row) {
            self::assertLessThanOrEqual(self::STRIP_COLS, Width::string($row), 'row ' . $index . ': ' . $row);
        }
        self::assertCount(self::STRIP_ROWS, $lines, 'the frame keeps its height');
    }

    public function testTheStripStandsDownBelowTheRowFloor(): void
    {
        $app = $this->stripDeckApp();
        $lines = $this->renderShell($app, TuiRenderer::WINDOW_STRIP_MIN_ROWS - 1);

        self::assertNull(TuiRenderer::chromeScanner()->get(WindowStrip::ZONE_NEW));
        foreach ($lines as $row) {
            self::assertStringNotContainsString(WindowStrip::PLUS_PLAIN, $row, 'no strip row below the floor');
        }
    }

    public function testClickingThePlusOpensASecondTab(): void
    {
        $app = $this->stripDeckApp();
        $this->renderShell($app);
        $zone = TuiRenderer::chromeScanner()->get(WindowStrip::ZONE_NEW);
        self::assertNotNull($zone, 'fixture: the plus is painted');

        [$app] = $app->update(new MouseClickMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Press));
        [$app] = $app->update(new MouseReleaseMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Release));

        self::assertSame(2, $app->deck?->count(), 'the painted plus really opens a tab');
    }

    public function testClickingTheCrossOnABusyTabArmsThenACloseNeedsTheSameKeyAgain(): void
    {
        [$armed] = App::new($this->stripProvider, 'test-model')
            ->withChat(new Chat(backend: new EchoBackend(), inFlight: true, generation: 1))
            ->withTabFactory(static fn (?string $sessionId): Chat => new Chat(backend: new EchoBackend()))
            ->update(new WindowSizeMsg(self::STRIP_COLS, self::STRIP_ROWS));
        [$opened] = $armed->update(new KeyMsg(KeyType::Char, 'n', ctrl: true));
        [$busy] = $opened->update(new KeyMsg(KeyType::Tab, ctrl: true));
        self::assertSame('tab-1', $busy->deck?->activeKey(), 'fixture: the busy first tab is foreground again');
        [$busy] = $busy->update(new KeyMsg(KeyType::Char, 's', alt: true));
        self::assertFalse($busy->isDocked(Pane::Sessions), 'fixture: the auto-docked pane stands aside for its strip twin');

        $this->renderShell($busy);
        $zone = TuiRenderer::chromeScanner()->get(WindowStrip::ZONE_SESSION_CLOSE_PREFIX . 'tab-1');
        self::assertNotNull($zone, 'the active tab carries its close zone');

        [$app] = $busy->update(new MouseClickMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Press));
        [$app] = $app->update(new MouseReleaseMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Release));
        self::assertNotNull($app->pendingTabClose, 'a busy tab is armed, not closed');
        self::assertSame(2, $app->deck?->count());

        [$app] = $app->update(new MouseClickMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Press));
        [$app] = $app->update(new MouseReleaseMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Release));
        self::assertSame(1, $app->deck?->count(), 'the second press on the same armed tab closes it');
    }

    public function testTheDeckNeverClosesItsOnlyTabFromTheStrip(): void
    {
        $app = $this->stripDeckApp();
        $this->renderShell($app);
        $zone = TuiRenderer::chromeScanner()->get(WindowStrip::ZONE_SESSION_CLOSE_PREFIX . ($app->deck?->activeKey() ?? ''));
        self::assertNotNull($zone, 'fixture: the single active tab carries its close zone');

        [$app] = $app->update(new MouseClickMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Press));
        [$app] = $app->update(new MouseReleaseMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Release));

        self::assertSame(1, $app->deck?->count(), 'the deck never closes its last tab, armed or not');
        self::assertNull($app->pendingTabClose, 'a refuse to close refuses to arm too');
    }

    public function testTheDockedSessionsPaneReplacesTheStrip(): void
    {
        $app = $this->stripDeckApp();
        [$app] = $app->update(new KeyMsg(KeyType::Char, 's', alt: true));
        self::assertTrue($app->isDocked(Pane::Sessions), 'Alt+S docked the Sessions pane');

        $lines = $this->renderShell($app);
        self::assertNull(TuiRenderer::chromeScanner()->get(WindowStrip::ZONE_NEW), 'the strip stands down while its pane is shown');
        foreach ($lines as $row) {
            self::assertStringNotContainsString(WindowStrip::PLUS_PLAIN, $row, 'the same list is never painted twice');
        }
    }

    public function testTheStripStandsDownWhileAModalOwnsTheFrame(): void
    {
        // The trust dialog is the cheapest of the early-modal bands to arm,
        // and it stands at the very top of renderView — every other modal
        // (settings editor, media form, video player, Agents dashboard)
        // returns ahead of the strip block by the same ordering. At frame
        // height well above the row floor, the ONLY thing that can stand the
        // strip down here is that ordering; a reorder that hoisted the strip
        // above the modals would register its zone and paint its row.
        $app = $this->stripDeckApp()
            ->withTrustDialog(TrustDialog::new('ccmp-strip-modal-root', [TrustTarget::Hooks]));

        $lines = $this->renderShell($app);

        self::assertNull(
            TuiRenderer::chromeScanner()->get(WindowStrip::ZONE_NEW),
            'a modal owns the frame outright — the strip registers nothing to click',
        );
        foreach ($lines as $row) {
            self::assertStringNotContainsString(WindowStrip::PLUS_PLAIN, $row, 'a modal owns the frame: no strip row');
        }
    }

    public function testTheChatOwnTabStripStandsDownOnceTheDeckHostsIt(): void
    {
        $dir = sys_get_temp_dir() . '/ccmp_strip_' . uniqid('', true);
        mkdir($dir, 0o700, true);
        $this->stripTempDirs[] = $dir;
        $store = new SessionStore($dir . '/sessions.db');
        $store->createSession('hosted-a', 'openai', 'gpt-4', null, 'first window');
        $store->createSession('hosted-b', 'openai', 'gpt-4', null, 'second window');

        $chat = new Chat(
            backend: new EchoBackend(),
            sessionStore: $store,
            currentSessionId: 'hosted-a',
            tabState: new SessionTabState(tabs: self::openTabsFor(['hosted-a', 'hosted-b'])),
        );

        $strip = new \ReflectionMethod(LiveRenderer::class, 'renderSessionTabStrip');

        self::assertNotSame('', $strip->invoke(null, $chat), 'fixture: an unhosted chat still paints its own row');
        self::assertSame('', $strip->invoke(null, $chat->withTabHosted(true)), 'the window row is the single list surface once hosted');
    }
}
