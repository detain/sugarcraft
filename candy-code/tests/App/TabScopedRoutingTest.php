<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\App;
use SugarCraft\Code\App\AgentMessageSentMsg;
use SugarCraft\Code\App\Tabs\CloseTabMsg;
use SugarCraft\Code\App\Tabs\OpenTabMsg;
use SugarCraft\Code\App\Tabs\SessionDeck;
use SugarCraft\Code\App\Tabs\TabScopedMsg;
use SugarCraft\Code\App\ToolEventPumpMsg;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\KeyBindingRegistry;
use SugarCraft\Code\Events\SubAgentActivity;
use SugarCraft\Code\Events\TokenDelta;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Tui\Components\MenuSelectedMsg;
use SugarCraft\Code\Tui\MessagePicker\OpenMessagePickerMsg;
use SugarCraft\Code\Tui\Settings\OpenSettingsMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;

/**
 * P-TABS.e routing law: a wrapped message reaches the tab that owns it and
 * no other — foreground-scoped wraps unwrap into the shell's own match,
 * parked wraps land on the parked model, shell modals from a parked tab are
 * dropped with an attention raise, and the App's own messages are never
 * scoped at all.
 */
final class TabScopedRoutingTest extends TestCase
{
    private ProviderInterface $provider;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ProviderInterface::class);
    }

    private function idleRoutingChat(): Chat
    {
        return new Chat(backend: new EchoBackend());
    }

    /** A deck-armed App whose FOREGROUND chat holds a live turn (busy A). */
    private function routingDeckApp(int $extraTabs = 0, bool $foregroundBusy = true): App
    {
        $foreground = $foregroundBusy
            ? new Chat(backend: new EchoBackend(), inFlight: true, generation: 1)
            : $this->idleRoutingChat();
        $app = App::new($this->provider, 'test-model')
            ->withChat($foreground)
            ->withTabFactory(fn (?string $sessionId): Chat => $this->idleRoutingChat());
        [$app] = $app->update(new WindowSizeMsg(100, 30));
        for ($i = 0; $i < $extraTabs; $i++) {
            [$app] = $app->update(new OpenTabMsg());
        }

        return $app;
    }

    /** Read the attention state a deck tab advertises (SessionTab surface). */
    private static function tabAttention(App $app, string $tabKey): string
    {
        $deck = $app->deck;
        self::assertNotNull($deck);
        foreach ($deck->tabs()->tabs() as $tab) {
            if ($tab->id === $tabKey) {
                return $tab->attention();
            }
        }

        self::fail("deck has no tab {$tabKey}");
    }

    public function testDeckArmsWithTheLaunchTabAndKeepsForegroundSubscriptionsUnscoped(): void
    {
        $app = $this->routingDeckApp();

        self::assertNotNull($app->deck);
        self::assertSame('tab-1', $app->deck->activeKey());
        // The foreground's own ticks carry plain ids — scoping is only ever
        // added to PARKED tabs, so nothing about a single-tab launch changes.
        $subscriptions = $app->subscriptions();
        self::assertNotNull($subscriptions);
        self::assertTrue($subscriptions->has('code.tool-event-poll'));
        self::assertFalse($subscriptions->has('tab:tab-1:code.tool-event-poll'));
    }

    public function testForegroundScopedMessageUnwrapsIntoTheActiveTab(): void
    {
        $app = $this->routingDeckApp(extraTabs: 1, foregroundBusy: false);
        self::assertSame('tab-2', $app->deck?->activeKey());

        [$after] = $app->update(new TabScopedMsg('tab-2', new WindowSizeMsg(90, 24)));

        // Re-entering the shell's match means the App's size half fired:
        // cols/rows moved AND the foreground Chat re-sized.
        self::assertSame(90, $after->cols);
        self::assertSame(24, $after->rows);
        self::assertSame('tab-2', $after->deck?->activeKey());
    }

    public function testParkedMessageRoutesToTheParkedModelNotTheForeground(): void
    {
        $app = $this->routingDeckApp(extraTabs: 1, foregroundBusy: false);
        $parked = $app->deck?->model('tab-1');
        self::assertInstanceOf(Chat::class, $parked);
        $foreground = $app->chat;
        self::assertInstanceOf(Chat::class, $foreground);

        [$after] = $app->update(new TabScopedMsg('tab-1', new WindowSizeMsg(80, 20)));

        // The parked tab got the size; the App and the foreground tab did not.
        self::assertSame(100, $after->cols);
        self::assertSame(30, $after->rows);
        self::assertSame($foreground, $after->chat);
        $reparked = $after->deck?->model('tab-1');
        self::assertInstanceOf(Chat::class, $reparked);
        self::assertNotSame($parked, $reparked);
        self::assertSame(80, $reparked->cols());
        self::assertSame(20, $reparked->rows());
        self::assertSame(100, $foreground->cols());
    }

    public function testShellModalFromAParkedTabIsDroppedWithAnAttentionRaise(): void
    {
        $app = $this->routingDeckApp(extraTabs: 1, foregroundBusy: false);

        [$after] = $app->update(new TabScopedMsg('tab-1', new OpenSettingsMsg()));

        // A background tab can never pop a modal: the shell editor stayed
        // shut, the routing changed nothing but the tab's attention.
        self::assertNull($after->settingsEditor);
        self::assertSame(self::tabAttention($app, 'tab-1'), 'idle');
        self::assertSame(SessionDeck::ATTENTION_WAITING, self::tabAttention($after, 'tab-1'));
        self::assertSame('tab-2', $after->deck?->activeKey());
    }

    public function testAppLevelOpenSettingsStillOpensWithTheDeckArmed(): void
    {
        $app = $this->routingDeckApp(extraTabs: 1, foregroundBusy: false);

        [$after] = $app->update(new OpenSettingsMsg());

        // The unwrapped App route is untouched by the deck: shell messages
        // the foreground itself sends keep opening the editor.
        self::assertNotNull($after->settingsEditor);
        self::assertSame('tab-2', $after->deck?->activeKey());
    }

    public function testParkedSubscriptionsArePrefixedAndForegroundIdsNeverCollide(): void
    {
        // Busy A foreground, open B: A's pump tick appears a second time,
        // keyed and prefixed, while B (idle) declares nothing of its own.
        $app = $this->routingDeckApp(foregroundBusy: true);
        [$after] = $app->update(new OpenTabMsg());

        $subscriptions = $after->subscriptions();
        self::assertNotNull($subscriptions);
        // A's pump keeps ticking — under its key — while the parked tab holds
        // the turn; B is idle and the raw id is no longer anyone's.
        self::assertTrue($subscriptions->has('tab:tab-1:code.tool-event-poll'));
        self::assertFalse($subscriptions->has('code.tool-event-poll'));
    }

    public function testRunKeyedAgentMessageFromAParkedTabReachesTheGlobalLedger(): void
    {
        // The docblock's promised exception, pinned: a `.then` Agent row
        // that surfaces after the user switched tabs is keyed to a PARKED
        // tab, but the Agent View it feeds is global — dropping it into
        // the parked Chat (which ignores the msg) loses a row the
        // foreground itself would have recorded.
        $app = $this->routingDeckApp(extraTabs: 1, foregroundBusy: false);
        $foreground = $app->chat;
        $parked = $app->deck?->model('tab-1');
        self::assertInstanceOf(Chat::class, $foreground);
        self::assertInstanceOf(Chat::class, $parked);

        [$after] = $app->update(new TabScopedMsg('tab-1', new AgentMessageSentMsg(
            'run-7',
            'explore',
            'also check the auth flow',
            AgentMessageSentMsg::QUEUED,
        )));

        self::assertCount(1, $after->agentMessages, 'the parked-key row still lands in the shell ledger');
        self::assertSame('run-7', $after->agentMessages[0]->agentId);
        self::assertSame($foreground, $after->chat, 'recording never disturbs the foreground Chat');
        self::assertSame($parked, $after->deck?->model('tab-1'), 'the parked model is not fed the msg either');
    }

    public function testEscapeChordOnAStreamedCharIsNotDeckInterception(): void
    {
        // Control-n is the deck's; plain text keeps reaching the Chat buffer.
        $app = $this->routingDeckApp(foregroundBusy: false);
        $before = $app->chat;
        self::assertInstanceOf(Chat::class, $before);

        [$after] = $app->update(new KeyMsg(KeyType::Char, 'n'));

        self::assertSame(1, $after->deck?->count());
        self::assertNotSame($before, $after->chat);
    }

    /**
     * Deck-armed App whose FOREGROUND chat is handed in verbatim — the
     * producer-side sibling of {@see self::routingDeckApp()}, which builds
     * its own foreground and cannot seed an inbox or the tabHosted flag.
     */
    private function producerDeckApp(Chat $foreground): App
    {
        $app = App::new($this->provider, 'test-model')
            ->withChat($foreground)
            ->withTabFactory(fn (?string $sessionId): Chat => new Chat(backend: new EchoBackend()));
        [$app] = $app->update(new WindowSizeMsg(100, 30));

        return $app;
    }

    /**
     * P-TABS.e producer law, same-instance leg: a Cmd the foreground Chat
     * re-arms on itself (the live-event pump's continuation) must reach the
     * program stamped with the tab that produced it. Deleting the wrap on
     * App.php's `!$next instanceof Chat || $next === $this->chat` return
     * reddens exactly this pin (the raw ToolEventPumpMsg would surface).
     */
    public function testThePumpReArmProducedByTheForegroundChatCarriesItsTabScope(): void
    {
        $inbox = new \ArrayObject([
            [1, new SubAgentActivity('progress', 'run_a', 'explore', 'first beat', 1, '')],
            [1, new TokenDelta('a queued delta keeps one entry behind the fold')],
        ]);
        $chat = new Chat(
            backend: new EchoBackend(),
            inFlight: true,
            generation: 1,
            liveToolEvents: $inbox,
        );
        $app = $this->producerDeckApp($chat);

        [, $cmd] = $app->update(new ToolEventPumpMsg());

        self::assertNotNull($cmd, 'a non-empty queue after the fold must re-arm the pump');
        $produced = $cmd();
        self::assertInstanceOf(TabScopedMsg::class, $produced);
        self::assertSame('tab-1', $produced->tabKey);
        self::assertInstanceOf(ToolEventPumpMsg::class, $produced->inner);
    }

    /**
     * P-TABS.e producer law, transition leg: the idle double-Escape opens
     * the message picker ON THE TAB THAT OWNED THE KEYS. With tab-2 in
     * front, the wrap must carry tab-2 — the finding's exact shape (a
     * reply produced while another tab is backgrounded reaches its OWNER).
     * Deleting the wrap on the delegateToChat `withChat($next)` return
     * reddens exactly this pin.
     */
    public function testTheDoubleEscapePickerRequestCarriesTheActiveTabScope(): void
    {
        $app = $this->producerDeckApp(new Chat(backend: new EchoBackend(), tabHosted: true));
        [$app] = $app->update(new OpenTabMsg());
        self::assertSame('tab-2', $app->deck?->activeKey(), 'fixture: the second tab is in front');

        [$app, $first] = $app->update(new KeyMsg(KeyType::Escape));
        self::assertNull($first, 'the arming press sends nothing; the null Cmd passes through');

        [$app, $second] = $app->update(new KeyMsg(KeyType::Escape));
        self::assertNotNull($second);
        $produced = $second();
        self::assertInstanceOf(TabScopedMsg::class, $produced, 'the picker request must carry its owning tab');
        self::assertSame('tab-2', $produced->tabKey);
        self::assertInstanceOf(OpenMessagePickerMsg::class, $produced->inner);
    }

    /**
     * P-TABS.e producer law, menu door: a palette-only registry row chosen
     * from the menu bar runs on the FOREGROUND Chat, and its Cmd crosses the
     * deck boundary stamped ({@see App::runRegistryCommand()}). Deleting the
     * wrap there reddens exactly this pin.
     */
    public function testTheMenuRowCommandReturnCarriesTheActiveTabScope(): void
    {
        $app = $this->producerDeckApp(new Chat(backend: new EchoBackend(), tabHosted: true));

        [, $cmd] = $app->consumeShellCmd(new MenuSelectedMsg('Session', 'Close tab', 'cmd:close-tab'));

        self::assertNotNull($cmd, 'fixture: the deck-hosted close row answers with a CloseTabMsg Cmd');
        $produced = $cmd();
        self::assertInstanceOf(TabScopedMsg::class, $produced);
        self::assertSame('tab-1', $produced->tabKey);
        self::assertInstanceOf(CloseTabMsg::class, $produced->inner);
    }

    /**
     * P-TABS.e producer law, chord door: the same scoping applies to a
     * `command:<name>` chord binding ({@see App::runBoundCommand()}) —
     * typing parity includes deck parity. Deleting the wrap there reddens
     * exactly this pin.
     */
    public function testTheBoundChordCommandReturnCarriesTheActiveTabScope(): void
    {
        $overridesBefore = KeyBindingRegistry::overrides();
        $suppressedBefore = KeyBindingRegistry::suppressed();
        KeyBindingRegistry::useOverrides(['command:settings' => 'Ctrl+Z']);
        try {
            $app = $this->producerDeckApp(new Chat(backend: new EchoBackend(), tabHosted: true));

            [, $cmd] = $app->update(new KeyMsg(KeyType::Char, 'z', ctrl: true));

            self::assertNotNull($cmd, 'fixture: /settings without arguments answers with an OpenSettingsMsg Cmd');
            $produced = $cmd();
            self::assertInstanceOf(TabScopedMsg::class, $produced);
            self::assertSame('tab-1', $produced->tabKey);
            self::assertInstanceOf(OpenSettingsMsg::class, $produced->inner);
        } finally {
            KeyBindingRegistry::useOverrides(
                $overridesBefore + array_fill_keys($suppressedBefore, null),
            );
        }
    }

    /**
     * The fifth producer site — the message-picker accept path
     * (App::applyMessagePickerAction) — is unreachable with a non-null Cmd
     * at execution level today: the rewind it dispatches answers state-only,
     * so its wrap sees null and null passes through identically with or
     * without the wrap. This census is therefore that site's SOLE pin, and
     * belt-and-braces for the four sites the exec pins above cover: every
     * `scopeForegroundCmd(` call the shipped producer set has must stand at
     * its own return, in its own spelling.
     */
    public function testEveryProducerScopeForegroundCmdCallSiteStands(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/App/App.php');
        self::assertIsString($source);

        self::assertStringContainsString('private function scopeForegroundCmd', $source);
        self::assertSame(
            3,
            substr_count($source, '$app->scopeForegroundCmd($cmd)'),
            'runRegistryCommand, runBoundCommand and the delegateToChat transition leg each wrap their return',
        );
        self::assertSame(
            1,
            substr_count($source, '$this->scopeForegroundCmd($cmd)'),
            'the delegateToChat same-instance leg wraps its return',
        );
        self::assertSame(
            1,
            substr_count($source, '$app->scopeForegroundCmd($chatCmd)'),
            'applyMessagePickerAction wraps the rewind command it hands back',
        );
    }
}
