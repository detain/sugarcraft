<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Code\Agents\Live\AgentLiveRegistry;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\CancelAgentRunMsg;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Events\SubAgentActivity;
use SugarCraft\Code\Message;
use SugarCraft\Code\Support\StartupSummary;
use SugarCraft\Code\OpenAgentViewMsg;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\Theme;
use SugarCraft\Code\ToolCall;
use SugarCraft\Code\Tui\AgentStrip;
use SugarCraft\Code\Tui\AgentViewMode;
use SugarCraft\Code\Tui\Pane;

/**
 * Roadmap P-B3: the one-row live agents strip above the input — which runs
 * it lists, that it never paints wider than its row, and the keyboard it
 * takes with `Alt+↓`.
 */
final class AgentStripTest extends TestCase
{
    private float $now = 1000.0;

    public function testItListsLiveRunsAndFinishedOnesForThirtySeconds(): void
    {
        $live = $this->registry();
        $live->apply(self::started('r1', 'explore', 'call_1'));
        $live->apply(self::started('r2', 'reviewer', 'call_2'));
        $live->apply(self::finished('r2', 'reviewer', 'call_2'));

        $this->assertSame(['r1', 'r2'], self::ids(AgentStrip::items($live)));
        $this->assertSame(['r1'], self::ids(AgentStrip::items($live, ['r2' => true])), 'a dismissed run is left off');

        $this->now += AgentStrip::LINGER_SECONDS + 1;
        $live->advance();
        $this->assertSame(['r1'], self::ids(AgentStrip::items($live)), 'a finished run leaves after the linger');
    }

    public function testTheRowNamesEachRunWithItsStateAndTheHint(): void
    {
        $live = $this->registry();
        $live->apply(self::started('r1', 'explore', 'call_1'));
        $live->apply(self::started('r2', 'reviewer', 'call_2'));
        $live->apply(self::finished('r2', 'reviewer', 'call_2', SubAgentActivity::OUTCOME_FAILED));

        $row = self::plain(AgentStrip::render(AgentStrip::items($live), 80, Theme::default(), 0));

        $this->assertSame('agents: ⠋ explore · ✗ reviewer   (alt+↓)', $row);
        $this->assertSame('', AgentStrip::render([], 80, Theme::default(), 0), 'no runs, no row');
    }

    public function testTheRowNeverOutgrowsItsWidthAndCountsWhatItHid(): void
    {
        $live = $this->registry();
        foreach (range(1, 8) as $n) {
            $live->apply(self::started('r' . $n, 'agent-number-' . $n, 'call_' . $n));
        }
        $items = AgentStrip::items($live);

        foreach ([1, 5, 12, 20, 30, 45, 60, 80, 120, 200] as $width) {
            $row = AgentStrip::render($items, $width, Theme::default(), 3, 'r1', true);
            $this->assertLessThanOrEqual($width, Width::string(self::plain($row)), "width {$width}");
        }

        // 55 cells hold two runs without the hint and only one with it.
        $narrow = self::plain(AgentStrip::render($items, 55, Theme::default(), 0));
        $this->assertMatchesRegularExpression('/\+\d+ more$/', $narrow, 'the hint is dropped before items are');
        $this->assertStringNotContainsString('alt+↓', $narrow);
    }

    public function testAFocusedRunIsHighlightedAndKeptInView(): void
    {
        $live = $this->registry();
        foreach (range(1, 8) as $n) {
            $live->apply(self::started('r' . $n, 'agent-number-' . $n, 'call_' . $n));
        }
        $items = AgentStrip::items($live);

        $row = AgentStrip::render($items, 60, Theme::default(), 0, 'r7');

        $this->assertStringContainsString('agent-number-7', self::plain($row), 'the focused run is on screen');
        $this->assertMatchesRegularExpression('/\e\[(?:\d+;)*7m/', $row, 'and painted reversed');
        $this->assertStringContainsString('(←/→ · enter · c · x · esc)', self::plain(AgentStrip::render($items, 200, Theme::default(), 0, 'r1')));
    }

    public function testEachVisibleRunIsAClickZoneAndANameCannotForgeOne(): void
    {
        $live = $this->registry();
        $live->apply(self::started('r1', "evil\u{E000}name", 'call_1'));

        $row = AgentStrip::render(AgentStrip::items($live), 80, Theme::default(), 0, null, true);

        $this->assertStringContainsString('agent:r1', $row, 'the item carries its zone');
        $this->assertSame(2, substr_count($row, "\u{E000}"), 'only the zone\'s own open and close sentinels; the name\'s was stripped');
        $this->assertStringContainsString('evilname', self::plain($row));
        $this->assertStringNotContainsString('agent:r1', AgentStrip::render(AgentStrip::items($live), 80, Theme::default(), 0), 'no zones when clicks are off');
    }

    public function testTheChatPaintsTheStripAboveTheInputInsteadOfTheBlockBelowIt(): void
    {
        $chat = (new Chat())->withSize(100, 30);
        $chat->agentLive()->apply(self::started('r1', 'explore', 'call_1'));

        $lines = explode("\n", self::plain(Renderer::render($chat)));
        $strip = null;
        foreach ($lines as $i => $line) {
            if (str_starts_with($line, 'agents: ')) {
                $strip = $i;
            }
            $this->assertLessThanOrEqual(100, Width::string($line), "row {$i} fits the terminal");
        }

        $this->assertNotNull($strip, 'the strip is painted');
        $this->assertStringStartsWith('┌', $lines[$strip + 1], 'directly above the input box');
        // CL-3: the shell paints no bottom border any more, so the row above
        // the strip is transcript text itself — since VIS-1 the startup
        // summary's tip row (a fresh launch opens on the summary, not on the
        // old empty-conversation notice), packed against the strip. The row
        // is asserted equal to the summary block's own last line rather than
        // to a hand-copied tip: the roster order may change, the seam may not.
        $summary = explode("\n", self::plain(StartupSummary::render($chat)));
        $this->assertSame(
            (string) end($summary),
            rtrim($lines[$strip - 1]),
            'and directly below the transcript text',
        );

        $focused = Renderer::renderView($chat, 'r1')->body;
        $this->assertMatchesRegularExpression('/\e\[(?:\d+;)*7m/', $focused, 'the shell\'s focus is painted');
        $this->assertStringNotContainsString('agents: ', self::plain(Renderer::renderView($chat, null, ['r1' => true])->body), 'a dismissed run leaves no strip');
    }

    public function testAltDownFocusesTheStripAndArrowsMoveAlongIt(): void
    {
        $app = $this->app();

        [$app] = $app->update(new KeyMsg(KeyType::Down, alt: true));
        $this->assertSame('r1', $app->agentStripFocus);

        [$app] = $app->update(new KeyMsg(KeyType::Right));
        $this->assertSame('r2', $app->agentStripFocus);
        [$app] = $app->update(new KeyMsg(KeyType::Right));
        $this->assertSame('r2', $app->agentStripFocus, 'the last run stays focused');
        [$app] = $app->update(new KeyMsg(KeyType::Up));
        $this->assertSame('r1', $app->agentStripFocus);

        [$app] = $app->update(new KeyMsg(KeyType::Up, alt: true));
        $this->assertNull($app->agentStripFocus, 'Alt+↑ hands the keyboard back');
    }

    public function testAnotherKeyHandsTheKeyboardBackAndLandsInTheInput(): void
    {
        [$app] = $this->app()->update(new KeyMsg(KeyType::Down, alt: true));
        [$app] = $app->update(new KeyMsg(KeyType::Char, 'h'));

        $this->assertNull($app->agentStripFocus);
        $this->assertSame('h', $app->chat?->inputBuf);
    }

    public function testAltDownDoesNothingWithoutLiveRuns(): void
    {
        $app = App::new($this->createMock(ProviderInterface::class), 'm')->withChat(new Chat());

        [$next] = $app->update(new KeyMsg(KeyType::Down, alt: true));

        $this->assertNull($next->agentStripFocus);
    }

    public function testEnterOpensTheFocusedRunInTheAgentView(): void
    {
        [$app] = $this->app()->update(new KeyMsg(KeyType::Down, alt: true));
        [$app] = $app->update(new KeyMsg(KeyType::Right));
        [$app] = $app->update(new KeyMsg(KeyType::Enter));

        // Roadmap P-C2: the run's transcript in the main area.
        $this->assertSame(Pane::Chat, $app->pane);
        $this->assertSame('r2', $app->agentViewTarget, 'the run that was focused');
        $this->assertSame(AgentViewMode::Attach, $app->agentViewMode);
        $this->assertNull($app->agentStripFocus);
        $entries = \SugarCraft\Code\Tui\Components\AgentDashboardPane::entries($app);
        $this->assertSame('r2', $entries[$app->selectedAgentIndex]->key, 'the dashboard agrees');
    }

    public function testAClickOnARunsZoneOpensItThroughTheShell(): void
    {
        [$app] = $this->app()->update(new OpenAgentViewMsg('r1'));

        $this->assertSame(Pane::Chat, $app->pane);
        $this->assertSame('r1', $app->agentViewTarget);
        $entries = \SugarCraft\Code\Tui\Components\AgentDashboardPane::entries($app);
        $this->assertSame('r1', $entries[$app->selectedAgentIndex]->key);
    }

    public function testAClickOnAStripItemAsksTheShellToOpenThatRun(): void
    {
        $chat = (new Chat())->withSize(100, 30);
        $chat->agentLive()->apply(self::started('r1', 'explore', 'call_1'));
        $tracker = new \ReflectionProperty(Chat::class, 'clickTracker');
        $tracker->setValue(null, null);
        Renderer::scanner()->clear();
        $chat->view();

        $zone = Renderer::scanner()->get(AgentStrip::ZONE_PREFIX . 'r1');
        $this->assertNotNull($zone, 'the strip item is a click zone in the live frame');

        [$pressed] = $chat->update(new \SugarCraft\Core\Msg\MouseClickMsg($zone->startCol, $zone->startRow, \SugarCraft\Core\MouseButton::Left, \SugarCraft\Core\MouseAction::Press));
        [, $cmd] = $pressed->update(new \SugarCraft\Core\Msg\MouseReleaseMsg($zone->startCol, $zone->startRow, \SugarCraft\Core\MouseButton::Left, \SugarCraft\Core\MouseAction::Release));
        $tracker->setValue(null, null);
        Renderer::scanner()->clear();

        $this->assertInstanceOf(\Closure::class, $cmd);
        $msg = $cmd();
        $this->assertInstanceOf(OpenAgentViewMsg::class, $msg);
        $this->assertSame('r1', $msg->agentId);
    }

    public function testCStopsOnlyTheFocusedRunsTaskCall(): void
    {
        $token = new CancellationToken();
        $app = $this->app($token);

        [$app] = $app->update(new KeyMsg(KeyType::Down, alt: true));
        [$app] = $app->update(new KeyMsg(KeyType::Right));
        [$app] = $app->update(new KeyMsg(KeyType::Char, 'c'));

        $this->assertSame(['call_2'], $token->takeToolCancels(), 'cancel_tool for that run\'s Task call');
        $this->assertFalse($token->isSoftCancelled(), 'the turn itself carries on');
        $this->assertTrue($app->chat?->inFlight);
    }

    public function testXDismissesAFinishedRunAndStopsARunningOne(): void
    {
        $token = new CancellationToken();
        $app = $this->app($token);
        $app->chat?->agentLive()->apply(self::finished('r2', 'reviewer', 'call_2'));

        [$app] = $app->update(new KeyMsg(KeyType::Down, alt: true));
        [$app] = $app->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertSame(['call_1'], $token->takeToolCancels(), 'r1 still runs: x stops it');

        [$app] = $app->update(new KeyMsg(KeyType::Right));
        [$app] = $app->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertSame(['r1'], self::ids($app->agentStripItems()), 'r2 had finished: x took it off the strip');
        $this->assertSame('r1', $app->agentStripFocus, 'and the focus moved to its neighbour');
        $this->assertSame([], $token->takeToolCancels());
    }

    public function testARequestForACallTheTurnIsNotRunningStopsNothing(): void
    {
        $token = new CancellationToken();
        $chat = $this->runningChat($token);

        [$next] = $chat->update(new CancelAgentRunMsg('call_9'));

        $this->assertSame([], $token->takeToolCancels());
        $this->assertSame($chat, $next);
    }

    private function app(?CancellationToken $token = null): App
    {
        $chat = $this->runningChat($token ?? new CancellationToken());
        $chat->agentLive()->apply(self::started('r1', 'explore', 'call_1'));
        $chat->agentLive()->apply(self::started('r2', 'reviewer', 'call_2'));
        $chat->agentManager()?->projectRemoteSubAgent(self::started('r1', 'explore', 'call_1'));
        $chat->agentManager()?->projectRemoteSubAgent(self::started('r2', 'reviewer', 'call_2'));

        return App::new($this->createMock(ProviderInterface::class), 'm')->withChat($chat);
    }

    private function runningChat(CancellationToken $token): Chat
    {
        $provider = $this->createMock(ProviderInterface::class);
        $manager = new \SugarCraft\Code\Agents\AgentManager($provider, new \SugarCraft\Code\Skills\SkillRegistry());
        $manager->register(\SugarCraft\Code\Tests\Support\RosterAgent::named('explore'));
        $manager->register(\SugarCraft\Code\Tests\Support\RosterAgent::named('reviewer'));

        return (new Chat(
            history: [
                Message::user('go'),
                Message::toolRunning(new ToolCall('Task', [], 'call_1')),
                Message::toolRunning(new ToolCall('Task', [], 'call_2')),
            ],
            backend: new EchoBackend(),
            inFlight: true,
            generation: 1,
            inFlightCancellation: $token,
            agentManager: $manager,
        ))->withSize(100, 30);
    }

    private function registry(): AgentLiveRegistry
    {
        return AgentLiveRegistry::new(fn (): float => $this->now);
    }

    private static function started(string $id, string $name, string $callId): SubAgentActivity
    {
        return new SubAgentActivity(SubAgentActivity::OP_STARTED, $id, $name, 'a task', 1, '', parentCallId: $callId);
    }

    private static function finished(string $id, string $name, string $callId, string $outcome = SubAgentActivity::OUTCOME_COMPLETE): SubAgentActivity
    {
        return new SubAgentActivity(SubAgentActivity::OP_FINISHED, $id, $name, '', 2, 'done', parentCallId: $callId, outcome: $outcome);
    }

    /**
     * @param list<\SugarCraft\Code\Agents\Live\AgentLiveState> $items
     *
     * @return list<string>
     */
    private static function ids(array $items): array
    {
        return array_map(static fn ($state): string => $state->id, $items);
    }

    private static function plain(string $s): string
    {
        $s = (string) preg_replace('/\e\[[0-9;:]*[A-Za-z]/', '', $s);

        return (string) preg_replace('/\xEE\x80\x80\/?[A-Za-z0-9._:-]*\xEE\x80\x81/', '', $s);
    }
}
