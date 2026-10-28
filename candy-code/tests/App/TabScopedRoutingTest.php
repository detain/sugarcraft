<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\App;
use SugarCraft\Code\App\AgentMessageSentMsg;
use SugarCraft\Code\App\Tabs\OpenTabMsg;
use SugarCraft\Code\App\Tabs\SessionDeck;
use SugarCraft\Code\App\Tabs\TabScopedMsg;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Providers\ProviderInterface;
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
}
