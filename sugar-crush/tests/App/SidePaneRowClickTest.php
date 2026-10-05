<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMotionMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Renderer as LiveRenderer;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\RosterAgent;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Tui\Components\AgentsPane;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;
use SugarCraft\Layout\Dock\DockLayout;
use SugarCraft\Layout\Dock\Side;
use SugarCraft\Mouse\Zone;

/**
 * The shell's side-surface mouse gestures. Clicking a row of a docked side
 * pane expands it, the way clicking a tool
 * row in the transcript does — through the live routing: the zone the frame
 * published, a press and a release through {@see App::update()}.
 *
 * A Tools row shares the transcript's expansion entry for its call, so a
 * click there and a click on the transcript row open the same thing; an
 * Agents row expands its run's activity in place. The wheel scrolls the
 * surface under the pointer. And the seam between the band and the
 * live-agent column drags like any side's — previewed as it moves, Escape
 * mid-drag handing back the width it had.
 */
final class SidePaneRowClickTest extends TestCase
{
    private const SHELL_COLS = 160;

    private const SHELL_ROWS = 40;

    private ProviderInterface $provider;

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
        (new \ReflectionProperty(App::class, 'chromeClickTracker'))->setValue(null, null);

        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('TestProvider');
    }

    protected function tearDown(): void
    {
        putenv($this->originalDisableMouse === false ? 'SUGARCRUSH_DISABLE_MOUSE' : 'SUGARCRUSH_DISABLE_MOUSE=' . $this->originalDisableMouse);
        putenv($this->originalDisableClicks === false ? 'SUGARCRUSH_DISABLE_MOUSE_CLICKS' : 'SUGARCRUSH_DISABLE_MOUSE_CLICKS=' . $this->originalDisableClicks);

        TuiRenderer::chromeScanner()->clear();
        LiveRenderer::scanner()->clear();
        LiveRenderer::setZoneOrigin(0, 0);
        App::resetPaneDragController();
        (new \ReflectionProperty(App::class, 'chromeClickTracker'))->setValue(null, null);
        TuiRenderer::setSize(200, 60);

        parent::tearDown();
    }

    public function testClickingAToolsRowExpandsItAndAgainCollapsesIt(): void
    {
        $app = $this->app();
        $this->assertStringNotContainsString('│ first line of output', $this->frame($app));

        $app = $this->click($app, LiveRenderer::SIDE_ROW_ZONE_PREFIX . 'tools:call_read');

        $this->assertTrue($app->chat?->expanded()['call_read'] ?? false, 'the same expansion entry the transcript row uses');
        $frame = $this->frame($app);
        $this->assertStringContainsString('path: src/Foo.php', $frame, 'the call\'s arguments');
        $this->assertStringContainsString('│ first line of output', $frame, 'the head of its output');

        $app = $this->click($app, LiveRenderer::SIDE_ROW_ZONE_PREFIX . 'tools:call_read');
        $this->assertArrayNotHasKey('call_read', $app->chat?->expanded() ?? []);
    }

    public function testClickingAnAgentsRowShowsTheRunsActivityInPlace(): void
    {
        $app = $this->app();
        // Scoped to the Agents pane itself: the live-agent column beside the
        // band shows the same activity, so the whole frame cannot tell them apart.
        $pane = static fn (App $a): string => Ansi::strip(AgentsPane::render($a, 40, 12));
        $this->assertStringNotContainsString('-> Grep(pattern: "TODO")', $pane($app));

        $app = $this->click($app, LiveRenderer::SIDE_ROW_ZONE_PREFIX . 'agents:run-1');

        $this->assertTrue($app->isAgentExpanded('run-1'));
        $this->assertStringContainsString('-> Grep(pattern: "TODO")', $pane($app));

        $app = $this->click($app, LiveRenderer::SIDE_ROW_ZONE_PREFIX . 'agents:run-1');
        $this->assertFalse($app->isAgentExpanded('run-1'), 'a second click collapses it');
    }

    public function testClickingARunsRowInTheAgentDashboardOpensItsAgentView(): void
    {
        $app = $this->app()->withPane(\SugarCraft\Crush\Tui\Pane::Agents);
        $this->assertNull($app->agentViewTarget);
        $lines = explode("\n", $this->frame($app));
        $zone = TuiRenderer::chromeScanner()->get(\SugarCraft\Crush\Tui\Components\AgentDashboardPane::ZONE_PREFIX . 'run-1');
        $this->assertInstanceOf(Zone::class, $zone);
        $this->assertStringContainsString('reviewer', $lines[$zone->startRow - 1] ?? '', 'the zone sits on the run\'s own row');

        $app = $this->click($app, \SugarCraft\Crush\Tui\Components\AgentDashboardPane::ZONE_PREFIX . 'run-1');

        $this->assertSame('run-1', $app->agentViewTarget, 'the click opens that run, as Enter on its row does');
        $this->assertSame(\SugarCraft\Crush\Tui\Pane::Chat, $app->pane);
    }

    public function testARowClickIsRefusedWhileThePermissionPromptOrKeyHelpCoversTheScreen(): void
    {
        $app = $this->app();
        $this->frame($app);
        $chat = $app->chat;
        $this->assertNotNull($chat);
        $helped = $app->withChat((new \ReflectionMethod($chat, 'mutate'))->invoke($chat, ['keyHelp' => 0]));

        $after = (new \ReflectionMethod(App::class, 'dispatchChromeClick'))
            ->invoke($helped, LiveRenderer::SIDE_ROW_ZONE_PREFIX . 'agents:run-1')[0];

        $this->assertFalse($after->isAgentExpanded('run-1'));
    }

    /**
     * A wheel notch over a docked pane scrolls THAT pane (down shows older
     * calls), clamped at its end; anywhere else the wheel still scrolls the
     * transcript, untouched by the pane's offset.
     */
    public function testTheWheelOverASidePaneScrollsThatPaneOnly(): void
    {
        $history = [];
        for ($i = 0; $i < 60; $i++) {
            $history[] = Message::assistant('x')->withToolResults([ToolResult::ok('Read', 'x', 'c' . $i)->withDescription('Read file ' . $i)]);
        }
        $dock = DockLayout::new('chat')->withSlotAdded(Side::Right, 'tools');
        [$app] = App::new($this->provider, 'test-model')
            ->withDock($dock)
            ->withChat(new Chat($history))
            ->update(new WindowSizeMsg(self::SHELL_COLS, self::SHELL_ROWS));

        $this->frame($app);
        // Content is read off the pane itself — the transcript beside it
        // carries the same labels.
        $pane = static fn (App $a): string => Ansi::strip(\SugarCraft\Crush\Tui\Components\ToolsPane::render($a, 50, 20));
        $this->assertStringContainsString('Read file 59', $pane($app), 'newest first, at the top');
        $region = TuiRenderer::scrollRegionAt(self::SHELL_COLS - 10, 5);
        $this->assertSame('pane:tools', $region['id'] ?? null, 'fixture: the Tools pane is under the pointer');

        [$scrolled] = $app->update(new MouseWheelMsg(self::SHELL_COLS - 10, 5, MouseButton::WheelDown, MouseAction::Press));

        $this->assertSame(3, $scrolled->paneScroll('pane:tools'), 'one notch, three rows');
        $this->assertSame($app->chat?->scrollOffset(), $scrolled->chat?->scrollOffset(), 'the transcript did not move');
        $this->assertStringNotContainsString('Read file 59', $pane($scrolled));
        $this->assertStringContainsString('↑ 3 newer', $pane($scrolled));

        for ($i = 0; $i < 40; $i++) {
            [$scrolled] = $scrolled->update(new MouseWheelMsg(self::SHELL_COLS - 10, 5, MouseButton::WheelDown, MouseAction::Press));
        }
        $this->assertSame($region['max'], $scrolled->paneScroll('pane:tools'), 'clamped at the end of the list');

        [$chatScrolled] = $app->update(new MouseWheelMsg(10, 10, MouseButton::WheelUp, MouseAction::Press));
        $this->assertSame(0, $chatScrolled->paneScroll('pane:tools'), 'the wheel over the chat leaves the pane alone');
    }

    public function testDraggingTheSeamLeftWidensTheColumnByTheTravel(): void
    {
        $app = $this->splitApp();
        $seam = $this->seam($app);
        $before = TuiRenderer::lastDockFrame()['agentCols'] ?? 0;
        $this->assertGreaterThan(0, $before, 'fixture: the live-agent column is on screen');

        [$app] = $app->update(new MouseClickMsg($seam->startCol, $seam->startRow, MouseButton::Left, MouseAction::Press));
        [$app] = $app->update(new MouseMotionMsg($seam->startCol - 6, $seam->startRow, MouseButton::Left, MouseAction::Motion));
        $this->assertSame($before + 6, $app->agentSplitCols, 'previewed live, as the pointer moves');
        [$app] = $app->update(new MouseMotionMsg($seam->startCol - 10, $seam->startRow, MouseButton::Left, MouseAction::Motion));
        [$app] = $app->update(new MouseReleaseMsg($seam->startCol - 10, $seam->startRow, MouseButton::Left, MouseAction::Release));

        $this->assertSame($before + 10, $app->agentSplitCols);
        $this->frame($app);
        $this->assertSame($before + 10, TuiRenderer::lastDockFrame()['agentCols'] ?? 0, 'the next frame paints it');
    }

    public function testEscapeMidDragHandsBackTheWidthItHad(): void
    {
        $app = $this->splitApp();
        $seam = $this->seam($app);

        [$app] = $app->update(new MouseClickMsg($seam->startCol, $seam->startRow, MouseButton::Left, MouseAction::Press));
        [$app] = $app->update(new MouseMotionMsg($seam->startCol - 8, $seam->startRow, MouseButton::Left, MouseAction::Motion));
        $this->assertNotNull($app->agentSplitCols);

        [$app] = $app->update(new KeyMsg(KeyType::Escape));

        $this->assertNull($app->agentSplitCols, 'back to the default proportion');
    }

    public function testAClickOnTheSeamWithNoMotionChangesNothing(): void
    {
        $app = $this->splitApp();
        $seam = $this->seam($app);

        [$app] = $app->update(new MouseClickMsg($seam->startCol, $seam->startRow, MouseButton::Left, MouseAction::Press));
        [$app] = $app->update(new MouseReleaseMsg($seam->startCol, $seam->startRow, MouseButton::Left, MouseAction::Release));

        $this->assertNull($app->agentSplitCols);
    }

    private function app(): App
    {
        $manager = new AgentManager($this->provider, new SkillRegistry());
        $manager->register(RosterAgent::named('reviewer')->withActive(false));
        $manager->projectRemoteSubAgent(new SubAgentActivity('started', 'run-1', 'reviewer', 'Audit candy-core', 1, ''));
        $manager->projectRemoteSubAgent(new SubAgentActivity('progress', 'run-1', 'reviewer', '', 2, '-> Grep(pattern: "TODO")'));

        $history = [
            Message::assistant('read it')->withToolResults([
                new ToolResult('Read', "first line of output\nsecond line", id: 'call_read', arguments: ['path' => 'src/Foo.php']),
            ]),
        ];
        $dock = DockLayout::new('chat')->withSlotAdded(Side::Right, 'tools')->withSlotAdded(Side::Right, 'agents');

        [$app] = App::new($this->provider, 'test-model')
            ->withDock($dock)
            ->withChat(new Chat($history, agentManager: $manager))
            ->update(new WindowSizeMsg(self::SHELL_COLS, self::SHELL_ROWS));

        return $app;
    }

    private function frame(App $app): string
    {
        return Ansi::strip(TuiRenderer::renderView($app, self::SHELL_COLS, self::SHELL_ROWS)->body);
    }

    private function click(App $app, string $zoneId): App
    {
        $this->frame($app);
        $zone = TuiRenderer::chromeScanner()->get($zoneId);
        self::assertInstanceOf(Zone::class, $zone, 'fixture: the painted frame zoned ' . $zoneId);

        [$app] = $app->update(new MouseClickMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Press));
        [$app] = $app->update(new MouseReleaseMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Release));

        return $app;
    }

    private function splitApp(): App
    {
        $manager = new AgentManager($this->provider, new SkillRegistry());
        $manager->register(RosterAgent::named('reviewer')->withActive(false));
        $manager->projectRemoteSubAgent(new SubAgentActivity('started', 'run-1', 'reviewer', 'Audit candy-core', 1, ''));
        $manager->projectRemoteSubAgent(new SubAgentActivity('progress', 'run-1', 'reviewer', '', 2, '-> Read(path: "a")'));
        $dock = DockLayout::new('chat')->withSlotAdded(Side::Right, 'tools');

        [$app] = App::new($this->provider, 'test-model')
            ->withDock($dock)
            ->withChat(new Chat(agentManager: $manager))
            ->update(new WindowSizeMsg(self::SHELL_COLS, self::SHELL_ROWS));

        return $app;
    }

    private function seam(App $app): Zone
    {
        $this->frame($app);
        $bandTop = TuiRenderer::lastDockFrame()['bandTop'] ?? 1;
        $zone = TuiRenderer::chromeScanner()->get(TuiRenderer::SPLIT_DIVIDER_ZONE_PREFIX . 'r' . ($bandTop + 2));
        self::assertInstanceOf(Zone::class, $zone, 'fixture: the seam is a grab target');

        return $zone;
    }
}
