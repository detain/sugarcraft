<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionLock;
use SugarCraft\Crush\TranscriptFlushMsg;

/**
 * Audit SES-3(b): a second TUI on a session that is already open opens it
 * READ-ONLY and offers to fork it.
 *
 * Before this, `--continue` in two terminals opened the same session in both,
 * each rewrote the whole transcript on every change, and the last writer
 * erased the other's conversation. Each case here builds two Chats on one
 * store — the in-process stand-in for two terminals, since a `flock()` held by
 * one open file description refuses another in the same process exactly as it
 * does across processes.
 */
final class ReadOnlySessionTest extends TestCase
{
    private string $dir;

    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-read-only-session-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->store = new EnhancedSessionStore($this->dir . '/session.db');
        $this->store->createSession('shared', 'p', 'm', null, 'Shared work');
        $this->store->saveTranscript('shared', [Message::user('earlier question'), Message::assistant('earlier answer')]);
    }

    protected function tearDown(): void
    {
        // The lock directory first, then the sandbox it sits in.
        foreach ([$this->dir . '/' . SessionLock::DIRECTORY, $this->dir] as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    public function testTheFirstWindowWritesAndTheSecondOpensReadOnlyWithAForkOffer(): void
    {
        $first = $this->open();
        $second = $this->open();

        self::assertFalse($first->isReadOnlySession());
        self::assertTrue($second->isReadOnlySession());

        $last = $second->history[\count($second->history) - 1];
        self::assertSame(Role::System, $last->role);
        self::assertSame(
            sprintf(Chat::READ_ONLY_SESSION_NOTICE, 'Shared work', ' (pid ' . getmypid() . ')'),
            $last->content,
        );
        self::assertStringContainsString('/branch', $last->content);
        self::assertSame('earlier answer', $second->history[1]->content, 'the transcript is still shown');
    }

    /**
     * THE DEFECT ITSELF: the second window's prompt must not run, and must not
     * be written over the first window's conversation.
     */
    public function testAReadOnlyWindowRefusesAPromptAndWritesNothing(): void
    {
        $writer = $this->open();
        $second = self::typed($this->open(), 'please overwrite everything');

        [$refused, $cmd] = $second->update(new KeyMsg(KeyType::Enter));
        $refused->update(new TranscriptFlushMsg());
        $refused->flushTranscript();

        self::assertNull($cmd, 'no turn was dispatched');
        self::assertFalse($refused->inFlight);
        self::assertSame('', $refused->inputBuf, 'the box is free for /branch');
        $notice = $refused->history[\count($refused->history) - 1]->content;
        self::assertStringContainsString('was not sent', $notice);
        self::assertStringContainsString('/branch', $notice);
        self::assertSame(
            ['earlier question', 'earlier answer'],
            array_column((array) $this->store->loadTranscript('shared'), 'content'),
            'the read-only window wrote nothing to the session',
        );
        self::assertFalse($writer->isReadOnlySession(), 'the writer stays open throughout');
    }

    /**
     * And the writer's own changes still land, unclobbered by the reader.
     */
    public function testTheWritingWindowStillSavesWhileAReaderIsOpen(): void
    {
        $first = $this->open();
        $reader = $this->open();
        self::assertTrue($reader->isReadOnlySession());

        [$sent] = self::typed($first, 'next question')->update(new KeyMsg(KeyType::Enter));
        $sent->update(new TranscriptFlushMsg());

        self::assertContains(
            'next question',
            array_column((array) $this->store->loadTranscript('shared'), 'content'),
        );
    }

    /**
     * `/workflow run` is refused BEFORE it can mark a workflow turn in flight
     * (wave 9's note on `workflowTurnInFlight`): a run appends to the session.
     * So are the commands that rewrite the session in place.
     *
     * @return iterable<string, array{string}>
     */
    public static function refusedCommands(): iterable
    {
        yield '/workflow run' => ['/workflow run deploy'];
        yield '/workflow resume' => ['/workflow resume deploy'];
        yield '/clear' => ['/clear'];
        yield '/compact' => ['/compact'];
        yield '/rename' => ['/rename Mine now'];
        yield '/rewind' => ['/rewind'];
    }

    /**
     * @dataProvider refusedCommands
     */
    public function testCommandsThatWriteTheSessionAreRefused(string $command): void
    {
        $writer = $this->open();
        $reader = self::typed($this->open(), $command);

        self::assertTrue($reader->isReadOnlySession(), 'fixture: the writer holds the session');
        [$refused, $cmd] = $reader->update(new KeyMsg(KeyType::Enter));

        self::assertNull($cmd);
        self::assertFalse($refused->inFlight);
        self::assertSame('', $refused->inputBuf);
        self::assertStringContainsString('was not sent', $refused->history[\count($refused->history) - 1]->content);
        self::assertSame('Shared work', $this->store->getSession('shared')['name'] ?? null);
    }

    /**
     * Commands that only read, or change this window's own view, still run.
     */
    public function testCommandsThatLeaveTheSessionAloneStillRun(): void
    {
        $writer = $this->open();
        $reader = self::typed($this->open(), '/help');

        self::assertTrue($reader->isReadOnlySession(), 'fixture: the writer holds the session');
        [$answered] = $reader->update(new KeyMsg(KeyType::Enter));

        self::assertSame('', $answered->inputBuf, '/help ran and consumed the draft');
        self::assertStringNotContainsString(
            'was not sent',
            $answered->history[\count($answered->history) - 1]->content,
        );
    }

    /**
     * THE FORK OFFER: `/branch` copies the session into a new one this window
     * owns, and from then on the window writes — to the branch, never to the
     * session the other window has.
     */
    public function testBranchingOutOfAReadOnlySessionMakesTheWindowWritableOnTheFork(): void
    {
        $writer = $this->open();
        $reader = self::typed($this->open(), '/branch');

        self::assertTrue($reader->isReadOnlySession(), 'fixture: the writer holds the session');
        [$branched] = $reader->update(new KeyMsg(KeyType::Enter));
        $branchId = (string) $branched->currentSessionId();

        self::assertNotSame('shared', $branchId);
        self::assertFalse($branched->isReadOnlySession());
        self::assertStringContainsString('now writes to the branch', $branched->history[\count($branched->history) - 1]->content);
        self::assertNull($this->store->lockSession($branchId), 'this window holds the branch\'s lock');

        [$sent] = self::typed($branched, 'carry on here')->update(new KeyMsg(KeyType::Enter));
        $sent->update(new TranscriptFlushMsg());

        self::assertContains('carry on here', array_column((array) $this->store->loadTranscript($branchId), 'content'));
        self::assertNotContains(
            'carry on here',
            array_column((array) $this->store->loadTranscript('shared'), 'content'),
        );
    }

    /**
     * The refused draft is not lost: the box is cleared so `/branch` can be
     * typed straight away, and the fork hands the draft back — measured live,
     * a draft left in the box swallowed the `/branch` typed after it.
     */
    public function testTheRefusedDraftComesBackInTheBoxAfterBranching(): void
    {
        $writer = $this->open();
        [$refused] = self::typed($this->open(), 'what I meant to ask')->update(new KeyMsg(KeyType::Enter));
        self::assertSame('', $refused->inputBuf);

        [$branched] = self::typed($refused, '/branch')->update(new KeyMsg(KeyType::Enter));

        self::assertFalse($branched->isReadOnlySession());
        self::assertSame('what I meant to ask', $branched->inputBuf);
        self::assertFalse($writer->isReadOnlySession());
    }

    /**
     * A session switch moves the lock: the session left is free again.
     */
    public function testSwitchingAwayReleasesTheLockOnTheSessionLeft(): void
    {
        $this->store->createSession('elsewhere', 'p', 'm');
        $first = $this->open();
        self::assertNull($this->store->lockSession('shared'), 'fixture: held');

        [$switched] = $first->update(new KeyMsg(KeyType::Tab, ctrl: true));

        self::assertSame('elsewhere', $switched->currentSessionId());
        self::assertFalse($switched->isReadOnlySession());
        self::assertNotNull($this->store->lockSession('shared'), 'the session left is free');
        self::assertNull($this->store->lockSession('elsewhere'), 'and the one now open is held');
    }

    /**
     * Locking is opt-in: a Chat that never asked for it (tests, embedders)
     * behaves exactly as before, however many share a session.
     */
    public function testWithoutLockingTwoChatsOnOneSessionAreBothWritable(): void
    {
        $one = new Chat(sessionStore: $this->store, currentSessionId: 'shared');
        $two = new Chat(sessionStore: $this->store, currentSessionId: 'shared');

        self::assertFalse($one->isReadOnlySession());
        self::assertFalse($two->isReadOnlySession());
        self::assertNotNull($this->store->lockSession('shared'));
    }

    private function open(): Chat
    {
        return (new Chat(
            history: Chat::loadTranscript($this->store, 'shared'),
            sessionStore: $this->store,
            currentSessionId: 'shared',
            currentSessionName: 'Shared work',
        ))->withSessionLocking();
    }

    private static function typed(Chat $chat, string $draft): Chat
    {
        return (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($chat, ['inputBuf' => $draft]);
    }
}
