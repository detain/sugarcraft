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
 * notification journal and its bound, and owner-only removal of the files.
 */
final class LspExchangeLockTest extends TestCase
{
    use ReapsForkedChildrenTrait;

    private ?LspExchangeLock $lock = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('cross-process exclusion needs ext-pcntl to fork a second process');
        }

        $this->lock = LspExchangeLock::create('test');
    }

    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();
        $this->lock?->destroy();
        $this->lock = null;

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

    private function lock(): LspExchangeLock
    {
        self::assertNotNull($this->lock);

        return $this->lock;
    }
}
