<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Host\EventLog;
use SugarCraft\Crush\Host\TranscriptStore;
use SugarCraft\Crush\Host\WorkspaceContext;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Session\DebouncedTranscriptWriter;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionStore;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\TranscriptFlushMsg;

/**
 * Roadmap O-2b: transcript persistence moved out of Chat into
 * {@see TranscriptStore}, and a LIVE row's identity is stable across saves.
 *
 * The identity half closes the W1-b/W2-a handoff: the store used to remember
 * the id/ref it gave a not-yet-reloaded row by Message INSTANCE, so every
 * wither copy — a placeholder finished by `withToolResults()`, a shown tool
 * row stamped by `withStepId()` — was a new row to it and burned a fresh ref
 * on the next save. It now keys by {@see Message::rowKey()}, which withers
 * carry forward.
 */
final class TranscriptStoreTest extends TestCase
{
    private string $dir;

    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-transcript-store-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->store = new EnhancedSessionStore($this->dir . '/session.db');
        $this->store->createSession('s', 'p', 'm');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        foreach (glob($this->dir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            foreach (glob($sub . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($sub);
        }
        @rmdir($this->dir);
    }

    /**
     * THE HANDOFF'S CASE. Live rows — minted here, never reloaded — keep the
     * identity their first save gave them on every later save, including the
     * copies withers make of them, and a reload hands back exactly those ids.
     */
    public function testLiveRowsHaveStableIdsAcrossSaves(): void
    {
        $transcripts = TranscriptStore::new($this->store);
        $user = Message::user('run ls');
        $placeholder = Message::toolRunning(new ToolCall('Bash', ['command' => 'ls'], 'c1'));

        $transcripts->save('s', [$user, $placeholder]);
        $userId = $transcripts->identityOf($user);
        $placeholderId = $transcripts->identityOf($placeholder);
        self::assertSame(['m_s_1', 1], $userId);
        self::assertSame(['m_s_2', 2], $placeholderId);
        self::assertNull($user->id, 'fixture: the live row itself still carries no id');

        // The turn settles: the placeholder is finished and stamped with its
        // step, both through withers, and a reply is appended.
        $finished = $placeholder
            ->withToolResults([ToolResult::ok('Bash', 'a.txt', 'c1')])
            ->withStepId('step-1');
        $reply = Message::assistant('done');
        $transcripts->save('s', [$user, $finished, $reply]);

        self::assertSame($placeholderId, $transcripts->identityOf($finished), 'a wither copy is the same row');
        self::assertSame(['m_s_3', 3], $transcripts->identityOf($reply), 'no ref was burned on the copy');

        // And again, unchanged rows, a fresh wither on the reply.
        $transcripts->save('s', [$user, $finished, $reply->withReasoning('because')]);
        self::assertSame(['m_s_3', 3], $transcripts->identityOf($reply->withUiOnly()));

        $reloaded = $transcripts->load('s');
        self::assertSame(
            [['m_s_1', 1], ['m_s_2', 2], ['m_s_3', 3]],
            array_map(static fn (Message $m): array => [$m->id, $m->ref], $reloaded),
            'what a reload reads is what the live rows were answering',
        );
        self::assertSame(4, $this->storedNextRef('s'), 'three rows, three refs: none spent on copies');
    }

    /**
     * A host that must name a row in an event before the debounced save
     * reaches it allocates the identity now; the save then writes that one.
     */
    public function testIdentifyAllocatesAheadOfTheSaveAndTheSaveKeepsIt(): void
    {
        $transcripts = TranscriptStore::new($this->store);
        $early = Message::user('first');
        $later = Message::assistant('second');

        self::assertNull($transcripts->identityOf($early));
        $given = $transcripts->identify('s', $later);
        self::assertSame(['m_s_1', 1], $given);
        self::assertSame($given, $transcripts->identify('s', $later->withStepId('x')), 'idempotent per row');

        $transcripts->save('s', [$early, $later]);

        self::assertSame(['m_s_2', 2], $transcripts->identityOf($early), 'the earlier row takes the next free ref');
        self::assertSame(['m_s_1', 1], $transcripts->identityOf($later));
    }

    /**
     * The same row twice in one history (a caller appended a row and a wither
     * of it) is two rows on disk: they never share an id, and both keep theirs.
     */
    public function testTheSameRowTwiceInOneHistoryGetsTwoStableIdentities(): void
    {
        $transcripts = TranscriptStore::new($this->store);
        $row = Message::user('echo');
        $copy = $row->withUiOnly();

        $transcripts->save('s', [$row, $copy]);
        $transcripts->save('s', [$row, $copy]);

        self::assertSame(
            [['m_s_1', 1], ['m_s_2', 2]],
            array_map(static fn (Message $m): array => [$m->id, $m->ref], $transcripts->load('s')),
        );
        self::assertSame(['m_s_2', 2], $transcripts->identityOf($copy));
    }

    public function testARowThatCarriesItsIdentityAnswersWithIt(): void
    {
        $transcripts = TranscriptStore::new($this->store);
        $carried = Message::user('x')->withIdentity('custom', 9);

        self::assertSame(['custom', 9], $transcripts->identityOf($carried));
        self::assertSame(['custom', 9], $transcripts->identify('s', $carried));
    }

    public function testLoadHealsARunningPlaceholderAndRevivesEveryRole(): void
    {
        $transcripts = TranscriptStore::new($this->store);
        $transcripts->save('s', [
            Message::user('u'),
            Message::toolRunning(new ToolCall('Bash', ['command' => 'sleep 9'], 'c9')),
        ]);

        $loaded = $transcripts->load('s');

        self::assertCount(2, $loaded);
        self::assertNull($loaded[1]->pendingToolCallId);
        self::assertSame('c9', $loaded[1]->toolResults[0]->id);
        self::assertSame(Chat::INTERRUPTED_TOOL_CALL, $loaded[1]->toolResults[0]->error);
        self::assertSame([], $transcripts->load('missing'));

        self::assertSame('system', TranscriptStore::reviveCheckpointRow(['role' => 'system', 'content' => 'n'])->role->value);
        self::assertTrue(TranscriptStore::reviveCheckpointRow(['role' => 'system', 'content' => 'n', 'uiOnly' => true])->uiOnly);
        self::assertSame('assistant', TranscriptStore::reviveRow(['role' => 'assistant', 'content' => 'a'])->role->value);

        $cancelled = TranscriptStore::interruptedToolCallRow('', 'c2', Chat::CANCELLED_TOOL_CALL);
        self::assertSame('c2', $cancelled->toolResults[0]->name, 'an empty description falls back to the call id');
        self::assertSame(Chat::CANCELLED_TOOL_CALL, $cancelled->toolResults[0]->error);
    }

    /** Chat's static entry points are the store's builders, unchanged. */
    public function testChatsRevivalEntryPointsDelegateToTheStore(): void
    {
        $row = ['role' => 'assistant', 'content' => 'x', 'pendingToolCallId' => 'c1'];

        self::assertEquals(TranscriptStore::reviveRow($row), Chat::reviveTranscriptMessage($row));
        self::assertEquals(TranscriptStore::reviveCheckpointRow($row), Chat::reviveCheckpointMessage($row));

        TranscriptStore::new($this->store)->save('s', [Message::user('hi')]);
        self::assertSame(['hi'], array_map(static fn (Message $m): string => $m->content, Chat::loadTranscript($this->store, 's')));
        self::assertSame([], Chat::loadTranscript(null, 's'));
    }

    public function testScheduleIsDebouncedUntilFlushAndSaveFlushesFirst(): void
    {
        $writer = new DebouncedTranscriptWriter();
        $transcripts = TranscriptStore::new($this->store, $writer);
        self::assertSame($writer, $transcripts->writer());

        $transcripts->schedule('s', [Message::user('pending')]);
        self::assertTrue($transcripts->hasPending());
        self::assertNull($this->store->loadTranscript('s'));

        $transcripts->flush();
        self::assertFalse($transcripts->hasPending());
        self::assertSame('pending', $this->store->loadTranscript('s')[0]['content'] ?? null);

        $this->store->createSession('t', 'p', 'm');
        $transcripts->schedule('t', [Message::user('other')]);
        $transcripts->save('s', [Message::user('now')]);
        self::assertFalse($transcripts->hasPending(), 'a synchronous save writes what was pending first');
        self::assertSame('other', $this->store->loadTranscript('t')[0]['content'] ?? null);
        self::assertSame('now', $this->store->loadTranscript('s')[0]['content'] ?? null);
    }

    public function testWithoutAnEnhancedStoreNothingPersists(): void
    {
        $plain = TranscriptStore::new(new SessionStore($this->dir . '/plain.db'));
        $none = TranscriptStore::new(null);

        foreach ([$plain, $none] as $transcripts) {
            self::assertFalse($transcripts->persists());
            self::assertNull($transcripts->store());
            self::assertNull($transcripts->events());
            $transcripts->schedule('s', [Message::user('x')]);
            $transcripts->save('s', [Message::user('x')]);
            self::assertFalse($transcripts->hasPending());
            self::assertSame([], $transcripts->load('s'));
            self::assertNull($transcripts->lock('s'));
            self::assertNull($transcripts->lockHolder('s'));
            self::assertNull($transcripts->identify('s', Message::user('x')));
            self::assertNull($transcripts->identityOf(Message::user('x')));
            self::assertSame(['i', 1], $transcripts->identityOf(Message::user('x')->withIdentity('i', 1)));
        }
    }

    public function testLockIsTheSingleWriterSessionLock(): void
    {
        $transcripts = TranscriptStore::new($this->store);

        $lock = $transcripts->lock('s');
        self::assertNotNull($lock);
        self::assertSame('s', $lock->sessionId());

        $lock->release();
    }

    public function testWithWriterAndEventsAreCarried(): void
    {
        $log = EventLog::new($this->store, 5);
        $transcripts = TranscriptStore::new($this->store, events: $log);
        $writer = new DebouncedTranscriptWriter();

        $bound = $transcripts->withWriter($writer);

        self::assertNotSame($transcripts, $bound);
        self::assertSame($writer, $bound->writer());
        self::assertSame($log, $bound->events());
        self::assertSame($this->store, $bound->store());
        self::assertSame($bound, $bound->withWriter($writer), 'the same writer is no change');
        self::assertInstanceOf(EventLog::class, TranscriptStore::new($this->store)->events(), 'a store brings its log');
    }

    /**
     * Chat reaches the store through the O-2a locator, bound to its own
     * lineage's writer (the one its flush tick watches) — and never through a
     * registration over a different store.
     */
    public function testChatResolvesTheRegisteredStoreBoundToItsOwnWriter(): void
    {
        $log = EventLog::new($this->store);
        $registered = TranscriptStore::new($this->store, events: $log);
        $workspace = WorkspaceContext::new(sessionStore: $this->store)
            ->withService(TranscriptStore::class, $registered);
        $writer = new DebouncedTranscriptWriter();
        $chat = new Chat(sessionStore: $this->store, currentSessionId: 's', transcriptWriter: $writer, workspace: $workspace);

        $resolved = self::transcriptsOf($chat);
        self::assertSame($log, $resolved->events(), 'the registered store is the one used');
        self::assertSame($writer, $resolved->writer(), 'bound to the Chat lineage writer');

        $other = new EnhancedSessionStore($this->dir . '/other.db');
        $foreign = new Chat(sessionStore: $other, workspace: $workspace);
        self::assertSame($other, self::transcriptsOf($foreign)->store(), 'a registration over another store is not used');
        self::assertNotSame($log, self::transcriptsOf($foreign)->events());

        $bare = new Chat(sessionStore: $this->store, currentSessionId: 's');
        self::assertSame($this->store, self::transcriptsOf($bare)->store(), 'no workspace still persists');
    }

    /**
     * End to end through Chat: a history change is scheduled on the store the
     * workspace registered, written on Chat's flush tick, and the live rows
     * answer the identities the save gave them.
     */
    public function testChatSavesThroughTheStoreAndItsLiveRowsAreIdentified(): void
    {
        $registered = TranscriptStore::new($this->store);
        $workspace = WorkspaceContext::new(sessionStore: $this->store)
            ->withService(TranscriptStore::class, $registered);
        $chat = new Chat(sessionStore: $this->store, currentSessionId: 's', workspace: $workspace);

        $user = Message::user('hello');
        $next = (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($chat, ['history' => [$user]]);
        (new \ReflectionMethod(Chat::class, 'persistTranscript'))->invoke($next, $chat);
        self::assertNull($this->store->loadTranscript('s'), 'debounced, not written inside update()');

        $next->update(new TranscriptFlushMsg());

        self::assertSame('hello', $this->store->loadTranscript('s')[0]['content'] ?? null);
        self::assertSame(['m_s_1', 1], $registered->identityOf($user));
        self::assertSame(['m_s_1', 1], $registered->identityOf($user->withUiOnly()));
    }

    private static function transcriptsOf(Chat $chat): TranscriptStore
    {
        $resolved = (new \ReflectionMethod(Chat::class, 'transcripts'))->invoke($chat);
        self::assertInstanceOf(TranscriptStore::class, $resolved);

        return $resolved;
    }

    private function storedNextRef(string $sessionId): int
    {
        $pdo = new \PDO('sqlite:' . $this->dir . '/session.db');
        $stmt = $pdo->prepare('SELECT state_data FROM session_transcripts WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        $decoded = json_decode((string) $stmt->fetchColumn(), true);
        $state = \is_array($decoded['__cps'] ?? null) ? $decoded['__cps'] : $decoded;

        return (int) ($state['nextRef'] ?? 0);
    }
}
