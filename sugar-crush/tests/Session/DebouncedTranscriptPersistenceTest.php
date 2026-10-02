<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Session;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Session\DebouncedTranscriptWriter;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\TranscriptFlushMsg;

/**
 * Audit R2: Chat no longer rewrites the whole transcript synchronously inside
 * `update()` on every history change. The change goes to a shared
 * {@see DebouncedTranscriptWriter}; {@see Chat::subscriptions()} declares a
 * flush tick while a snapshot is pending, and the tick writes the newest one.
 *
 * What must still hold, per the wave plan: nothing is lost on a session
 * switch, a fork, or the end of the process, and a read-only session (SES-3(b))
 * never writes — the last is in ReadOnlySessionTest.
 */
final class DebouncedTranscriptPersistenceTest extends TestCase
{
    private const FLUSH_SUBSCRIPTION = 'crush.transcript-flush';

    private string $dir;

    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-debounced-transcript-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->store = new EnhancedSessionStore($this->dir . '/session.db');
        $this->store->createSession('live', 'p', 'm');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    /**
     * THE CHANGE ITSELF, and the case that fails on the pre-R2 code: there,
     * the Enter that dispatched the turn had already written the transcript
     * before update() returned.
     */
    public function testAHistoryChangeIsNotWrittenInsideUpdateButOnTheFlushTick(): void
    {
        $chat = new Chat(sessionStore: $this->store, currentSessionId: 'live', inputBuf: 'hello');
        self::assertNull(self::subscriptionIds($chat), 'nothing pending, no tick');

        [$sent] = $chat->update(new KeyMsg(KeyType::Enter));
        self::assertInstanceOf(Chat::class, $sent);

        // The submit-time checkpoint (the PRE-turn state, which is empty) is
        // what a resume would find now — not the turn update() just started.
        self::assertSame([], self::stored('live'), 'update() must not write the transcript synchronously');
        self::assertContains(self::FLUSH_SUBSCRIPTION, self::subscriptionIds($sent) ?? []);

        [$flushed, $cmd] = $sent->update(new TranscriptFlushMsg());

        self::assertSame($sent, $flushed, 'the tick changes no model state');
        self::assertNull($cmd);
        self::assertSame(self::contents($sent->history), self::stored('live'));
        self::assertNotContains(
            self::FLUSH_SUBSCRIPTION,
            self::subscriptionIds($flushed) ?? [],
            'the tick is cancelled once nothing is pending',
        );
    }

    /**
     * Several changes inside one window are ONE write of the newest history,
     * not one write per change.
     */
    public function testChangesInsideOneWindowAreWrittenOnceAsTheNewest(): void
    {
        $chat = new Chat(sessionStore: $this->store, currentSessionId: 'live');

        $chatWithOne = self::advance($chat, [Message::user('one')]);
        $chatWithTwo = self::advance($chatWithOne, [Message::user('one'), Message::assistant('two')]);

        self::assertNull($this->store->loadTranscript('live'));

        $chatWithTwo->update(new TranscriptFlushMsg());

        self::assertSame(['one', 'two'], self::stored('live'));
    }

    /**
     * A switch away writes the session being LEFT first, so switching
     * straight back reads what was on screen.
     */
    public function testASessionSwitchWritesThePendingSnapshotOfTheSessionLeft(): void
    {
        $this->store->createSession('other', 'p', 'm');
        $chat = self::advance(
            new Chat(sessionStore: $this->store, currentSessionId: 'live'),
            [Message::user('unsaved line')],
        );
        self::assertNull($this->store->loadTranscript('live'), 'fixture: still pending');

        [$switched] = $chat->update(new KeyMsg(KeyType::Tab, ctrl: true));

        self::assertNotSame('live', $switched->currentSessionId(), 'fixture: Ctrl+Tab moved sessions');
        self::assertSame(['unsaved line'], self::stored('live'));
    }

    /**
     * `/branch` copies the STORED transcript, so a pending change is written
     * before the fork reads it.
     */
    public function testBranchWritesThePendingSnapshotBeforeForking(): void
    {
        $chat = self::advance(
            new Chat(sessionStore: $this->store, currentSessionId: 'live'),
            [Message::user('unsaved line')],
        );
        $draft = (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($chat, ['inputBuf' => '/branch']);

        [$branched] = $draft->update(new KeyMsg(KeyType::Enter));
        $branchId = $branched->currentSessionId();

        self::assertNotSame('live', $branchId);
        self::assertSame(['unsaved line'], self::stored('live'));
        self::assertContains('unsaved line', self::stored((string) $branchId), 'the fork carries the line');
    }

    /**
     * `bin/sugarcrush` discards the model `Program::run()` returns, so the last
     * Chat can be collected before shutdown functions run. The writer's
     * destructor writes what was pending.
     */
    public function testAPendingSnapshotIsWrittenWhenTheLastChatGoesAway(): void
    {
        $chat = self::advance(
            new Chat(sessionStore: $this->store, currentSessionId: 'live'),
            [Message::user('last words')],
        );
        self::assertNull($this->store->loadTranscript('live'));

        unset($chat);
        gc_collect_cycles();

        self::assertSame(['last words'], self::stored('live'));
    }

    public function testFlushTranscriptWritesNow(): void
    {
        $chat = self::advance(
            new Chat(sessionStore: $this->store, currentSessionId: 'live'),
            [Message::user('now')],
        );

        $chat->flushTranscript();

        self::assertSame(['now'], self::stored('live'));
    }

    /**
     * A host that drives update() but never runs subscriptions() gets no tick.
     * A snapshot left pending for several windows is then written by the next
     * change, so such a host still saves rather than holding everything until
     * exit.
     */
    public function testASnapshotWhoseTickNeverCameIsWrittenByTheNextChange(): void
    {
        $writer = new DebouncedTranscriptWriter(0.001);
        $writer->schedule($this->store, 'live', [Message::user('first')]);
        usleep(20_000);

        $writer->schedule($this->store, 'live', [Message::user('first'), Message::assistant('second')]);

        self::assertSame(['first'], self::stored('live'), 'the stale snapshot was written synchronously');
        self::assertTrue($writer->hasPending(), 'the new one waits for its own tick');
    }

    /**
     * A snapshot for one session is never carried into another: scheduling a
     * different session writes the first one at once.
     */
    public function testSchedulingAnotherSessionWritesThePendingOneFirst(): void
    {
        $this->store->createSession('other', 'p', 'm');
        $writer = new DebouncedTranscriptWriter();
        $writer->schedule($this->store, 'live', [Message::user('for live')]);

        $writer->schedule($this->store, 'other', [Message::user('for other')]);

        self::assertSame(['for live'], self::stored('live'));
        self::assertNull($this->store->loadTranscript('other'));
        $writer->flush();
        self::assertSame(['for other'], self::stored('other'));
    }

    /**
     * A Chat one update() past $chat with $history — the route a real change
     * takes, so the writer is scheduled exactly as in the TUI.
     *
     * @param list<Message> $history
     */
    private static function advance(Chat $chat, array $history): Chat
    {
        $next = (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($chat, ['history' => $history]);
        // persistTranscript() is the private step update() takes after route();
        // driving it directly keeps the fixture free of a backend turn.
        (new \ReflectionMethod(Chat::class, 'persistTranscript'))->invoke($next, $chat);

        return $next;
    }

    /**
     * @return list<string>|null
     */
    private static function subscriptionIds(Chat $chat): ?array
    {
        $subscriptions = $chat->subscriptions();
        if ($subscriptions === null) {
            return null;
        }

        return array_map(static fn($sub): string => $sub->id, $subscriptions->all());
    }

    /**
     * @param list<Message> $history
     *
     * @return list<string>
     */
    private static function contents(array $history): array
    {
        return array_map(static fn(Message $m): string => $m->content, $history);
    }

    /**
     * @return list<string>
     */
    private function stored(string $sessionId): array
    {
        return array_column((array) $this->store->loadTranscript($sessionId), 'content');
    }
}
