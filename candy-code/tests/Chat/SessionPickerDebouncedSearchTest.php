<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\TickRequest;
use SugarCraft\Code\App\SessionPickerSearchMsg;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Message;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tui\SessionPicker;

/**
 * The debounced FTS re-read behind the session picker's `/` filter
 * (C-SES-2). Before it, the store was read exactly once — when the filter
 * opened — so body hits could never reach the list: the ranking ran over a
 * stale page built for a different query.
 *
 * Law pinned here: every query edit hands the host a `Cmd::tick` for
 * {@see SessionPickerSearchMsg} carrying the query it was armed for, and the
 * handler applies the re-read ONLY while the picker still shows exactly that
 * text. Typing therefore costs one store read per pause, and abandoned
 * prefixes' ticks arrive to no-op — there is no cancellation, the stamp is
 * the cancellation. The picker's own synchronous open read (empty query) is
 * the one edit-shaped action that never goes through the debounce.
 *
 * @internal
 */
final class SessionPickerDebouncedSearchTest extends TestCase
{
    use HomeSandboxTrait;

    private string $homeSandbox = '';

    private string $tempDir;

    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/ses_debounced_test_' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
        $this->homeSandbox = $this->useHomeSandbox($this->tempDir . '-home');

        $this->store = new EnhancedSessionStore($this->tempDir . '/debounced.db');
        $this->store->createSession('bare-list', 'openai', 'gpt-4', null, 'Bare List');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        @rmdir($this->homeSandbox);
        $files = glob($this->tempDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    private function chat(): Chat
    {
        return new Chat(
            history: [Message::user('hi')],
            inputBuf: '',
            backend: new EchoBackend(),
            sessionStore: $this->store,
            currentSessionId: 'bare-list',
        );
    }

    /** Ctrl+R opens the picker; `/` opens the filter (the one synchronous read). */
    private function openFilter(Chat $chat): Chat
    {
        [$chat] = $chat->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $this->assertNotNull($chat->sessionPicker(), 'fixture: Ctrl+R opened the picker');

        [$chat] = $chat->update(new KeyMsg(KeyType::Char, '/'));
        $this->assertSame(SessionPicker::SEARCH_LIMIT, $chat->sessionPicker()?->fetchLimit(), 'fixture: opening the filter read the search page');

        return $chat;
    }

    /**
     * Type $text one character at a time; return the model after the last
     * edit and the Cmd that edit handed back.
     *
     * @return array{0: Chat, 1: ?\Closure}
     */
    private function typeChars(Chat $chat, string $text): array
    {
        $cmd = null;
        foreach (mb_str_split($text) as $char) {
            [$chat, $cmd] = $chat->update(new KeyMsg(KeyType::Char, $char));
        }

        return [$chat, $cmd];
    }

    /** @return list<string> */
    private static function visibleIds(Chat $chat): array
    {
        return array_column($chat->sessionPicker()?->filteredSessions() ?? [], 'sessionId');
    }

    /** @return array<string, mixed> */
    private static function visibleRow(Chat $chat, string $id): array
    {
        foreach ($chat->sessionPicker()?->filteredSessions() ?? [] as $row) {
            if ($row['sessionId'] === $id) {
                return $row;
            }
        }

        $this->fail("row {$id} is not visible");
    }

    public function testAQueryEditArmsTheDebounceTickInsteadOfReadingTheStoreNow(): void
    {
        $chat = $this->openFilter($this->chat());

        // The C-SES-2 body hit exists ONLY in the transcript, and a session
        // added after the page read exists ONLY in the store — neither may be
        // visible until the tick lands, and the body hit must NOT ride in on
        // a synchronous keystroke read either.
        $this->store->createSession('quiet-vault', 'openai', 'gpt-4', null, 'Quarterly Notes');
        $this->store->saveTranscript('quiet-vault', [
            ['role' => 'user', 'content' => 'the ledger entry about the amber anchor was misplaced'],
        ]);

        [$typed, $cmd] = $this->typeChars($chat, 'anchor');

        $this->assertNotNull($cmd, 'every query edit hands the host a Cmd');
        $tick = $cmd();
        $this->assertInstanceOf(TickRequest::class, $tick, 'the edit re-arms a debounced tick, it does not read');
        $this->assertSame(0.3, $tick->seconds, '300 ms UI debounce — not a request timeout (E646 keeps LLM calls unbanned from timeouts)');

        $msg = ($tick->produce)();
        $this->assertInstanceOf(SessionPickerSearchMsg::class, $msg);
        $this->assertSame('anchor', $msg->query, 'the tick carries the query it was armed for');

        $this->assertNotContains('quiet-vault', self::visibleIds($typed), 'the keystroke itself read nothing — the body hit cannot be visible yet');
    }

    public function testTheFreshTickReReadsTheStoreAndTheBodyHitReachesTheVisibleRows(): void
    {
        $chat = $this->openFilter($this->chat());
        $this->store->createSession('quiet-vault', 'openai', 'gpt-4', null, 'Quarterly Notes');
        $this->store->saveTranscript('quiet-vault', [
            ['role' => 'user', 'content' => 'the ledger entry about the amber anchor was misplaced'],
        ]);
        [$typed, $cmd] = $this->typeChars($chat, 'anchor');

        $tick = $cmd();
        $this->assertInstanceOf(TickRequest::class, $tick);
        [$settled] = $typed->update(($tick->produce)());

        $this->assertContains('quiet-vault', self::visibleIds($settled), 'the debounced re-read answers FTS: a transcript-only hit becomes visible');
        $row = self::visibleRow($settled, 'quiet-vault');
        $this->assertNotNull($row['bodySnippet'], 'and it rides with its snippet, which is what admits it below the fuzzy hits');
        $this->assertStringContainsString('anchor', (string) $row['bodySnippet']);
    }

    public function testAStaleTickIsIgnoredTheStampIsTheCancellation(): void
    {
        $chat = $this->openFilter($this->chat());
        [$typed] = $this->typeChars($chat, 'anch');
        [$later] = $this->typeChars($typed, 'o');

        // A session the 'ancho' FTS page must answer with, created only after
        // every keystroke — its presence is the observable of "the store was
        // read", its absence of "this tick was ignored".
        $this->store->createSession('late-comer', 'openai', 'gpt-4', null, 'Late Drill');
        $this->store->saveTranscript('late-comer', [
            ['role' => 'user', 'content' => 'the ancho drill was logged at dawn'],
        ]);

        [$stale] = $later->update(new SessionPickerSearchMsg('anch'));
        $this->assertNotContains('late-comer', self::visibleIds($stale), 'an abandoned prefix tick must not clobber the live page');

        [$fresh] = $later->update(new SessionPickerSearchMsg('ancho'));
        $this->assertContains('late-comer', self::visibleIds($fresh), 'the tick matching the live query re-reads: the body hit arrives store-ranked');
        $this->assertNotNull(self::visibleRow($fresh, 'late-comer')['bodySnippet']);
    }

    public function testATickForAClosedPickerIsIgnored(): void
    {
        $chat = $this->openFilter($this->chat());
        [$typed, $cmd] = $this->typeChars($chat, 'anchor');

        // Escape retires the filter text first, then closes the picker.
        [$once] = $typed->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertNotNull($once->sessionPicker(), 'fixture: the first Escape clears the filter text, picker stays');
        [$closed] = $once->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertNull($closed->sessionPicker(), 'fixture: the second Escape closes the picker');

        $tick = $cmd();
        $this->assertInstanceOf(TickRequest::class, $tick);
        [$after] = $closed->update(($tick->produce)());
        $this->assertNull($after->sessionPicker());
        $this->assertSame($closed, $after, 'an unhosted tick is a literal no-op on the model');
    }

    public function testTheOpenReadIsSynchronousAndCarriesNoTick(): void
    {
        $chat = $this->chat();
        [$opened] = $chat->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $this->assertNotNull($opened->sessionPicker());

        [$filtering, $cmd] = $opened->update(new KeyMsg(KeyType::Char, '/'));
        $this->assertNull($cmd, 'the OPEN of the filter is the one read that lands synchronously — nothing to debounce');
        $this->assertSame(SessionPicker::SEARCH_LIMIT, $filtering->sessionPicker()?->fetchLimit());
        $this->assertSame('', $filtering->sessionPicker()?->query());
    }
}
