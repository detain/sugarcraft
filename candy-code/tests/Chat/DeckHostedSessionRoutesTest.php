<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\AgentControlMsg;
use SugarCraft\Code\App\Tabs\CloseTabMsg;
use SugarCraft\Code\App\Tabs\OpenTabMsg;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Events\SubAgentActivity;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;
use SugarCraft\Code\Session\SessionStore;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * P-TABS.f: with the App's tab deck armed the session routes that used to
 * swap sessions in place hand off to the deck instead.
 *
 * Four routes are walked under a running turn ({@see Chat::$tabHosted}): the
 * session picker's Enter-resume, the picker's fork, the Agent View's
 * "open as session", and the palette's New session row. Hosted, each closes
 * only its own overlay and returns a Cmd sending the App's OpenTabMsg — the
 * foreground chat's id, history and in-flight turn are untouched. Standalone
 * (the flag off) the same drives keep the historic in-place switch, and the
 * mid-turn refusal the deck relaxes is still refused with the same notice.
 * Close tab is the inverse: hosted it sends CloseTabMsg; standalone it
 * answers with a notice naming why there is no deck, never silently.
 *
 * @internal
 */
final class DeckHostedSessionRoutesTest extends TestCase
{
    private SessionStore $deckStore;

    protected function setUp(): void
    {
        // Two rows, created oldest first: the picker highlights 'sess-b'
        // (newest) on open while the foreground sits on 'sess-a'.
        $this->deckStore = new SessionStore(':memory:');
        $this->deckStore->createSession('sess-a', 'candy-code', 'test-model', null, 'Alpha');
        $this->deckStore->createSession('sess-b', 'candy-code', 'test-model', null, 'Beta');
    }

    /** A live turn started the way the user starts one — a draft plus Enter. */
    private function runningTurn(bool $hosted, bool $withStore = true): Chat
    {
        [$chat] = (new Chat(
            inputBuf: 'the first thing',
            backend: new EchoBackend(),
            tabHosted: $hosted,
            currentSessionId: $withStore ? 'sess-a' : null,
            sessionStore: $withStore ? $this->deckStore : null,
        ))->update(new KeyMsg(KeyType::Enter, ''));
        self::assertTrue($chat->inFlight, 'fixture: a turn must actually be in flight');

        return $chat;
    }

    /** @return list<Message> the transcript rows a route appended beyond the turn's own */
    private function appendedRows(Chat $before, Chat $after): array
    {
        return \array_slice($after->history, \count($before->history));
    }

    /** One seeded sub-agent run whose finished child lives in the store. */
    private function chatWithAgentChild(bool $hosted): Chat
    {
        $chat = $this->runningTurn($hosted);
        $chat->agentLive()->apply(
            (new SubAgentActivity(
                SubAgentActivity::OP_STARTED,
                'run-1',
                'explorer',
                'a task',
                1,
                '',
                parentCallId: 'call_1',
            ))->withChildSessionId('sess-b'),
        );

        return $chat;
    }

    // ── hosted: the four routes hand the deck a tab ──────────────────────

    public function testHostedPickerResumeMidTurnOpensATabAndTouchesNothingElse(): void
    {
        $chat = $this->runningTurn(hosted: true);
        [$open] = $chat->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        self::assertNotNull($open->sessionPicker(), 'fixture: Ctrl+R opens the picker mid-turn');

        [$after, $cmd] = $open->update(new KeyMsg(KeyType::Enter, ''));

        self::assertInstanceOf(\Closure::class, $cmd, 'deck-hosted, Enter is not refused — it is handed to the deck');
        $msg = $cmd();
        self::assertInstanceOf(OpenTabMsg::class, $msg);
        self::assertSame('sess-b', $msg->sessionId(), 'the highlighted row is the tab to open');
        self::assertSame('Beta', $msg->sessionName(), 'the stored name rides along');
        self::assertNull($after->sessionPicker(), 'the overlay closes');
        self::assertSame('sess-a', $after->currentSessionId(), 'the foreground session stays');
        self::assertSame([], $this->appendedRows($chat, $after), 'no notice row — the running turn is left exactly as it was');
        self::assertTrue($after->inFlight, 'mid-turn, the route no longer needs the turn to end');
    }

    public function testHostedPickerForkMidTurnOpensATabWithoutSwitchingHere(): void
    {
        $chat = $this->runningTurn(hosted: true);
        [$open] = $chat->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));

        [$after, $cmd] = $open->update(new KeyMsg(KeyType::Char, 'f'));

        self::assertInstanceOf(\Closure::class, $cmd);
        $msg = $cmd();
        self::assertInstanceOf(OpenTabMsg::class, $msg);
        self::assertNotNull($msg->sessionId(), 'the forked session is minted in the store first');
        self::assertNotSame('sess-a', $msg->sessionId(), 'a new id, not the foreground one');
        self::assertSame('Beta (branch)', $msg->sessionName());
        self::assertNull($after->sessionPicker(), 'the overlay closes');
        self::assertSame('sess-a', $after->currentSessionId(), 'this chat never adopts the fork');
        self::assertSame([], $this->appendedRows($chat, $after), 'no refusal notice mid-turn any more');
    }

    public function testHostedAgentViewOpenAsSessionSendsTheTabMessage(): void
    {
        $chat = $this->chatWithAgentChild(hosted: true);

        [$after, $cmd] = $chat->update(new AgentControlMsg(AgentControlMsg::OPEN_SESSION, ['run-1']));

        self::assertInstanceOf(\Closure::class, $cmd);
        $msg = $cmd();
        self::assertInstanceOf(OpenTabMsg::class, $msg);
        self::assertSame('sess-b', $msg->sessionId());
        self::assertSame('explorer (agent)', $msg->sessionName(), 'the announced tab name');
        self::assertSame('sess-a', $after->currentSessionId(), 'the running chat keeps its own session');
        self::assertSame([], $this->appendedRows($chat, $after), 'the turn_running problem is no longer raised');
    }

    public function testHostedPaletteNewSessionMidTurnHandsOffToTheDeck(): void
    {
        $chat = $this->runningTurn(hosted: true);

        [$after, $cmd] = $chat->runPaletteAction(Lang::t('cmd.new.label'));

        self::assertInstanceOf(\Closure::class, $cmd);
        $msg = $cmd();
        self::assertInstanceOf(OpenTabMsg::class, $msg);
        self::assertNull($msg->sessionId(), 'App mints the fresh tab, like the Ctrl+N route');
        self::assertSame('sess-a', $after->currentSessionId(), 'no in-place session swap in this chat');
        self::assertSame([], $this->appendedRows($chat, $after), 'no created-session row appended here');
    }

    public function testHostedPaletteNewSessionAtIdleHandsOffToTheDeck(): void
    {
        $chat = (new Chat(
            history: [Message::user('done')],
            backend: new EchoBackend(),
            sessionStore: $this->deckStore,
            currentSessionId: 'sess-a',
            tabHosted: true,
        ))->withSize(100, 30);
        $before = $chat;

        [$after, $cmd] = $chat->runPaletteAction(Lang::t('cmd.new.label'));

        self::assertInstanceOf(\Closure::class, $cmd);
        self::assertInstanceOf(OpenTabMsg::class, $cmd());
        self::assertSame('sess-a', $after->currentSessionId());
        self::assertSame([], $this->appendedRows($before, $after), 'the bespoke createSession block is the standalone route only');
    }

    // ── hosted: Close tab sends the close ────────────────────────────────

    public function testCloseTabHostedSendsTheCloseMessage(): void
    {
        $chat = (new Chat(
            history: [Message::user('done')],
            backend: new EchoBackend(),
            tabHosted: true,
        ))->withSize(100, 30);

        [$after, $cmd] = $chat->runPaletteAction(Lang::t('cmd.close-tab.label'));

        self::assertInstanceOf(\Closure::class, $cmd);
        $msg = $cmd();
        self::assertInstanceOf(CloseTabMsg::class, $msg);
        self::assertNull($msg->tabKey(), 'the foreground tab is App-choosen');
        self::assertNull($after->palette(), 'the palette route closes its own overlay');
        self::assertSame([], $this->appendedRows($chat, $after), 'App does the closing, no notice row here');
    }

    // ── standalone: the historic behaviour is byte-for-byte the old one ──

    public function testStandalonePickerResumeMidTurnKeepsTheHistoricRefusal(): void
    {
        $chat = $this->runningTurn(hosted: false);
        [$open] = $chat->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $before = \count($open->history);

        [$after, $cmd] = $open->update(new KeyMsg(KeyType::Enter, ''));

        self::assertNull($cmd, 'no deck, so the refusal is still a refusal');
        self::assertSame('sess-a', $after->currentSessionId());
        self::assertNull($after->sessionPicker(), 'the overlay closes so the notice is visible');
        self::assertCount($before + 1, $after->history);
        self::assertStringContainsString('Resume session', $after->history[$before]->content);
    }

    public function testStandalonePickerForkMidTurnKeepsTheHistoricRefusal(): void
    {
        $chat = $this->runningTurn(hosted: false);
        [$open] = $chat->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $before = \count($open->history);

        [$after, $cmd] = $open->update(new KeyMsg(KeyType::Char, 'f'));

        self::assertNull($cmd, 'no deck, so the mid-turn fork is still refused');
        self::assertSame('sess-a', $after->currentSessionId(), 'the fork waits out the turn');
        self::assertCount($before + 1, $after->history);
        self::assertStringContainsString('Fork session', $after->history[$before]->content);
    }

    public function testStandalonePickerForkAtIdleStillSwitchesInPlace(): void
    {
        $chat = (new Chat(
            history: [Message::user('done')],
            backend: new EchoBackend(),
            sessionStore: $this->deckStore,
            currentSessionId: 'sess-a',
        ))->withSize(100, 30);
        [$open] = $chat->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));

        [$after, $cmd] = $open->update(new KeyMsg(KeyType::Char, 'f'));

        self::assertNull($cmd, 'standalone produces no deck message');
        $forked = $after->currentSessionId();
        self::assertNotNull($forked);
        self::assertNotSame('sess-a', $forked, 'the historic in-place adoption');
        self::assertNotSame('sess-b', $forked, 'a fresh id, not the forked-from row');
        self::assertSame('Beta (branch)', $after->currentSessionName());
        self::assertNull($after->sessionPicker());
    }

    public function testStandaloneAgentViewOpenMidTurnKeepsTheRunningTurnProblem(): void
    {
        $chat = $this->chatWithAgentChild(hosted: false);

        [$after, $cmd] = $chat->update(new AgentControlMsg(AgentControlMsg::OPEN_SESSION, ['run-1']));

        self::assertNull($cmd);
        self::assertSame('sess-a', $after->currentSessionId());
        $rows = $this->appendedRows($chat, $after);
        self::assertCount(1, $rows);
        self::assertStringContainsString('turn is running', $rows[0]->content, 'the historic problem row');
    }

    public function testStandaloneCloseTabSaysThereIsNoDeck(): void
    {
        $chat = (new Chat(
            history: [Message::user('done')],
            backend: new EchoBackend(),
        ))->withSize(100, 30);

        [$after, $cmd] = $chat->runPaletteAction(Lang::t('cmd.close-tab.label'));

        self::assertNull($cmd);
        $rows = $this->appendedRows($chat, $after);
        self::assertCount(1, $rows, 'loud feedback, never a silent no-op');
        self::assertStringContainsString('multi-tab shell', $rows[0]->content);
    }

    public function testStandaloneCloseTabMidTurnIsRefusedThroughTheSameNotice(): void
    {
        $chat = $this->runningTurn(hosted: false);

        [$after, $cmd] = $chat->runPaletteAction(Lang::t('cmd.close-tab.label'));

        self::assertNull($cmd);
        $rows = $this->appendedRows($chat, $after);
        self::assertCount(1, $rows);
        self::assertStringContainsString('multi-tab shell', $rows[0]->content);
    }

    // ── plumbing ──────────────────────────────────────────────────────────

    public function testTheFlagIsOffByDefaultAndIdentityPreservingWhenUnchanged(): void
    {
        $plain = new Chat(history: [Message::user('hi')], backend: new EchoBackend());

        self::assertFalse($plain->isTabHosted());
        self::assertSame($plain, $plain->withTabHosted(false), 'factory-less launches stay byte-stable');

        $hosted = $plain->withTabHosted(true);
        self::assertTrue($hosted->isTabHosted());
        self::assertNotSame($plain, $hosted);
        self::assertSame($hosted, $hosted->withTabHosted(true));
    }

    public function testPresentSessionStandaloneWithoutAnIdFailsClosed(): void
    {
        // Only reachable if a caller ever hands the standalone route a null id;
        // the palette branches before this, so the pin is the method itself.
        $chat = new Chat(history: [Message::user('hi')], backend: new EchoBackend());

        [$after, $cmd] = (new \ReflectionMethod(Chat::class, 'presentSession'))->invoke($chat, null, null);

        self::assertSame($chat, $after);
        self::assertNull($cmd);
    }
}
