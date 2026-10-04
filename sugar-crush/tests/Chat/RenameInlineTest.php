<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Host\TitleService;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionStore;
use SugarCraft\Crush\Session\TitleSource;
use SugarCraft\Crush\SessionTitledMsg;
use SugarCraft\Crush\Usage;

/**
 * Roadmap P-A4: `/rename` with no argument opens an inline title row, an empty
 * save (and `/rename --auto`) hands the name back to the title model, the
 * palette and a tab double-click reach the same editor, and the `TitleSource`
 * latch keeps a user's title ahead of any generated one.
 */
final class RenameInlineTest extends TestCase
{
    private SessionStore $store;

    protected function setUp(): void
    {
        $this->store = new SessionStore(':memory:');
        $this->store->createSession('s1', 'p', 'm');
    }

    // ── the inline editor ────────────────────────────────────────────────

    public function testABareRenameOpensTheEditorPrefilledWithTheCurrentName(): void
    {
        $next = $this->submit($this->chat(name: 'Old title'), '/rename');

        self::assertNotNull($next->titleEditor());
        self::assertSame('Old title', $next->titleEditor()->value);
        self::assertSame([], $next->history, 'opening the editor writes nothing to the transcript');
    }

    public function testTypingAndEnterSavesAUserTitleInTheStoreAndTheUi(): void
    {
        $open = $this->submit($this->chat(), '/rename');
        $typed = $this->type($open, 'Auth refactor');
        [$saved, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        self::assertNull($cmd);
        self::assertNull($saved->titleEditor(), 'Enter closes the editor');
        self::assertSame('Auth refactor', $saved->currentSessionName());
        self::assertSame(TitleSource::User, $saved->currentSessionTitleSource());
        $row = $this->store->getSession('s1');
        self::assertSame('Auth refactor', $row['name']);
        self::assertSame('user', $row['title_source']);
        $last = $saved->history[count($saved->history) - 1];
        self::assertTrue($last->uiOnly, 'the confirmation never reaches the model');
        self::assertStringContainsString("Session renamed to 'Auth refactor'", $last->content);
    }

    public function testEscapeClosesTheEditorAndWritesNothing(): void
    {
        $open = $this->type($this->submit($this->chat(name: 'Keep me'), '/rename'), 'xyz');
        [$closed, $cmd] = $open->update(new KeyMsg(KeyType::Escape));

        self::assertNull($cmd);
        self::assertNull($closed->titleEditor());
        self::assertSame('Keep me', $closed->currentSessionName());
        self::assertNull($this->store->getSession('s1')['name']);
        self::assertSame([], $closed->history);
    }

    public function testTheEditorOwnsTheKeyboardSoTheDraftIsUntouched(): void
    {
        $open = $this->submit($this->chat(), '/rename');
        $typed = $this->type($open, 'hello');

        self::assertSame('', $typed->inputBuf, 'keys typed into the title never reach the input box');
        self::assertSame('hello', $typed->titleEditor()?->value);
    }

    public function testSavingItEmptyHandsTheNameBackToTheTitleModel(): void
    {
        $this->store->renameSession('s1', 'Mine', TitleSource::User);
        $chat = $this->chat(name: 'Mine', source: TitleSource::User, history: [Message::user('fix the login bug'), Message::assistant('done')]);
        $open = $this->submit($chat, '/rename');
        $cleared = $open;
        foreach (range(1, 4) as $_) {
            [$cleared] = $cleared->update(new KeyMsg(KeyType::Backspace));
        }
        [$saved, $cmd] = $cleared->update(new KeyMsg(KeyType::Enter));

        self::assertNull($saved->currentSessionName(), 'the name is cleared, not set to a blank user title');
        self::assertNull($saved->currentSessionTitleSource());
        self::assertNull($this->store->getSession('s1')['name']);
        self::assertNull($this->store->getSession('s1')['title_source']);
        self::assertNotNull($cmd, 'the title model is asked again');

        $titled = $this->resolve($cmd);
        self::assertInstanceOf(SessionTitledMsg::class, $titled);
        self::assertSame('Login Bug Fix', $titled->title);
        [$after] = $saved->update($titled);
        self::assertSame('Login Bug Fix', $after->currentSessionName());
        self::assertSame(TitleSource::Auto, $after->currentSessionTitleSource());
        self::assertSame('auto', $this->store->getSession('s1')['title_source']);
    }

    // ── /rename --auto ───────────────────────────────────────────────────

    public function testRenameAutoClearsAUserTitleAndRegenerates(): void
    {
        $this->store->renameSession('s1', 'Mine', TitleSource::User);
        $chat = $this->chat(name: 'Mine', source: TitleSource::User, history: [Message::user('fix the login bug')], buf: '/rename --auto');
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter));

        self::assertNotNull($cmd);
        self::assertNull($next->currentSessionName());
        self::assertStringContainsString('Asking the title model', $next->history[count($next->history) - 1]->content);

        [$after] = $next->update($this->resolve($cmd));
        self::assertSame('Login Bug Fix', $after->currentSessionName());
        self::assertSame('Login Bug Fix', $this->store->getSession('s1')['name']);
    }

    public function testRenameAutoWithNoTitleModelLeavesTheNameAlone(): void
    {
        $this->store->renameSession('s1', 'Mine', TitleSource::User);
        $chat = $this->chat(name: 'Mine', history: [Message::user('hi')], buf: '/rename --auto', titled: false);
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter));

        self::assertNull($cmd);
        self::assertSame('Mine', $next->currentSessionName());
        self::assertSame('Mine', $this->store->getSession('s1')['name']);
        self::assertStringContainsString('No title model is configured', $next->history[count($next->history) - 1]->content);
    }

    public function testRenameAutoBeforeAnyTurnClearsAndLeavesItToTheFirstReply(): void
    {
        $this->store->renameSession('s1', 'Mine', TitleSource::User);
        [$next, $cmd] = $this->chat(name: 'Mine', buf: '/rename --auto')->update(new KeyMsg(KeyType::Enter));

        self::assertNull($cmd);
        self::assertNull($next->currentSessionName());
        self::assertNull($this->store->getSession('s1')['name']);
        self::assertStringContainsString('the first reply will name it', $next->history[count($next->history) - 1]->content);
    }

    // ── the latch ────────────────────────────────────────────────────────

    public function testAUserTitleTypedWhileTheRegenerationIsInFlightWins(): void
    {
        $chat = $this->chat(history: [Message::user('fix the login bug')], buf: '/rename --auto');
        [$regenerating, $cmd] = $chat->update(new KeyMsg(KeyType::Enter));
        self::assertNotNull($cmd);

        // The user names it before the title lands.
        $named = $this->submit($regenerating, '/rename Mine');
        $titled = $this->resolve($cmd);

        self::assertSame('', $titled->title, 'the store refused the generated title');
        [$after] = $named->update($titled);
        self::assertSame('Mine', $after->currentSessionName());
        self::assertSame(TitleSource::User, $after->currentSessionTitleSource());
        self::assertSame('Mine', $this->store->getSession('s1')['name']);
    }

    public function testAUserSourceIsNeverReplacedByAGeneratedTitle(): void
    {
        $chat = $this->chat(source: TitleSource::User);
        [$after] = $chat->update(new SessionTitledMsg('s1', 'Generated', Usage::new(5, 0.001)));

        self::assertNull($after->currentSessionName(), 'a USER-sourced session is never auto-titled');
    }

    public function testChangingSessionDropsTheSourceAndAnOpenEditor(): void
    {
        $this->store->createSession('s2', 'p', 'm');
        $open = $this->submit($this->chat(name: 'Mine', source: TitleSource::User), '/rename');
        self::assertNotNull($open->titleEditor());

        $moved = $open->withCurrentSessionId('s2');

        self::assertNull($moved->titleEditor(), 'a draft for one session can never rename another');
        self::assertNull($moved->currentSessionTitleSource());
    }

    // ── store ────────────────────────────────────────────────────────────

    public function testClearSessionNameMakesTheRowAutoTitleableAgain(): void
    {
        foreach ([$this->store, $this->enhanced()] as $store) {
            $store->renameSession('s1', 'Mine', TitleSource::User);
            self::assertFalse($store->renameSessionIfUnnamed('s1', 'Generated'), 'a user name blocks the titler');

            self::assertTrue($store->clearSessionName('s1'));
            self::assertNull($store->getSession('s1')['name']);
            self::assertNull($store->getSession('s1')['title_source']);
            self::assertTrue($store->renameSessionIfUnnamed('s1', 'Generated'), 'cleared, the titler may name it again');
            self::assertFalse($store->clearSessionName('nope'), 'a missing row is reported');
        }
    }

    public function testRegenerateCallNeedsAUserTurnAStoreAndATitleModel(): void
    {
        $service = TitleService::new();
        $backend = $this->titleBackend();
        $said = [Message::user('hi')];

        self::assertNotNull($service->regenerateCall($backend, $this->store, 's1', $said));
        self::assertNotNull($service->regenerateCall($backend, $this->store, 's1', [...$said, Message::assistant('a'), Message::user('b')]), 'no first-turn gate');
        self::assertNull($service->regenerateCall($backend, $this->store, 's1', [Message::user('/help')->withUiOnly()]), 'UI-only rows are not a turn');
        self::assertNull($service->regenerateCall(null, $this->store, 's1', $said));
        self::assertNull($service->regenerateCall($backend, null, 's1', $said));
        self::assertNull($service->regenerateCall($backend, $this->store, null, $said));
    }

    // ── palette ──────────────────────────────────────────────────────────

    public function testThePaletteRenameRowOpensTheEditor(): void
    {
        [$next] = $this->palette($this->chat(name: 'Old'), 'Rename session…');

        self::assertSame('Old', $next->titleEditor()?->value);
    }

    public function testThePalettePinRowTogglesThePin(): void
    {
        [$pinned] = $this->palette($this->chat(), 'Pin or unpin session');
        self::assertSame(1, (int) $this->store->getSession('s1')['pinned']);
        self::assertStringContainsString('Pinned this session', $pinned->history[count($pinned->history) - 1]->content);

        [$unpinned] = $this->palette($pinned, 'Pin or unpin session');
        self::assertSame(0, (int) $this->store->getSession('s1')['pinned']);
        self::assertStringContainsString('Unpinned', $unpinned->history[count($unpinned->history) - 1]->content);
    }

    public function testThePaletteDeleteRowOpensTheListAndSaysHowRatherThanDeleting(): void
    {
        $this->store->createSession('s2', 'p', 'm');
        [$next] = $this->palette($this->chat(), 'Delete session…');

        self::assertNotNull($next->sessionPicker());
        self::assertNotNull($this->store->getSession('s2'), 'nothing is deleted blind');
        self::assertStringContainsString('press d twice', (string) $next->sessionPicker()->notice());
    }

    public function testThePaletteBranchRowForks(): void
    {
        [$next] = $this->palette($this->chat(history: [Message::user('hi'), Message::assistant('yo')]), 'Branch session');

        self::assertNotSame('s1', $next->currentSessionId(), 'the branch is the session on screen now');
    }

    // ── tab double-click ─────────────────────────────────────────────────

    public function testADoubleClickOnTheCurrentTabOpensTheEditor(): void
    {
        $chat = $this->chat(name: 'Tabbed');
        [$once] = $this->clickTab($chat, 's1');
        self::assertNull($once->titleEditor(), 'one click names no rename');

        [$twice] = $this->clickTab($once, 's1');
        self::assertSame('Tabbed', $twice->titleEditor()?->value);
    }

    public function testTwoClicksFurtherApartThanTheWindowAreNotADoubleClick(): void
    {
        $stale = new Chat(
            backend: new EchoBackend(),
            sessionStore: $this->store,
            currentSessionId: 's1',
            lastTabClick: ['s1', microtime(true) - Chat::TAB_DOUBLE_CLICK_SECONDS - 1.0],
        );

        [$next] = $this->clickTab($stale, 's1');

        self::assertNull($next->titleEditor());
    }

    public function testADoubleClickInAReadOnlyWindowIsRefusedLikeRename(): void
    {
        $chat = new Chat(
            backend: new EchoBackend(),
            sessionStore: $this->store,
            currentSessionId: 's1',
            readOnlySession: true,
        );
        [$once] = $this->clickTab($chat, 's1');
        [$twice] = $this->clickTab($once, 's1');

        self::assertNull($twice->titleEditor());
    }

    // ── renderer ─────────────────────────────────────────────────────────

    public function testTheEditorRowSitsAboveTheInputBoxInsideTheTerminalWidth(): void
    {
        foreach ([80, 30, 12] as $cols) {
            $open = $this->type($this->submit($this->chat()->withSize($cols, 20), '/rename'), str_repeat('long title ', 8));
            $frame = Renderer::render($open);
            $lines = explode("\n", $frame);
            $row = null;
            foreach ($lines as $i => $line) {
                if (str_contains(self::plain($line), '✎')) {
                    $row = $i;
                }
                self::assertLessThanOrEqual($cols, Width::string(self::plain($line)), "line {$i} at {$cols} cols overflows");
            }
            self::assertNotNull($row, "the title row is painted at {$cols} cols");
            self::assertStringStartsWith('┌', self::plain($lines[$row + 1]), 'the input box is directly below it');
        }
    }

    public function testTheTabStripMirrorsTheDraftAndLeadsWithPinnedSessions(): void
    {
        $this->store->createSession('s2', 'p', 'm');
        $this->store->renameSession('s2', 'Pinned one');
        $this->store->setPinned('s2', true);
        $open = $this->type($this->submit($this->chat()->withSize(100, 20), '/rename'), 'Draft');

        $strip = self::plain(explode("\n", Renderer::render($open))[0]);

        self::assertStringContainsString('★ Pinned one', $strip);
        self::assertStringContainsString('[✎ Draft]', $strip);
        self::assertLessThan(strpos($strip, '✎ Draft'), strpos($strip, '★ Pinned one'), 'pinned sessions lead the strip');
    }

    // ── harness ──────────────────────────────────────────────────────────

    /** @param list<Message> $history */
    private function chat(
        ?string $name = null,
        ?TitleSource $source = null,
        array $history = [],
        string $buf = '',
        bool $titled = true,
    ): Chat {
        return new Chat(
            history: $history,
            inputBuf: $buf,
            backend: new EchoBackend(),
            sessionStore: $this->store,
            currentSessionId: 's1',
            currentSessionName: $name,
            titleBackend: $titled ? $this->titleBackend() : null,
            currentSessionTitleSource: $source,
        );
    }

    private function submit(Chat $chat, string $draft): Chat
    {
        foreach (mb_str_split($draft) as $char) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $char));
        }
        [$next] = $chat->update(new KeyMsg(KeyType::Enter));

        return $next;
    }

    private function type(Chat $chat, string $text): Chat
    {
        foreach (mb_str_split($text) as $char) {
            [$chat] = $chat->update(new KeyMsg($char === ' ' ? KeyType::Space : KeyType::Char, $char));
        }

        return $chat;
    }

    /** @return array{0: Chat, 1: ?\Closure} */
    private function palette(Chat $chat, string $label): array
    {
        return (new \ReflectionMethod(Chat::class, 'runRootPaletteAction'))->invoke($chat, $label);
    }

    /** @return array{0: Chat, 1: ?\Closure} */
    private function clickTab(Chat $chat, string $id): array
    {
        return (new \ReflectionMethod(Chat::class, 'selectSessionTab'))->invoke($chat, $id);
    }

    private function resolve(\Closure $cmd): mixed
    {
        $async = $cmd();
        self::assertInstanceOf(AsyncCmd::class, $async);
        $resolved = null;
        $async->promise->then(static function (mixed $msg) use (&$resolved): void {
            $resolved = $msg;
        });

        return $resolved;
    }

    private function enhanced(): EnhancedSessionStore
    {
        $store = new EnhancedSessionStore(':memory:');
        $store->createSession('s1', 'p', 'm');

        return $store;
    }

    private function titleBackend(): Backend
    {
        return new class () implements Backend {
            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant('Login Bug Fix');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                return \React\Promise\resolve(Message::assistant('Login Bug Fix')->withUsage(Usage::new(9, 0.001)));
            }
        };
    }

    private static function plain(string $line): string
    {
        return (string) preg_replace('/\x1b\[[0-9;?]*[A-Za-z]|[\x{E000}-\x{F8FF}]/u', '', $line);
    }
}
