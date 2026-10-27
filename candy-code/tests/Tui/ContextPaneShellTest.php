<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Code\App\App;
use SugarCraft\Code\App\DockPaneMsg;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Context\ContextBreakdown;
use SugarCraft\Code\Context\ContextCategories;
use SugarCraft\Code\Context\ContextCategory;
use SugarCraft\Code\Host\TurnRunner;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\EchoProvider;
use SugarCraft\Code\Renderer as ShellRenderer;
use SugarCraft\Code\Tui\Components\ContextPane;
use SugarCraft\Code\Tui\Components\MenuBar;
use SugarCraft\Code\Tui\Pane;
use SugarCraft\Code\Tui\Renderer as ChromeRenderer;
use SugarCraft\Constraints\Dock\Side;
use SugarCraft\Mouse\Zone;

/**
 * P-CTX.e — the Context pane's SHELL half: the docked box never over-runs
 * the frame at any width the strip can meet, a click on a category row
 * drills, a collapsed header click expands, the status-bar meter zone docks
 * and undocks through the shell's one DockPaneMsg entry point, and the
 * auto-show docks the box once on a wide terminal after the first settled
 * measurement while honouring a hand close and staying pending below the
 * width floor. The pure box itself is pinned by ContextPaneTest; the key rows
 * are pinned by KeyBindingDriftTest's contextPane.* observations.
 */
final class ContextPaneShellTest extends TestCase
{
    private const VARS = ['CANDY_CODE_DISABLE_MOUSE', 'CANDY_CODE_DISABLE_MOUSE_CLICKS'];

    protected function setUp(): void
    {
        $this->resetShellChrome();
    }

    protected function tearDown(): void
    {
        $this->resetShellChrome();
    }

    public function testTheDockedBoxNeverOverRunsTheFrame(): void
    {
        $base = $this->measuredApp();
        [$sized] = $base->update(new WindowSizeMsg(160, 40));

        foreach ([60, 80, 100, 160] as $cols) {
            [$atWidth] = $sized->update(new WindowSizeMsg($cols, 40));
            foreach ([false, true] as $expanded) {
                $app = $atWidth->contextPaneToggle();
                if ($expanded) {
                    $app = $app->contextPaneDrill(ContextCategory::Messages);
                }

                $frame = ChromeRenderer::renderView($app, $cols, 40)->body;
                foreach (explode("\n", $frame) as $line) {
                    $this->assertLessThanOrEqual(
                        $cols,
                        Width::of(Ansi::strip($line)),
                        "docked Context pane over-ran a {$cols}-column frame (expanded: "
                        . var_export($expanded, true) . ')',
                    );
                }
            }
        }
    }

    public function testAClickOnACategoryRowDrillsTheBox(): void
    {
        [$sized] = $this->measuredApp()->update(new WindowSizeMsg(160, 40));
        $app = $sized->contextPaneToggle()->withPane(Pane::Context);

        ChromeRenderer::renderView($app, 160, 40);
        $zone = ChromeRenderer::chromeScanner()->get(
            ShellRenderer::SIDE_ROW_ZONE_PREFIX . 'context:' . ContextPane::ROW_KEY_PREFIX . 'messages',
        );
        $this->assertInstanceOf(Zone::class, $zone, 'the Messages row of the docked box is a click zone');

        [$pressed] = $app->update(new MouseClickMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Press));
        [$after] = $pressed->update(new MouseReleaseMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Release));

        $this->assertSame(ContextCategory::Messages, $after->contextDrill, 'the click drilled the row');
        $this->assertTrue($after->contextPaneExpanded, 'and stayed open');
    }

    public function testAClickOnTheCollapsedHeaderRowExpandsTheBox(): void
    {
        [$sized] = $this->measuredApp()->update(new WindowSizeMsg(160, 40));
        $app = $sized->withPane(Pane::Context);

        ChromeRenderer::renderView($app, 160, 40);
        $zone = ChromeRenderer::chromeScanner()->get(
            ShellRenderer::SIDE_ROW_ZONE_PREFIX . 'context:' . ContextPane::TOGGLE_KEY,
        );
        $this->assertInstanceOf(Zone::class, $zone, 'the collapsed box names its expander as a click zone');

        [$pressed] = $app->update(new MouseClickMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Press));
        [$after] = $pressed->update(new MouseReleaseMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Release));

        $this->assertTrue($after->contextPaneExpanded, 'the header click opened the box');
    }

    public function testTheStatusMeterTogglesTheDockBothWays(): void
    {
        $chat = $this->measuredChat();
        ShellRenderer::render($chat);
        $zone = ShellRenderer::scanner()->get(ShellRenderer::CTXMETER_ZONE);
        $this->assertInstanceOf(Zone::class, $zone, 'the status-bar context segment is a click zone');

        [$pressed] = $chat->update(new MouseClickMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Press));
        [, $cmd] = $pressed->update(new MouseReleaseMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Release));
        $this->assertInstanceOf(\Closure::class, $cmd);

        $msg = $cmd();
        $this->assertInstanceOf(DockPaneMsg::class, $msg, 'the meter click rides the one docking entry point the shell has');

        $app = $this->plainApp()->withChat($chat);
        [$docked, $measure] = $app->update($msg);
        $this->assertTrue($docked->isDocked(Pane::Context), 'the meter click docked the box');
        $this->assertInstanceOf(\Closure::class, $measure, 'and a fresh dock asks for one measurement');

        [$undocked, $again] = $docked->update($msg);
        $this->assertFalse($undocked->isDocked(Pane::Context), 'the same click on a docked box undocks it');
        $this->assertNull($again, 'undocking asks for no fresh measurement');
    }

    public function testAutoShowDocksTheBoxOnceOnAWideTerminalAfterTheFirstMeasurement(): void
    {
        // The offer is pending on a narrow frame, then lands the moment the
        // window widens past both floors — no keystroke needed for the resize
        // itself, and none ever again after that.
        [$narrow] = $this->plainApp()->withChat($this->measuredChat())
            ->update(new WindowSizeMsg(119, 40));
        $this->assertFalse($narrow->isDocked(Pane::Context), 'below the width floor nothing docks');

        [$next] = $narrow->update(new WindowSizeMsg(160, 40));
        $this->assertTrue($next->isDocked(Pane::Context), 'a resize past both floors docks it');
        $this->assertSame(['context'], $this->dockSlotIds($next, Side::Right));
        $this->assertTrue($next->paneAutoShow->settled(Pane::Context));

        [$again] = $next->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertSame(['context'], $this->dockSlotIds($again, Side::Right), 'once is the whole promise');
    }

    public function testAHandCloseEndsTheAutoShowForGood(): void
    {
        [$sized] = $this->plainApp()->withChat($this->measuredChat())
            ->update(new WindowSizeMsg(160, 40));
        $this->assertTrue($sized->isDocked(Pane::Context), 'the wide sizing docks it');
        $user = $sized->togglePaneDocking(Pane::Context);
        $this->assertFalse($user->isDocked(Pane::Context), 'the hand toggle closed it');

        [$next] = $user->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertFalse($next->isDocked(Pane::Context), 'and the auto-show never reopens it');
        $this->assertTrue($next->paneAutoShow->settled(Pane::Context));
    }

    public function testANarrowTerminalLeavesTheAutoShowPending(): void
    {
        [$sized] = $this->plainApp()->withChat($this->measuredChat())
            ->update(new WindowSizeMsg(119, 40));
        [$next] = $sized->update(new KeyMsg(KeyType::Char, 'x'));

        $this->assertFalse($next->isDocked(Pane::Context), 'below the width floor nothing docks');
        $this->assertFalse($next->paneAutoShow->settled(Pane::Context), 'and the offer survives for a later resize');

        [$mid] = $this->plainApp()->withChat($this->measuredChat())
            ->update(new WindowSizeMsg(130, 40));
        [$kept] = $mid->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertFalse($kept->isDocked(Pane::Context), 'past the width floor the box still yields to the chat band');
        $this->assertFalse($kept->paneAutoShow->settled(Pane::Context), 'a readability refusal is no decision — a wider frame retries');
    }

    // ── fixtures ────────────────────────────────────────────────────────

    /**
     * A Chat whose TurnRunner holds a settled context measurement for the
     * session — the state a first reply leaves behind (P-CTX.d's wire).
     */
    private function measuredChat(): Chat
    {
        $runner = TurnRunner::new();
        $runner->saveContext(null, self::ctxSnapshot());

        return new Chat(
            history: [Message::user('measure it')],
            backend: new EchoBackend(),
            workspace: WorkspaceContext::new()->withService(TurnRunner::class, $runner),
        );
    }

    private function ctxSnapshot(): ContextCategories
    {
        return ContextCategories::from(new ContextBreakdown(
            window: 200000,
            sections: null,
            toolCount: 5,
            toolTokens: 1500,
            historyTokens: 40000,
            historyMessages: 12,
            uiOnlyRows: 0,
            largest: [],
            lastCache: null,
            sessionCache: null,
            instructionFiles: ['files' => [['path' => 'AGENTS.md', 'bytes' => 4096, 'personal' => false]]],
            mcpToolCount: 2,
            mcpToolTokens: 800,
            mcpServers: [['name' => 'alpha', 'tokens' => 500]],
        ));
    }

    /**
     * An App with the Context pane docked right, focused, and a hosted chat
     * carrying a measured context — the shell state the brief's pins describe.
     */
    private function measuredApp(): App
    {
        $dock = \SugarCraft\Constraints\Dock\DockLayout::new('chat')
            ->withSlotAdded(Side::Right, 'context');

        return $this->plainApp()
            ->withChat($this->measuredChat()->withSize(160, 40))
            ->withDock($dock)
            ->withPane(Pane::Context);
    }

    private function plainApp(): App
    {
        return App::new(new EchoProvider(), 'test-model');
    }

    /** @return list<string> the pane ids stacked on one side, in order */
    private function dockSlotIds(App $app, Side $side): array
    {
        return array_map(
            static fn(\SugarCraft\Constraints\Dock\DockSlot $slot): string => $slot->paneId,
            $app->dock()->slots($side),
        );
    }

    private function resetShellChrome(): void
    {
        foreach (self::VARS as $var) {
            putenv($var);
        }
        MenuBar::closeMenu();
        ChromeRenderer::resetSizeCache();
        ChromeRenderer::chromeScanner()->clear();
        ShellRenderer::scanner()->clear();
        ShellRenderer::setZoneOrigin(0, 0);
    }
}
