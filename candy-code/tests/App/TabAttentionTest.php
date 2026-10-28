<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\RawMsg;
use SugarCraft\Code\App\App;
use SugarCraft\Code\App\AssistantMsg;
use SugarCraft\Code\App\Tabs\CloseTabMsg;
use SugarCraft\Code\App\Tabs\OpenTabMsg;
use SugarCraft\Code\App\Tabs\TabScopedMsg;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Tui\Settings\OpenSettingsMsg;
use SugarCraft\Code\Tui\Window\WindowAttention;
use SugarCraft\Code\Tui\Window\WindowList;

/**
 * P-TABS.g (GAP-SES-12): a background tab that starts waiting for an answer
 * or finishes unseen is announced as a desktop notification through the
 * shared `notify` setting — once per transition, never while it is the
 * focused window, and only when notifications are actually armed. The
 * booking ledger rides the App (`tabNotified`), so re-enabling `notify`
 * later tells about still-pending states and never about expired ones.
 */
final class TabAttentionTest extends TestCase
{
    private ProviderInterface $attentionProvider;

    protected function setUp(): void
    {
        $this->attentionProvider = $this->createMock(ProviderInterface::class);
    }

    /**
     * A two-tab deck with the foreground back on tab-1 (tab-2 parked), the
     * chats carrying a `notify: $mode` workspace config when $mode is armed.
     */
    private function attentionApp(?string $mode): App
    {
        $chat = static fn (): Chat => new Chat(
            backend: new EchoBackend(),
            workspace: WorkspaceContext::new(root: sys_get_temp_dir(), userConfig: $mode === null ? [] : ['notify' => $mode]),
        );
        [$armed] = App::new($this->attentionProvider, 'test-model')
            ->withChat($chat())
            ->withTabFactory(static fn (?string $sessionId): Chat => $chat())
            ->update(new WindowSizeMsg(100, 30));
        [$opened] = $armed->update(new OpenTabMsg());
        [$back] = $opened->update(new KeyMsg(KeyType::Tab, ctrl: true));

        return $back;
    }

    /** @return list<string> every byte string the Cmd writes, batches opened */
    private static function notificationLines(?\Closure $cmd): array
    {
        $queue = $cmd === null ? [] : [$cmd];
        $bytes = [];
        while ($queue !== []) {
            $msg = array_shift($queue)();
            if ($msg instanceof BatchMsg) {
                foreach ($msg->cmds as $inner) {
                    $queue[] = $inner;
                }

                continue;
            }

            if ($msg instanceof RawMsg) {
                $bytes[] = $msg->bytes;
            }
        }

        return $bytes;
    }

    private static function tabAttention(App $app, string $key): ?WindowAttention
    {
        $found = self::windowRows($app)->find($key);

        return $found?->attention;
    }

    private static function windowRows(App $app): WindowList
    {
        $list = $app->windowList();
        self::assertNotNull($list, 'fixture: the deck is armed');

        return $list;
    }

    public function testABackgroundTabStartingToWaitBooksOneNoticeWhenNotifyIsArmed(): void
    {
        [$app, $cmd] = $this->attentionApp('osc9')
            ->update(new TabScopedMsg('tab-2', new OpenSettingsMsg()));

        self::assertSame(WindowAttention::Waiting, self::tabAttention($app, 'tab-2'), 'fixture: the parked tab waits');
        self::assertSame(['tab-2' => 'waiting'], $app->tabNotified, 'the transition is booked');

        $announced = array_values(array_filter(
            self::notificationLines($cmd),
            static fn (string $bytes): bool => str_starts_with($bytes, "\x1b]9;"),
        ));
        self::assertCount(1, $announced, 'exactly one desktop notice, none for the focused tab');
        self::assertStringContainsString('is waiting for an answer', $announced[0]);
    }

    public function testTheSameStateIsNeverAnnouncedTwice(): void
    {
        $app = $this->attentionApp('osc9');
        [$first] = $app->update(new TabScopedMsg('tab-2', new OpenSettingsMsg()));
        self::assertSame(['tab-2' => 'waiting'], $first->tabNotified, 'fixture: the first press announced');

        [$again, $cmd] = $first->update(new TabScopedMsg('tab-2', new OpenSettingsMsg()));

        self::assertNull($cmd, 'a second route that changes nothing writes nothing — not even the title');
        self::assertSame(['tab-2' => 'waiting'], $again->tabNotified);
    }

    public function testAFinishedTurnIsAnnouncedAsUnseenOnce(): void
    {
        [$app, $cmd] = $this->attentionApp('bell')
            ->update(new TabScopedMsg('tab-2', new AssistantMsg(Message::assistant('a quiet reply'), 1)));

        self::assertSame(WindowAttention::Unseen, self::tabAttention($app, 'tab-2'));
        self::assertSame(['tab-2' => 'unseen'], $app->tabNotified);
        self::assertContains("\x07", self::notificationLines($cmd), 'the bell mode rings once for the finish');
    }

    public function testNotificationsOffAnnounceNothingButBookNothingEither(): void
    {
        [$app, $cmd] = $this->attentionApp(null)
            ->update(new TabScopedMsg('tab-2', new OpenSettingsMsg()));

        self::assertSame([], $app->tabNotified, 'an un-armed notifier books nothing, so arming it later still tells');

        $announced = array_values(array_filter(
            self::notificationLines($cmd),
            static fn (string $bytes): bool => str_starts_with($bytes, "\x1b]9;") || $bytes === "\x07",
        ));
        self::assertSame([], $announced, 'no notice bytes while notify is off');
    }

    public function testAClosedTabLeavesNoStaleBookingBehind(): void
    {
        [$waiting] = $this->attentionApp('osc9')
            ->update(new TabScopedMsg('tab-2', new OpenSettingsMsg()));
        self::assertSame(['tab-2' => 'waiting'], $waiting->tabNotified, 'fixture: tab-2 booked');

        [$app, $cmd] = $waiting->update(new CloseTabMsg('tab-2'));

        self::assertSame(1, $app->deck?->count(), 'fixture: the parked tab closed');
        self::assertNull(self::windowRows($app)->find('tab-2'));
        $retitled = array_values(array_filter(
            self::notificationLines($cmd),
            static fn (string $bytes): bool => str_starts_with($bytes, "\x1b]9;"),
        ));
        self::assertSame([], $retitled, 'closing a waiting tab retitles the window but never announces');

        // One more wrapped route so the ledger is re-derived: the closed
        // key must be pruned, not carried as a booking for a window that
        // no longer exists (its re-opened namesake must be able to announce).
        [$pruned] = $app->update(new TabScopedMsg('tab-1', new OpenSettingsMsg()));
        self::assertArrayNotHasKey('tab-2', $pruned->tabNotified);
    }

    public function testTheFocusedTabIsNeverAnnouncedEvenWhenItWaits(): void
    {
        // tab-2 is the foreground; a route that arms ITS close does not
        // touch attention, and a permission wait on the FOREGROUND rides
        // the shell's own prompt — the window notice is for tab-away states.
        $app = $this->attentionApp('osc9');
        [$onTab2] = $app->update(new KeyMsg(KeyType::Tab, ctrl: true));
        self::assertSame('tab-2', $onTab2->deck?->activeKey(), 'fixture: tab-2 is the focused window');

        [$next, $cmd] = $onTab2->update(new TabScopedMsg('tab-1', new OpenSettingsMsg()));
        // tab-1 was parked by the first Ctrl+Tab; waiting there is a
        // background state and must announce — and the focused tab-2 never
        // enters the booked set whatever its own state.
        self::assertSame(WindowAttention::Waiting, self::tabAttention($next, 'tab-1'));
        self::assertSame(['tab-1' => 'waiting'], $next->tabNotified);
        self::assertArrayNotHasKey('tab-2', $next->tabNotified, 'the focused window is never in the booked set');
        self::assertNotNull($cmd);
    }
}
