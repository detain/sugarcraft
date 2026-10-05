<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

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
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\Live\AgentLiveRegistry;
use SugarCraft\Crush\Agents\Live\SubAgentTranscriptLog;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\CloseAgentViewMsg;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\OpenAgentViewMsg;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionKind;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\Tui\AgentViewHeader;
use SugarCraft\Crush\Tui\AgentViewMode;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Mouse\Mark;

/**
 * Roadmap P-C2: the read-only Agent View — a delegated run's own transcript
 * in the main area, opened from its live line, tailed while it runs, left
 * with Esc without arming the parent turn's Esc-Esc cancel.
 */
final class AgentViewTest extends TestCase
{
    private const VIEW_COLS = 120;
    private const VIEW_ROWS = 30;

    /** @var list<string> */
    private array $logs = [];

    protected function setUp(): void
    {
        Renderer::setAgentView(null);
        Renderer::scanner()->clear();
        (new \ReflectionProperty(Chat::class, 'clickTracker'))->setValue(null, null);
    }

    protected function tearDown(): void
    {
        Renderer::setAgentView(null);
        Renderer::scanner()->clear();
        (new \ReflectionProperty(Chat::class, 'clickTracker'))->setValue(null, null);
        foreach ($this->logs as $log) {
            @unlink($log);
            @rmdir(\dirname($log));
        }
    }

    public function testAClickOnATaskLineOpensThatRunsTranscriptInTheMainArea(): void
    {
        $app = $this->app();
        $chat = $app->chat;
        $this->assertNotNull($chat);

        Renderer::render($chat);
        $zone = Renderer::scanner()->get(Renderer::AGENT_LINE_ZONE_PREFIX . 'run-2');
        $this->assertNotNull($zone, 'the Task row\'s live line is a click zone');
        [$pressed] = $chat->update(new MouseClickMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Press));
        [, $cmd] = $pressed->update(new MouseReleaseMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Release));
        $this->assertInstanceOf(\Closure::class, $cmd);
        $msg = $cmd();
        $this->assertInstanceOf(OpenAgentViewMsg::class, $msg);
        $this->assertSame('run-2', $msg->agentId);

        [$open] = $app->update($msg);
        $this->assertSame('run-2', $open->agentViewTarget);
        $this->assertSame(AgentViewMode::Attach, $open->agentViewMode);
        $this->assertSame(Pane::Chat, $open->pane);

        $frame = self::plain($open);
        $this->assertStringContainsString('main ▸ explore', $frame, 'the header names where the user is');
        $this->assertStringContainsString('esc back', $frame);
        $this->assertStringContainsString('look at the session layer', $frame, 'the run\'s own task');
        $this->assertStringContainsString('The store keeps sessions in SQLite', $frame, 'and its own reply');
        $this->assertStringNotContainsString('audit everything', $frame, 'not the parent\'s transcript');
    }

    public function testTheViewTailsTheLogWhileItIsOpen(): void
    {
        $app = $this->app()->openAgentView('run-2');
        $this->assertSame(1, \count(array_filter($app->agentViewRows, static fn (Message $m): bool => $m->content === 'The store keeps sessions in SQLite.')));

        $subscriptions = $app->subscriptions();
        $this->assertNotNull($subscriptions);
        $this->assertTrue($subscriptions->has(App::AGENT_VIEW_SUBSCRIPTION . 'run-2'), 'the tail tick runs while the view is open');

        SubAgentTranscriptLog::at($this->logs[1])->assistant('Found the retention window, too.');
        [$ticked] = $app->update(new OpenAgentViewMsg('run-2'));
        $this->assertSame('run-2', $ticked->agentViewTarget, 'the tick refreshes, it does not reopen');
        $this->assertStringContainsString('Found the retention window, too.', self::plain($ticked));

        [$closed] = $ticked->update(new CloseAgentViewMsg());
        $this->assertNull($closed->agentViewTarget);
        $this->assertFalse(
            $closed->subscriptions()?->has(App::AGENT_VIEW_SUBSCRIPTION . 'run-2') ?? false,
            'and stops once it is closed',
        );
        $this->assertStringContainsString('audit everything', self::plain($closed), 'the main transcript is back');
    }

    public function testEscLeavesTheViewWithoutArmingTheTurnCancel(): void
    {
        $app = $this->app();

        // An Esc in the main view arms the double-tap cancel...
        [$armed] = $app->update(new KeyMsg(KeyType::Escape));
        $this->assertTrue($armed->chat?->inFlight);

        // ...the user opens a run, and Esc leaves it...
        [$open] = $armed->update(new OpenAgentViewMsg('run-1'));
        [$back] = $open->update(new KeyMsg(KeyType::Escape));
        $this->assertNull($back->agentViewTarget);
        $this->assertTrue($back->chat?->inFlight, 'leaving the view cancels nothing');

        // ...so the next Esc is a FIRST press again, not the second half.
        [$after] = $back->update(new KeyMsg(KeyType::Escape));
        $this->assertTrue($after->chat?->inFlight, 'the Esc that left the view did not count toward a cancel');
    }

    public function testAltNAndAltPWalkTheBatchAndTheHeaderSaysWhere(): void
    {
        $app = $this->app()->openAgentView('run-1');
        $this->assertSame(['run-1', 'run-2'], $app->agentViewSiblings());
        $this->assertStringContainsString('‹ 1 of 2 ›', self::plain($app));

        [$next] = $app->update(new KeyMsg(KeyType::Char, 'n', alt: true));
        $this->assertSame('run-2', $next->agentViewTarget);
        $this->assertStringContainsString('‹ 2 of 2 ›', self::plain($next));

        [$end] = $next->update(new KeyMsg(KeyType::Char, 'n', alt: true));
        $this->assertSame('run-2', $end->agentViewTarget, 'past the last sibling nothing moves');

        [$prev] = $end->update(new KeyMsg(KeyType::Char, 'p', alt: true));
        $this->assertSame('run-1', $prev->agentViewTarget);
    }

    public function testTheHeaderZonesLeaveTheViewAndOpenASibling(): void
    {
        $app = $this->app()->openAgentView('run-1');
        $chat = $app->chat;
        $this->assertNotNull($chat);
        // The chat's own frame with the shell's view in it — what the shell
        // composites, at the chat's own origin.
        Renderer::setAgentView($app->agentViewFrame());
        Renderer::render($chat);
        Renderer::setAgentView(null);

        $back = Renderer::scanner()->get(AgentViewHeader::BACK_ZONE);
        $this->assertNotNull($back, 'the breadcrumb is a click zone');
        $this->assertInstanceOf(CloseAgentViewMsg::class, self::click($chat, $back->startCol, $back->startRow));

        $sibling = Renderer::scanner()->get(AgentViewHeader::NAV_ZONE_PREFIX . 'run-2');
        $this->assertNotNull($sibling, 'the next-sibling arrow is a click zone');
        $msg = self::click($chat, $sibling->startCol, $sibling->startRow);
        $this->assertInstanceOf(OpenAgentViewMsg::class, $msg);
        $this->assertSame('run-2', $msg->agentId);
    }

    public function testAFinishedRunReopensFromItsStoredChildSession(): void
    {
        $dir = sys_get_temp_dir() . '/crush_agent_view_' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        try {
            $store = new EnhancedSessionStore($dir . '/s.db');
            $store->createSession('parent', 'p', 'm', null, 'Parent');
            $child = $store->createChildSession('parent', SessionKind::Subagent, 'reviewer', 'call_9', 'p', 'm', 'Check CSRF (@reviewer)');
            $store->saveTranscript($child, [
                ['role' => 'user', 'content' => 'check the CSRF middleware'],
                ['role' => 'assistant', 'content' => 'Every POST route carries the token.'],
            ]);
            $chat = (new Chat(history: [Message::user('hi')], backend: new EchoBackend(), sessionStore: $store, currentSessionId: 'parent'))
                ->withSize(self::VIEW_COLS, self::VIEW_ROWS);
            $app = App::new($this->createMock(ProviderInterface::class), 'm')->withChat($chat);

            [$open] = $app->update(new OpenAgentViewMsg($child, $child, 'reviewer'));

            $frame = self::plain($open);
            $this->assertStringContainsString('main ▸ reviewer', $frame);
            $this->assertStringContainsString('Every POST route carries the token.', $frame);
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    public function testAWorkerWithNoTranscriptIsItsLiveOutputInAttachMode(): void
    {
        $manager = new AgentManager($this->createMock(ProviderInterface::class), new SkillRegistry());
        $manager->register(new Agent(
            name: 'stage-1',
            description: 'A workflow stage',
            prompt: 'You are a stage.',
            model: 'test-model',
            provider: 'test',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        ));
        $chat = (new Chat(history: [Message::user('hi')], backend: new EchoBackend(), agentManager: $manager))
            ->withSize(self::VIEW_COLS, self::VIEW_ROWS);
        $app = App::new($this->createMock(ProviderInterface::class), 'm')
            ->withChat($chat)
            ->withPane(Pane::Agents)
            ->withSelectedAgentIndex(0)
            ->withAgentViewMode(AgentViewMode::Peek);

        // The dashboard's peek Enter attaches: the main area shows the worker.
        [$attached] = $app->update(new KeyMsg(KeyType::Enter));
        $this->assertSame('stage-1', $attached->agentViewTarget);
        $this->assertSame(AgentViewMode::Attach, $attached->agentViewMode);
        $this->assertSame(Pane::Chat, $attached->pane);

        $frame = self::plain($attached);
        $this->assertStringContainsString('main ▸ stage-1', $frame);
        $this->assertStringContainsString('stage-1', $frame);
        $this->assertStringNotContainsString(Lang::t(AgentViewHeader::NO_TRANSCRIPT), $frame, 'its live pane, not an empty view');

        // Esc from the dashboard while attached detaches the view too.
        [$dash] = $attached->withPane(Pane::Agents)->update(new KeyMsg(KeyType::Escape));
        $this->assertNull($dash->agentViewTarget);
        $this->assertSame(AgentViewMode::List, $dash->agentViewMode);
    }

    public function testATaskBatchCarriesTheHintUnderItsLastRunningRow(): void
    {
        $frame = Ansi::strip(Renderer::render($this->app()->chat ?? self::fail('no chat')));

        $this->assertSame(1, substr_count($frame, Lang::t(Renderer::AGENT_BATCH_HINT)), 'once per batch');
        $lines = explode("\n", $frame);
        $at = 0;
        foreach ($lines as $index => $line) {
            if (str_contains($line, Lang::t(Renderer::AGENT_BATCH_HINT))) {
                $at = $index;
            }
        }
        $this->assertStringContainsString(Lang::t(Renderer::AGENT_BATCH_HINT_CLICK), $lines[$at], 'clicks are on, so it says so');
        $this->assertStringContainsString('└', $lines[$at - 1], 'right under the batch\'s last live line');
        $this->assertStringContainsString('└', $lines[$at - 2], 'which follows the first');
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function widths(): iterable
    {
        foreach ([1, 8, 20, 30, 40, 60, 80, 120, 160] as $width) {
            yield "{$width} cols" => [$width];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('widths')]
    public function testTheHeaderNeverOutgrowsItsRowAndScrubsTheName(int $width): void
    {
        $live = AgentLiveRegistry::new(static fn (): float => 1000.0);
        $live->apply(self::started('run-1', "explore\u{E000}\x1b[31m-auth", 'call_1', null, ['tools' => 7, 'step' => 4, 'maxSteps' => 50, 'tokensIn' => 3000, 'tokensOut' => 1100, 'costUsd' => 0.0021]));
        $live->apply(self::started('run-2', 'explore', 'call_1'));
        $state = $live->get('run-1');
        $this->assertNotNull($state);

        $row = AgentViewHeader::render($state->name, $state, ['run-1', 'run-2'], $width, Theme::default(), 0, 1012.0, true);

        $this->assertLessThanOrEqual($width, Width::string(self::stripZones($row)), 'never wider than the row');
        $this->assertStringNotContainsString("\u{E000}", self::stripZones(Ansi::strip($row)), 'no Private-Use code point from the name');
        $this->assertStringNotContainsString("\x1b[31m", $row, 'no escape from the name');
        if ($width >= 120) {
            $this->assertStringContainsString('main ▸ explore-auth  ‹ 1 of 2 ›', self::stripZones(Ansi::strip($row)));
            $this->assertStringContainsString('step 4/50 · 0:12 · 4.1K tok · $0.0021   esc back', self::stripZones(Ansi::strip($row)));
        }
    }

    public function testSlashAgentNamingALiveRunOpensItsView(): void
    {
        $events = new \ArrayObject();
        $manager = new AgentManager($this->createMock(ProviderInterface::class), new SkillRegistry());
        $typed = static fn (string $text): Chat => new Chat(inputBuf: $text, agentManager: $manager, liveToolEvents: $events);
        $typed('')->agentLive()->apply(self::started('run-1', 'explore', 'call_1'));
        $typed('')->agentLive()->apply(self::started('run-2', 'review', 'call_1'));

        [$byId, $cmd] = $typed('/agent run-2')->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame('', $byId->inputBuf);
        $this->assertInstanceOf(\Closure::class, $cmd);
        $msg = $cmd();
        $this->assertInstanceOf(OpenAgentViewMsg::class, $msg);
        $this->assertSame('run-2', $msg->agentId, 'by run id');

        [, $cmd] = $typed('/agent explore')->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertSame('run-1', $cmd()->agentId, 'by the name of the one run it has');

        $typed('')->agentLive()->apply(self::started('run-3', 'explore', 'call_2'));
        [$ambiguous] = $typed('/agent explore')->update(new KeyMsg(KeyType::Enter, ''));
        $rows = $ambiguous->history;
        $this->assertStringContainsString('explore', $rows[\count($rows) - 1]->content, 'two runs of one name: the preset answer');
    }

    // ── fixtures ─────────────────────────────────────────────────────────

    /**
     * A shell over a chat whose turn runs two delegated runs of one Task
     * batch (`run-1`, `run-2` under `call_1`), each with its own log.
     */
    private function app(): App
    {
        $chat = (new Chat(
            history: [Message::user('audit everything'), Message::toolRunning(new ToolCall('Task', [], 'call_1'))],
            backend: new EchoBackend(),
            inFlight: true,
            generation: 1,
        ))->withSize(self::VIEW_COLS, self::VIEW_ROWS);

        $tasks = ['run-1' => ['map the login flow', 'Login goes through AuthManager.'], 'run-2' => ['look at the session layer', 'The store keeps sessions in SQLite.']];
        foreach ($tasks as $id => [$task, $reply]) {
            $log = SubAgentTranscriptLog::forRun('parent-' . bin2hex(random_bytes(3)), $id);
            $log->user($task);
            $log->toolCall('c-' . $id, 'Read', ['path' => 'src/Session/Store.php']);
            $log->toolResult('c-' . $id, 'Read', true, '<?php // store');
            $log->assistant($reply);
            $this->logs[] = $log->path();
            $chat->agentLive()->apply(self::started($id, 'explore', 'call_1', $log->path()));
        }

        [$app] = App::new($this->createMock(ProviderInterface::class), 'm')
            ->withChat($chat)
            ->update(new WindowSizeMsg(self::VIEW_COLS, self::VIEW_ROWS));

        return $app;
    }

    /**
     * @param array<string, int|float> $stats
     */
    private static function started(string $id, string $name, string $call, ?string $log = null, array $stats = []): SubAgentActivity
    {
        return new SubAgentActivity(
            SubAgentActivity::OP_STARTED,
            $id,
            $name,
            'a task',
            1,
            '',
            parentCallId: $call,
            stats: $stats === [] ? [] : $stats + ['startedAt' => 1000.0],
            transcriptLog: $log,
        );
    }

    /** What the shell paints, as plain text. */
    private static function plain(App $app): string
    {
        $view = $app->view();

        return self::stripZones(Ansi::strip(\is_string($view) ? $view : $view->body));
    }

    private static function stripZones(string $text): string
    {
        return (string) preg_replace('/\x{E000}[^\x{E001}]*\x{E001}|[\x{E000}-\x{F8FF}]/u', '', $text);
    }

    /** Press and release at a cell of the chat's last frame; the Msg its Cmd sends. */
    private static function click(Chat $chat, int $col, int $row): ?object
    {
        (new \ReflectionProperty(Chat::class, 'clickTracker'))->setValue(null, null);
        [$pressed] = $chat->update(new MouseClickMsg($col, $row, MouseButton::Left, MouseAction::Press));
        [, $cmd] = $pressed->update(new MouseReleaseMsg($col, $row, MouseButton::Left, MouseAction::Release));

        return $cmd instanceof \Closure ? $cmd() : null;
    }
}
