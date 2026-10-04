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
use SugarCraft\Crush\SessionLockRetryMsg;
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
     * N-P1: the settings view only reads, so both of its names open it in a
     * read-only window too.
     */
    public function testTheSettingsViewOpensInAReadOnlyWindow(): void
    {
        $writer = $this->open();
        foreach (['/settings', '/config'] as $command) {
            $reader = self::typed($this->open(), $command);
            self::assertTrue($reader->isReadOnlySession(), 'fixture: the writer holds the session');

            [$answered, $cmd] = $reader->update(new KeyMsg(KeyType::Enter));

            self::assertSame('', $answered->inputBuf, "{$command} ran and consumed the draft");
            self::assertInstanceOf(\SugarCraft\Crush\Tui\Settings\OpenSettingsMsg::class, $cmd === null ? null : $cmd());
        }

        self::assertFalse($writer->isReadOnlySession());
    }

    /**
     * 5.6: `/context` (and `/tokens`) only measure the session, so a
     * read-only window answers them rather than refusing.
     */
    public function testTheContextBreakdownAnswersInAReadOnlyWindow(): void
    {
        $writer = $this->open();
        foreach (['/context', '/tokens'] as $command) {
            $reader = self::typed($this->open(), $command);
            self::assertTrue($reader->isReadOnlySession(), 'fixture: the writer holds the session');

            [$answered] = $reader->update(new KeyMsg(KeyType::Enter));

            self::assertSame('', $answered->inputBuf, "{$command} ran and consumed the draft");
            $last = $answered->history[\count($answered->history) - 1]->content;
            self::assertStringNotContainsString('was not sent', $last);
            self::assertStringContainsString('History:', $last, "{$command} printed the breakdown");
        }

        self::assertFalse($writer->isReadOnlySession());
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

    // =====================================================================
    // Becoming writable when the other window lets go (SES-3 residual)
    // =====================================================================

    private const LOCK_RETRY = 'crush.session-lock-retry';

    /**
     * THE RESIDUAL: closing the window that held the session used to leave
     * this one refusing every prompt until a switch, `/branch` or relaunch.
     * The retry tick takes the lock, and the transcript is RELOADED — the
     * holder wrote after this window loaded, and saving the stale copy would
     * erase that.
     */
    public function testAReadOnlyWindowBecomesWritableOnceTheOtherWindowLetsGo(): void
    {
        $writer = $this->open();
        $reader = $this->open();
        self::assertTrue($reader->isReadOnlySession(), 'fixture: the writer holds the session');
        self::assertTrue($reader->subscriptions()?->has(self::LOCK_RETRY), 'a read-only window retries the lock');

        [$sent] = self::typed($writer, 'written after the reader loaded')->update(new KeyMsg(KeyType::Enter));
        $sent->update(new TranscriptFlushMsg());
        self::releaseLockOf($sent);
        unset($writer, $sent);

        [$upgraded, $cmd] = $reader->update(new SessionLockRetryMsg());

        self::assertNull($cmd);
        self::assertFalse($upgraded->isReadOnlySession());
        self::assertContains(
            'written after the reader loaded',
            array_map(static fn(Message $m): string => $m->content, $upgraded->history),
            'the transcript was reloaded from the store, not kept',
        );
        self::assertSame(
            sprintf(Chat::SESSION_WRITABLE_NOTICE, 'Shared work'),
            $upgraded->history[\count($upgraded->history) - 1]->content,
        );
        self::assertNull($this->store->lockSession('shared'), 'this window holds the session now');
        self::assertFalse($upgraded->subscriptions()?->has(self::LOCK_RETRY) ?? false, 'and stops retrying');
    }

    /**
     * And the upgraded window really writes: its next prompt is sent and saved
     * on top of what the other window wrote, not over it.
     */
    public function testTheUpgradedWindowSavesOnTopOfTheOtherWindowsWork(): void
    {
        $writer = $this->open();
        $reader = $this->open();
        [$sent] = self::typed($writer, 'from the first window')->update(new KeyMsg(KeyType::Enter));
        $sent->update(new TranscriptFlushMsg());
        self::releaseLockOf($sent);
        unset($writer, $sent);

        [$upgraded] = $reader->update(new SessionLockRetryMsg());
        [$prompted, $cmd] = self::typed($upgraded, 'from the second window')->update(new KeyMsg(KeyType::Enter));
        $prompted->flushTranscript();

        self::assertNotNull($cmd, 'the prompt was dispatched');
        $saved = array_column((array) $this->store->loadTranscript('shared'), 'content');
        self::assertContains('from the first window', $saved);
        self::assertContains('from the second window', $saved);
    }

    public function testWhileTheOtherWindowStillHoldsItTheRetryChangesNothing(): void
    {
        $writer = $this->open();
        $reader = $this->open();

        [$same, $cmd] = $reader->update(new SessionLockRetryMsg());

        self::assertSame($reader, $same, 'no clone, so nothing is saved or repainted');
        self::assertNull($cmd);
        self::assertTrue($same->isReadOnlySession());
        self::assertFalse($writer->isReadOnlySession());
    }

    /** A draft a refusal stashed comes back into the box, as `/branch` puts it back. */
    public function testTheRefusedDraftComesBackWhenTheWindowBecomesWritable(): void
    {
        $writer = $this->open();
        [$refused] = self::typed($this->open(), 'my real question')->update(new KeyMsg(KeyType::Enter));
        self::assertSame('', $refused->inputBuf, 'fixture: refused and stashed');
        self::releaseLockOf($writer);
        unset($writer);

        [$upgraded] = $refused->update(new SessionLockRetryMsg());

        self::assertFalse($upgraded->isReadOnlySession());
        self::assertSame('my real question', $upgraded->inputBuf);
    }

    /** A writable window, and a window that never asked for locking, poll nothing. */
    public function testOnlyAReadOnlyWindowDeclaresTheRetry(): void
    {
        $writer = $this->open();
        $unlocked = new Chat(sessionStore: $this->store, currentSessionId: 'shared');

        self::assertFalse($writer->subscriptions()?->has(self::LOCK_RETRY) ?? false);
        self::assertFalse($unlocked->subscriptions()?->has(self::LOCK_RETRY) ?? false);
    }

    /** What the holding process exiting does to its lock, in-process. */
    private static function releaseLockOf(Chat $chat): void
    {
        $lock = (new \ReflectionProperty(Chat::class, 'sessionLock'))->getValue($chat);
        self::assertInstanceOf(SessionLock::class, $lock, 'fixture: this window holds the lock');
        $lock->release();
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
