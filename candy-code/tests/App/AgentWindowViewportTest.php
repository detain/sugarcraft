<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Live\SubAgentTranscriptLog;
use SugarCraft\Code\App\App;
use SugarCraft\Code\App\PermissionRequestMsg;
use SugarCraft\Code\App\Tabs\OpenTabMsg;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Backend\PendingAsk;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Events\SubAgentActivity;
use SugarCraft\Code\Message;
use SugarCraft\Code\Permissions\AskOrigin;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Renderer as LiveRenderer;
use SugarCraft\Code\ToolCall;
use SugarCraft\Code\Tui\Renderer as TuiRenderer;
use SugarCraft\Code\Tui\Window\WindowAttention;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Mouse\Zone;

/**
 * Roadmap P-AGENT.b gaps 1 + 2: every window — the main composer of each
 * tab and every agent view — owns its draft, caret and scroll, stashed in
 * {@see \SugarCraft\Code\Tui\Window\WindowViewports}; the open agent view
 * is remembered per tab so switching windows takes it with the tab that
 * owned it and brings it back; and the window list carries EACH tab's live
 * runs as children (⏳ when one waits on the user), not just the active
 * tab's.
 */
final class AgentWindowViewportTest extends TestCase
{
    private const COLS = 160;
    private const ROWS = 40;
    private const TAB2_SESSION = 'viewport-tab2-session';
    // With a real deck the Sessions pane auto-docks (P-TABS.g), and the
    // docked pane's rows — not the window strip's chips — carry the click
    // zones for the same windows: that is the production shape here.
    private const AGENT_ROW = LiveRenderer::SIDE_ROW_ZONE_PREFIX . 'sessions:a:';
    private const SESSION_ROW = LiveRenderer::SIDE_ROW_ZONE_PREFIX . 'sessions:s:';

    private ProviderInterface $provider;

    private string $session;

    private string|false $originalDisableMouse;

    private string|false $originalDisableClicks;

    /** @var list<string> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->originalDisableMouse = getenv('CANDY_CODE_DISABLE_MOUSE');
        $this->originalDisableClicks = getenv('CANDY_CODE_DISABLE_MOUSE_CLICKS');
        putenv('CANDY_CODE_DISABLE_MOUSE');
        putenv('CANDY_CODE_DISABLE_MOUSE_CLICKS');

        TuiRenderer::setSize(self::COLS, self::ROWS);
        TuiRenderer::chromeScanner()->clear();
        LiveRenderer::scanner()->clear();
        LiveRenderer::setZoneOrigin(0, 0);
        App::resetPaneDragController();
        (new \ReflectionProperty(App::class, 'chromeClickTracker'))->setValue(null, null);

        $this->provider = $this->createMock(ProviderInterface::class);
        $this->session = 'viewport-' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        TuiRenderer::setSize(200, 60);
        TuiRenderer::chromeScanner()->clear();
        LiveRenderer::scanner()->clear();
        $this->restoreEnv($this->originalDisableMouse, 'CANDY_CODE_DISABLE_MOUSE');
        $this->restoreEnv($this->originalDisableClicks, 'CANDY_CODE_DISABLE_MOUSE_CLICKS');
        foreach ($this->logs as $log) {
            @unlink($log);
            @rmdir(\dirname($log));
        }
    }

    public function testTheMainDraftCaretAndScrollSurviveAnOpenCloseOperation(): void
    {
        $app = $this->singleApp();
        $main = $this->typedInto($app->chat, 'summarise the diff but keep it brief');
        [$main] = $main->update(new KeyMsg(KeyType::Left));
        [$main] = $main->update(new KeyMsg(KeyType::Left));
        $app = $app->withChat($main->withScrollOffset(4));

        $open = $app->openAgentView('run-1');
        $this->assertSame('run-1', $open->agentViewTarget);
        $this->assertSame(['', 0], $open->chat?->draft(), 'the view opens at its own bottom, not the main draft\'s');
        $this->assertSame(0, $open->chat?->scrollOffset(), 'and the main scroll does not leak into it');

        $inView = $this->typedInto($open->chat, 'use two lines only');
        $closed = $open->withChat($inView)->closeAgentView();

        $this->assertNull($closed->agentViewTarget);
        $this->assertSame(
            ['summarise the diff but keep it brief', 34],
            $closed->chat?->draft(),
            'the main composer comes back exactly where it was left',
        );
        $this->assertSame(4, $closed->chat?->scrollOffset());

        $again = $closed->openAgentView('run-1');
        $this->assertSame(['use two lines only', 18], $again->chat?->draft(), 'the agent keeps its own draft between visits');
    }

    public function testSiblingNavigationKeepsEachAgentItsOwnViewport(): void
    {
        $app = $this->singleApp();

        $one = $this->typedInto($app->openAgentView('run-1')->chat, 'note for the first run');
        $two = $this->typedInto($app->openAgentView('run-1')->withChat($one)->openAgentView('run-2')->chat, 'note for the second');

        $back = $app->openAgentView('run-1')->withChat($one)->openAgentView('run-2')->withChat($two)->openAgentView('run-1');
        $this->assertSame('run-1', $back->agentViewTarget);
        $this->assertSame(['note for the first run', 22], $back->chat?->draft());

        $forward = $back->openAgentView('run-2');
        $this->assertSame(['note for the second', 19], $forward->chat?->draft(), 'and the other window still holds its own');
    }

    public function testTheOpenViewIsRememberedPerTabAcrossWindowSwitches(): void
    {
        $app = $this->deckApp();
        $this->assertSame('tab-2', $app->deck?->activeKey());
        $app = $app->withChat($this->typedInto($app->chat, 'tab two main draft'));

        $open = $this->click($app, self::AGENT_ROW . 'run-9');
        $this->assertSame('run-9', $open->agentViewTarget, 'the strip chip opens the view through the live route');
        $open = $open->withChat($this->typedInto($open->chat, 'interrupt me if lost'));

        $away = $this->click($open, self::SESSION_ROW . 'tab-1');
        $this->assertNull($away->agentViewTarget, 'switching tabs takes the view with the tab that owned it');
        $this->assertSame('', $away->chat?->draft()[0], 'and the other tab arrives on its own clean composer');

        $back = $this->click($away, self::SESSION_ROW . 'tab-2');
        $this->assertSame('tab-2', $back->deck?->activeKey());
        $this->assertSame('run-9', $back->agentViewTarget, 'the remembered run is open again');
        $this->assertSame(['interrupt me if lost', 20], $back->chat?->draft(), 'with its own draft back');

        // tab-2 is active again, so its live Chat rides the app, not the
        // parked map; its main draft survives the round trip in the tab's
        // stashed viewport — that is where production keeps it while the
        // remembered agent window owns the composer.
        $stashed = $back->windowViewports?->restore('s:tab-2');
        $this->assertNotNull($stashed);
        $this->assertSame('tab two main draft', $stashed->draft, 'the main draft was restored before parking, never eaten');
    }

    public function testTheWindowListCarriesEveryTabsRunsAndWaiters(): void
    {
        $app = $this->deckApp(waitingOnTab2: true);
        $list = $app->windowList();
        $this->assertNotNull($list);

        $byKey = [];
        foreach ($list->entries() as $entry) {
            $byKey[$entry->ref->key] = $entry;
        }

        $this->assertArrayHasKey('tab-1', $byKey, 'a background tab is in the list');
        $background = $byKey['tab-1']->child('run-1');
        $this->assertNotNull($background, 'with its own run as a child row — P-AGENT.b gap 2');
        $this->assertSame(WindowAttention::Working, $background->attention);
        $this->assertSame('tab-1', $background->ref->parentKey);

        $focused = $byKey['tab-2']->child('run-9');
        $this->assertNotNull($focused);
        $this->assertSame(
            WindowAttention::Waiting,
            $focused->attention,
            'a run parked on a permission ask wears the waiting attention of its tab\'s pending question',
        );
    }

    public function testClickingABackgroundRunsTabSwitchesToItFirst(): void
    {
        $app = $this->deckApp();
        $this->assertSame('tab-2', $app->deck?->activeKey());

        $open = $this->click($app, self::AGENT_ROW . 'run-9');
        $this->assertSame('run-9', $open->agentViewTarget);
        $closed = $open->closeAgentView();

        $onTab1 = $this->click($closed, self::SESSION_ROW . 'tab-1');
        $this->assertSame('tab-1', $onTab1->deck?->activeKey());
        $run1 = $this->click($onTab1, self::AGENT_ROW . 'run-1');
        $this->assertSame('run-1', $run1->agentViewTarget, 'now the background run is the foreground view');
        $this->assertSame($onTab1->chat?->history, $run1->chat?->history, 'and it belongs to this tab\'s chat');
    }

    private function restoreEnv(string|false $value, string $name): void
    {
        if ($value === false) {
            putenv($name);
        } else {
            putenv($name . '=' . $value);
        }
    }

    /** @param list<string> $ids */
    private function makeChat(string $session, string $marker, array $ids): Chat
    {
        $chat = (new Chat(
            history: [Message::user($marker), Message::toolRunning(new ToolCall('Task', [], 'call_' . $marker))],
            backend: new EchoBackend(),
            inFlight: true,
            generation: 1,
        ))->withCurrentSessionId($session)->withSize(self::COLS, self::ROWS);

        foreach ($ids as $id) {
            $log = SubAgentTranscriptLog::forRun($session, $id);
            $log->user('the task, in ' . $marker);
            $this->logs[] = $log->path();
            $chat->agentLive()->apply(new SubAgentActivity(
                SubAgentActivity::OP_STARTED,
                $id,
                'explore',
                'a task',
                1,
                '',
                parentCallId: 'call_' . $marker,
                transcriptLog: $log->path(),
                parentSessionId: $session,
            ));
        }

        return $chat;
    }

    private function singleApp(): App
    {
        [$app] = App::new($this->provider, 'm')
            ->withChat($this->makeChat($this->session, 'solo', ['run-1', 'run-2']))
            ->update(new WindowSizeMsg(self::COLS, self::ROWS));

        return $app;
    }

    private function deckApp(bool $waitingOnTab2 = false): App
    {
        [$app] = App::new($this->provider, 'm')
            ->withChat($this->makeChat($this->session, 'solo', ['run-1']))
            ->withTabFactory(function (?string $sessionId) use ($waitingOnTab2): Chat {
                $session = $sessionId ?? self::TAB2_SESSION;
                $chat = $this->makeChat($session, 'two', ['run-9']);
                if (!$waitingOnTab2) {
                    return $chat;
                }
                [$armed] = $chat->update(new PermissionRequestMsg(
                    Message::assistant(''),
                    new ToolCall('Edit', [], 'call_p'),
                    'apply the patch?',
                    1,
                    new PendingAsk(
                        'ask-1',
                        'call_p',
                        'Edit',
                        [],
                        'r',
                        'gate',
                        'default',
                        [],
                        [],
                        static fn (): bool => true,
                        new AskOrigin('run-9', 'explore', 'call_two'),
                    ),
                ));

                return $armed;
            })
            ->update(new WindowSizeMsg(self::COLS, self::ROWS));
        [$app] = $app->update(new OpenTabMsg(sessionId: self::TAB2_SESSION));

        return $app;
    }

    private function typedInto(?Chat $chat, string $draft): Chat
    {
        $this->assertInstanceOf(Chat::class, $chat);
        foreach (mb_str_split($draft) as $rune) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $rune));
            $this->assertInstanceOf(Chat::class, $chat);
        }

        return $chat;
    }

    private function frame(App $app): string
    {
        return Ansi::strip(TuiRenderer::renderView($app, self::COLS, self::ROWS)->body);
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
}
