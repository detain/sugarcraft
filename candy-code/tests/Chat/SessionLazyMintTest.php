<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\InitialPromptMsg;
use SugarCraft\Code\Message;
use SugarCraft\Code\Session\EnhancedSessionStore;

/**
 * ISS-102: a launch must not leave an empty session record behind.
 *
 * `Cli\Bootstrap::openSession()` hands the Chat a minter closure instead of
 * writing the row, and the first dispatched prompt — through
 * `Chat::dispatchTurn()` — is what runs it. These pins hold the three halves
 * of that contract on the model directly (the launcher's own end-to-end walk
 * is `Cli\BootstrapTest` and `Integration\FeatWiringReachabilityTest`):
 *
 * - nothing reaches the store until a prompt dispatches;
 * - the dispatch mints EXACTLY once, and a second prompt changes nothing;
 * - a history change before the mint never schedules a transcript write,
 *   because `EnhancedSessionStore::saveTranscript()` backfills a missing row
 *   with `'candy-code'/'unknown'` — an unguarded pre-mint save would mint the
 *   row through the back door and lose the launcher's provider/model/cwd.
 *
 * The closures below mirror Bootstrap's minter exactly, defensive re-read
 * included: the guard is what makes a double-mint a no-op rather than the
 * duplicate-key throw `SessionStore::createSession()` would raise.
 */
final class SessionLazyMintTest extends TestCase
{
    private string $dir;
    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/lazy_mint_' . uniqid('', true);
        mkdir($this->dir, 0755, true);
        $this->store = new EnhancedSessionStore($this->dir . '/sessions.db');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testALaunchWithAPendingMintWritesNoRow(): void
    {
        $chat = $this->lazilyOpened('lazy');

        $this->assertSame([], $this->store->listSessions(), 'the launch promised a row and wrote none');
        $this->assertSame('lazy', $chat->currentSessionId(), 'the Chat still points at the promised id');
    }

    public function testTheFirstDispatchedPromptMintsItsRowOnceAndTheSecondChangesNothing(): void
    {
        $chat = $this->lazilyOpened('lazy');

        [$spoken] = $chat->update(new InitialPromptMsg('first words'));

        $this->assertSame(['lazy'], array_column($this->store->listSessions(), 'id'), 'the dispatch minted the row');
        $row = $this->store->getSession('lazy');
        $this->assertSame('openai', $row['provider'], 'the mint carries the launcher metadata, not a backfill');
        $this->assertSame('/work', $row['cwd']);

        [$again] = $spoken->update(new InitialPromptMsg('second words'));

        // The first turn never settled (its Cmd is unexecuted), so the second
        // prompt rides the mid-turn queue — the point is that no path after
        // the mint re-runs it: one row, same id, no duplicate-key throw.
        $this->assertSame(['lazy'], array_column($this->store->listSessions(), 'id'), 'the second prompt cannot re-INSERT');
        $this->assertSame('lazy', $again->currentSessionId());
    }

    public function testAMintIsSkippedWhenSomethingElseAlreadyWroteTheRow(): void
    {
        // The launcher's closure re-reads before INSERTing: a row created
        // out-of-band between the launch and the first prompt (a backfilling
        // save, a hand of the same id) stands, and the dispatch must not
        // collide with it — a duplicate-key throw would kill the turn.
        $this->store->createSession('lazy', 'backfiller', 'model');

        $chat = $this->lazilyOpened('lazy');
        [$spoken] = $chat->update(new InitialPromptMsg('first words'));

        $this->assertSame(['lazy'], array_column($this->store->listSessions(), 'id'));
        $this->assertSame('backfiller', $spoken->sessionStore()?->getSession('lazy')['provider'], 'the first writer keeps its metadata');
    }

    public function testAHistoryChangeBeforeTheMintReachesNoStoreWrite(): void
    {
        $chat = $this->lazilyOpened('lazy');
        $changed = (new \ReflectionMethod(Chat::class, 'mutate'))
            ->invoke($chat, ['history' => [...$chat->history, Message::user('a row before any prompt')]]);

        // What update() does after every model move: persist, then flush on a
        // session switch. With the guard, neither reaches the store; without
        // it, saveTranscript() backfills 'lazy' with 'candy-code'/'unknown'
        // and the launcher's real metadata is gone before the first prompt.
        (new \ReflectionMethod(Chat::class, 'persistTranscript'))->invoke($changed, $chat);
        (new \ReflectionMethod(Chat::class, 'transcripts'))->invoke($changed)->flush();

        $this->assertNull($this->store->getSession('lazy'), 'the pre-mint write must not materialize the row');
        $this->assertNull($this->store->loadTranscript('lazy'), 'and must not store a transcript against it');
    }

    public function testTheSettledFirstTurnStillWritesItsTranscriptAfterMinting(): void
    {
        $chat = $this->lazilyOpened('lazy');
        [$spoken] = $chat->update(new InitialPromptMsg('first words'));

        (new \ReflectionMethod(Chat::class, 'transcripts'))->invoke($spoken)->flush();

        $stored = $this->store->loadTranscript('lazy') ?? [];
        $this->assertContains('first words', array_map(static fn (array $r): string => (string) ($r['content'] ?? ''), $stored), 'the guard lifts with the mint');
    }

    /** A Chat as Bootstrap::chat() builds one on a fresh launch: an id, a minter, no row. */
    private function lazilyOpened(string $id): Chat
    {
        $store = $this->store;
        $mint = static function () use ($store, $id): void {
            if ($store->getSession($id) !== null) {
                return;
            }
            $store->createSession($id, 'openai', 'gpt-4', null, null, '/work', null);
        };

        return new Chat(
            backend: new EchoBackend(),
            sessionStore: $store,
            currentSessionId: $id,
            sessionMint: $mint,
        );
    }
}
