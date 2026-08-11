<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseMotionMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Palette\PaletteState;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Mouse\Sentinel;
use SugarCraft\Mouse\Zone;

/**
 * crush_feat.md section 8 E3 — click-to-switch pane.
 *
 * Renderer-side assertions are structural snapshots of the zone registry the
 * root scan produces (the sentinels themselves never survive into the frame,
 * so the registry IS the observable output); Chat-side assertions drive
 * `update()` and assert on the returned `[Model, ?Cmd]`.
 *
 * @see Renderer::markPane()
 * @see Chat::update()
 */
final class PaneClickTest extends TestCase
{
    private const VARS = ['SUGARCRUSH_DISABLE_MOUSE', 'SUGARCRUSH_DISABLE_MOUSE_CLICKS'];

    protected function setUp(): void
    {
        foreach (self::VARS as $var) {
            putenv($var);
        }
        Renderer::scanner()->clear();
        $this->resetClickTracker();
    }

    protected function tearDown(): void
    {
        foreach (self::VARS as $var) {
            putenv($var);
        }
        Renderer::scanner()->clear();
        $this->resetClickTracker();
    }

    // =========================================================================
    // Renderer: the pane header regions carry click zones
    // =========================================================================

    public function testStatusBarMenuHintIsRegisteredAsThePaneMenuZone(): void
    {
        $chat = new Chat();

        Renderer::render($chat);

        $zone = Renderer::scanner()->get('pane:menu');

        self::assertInstanceOf(Zone::class, $zone);
        // The hint is the last line of the frame (the status bar is appended
        // after the height clip), and the zone is at least as wide as the
        // literal it wraps.
        self::assertSame($chat->rows(), $zone->startRow);
        self::assertGreaterThanOrEqual(strlen('Ctrl+P menu'), $zone->width());
    }

    public function testNoMenuZoneIsMarkedWhileARequestIsInFlight(): void
    {
        // The in-flight status bar drops the hint entirely, so there is no
        // region to click - marking one anyway would register a zone over
        // whatever text replaced it.
        $chat = new Chat(inFlight: true);

        $frame = Renderer::render($chat);

        self::assertStringNotContainsString('Ctrl+P menu', $frame);
        self::assertNull(Renderer::scanner()->get('pane:menu'));
    }

    public function testAgentStatusHeaderIsRegisteredAsThePaneAgentsZone(): void
    {
        $chat = new Chat(agentManager: $this->agentManagerWith([$this->reviewerAgent()]));

        Renderer::render($chat);

        $menu = Renderer::scanner()->get('pane:menu');
        $agents = Renderer::scanner()->get('pane:agents');

        self::assertInstanceOf(Zone::class, $agents);
        self::assertInstanceOf(Zone::class, $menu);
        // Only the pane's header ROW is marked, never the whole block: a
        // multi-row zone can lose its opening sentinel to render()'s height
        // clip and desync the scan for the entire frame.
        self::assertSame($agents->startRow, $agents->endRow);
        // The agent block sits above the status bar.
        self::assertLessThan($menu->startRow, $agents->startRow);
    }

    public function testNoPaneZonesAreMarkedWhenClicksAreDisabled(): void
    {
        putenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS=1');
        $chat = new Chat(agentManager: $this->agentManagerWith([$this->reviewerAgent()]));

        $frame = Renderer::render($chat);

        self::assertStringContainsString('Ctrl+P menu', $frame);
        self::assertSame([], Renderer::scanner()->all());
    }

    public function testPaneZoneMarkersNeverReachTheRenderedFrame(): void
    {
        $chat = new Chat(agentManager: $this->agentManagerWith([$this->reviewerAgent()]));

        $frame = Renderer::render($chat);

        self::assertStringNotContainsString(Sentinel::OPEN, $frame);
        self::assertStringNotContainsString(Sentinel::CLOSE, $frame);
        self::assertStringContainsString('Ctrl+P menu', $frame);
        self::assertStringContainsString('reviewer', $frame);
    }

    // =========================================================================
    // Chat: a completed click on a pane header jumps to that pane
    // =========================================================================

    public function testLeftClickOnTheMenuHintOpensThePalette(): void
    {
        // Before W2.S11c the status bar advertised Ctrl+P and nothing else,
        // so this exact click sequence left the palette closed.
        $chat = new Chat();
        Renderer::render($chat);
        $zone = Renderer::scanner()->get('pane:menu');
        self::assertInstanceOf(Zone::class, $zone);

        [$afterPress, $pressCmd] = $chat->update($this->press($zone->startCol, $zone->startRow));
        self::assertNull($pressCmd);
        self::assertNull($afterPress->palette(), 'press alone must not open the palette');

        // Released on the press cell, not the zone's far edge: sweeping to
        // endCol is a text selection under section 8 E8, not a click.
        [$afterRelease, $releaseCmd] = $afterPress->update($this->release($zone->startCol, $zone->startRow));

        self::assertNull($releaseCmd);
        self::assertInstanceOf(PaletteState::class, $afterRelease->palette());
    }

    public function testMenuClickIsIgnoredWhileThePaletteIsAlreadyOpen(): void
    {
        $chat = new Chat();
        Renderer::render($chat);
        $zone = Renderer::scanner()->get('pane:menu');
        self::assertInstanceOf(Zone::class, $zone);

        [$chat] = $chat->update($this->press($zone->startCol, $zone->startRow));
        [$chat] = $chat->update($this->release($zone->startCol, $zone->startRow));
        $opened = $chat->palette();
        self::assertInstanceOf(PaletteState::class, $opened);

        [$chat] = $chat->update($this->press($zone->startCol, $zone->startRow));
        [$chat, $cmd] = $chat->update($this->release($zone->startCol, $zone->startRow));

        self::assertNull($cmd);
        self::assertSame($opened, $chat->palette(), 'a second click must not re-root an open palette');
    }

    public function testPressOnTheMenuHintAndReleaseElsewhereDoesNotOpenThePalette(): void
    {
        // The drag-away / text-selection case: routing through
        // ZoneClickTracker is what keeps this from firing.
        $chat = new Chat();
        Renderer::render($chat);
        $zone = Renderer::scanner()->get('pane:menu');
        self::assertInstanceOf(Zone::class, $zone);

        [$chat] = $chat->update($this->press($zone->startCol, $zone->startRow));
        [$chat] = $chat->update($this->release($zone->startCol, $zone->startRow - 4));

        self::assertNull($chat->palette());
    }

    public function testLeftClickOnTheAgentHeaderRunsTheAgentsCommand(): void
    {
        $chat = new Chat(agentManager: $this->agentManagerWith([$this->reviewerAgent()]));
        Renderer::render($chat);
        $zone = Renderer::scanner()->get('pane:agents');
        self::assertInstanceOf(Zone::class, $zone);
        self::assertSame([], $chat->history);

        [$chat] = $chat->update($this->press($zone->startCol, $zone->startRow));
        [$chat, $cmd] = $chat->update($this->release($zone->startCol, $zone->startRow));

        self::assertNull($cmd);
        // The same history pair handleAgentsCommand() appends for a typed
        // "/agents" (and for the palette's SwitchAgent action).
        self::assertCount(2, $chat->history);
        self::assertSame('/agents', $chat->history[0]->content);
        self::assertStringContainsString('reviewer', $chat->history[1]->content);
    }

    public function testClickIsIgnoredWhenClicksAreDisabled(): void
    {
        $chat = new Chat();
        Renderer::render($chat);
        $zone = Renderer::scanner()->get('pane:menu');
        self::assertInstanceOf(Zone::class, $zone);

        putenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS=1');
        [$chat] = $chat->update($this->press($zone->startCol, $zone->startRow));
        [$chat] = $chat->update($this->release($zone->startCol, $zone->startRow));

        self::assertNull($chat->palette());
    }

    public function testNonLeftButtonsAndNonClickEventsAreIgnored(): void
    {
        $chat = new Chat();
        Renderer::render($chat);
        $zone = Renderer::scanner()->get('pane:menu');
        self::assertInstanceOf(Zone::class, $zone);
        $col = $zone->startCol;
        $row = $zone->startRow;

        $ignored = [
            new MouseClickMsg($col, $row, MouseButton::Right, MouseAction::Press),
            new MouseReleaseMsg($col, $row, MouseButton::Right, MouseAction::Release),
            new MouseMotionMsg($col, $row, MouseButton::Left, MouseAction::Motion),
        ];

        foreach ($ignored as $msg) {
            [$next, $cmd] = $chat->update($msg);
            self::assertNull($cmd);
            self::assertNull($next->palette());
        }
    }

    public function testClickOnAPaneWithNoLiveSurfaceIsANoOp(): void
    {
        // Files/Tools/Skills/Settings/Help exist only in the disconnected
        // App/Tui system, so nothing marks a zone for them - but a zone id
        // from a previous frame (or another marker source) must not be
        // answered with an invented state change.
        $chat = new Chat();
        Renderer::scanner()->scan("\u{E000}pane:files\u{E001}Files\u{E000}/pane:files\u{E001}", 80);

        [$chat] = $chat->update($this->press(1, 1));
        [$chat, $cmd] = $chat->update($this->release(1, 1));

        self::assertNull($cmd);
        self::assertNull($chat->palette());
        self::assertSame([], $chat->history);
    }

    public function testClickOnAZoneNamingNoKnownPaneIsANoOp(): void
    {
        $chat = new Chat();
        Renderer::scanner()->scan("\u{E000}pane:nope\u{E001}Nope\u{E000}/pane:nope\u{E001}", 80);

        [$chat] = $chat->update($this->press(1, 1));
        [$chat, $cmd] = $chat->update($this->release(1, 1));

        self::assertNull($cmd);
        self::assertNull($chat->palette());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function press(int $col, int $row): MouseClickMsg
    {
        return new MouseClickMsg($col, $row, MouseButton::Left, MouseAction::Press);
    }

    private function release(int $col, int $row): MouseReleaseMsg
    {
        return new MouseReleaseMsg($col, $row, MouseButton::Left, MouseAction::Release);
    }

    /**
     * @param list<Agent> $agents
     */
    private function agentManagerWith(array $agents): AgentManager
    {
        $manager = new AgentManager($this->createMock(ProviderInterface::class), new SkillRegistry());
        foreach ($agents as $agent) {
            $manager->register($agent);
        }

        return $manager;
    }

    private function reviewerAgent(): Agent
    {
        return new Agent(
            name: 'reviewer',
            description: 'Reviews code for bugs',
            prompt: 'You are a reviewer.',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        );
    }

    private function resetClickTracker(): void
    {
        (new \ReflectionProperty(Chat::class, 'clickTracker'))->setValue(null, null);
    }
}
