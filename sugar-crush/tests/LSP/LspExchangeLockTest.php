<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\LSP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\LSP\LspExchangeLock;
use SugarCraft\Crush\LSP\LspExchangeState;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Tests\Support\ReapsForkedChildrenTrait;

/**
 * The cross-process lock and shared state behind LspConnection's fork safety
 * (audit B7): exclusion between processes, a deadline-bounded wait, the
 * notification journal and its bound, and owner-only removal of the files —
 * plus the sweep of the sets a killed owner left behind (audit B8).
 */
final class LspExchangeLockTest extends TestCase
{
    use ReapsForkedChildrenTrait;

    private ?LspExchangeLock $lock = null;

    private ?string $dir = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('cross-process exclusion needs ext-pcntl to fork a second process');
        }

        $this->lock = LspExchangeLock::new('test');
    }

    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();
        $this->lock?->destroy();
        $this->lock = null;

        if ($this->dir !== null) {
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->dir);
            $this->dir = null;
        }

        parent::tearDown();
    }

    public function testAFreshLockLoadsAFreshState(): void
    {
        $state = $this->lock()->load();

        self::assertSame(LspExchangeState::PHASE_CLEAN, $state->phase);
        self::assertFileExists($this->lock()->path);
    }

    public function testStoreAndLoadRoundTripTheStateAndTheFrame(): void
    {
        $lock = $this->lock();

        $lock->store(LspExchangeState::new()->withPhase(LspExchangeState::PHASE_READING)->withNoteSeq(3));
        $lock->storeFrame("Content-Length: 2\r\n\r\n{}");

        self::assertSame(LspExchangeState::PHASE_READING, $lock->load()->phase);
        self::assertSame(3, $lock->load()->noteSeq);
        self::assertSame("Content-Length: 2\r\n\r\n{}", $lock->loadFrame());
    }

    public function testTheDeprecatedCreateNameStillBuildsAWorkingLock(): void
    {
        $lock = LspExchangeLock::create('probe', $this->privateDir());

        try {
            self::assertStringStartsWith(LspExchangeLock::FILE_PREFIX, basename($lock->path));
            self::assertSame(LspExchangeState::PHASE_CLEAN, $lock->load()->phase);
        } finally {
            $lock->destroy();
        }
    }

    public function testStateWritesReportWhetherTheyLanded(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root writes through a read-only directory');
        }

        $dir = $this->privateDir();
        $lock = LspExchangeLock::new('probe', $dir);

        try {
            self::assertTrue($lock->store(LspExchangeState::new()->withNoteSeq(1)));
            self::assertTrue($lock->storeFrame('kept'));

            // The temp-then-rename needs a writable directory: a read-only one
            // fails every store the way a full or read-only temp filesystem does.
            chmod($dir, 0500);
            try {
                self::assertFalse($lock->store(LspExchangeState::new()->withNoteSeq(2)), 'a store that did not land was reported as stored');
                self::assertFalse($lock->storeFrame('lost'), 'a frame record that did not land was reported as stored');
            } finally {
                chmod($dir, 0700);
            }

            // The rename keeps the old contents whole: stale, never torn.
            self::assertSame(1, $lock->load()->noteSeq);
            self::assertSame('kept', $lock->loadFrame());
        } finally {
            $lock->destroy();
        }
    }

    public function testAMissingStateFileLoadsAsDirtyNotAsAFreshConnection(): void
    {
        // new() always writes the state before it returns, and stores replace
        // it by rename, so a missing file was removed under the connection and
        // the stream position it recorded is unknown: only a dirty reading
        // makes the next holder resynchronise instead of trusting stdout.
        $lock = $this->lock();
        self::assertTrue(unlink($lock->path . '.state'));

        self::assertSame(LspExchangeState::PHASE_READING, $lock->load()->phase);
        self::assertSame('', $lock->load()->buffer);
    }

    public function testAnotherProcessHoldingTheLockBoundsTheWaitByTheDeadline(): void
    {
        $lock = $this->lock();
        $held = (string) tempnam(sys_get_temp_dir(), 'lsp-lock-held-');
        @unlink($held);

        $pid = $this->forkTracked();
        self::assertNotSame(-1, $pid, 'fork failed');
        if ($pid === 0) {
            if ($lock->acquire(microtime(true) + 5.0, static fn (): bool => true)) {
                touch($held);
                usleep(1_500_000);
                $lock->release();
            }
            ForkedChild::exitNow();
        }

        $until = microtime(true) + 5.0;
        while (!is_file($held) && microtime(true) < $until) {
            usleep(5_000);
        }
        self::assertFileExists($held, 'the child never took the lock');

        $started = microtime(true);
        self::assertFalse($lock->acquire(microtime(true) + 0.2, static fn (): bool => true), 'the lock must exclude another process');
        self::assertLessThan(1.0, microtime(true) - $started, 'the wait must end at its deadline');
        self::assertFalse($lock->acquire(null, static fn (): bool => false), 'a dead server ends the wait');

        self::assertTrue($lock->acquire(microtime(true) + 5.0, static fn (): bool => true), 'the lock is free once the holder releases it');
        $lock->release();

        pcntl_waitpid($pid, $status);
        $this->forgetForkedChild($pid);
        @unlink($held);
    }

    public function testTheJournalHandsOutEntriesNewerThanWhatWasSeen(): void
    {
        $lock = $this->lock();

        self::assertSame(1, $lock->appendNote('a', ['n' => 1]));
        self::assertSame(2, $lock->appendNote('b', null));

        $after = $lock->notesAfter(1);
        self::assertCount(1, $after);
        self::assertSame(['seq' => 2, 'method' => 'b', 'params' => null], $after[0]);
        self::assertSame([], $lock->notesAfter(2));
    }

    /**
     * A journal entry that does not land is reported, never numbered: the
     * sequence number appendNote() used to return anyway was published as the
     * shared noteSeq hint, every sharer stepped past it, and the next append —
     * counting from the file — minted the same number again for an entry that
     * DID land, which they then skipped as already seen.
     */
    public function testAJournalWriteThatDoesNotLandIsReportedAndItsNumberIsNotSpent(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root writes through a read-only directory');
        }

        $dir = $this->privateDir();
        $lock = LspExchangeLock::new('journal', $dir);

        try {
            self::assertSame(1, $lock->appendNote('kept', null));

            chmod($dir, 0500);
            try {
                self::assertNull($lock->appendNote('lost', ['n' => 2]), 'a journal entry that did not land was given a sequence number');
            } finally {
                chmod($dir, 0700);
            }

            self::assertSame(
                [['seq' => 1, 'method' => 'kept', 'params' => null]],
                $lock->notesAfter(0),
                'the journal keeps its previous contents whole',
            );
            self::assertSame(2, $lock->appendNote('next', null), 'the next entry takes the number the failed one never had');
        } finally {
            $lock->destroy();
        }
    }

    public function testTheJournalIsBounded(): void
    {
        $lock = $this->lock();

        for ($i = 0; $i < 100; $i++) {
            $lock->appendNote('n', ['i' => $i]);
        }

        $kept = $lock->notesAfter(0);
        self::assertLessThan(100, count($kept), 'the journal must not grow without bound');
        self::assertSame(100, $kept[array_key_last($kept)]['seq'], 'the newest entry is always kept');
    }

    public function testANonOwnerDestroyLeavesTheFilesAndTheOwnerRemovesThem(): void
    {
        $lock = $this->lock();
        $lock->storeFrame('x');

        $pid = $this->forkTracked();
        self::assertNotSame(-1, $pid, 'fork failed');
        if ($pid === 0) {
            $lock->destroy();
            ForkedChild::exitNow();
        }
        pcntl_waitpid($pid, $status);
        $this->forgetForkedChild($pid);

        self::assertFileExists($lock->path, 'a forked process must not remove the owner\'s lock');

        $lock->destroy();
        self::assertSame([], glob($lock->path . '*') ?: [], 'the owner removes every file it created');
        $this->lock = null;
    }

    public function testTheLockNameRecordsTheOwnerAndTheFileIsPrivate(): void
    {
        $lock = LspExchangeLock::new('probe', $this->privateDir());

        try {
            [, $owner] = $this->nameParts($lock->path);
            self::assertSame((int) getmypid(), $owner);
            self::assertSame(0600, fileperms($lock->path) & 0777);
        } finally {
            $lock->destroy();
        }
    }

    public function testCreateSweepsTheWholeSetOfAnOwnerKilledWithoutDestroy(): void
    {
        $dir = $this->privateDir();
        $made = $dir . '/made';

        // The real B8 shape: an owner creates its lock, journals a note and
        // records a frame, then dies without destroy() — and a holder killed
        // between writing a temp and renaming it stranded one more file.
        $pid = $this->forkTracked();
        self::assertNotSame(-1, $pid, 'fork failed');
        if ($pid === 0) {
            $orphan = LspExchangeLock::new('dying', $dir);
            $orphan->appendNote('window/logMessage', ['message' => 'hi']);
            $orphan->storeFrame("Content-Length: 2\r\n\r\n{}");
            file_put_contents($orphan->path . '.state.' . getmypid() . '.0a0b0c0d', 'torn');
            file_put_contents($made, $orphan->path);
            ForkedChild::exitNow();
        }
        pcntl_waitpid($pid, $status);
        $this->forgetForkedChild($pid);

        $orphanPath = (string) file_get_contents($made);
        unlink($made);
        self::assertCount(5, glob($orphanPath . '*') ?: [], 'the killed owner left its lock, three sidecars and a temp');

        $lock = LspExchangeLock::new('next', $dir);

        try {
            self::assertSame([], glob($orphanPath . '*') ?: [], 'the dead owner\'s whole set is reclaimed at the next connection start');
            self::assertFileExists($lock->path);
            self::assertFileExists($lock->path . '.state');
        } finally {
            $lock->destroy();
        }
    }

    public function testTheSweepKeepsEverySetItCannotProveIsAbandoned(): void
    {
        $dir = $this->privateDir();
        $namespace = $this->namespace($dir);

        $livePid = $this->forkTracked();
        self::assertNotSame(-1, $livePid, 'fork failed');
        if ($livePid === 0) {
            usleep(30_000_000);
            ForkedChild::exitNow();
        }

        try {
            $ownedByLive = $this->setOwnedBy($livePid, $dir, $namespace);
            $ownedBySelf = $this->setOwnedBy((int) getmypid(), $dir, $namespace);
            $legacy = $dir . '/' . LspExchangeLock::FILE_PREFIX . 'Ab12Cd';
            touch($legacy);
            touch($legacy . '.state');
            $otherNamespace = $this->setOwnedBy($this->deadPid(), $dir, $namespace === '1' ? '2' : '1');

            // A dead owner's lock that a surviving fork is mid-exchange on.
            $held = $this->setOwnedBy($this->deadPid(), $dir, $namespace);
            $holder = fopen($held, 'r+');
            self::assertIsResource($holder);
            self::assertTrue(flock($holder, LOCK_EX));

            try {
                self::assertSame(0, LspExchangeLock::sweepStale($dir));
            } finally {
                fclose($holder);
            }

            foreach ([$ownedByLive, $ownedBySelf, $otherNamespace, $held] as $lock) {
                self::assertCount(4, glob($lock . '*') ?: [], basename($lock) . ' must keep its whole set');
            }
            self::assertFileExists($legacy, 'a pre-B8 name says nothing about its owner and is left');
            self::assertFileExists($legacy . '.state');

            self::assertSame(4, LspExchangeLock::sweepStale($dir), 'released, the held set is reclaimed on the next sweep');
            self::assertSame([], glob($held . '*') ?: []);
            self::assertCount(4, glob($ownedByLive . '*') ?: [], 'a live owner keeps its set');
        } finally {
            posix_kill($livePid, SIGKILL);
            pcntl_waitpid($livePid, $status);
            $this->forgetForkedChild($livePid);
        }
    }

    public function testASidecarWhoseLockIsGoneGoesOnlyWithItsOwner(): void
    {
        $dir = $this->privateDir();
        $namespace = $this->namespace($dir);

        // A sweep killed between unlinking the sidecars and the lock leaves
        // the opposite case too: sidecars with no lock beside them.
        $deadSet = $this->setOwnedBy($this->deadPid(), $dir, $namespace);
        unlink($deadSet);
        $selfSet = $this->setOwnedBy((int) getmypid(), $dir, $namespace);
        unlink($selfSet);

        self::assertSame(3, LspExchangeLock::sweepStale($dir));
        self::assertSame([], glob($deadSet . '*') ?: [], 'no process can reopen a lockless set whose owner is dead');
        self::assertCount(3, glob($selfSet . '*') ?: [], 'a live owner may be between creating its lock and its state');
    }

    public function testAWaiterWhoseLockWasSweptRefusesTheExchange(): void
    {
        $lock = $this->lock();
        $held = $this->privateDir() . '/held';

        // Stands in for a sweep: take the flock, unlink the name while
        // holding it, release. A waiter that then wins the flock holds an
        // inode no longer named — and none of the state beside it.
        $pid = $this->forkTracked();
        self::assertNotSame(-1, $pid, 'fork failed');
        if ($pid === 0) {
            $handle = fopen($lock->path, 'r+');
            if ($handle !== false && flock($handle, LOCK_EX)) {
                touch($held);
                usleep(400_000);
                unlink($lock->path);
                flock($handle, LOCK_UN);
            }
            ForkedChild::exitNow();
        }

        $until = microtime(true) + 5.0;
        while (!is_file($held) && microtime(true) < $until) {
            usleep(5_000);
        }
        self::assertFileExists($held, 'the child never took the lock');

        self::assertFalse(
            $lock->acquire(microtime(true) + 5.0, static fn (): bool => true),
            'a lock whose name went while this process waited must not be acquired',
        );

        pcntl_waitpid($pid, $status);
        $this->forgetForkedChild($pid);
    }

    private function lock(): LspExchangeLock
    {
        self::assertNotNull($this->lock);

        return $this->lock;
    }

    private function privateDir(): string
    {
        if ($this->dir === null) {
            $this->dir = sys_get_temp_dir() . '/lsp-exchange-lock-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
            mkdir($this->dir, 0700);
        }

        return $this->dir;
    }

    /** This process' pid-namespace tag, read off the name new() gives a lock. */
    private function namespace(string $dir): string
    {
        $probe = LspExchangeLock::new('ns-probe', $dir);
        [$namespace] = $this->nameParts($probe->path);
        $probe->destroy();

        return $namespace;
    }

    /** @return array{0: string, 1: int} [pid namespace, owner pid] */
    private function nameParts(string $path): array
    {
        $shape = '/^' . preg_quote(LspExchangeLock::FILE_PREFIX, '/') . '(\d+)-(\d+)-[0-9a-f]+$/';
        self::assertMatchesRegularExpression($shape, basename($path));
        preg_match($shape, basename($path), $m);

        return [$m[1], (int) $m[2]];
    }

    /** A lock and its three sidecars, named exactly as create() would in $owner. */
    private function setOwnedBy(int $owner, string $dir, string $namespace): string
    {
        $path = $dir . '/' . LspExchangeLock::FILE_PREFIX . $namespace . '-' . $owner . '-' . bin2hex(random_bytes(6));
        touch($path);
        foreach (['.state', '.frame', '.notes'] as $suffix) {
            file_put_contents($path . $suffix, 'stranded');
        }

        return $path;
    }

    /** The pid of a process that has exited and been reaped. */
    private function deadPid(): int
    {
        $pid = $this->forkTracked();
        self::assertNotSame(-1, $pid, 'fork failed');
        if ($pid === 0) {
            ForkedChild::exitNow();
        }
        pcntl_waitpid($pid, $status);
        $this->forgetForkedChild($pid);

        return $pid;
    }
}
