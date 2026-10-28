<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\App;
use SugarCraft\Code\App\AssistantMsg;
use SugarCraft\Code\App\Tabs\SessionDeck;
use SugarCraft\Code\App\Tabs\TabScopedMsg;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Diagnostics\NoticeSink;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Role;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use ReflectionClass;

/**
 * P-TABS.e background streaming: a turn started in tab A keeps running while
 * the user works in tab B — Ctrl+N and Ctrl+Tab are never refused because a
 * tab is busy, A's reply lands in A's history alone, A advertises itself
 * UNSEEN, and A's event pump stays declared while it is parked.
 */
final class BackgroundTabStreamingTest extends TestCase
{
    private ProviderInterface $provider;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ProviderInterface::class);
    }

    private function streamingFreshChat(): Chat
    {
        return new Chat(backend: new EchoBackend());
    }

    public function testCtrlNOpensATabWhileAStreamIsInFlightAndTheStreamSurvives(): void
    {
        $busy = new Chat(backend: new EchoBackend(), inFlight: true, generation: 1);
        $app = App::new($this->provider, 'test-model')
            ->withChat($busy)
            ->withTabFactory(fn (?string $sessionId): Chat => $this->streamingFreshChat());
        [$armed] = $app->update(new WindowSizeMsg(100, 30));

        [$after, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'n', ctrl: true));

        self::assertSame(2, $after->deck?->count());
        self::assertSame('tab-2', $after->deck?->activeKey());
        self::assertInstanceOf(Chat::class, $after->chat);
        self::assertFalse($after->chat->inFlight);
        $parked = $after->deck?->model('tab-1');
        self::assertInstanceOf(Chat::class, $parked);
        self::assertTrue($parked->inFlight, 'parking must not stop the turn');
        // The parked model is the SAME Chat content-wise: parking only
        // releases the notice-drain appointment (withNoticeDrain(false)),
        // the turn, history and generation ride along untouched.
        self::assertSame($busy->history, $parked->history);
    }

    public function testParkedTabKeepsItsEventPumpAndDropsTheForegroundOnlyPolls(): void
    {
        $busy = new Chat(backend: new EchoBackend(), inFlight: true, generation: 1);
        // The fresh tab-2 gets its own session inbox holding one row: the
        // notice poll arms on a PENDING row (Chat::subscriptions()), so this
        // is what makes the raw fg-held id observable below without leaning
        // on the process-global sink's ambient state.
        $inbox = NoticeSink::new();
        $inbox->arm(crossFork: false);
        self::assertTrue($inbox->record('a duplicate parameter was dropped'));
        $app = App::new($this->provider, 'test-model')
            ->withChat($busy)
            ->withTabFactory(fn (?string $sessionId): Chat => new Chat(
                backend: new EchoBackend(),
                workspace: WorkspaceContext::new(notices: $inbox),
            ));
        [$armed] = $app->update(new WindowSizeMsg(100, 30));
        [$after] = $armed->update(new KeyMsg(KeyType::Char, 'n', ctrl: true));

        self::assertSame('tab-2', $after->deck?->activeKey());
        $subscriptions = $after->subscriptions();
        self::assertNotNull($subscriptions);
        self::assertTrue($subscriptions->has('tab:tab-1:code.tool-event-poll'));
        self::assertFalse($subscriptions->has('tab:tab-1:code.background-poll'));
        self::assertFalse($subscriptions->has('tab:tab-1:code.status-line'));
        // The drain appointment moved with park(): the parked tab must
        // NOT declare the notice poll (a second drainer would race the
        // foreground for the shared queue), while the foreground's raw id
        // is present in the same merged list.
        self::assertFalse($subscriptions->has('tab:tab-1:code.runtime-notice-poll'));
        self::assertTrue($subscriptions->has('code.runtime-notice-poll'));
        // The suppression roster is the load-bearing half: TabScope only
        // drops ids THIS list names, so the list's content is pinned here.
        $listed = (new ReflectionClass(App::class))->getConstant('FOREGROUND_ONLY_TAB_SUBSCRIPTIONS');
        self::assertIsArray($listed);
        self::assertSame(['code.background-poll', 'code.schedule-watch', 'code.status-line'], $listed);
    }

    public function testSettledReplyLandsInTheParkedHistoryAndRaisesUnseen(): void
    {
        $busy = new Chat(backend: new EchoBackend(), inFlight: true, generation: 1);
        $app = App::new($this->provider, 'test-model')
            ->withChat($busy)
            ->withTabFactory(fn (?string $sessionId): Chat => $this->streamingFreshChat());
        [$armed] = $app->update(new WindowSizeMsg(100, 30));
        [$onB] = $armed->update(new KeyMsg(KeyType::Char, 'n', ctrl: true));
        $foregroundB = $onB->chat;

        [$settled] = $onB->update(new TabScopedMsg('tab-1', new AssistantMsg(Message::assistant('the settled reply'), 1)));

        $parked = $settled->deck?->model('tab-1');
        self::assertInstanceOf(Chat::class, $parked);
        self::assertFalse($parked->inFlight, 'the settle must close the turn inside A');
        $carried = null;
        foreach ($parked->history as $entry) {
            if ($entry->role === Role::Assistant && \str_contains($entry->content, 'the settled reply')) {
                $carried = $entry;
            }
        }
        self::assertNotNull($carried, 'A history must hold the reply');
        self::assertSame(SessionDeck::ATTENTION_UNSEEN, self::streamTabAttention($settled, 'tab-1'));
        self::assertSame($foregroundB, $settled->chat, 'B must not absorb the reply belonging to A');
        self::assertSame(100, $settled->cols);
    }

    public function testCtrlTabReturnsToTheFinishedTabWithoutRefusal(): void
    {
        $busy = new Chat(backend: new EchoBackend(), inFlight: true, generation: 1);
        $app = App::new($this->provider, 'test-model')
            ->withChat($busy)
            ->withTabFactory(fn (?string $sessionId): Chat => $this->streamingFreshChat());
        [$armed] = $app->update(new WindowSizeMsg(100, 30));
        [$onB] = $armed->update(new KeyMsg(KeyType::Char, 'n', ctrl: true));
        [$settled] = $onB->update(new TabScopedMsg('tab-1', new AssistantMsg(Message::assistant('the settled reply'), 1)));

        [$back] = $settled->update(new KeyMsg(KeyType::Tab, ctrl: true));

        self::assertSame('tab-1', $back->deck?->activeKey());
        $restored = $back->chat;
        self::assertInstanceOf(Chat::class, $restored);
        self::assertFalse($restored->inFlight);
        $found = false;
        foreach ($restored->history as $entry) {
            $found = $found || ($entry->role === Role::Assistant && \str_contains($entry->content, 'settled reply'));
        }
        self::assertTrue($found);
        // Coming back to a settled tab clears its unseen flag to idle.
        self::assertSame(SessionDeck::ATTENTION_IDLE, self::streamTabAttention($back, 'tab-1'));
    }

    private static function streamTabAttention(App $app, string $tabKey): string
    {
        self::assertNotNull($app->deck);
        foreach ($app->deck->tabs()->tabs() as $tab) {
            if ($tab->id === $tabKey) {
                return $tab->attention();
            }
        }

        self::fail("deck has no tab {$tabKey}");
    }
}
