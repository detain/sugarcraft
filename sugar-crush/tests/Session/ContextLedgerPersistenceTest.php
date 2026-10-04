<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Session;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\PruneAuthor;
use SugarCraft\Crush\Context\Pruning\PruneEntry;
use SugarCraft\Crush\Context\Pruning\PruneKind;
use SugarCraft\Crush\Context\Pruning\PruneReason;
use SugarCraft\Crush\Host\TranscriptStore;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Workspace\WorkspaceCheckpointer;

/**
 * Roadmap 2.2-2: a session's context ledger is stored beside its transcript
 * (`context_ledgers`), goes with it when it is branched, is snapshotted by
 * every checkpoint and put back by `/rewind` and `/redo`, and is deleted with
 * it (DCP #557).
 *
 * @see EnhancedSessionStore::saveContextLedger()
 * @see EnhancedSessionStore::loadContextLedger()
 */
final class ContextLedgerPersistenceTest extends TestCase
{
    private string $dir;

    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->dir = (string) realpath(sys_get_temp_dir()) . '/sc_ledger_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
        $this->store = new EnhancedSessionStore($this->dir . '/session.db');
        $this->store->createSession('s', 'p', 'm');
    }

    protected function tearDown(): void
    {
        EnhancedSessionStore::forgetAnnouncedSnapshots();
        WorkspaceCheckpointer::forgetDisabled();
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        @rmdir($this->dir);
    }

    public function testALedgerIsSavedAndReadBackAndReplacedWholesale(): void
    {
        $this->assertNull($this->store->loadContextLedger('s'), 'no ledger until one is saved');

        $this->assertTrue($this->store->saveContextLedger('s', self::ledger('a')));
        $this->assertEquals(self::ledger('a'), $this->store->loadContextLedger('s'));

        $this->store->saveContextLedger('s', self::ledger('b'));
        $this->assertSame(['b'], array_keys($this->store->loadContextLedger('s')?->prunes ?? []), 'one row per session, replaced');

        // A second handle on the same file reads the same ledger: it is in
        // the database, not in this instance.
        $this->assertEquals(self::ledger('b'), (new EnhancedSessionStore($this->dir . '/session.db'))->loadContextLedger('s'));
    }

    public function testAnUnknownSessionIsRefusedQuietly(): void
    {
        $this->assertFalse($this->store->saveContextLedger('no-such-session', self::ledger('a')));
        $this->assertNull($this->store->loadContextLedger('no-such-session'));
    }

    public function testABranchStartsWithItsParentsLedger(): void
    {
        $this->store->saveContextLedger('s', self::ledger('a'));
        $this->store->saveCheckpoint('s', ['messages' => ['m0'], 'inputBuf' => '']);

        $branch = $this->store->forkSession('s');

        $this->assertEquals(self::ledger('a'), $this->store->loadContextLedger($branch));
        $this->store->saveContextLedger($branch, self::ledger('b'));
        $this->assertEquals(self::ledger('a'), $this->store->loadContextLedger('s'), 'the copy is the branch\'s own');

        // The copied checkpoint kept the ledger it was taken with.
        $this->store->restoreCheckpoint($branch, 0);
        $this->assertEquals(self::ledger('a'), $this->store->loadContextLedger($branch));
    }

    public function testARewindPutsBackTheLedgerItsCheckpointWasTakenWithAndRedoReturns(): void
    {
        $this->store->saveContextLedger('s', self::ledger('first'));
        $this->store->saveCheckpoint('s', ['messages' => ['m0'], 'inputBuf' => '']);
        $this->store->saveContextLedger('s', self::ledger('second'));
        $this->store->saveCheckpoint('s', ['messages' => ['m0', 'm1'], 'inputBuf' => '']);
        $this->store->saveContextLedger('s', self::ledger('current'));

        $this->store->restoreCheckpoint('s', 0, ['messages' => ['m0', 'm1', 'm2'], 'inputBuf' => '']);
        $this->assertEquals(self::ledger('first'), $this->store->loadContextLedger('s'));

        $this->store->redoCheckpoint('s');
        $this->assertEquals(self::ledger('second'), $this->store->loadContextLedger('s'));

        $this->store->redoCheckpoint('s');
        $this->assertEquals(self::ledger('current'), $this->store->loadContextLedger('s'), 'the redo tip snapshotted the ledger the rewind left');
    }

    public function testACheckpointTakenBeforeAnyLedgerKeepsTheCurrentOne(): void
    {
        $this->store->saveCheckpoint('s', ['messages' => ['m0'], 'inputBuf' => '']);
        $this->store->saveContextLedger('s', self::ledger('later'));

        $this->store->restoreCheckpoint('s', 0);

        $this->assertEquals(self::ledger('later'), $this->store->loadContextLedger('s'), 'nothing to put back; the next turn syncs it');
    }

    public function testDeletingTheSessionDeletesItsLedger(): void
    {
        $this->store->saveContextLedger('s', self::ledger('a'));

        $this->store->deleteSession('s');

        $this->assertNull($this->store->loadContextLedger('s'));
    }

    public function testATranscriptStoreWithNoDatabaseSavesNothingAndReadsNothing(): void
    {
        $none = TranscriptStore::new(null);

        $this->assertFalse($none->saveLedger('s', self::ledger('a')));
        $this->assertNull($none->loadLedger('s'));

        $this->assertTrue(TranscriptStore::new($this->store)->saveLedger('s', self::ledger('a')));
        $this->assertEquals(self::ledger('a'), TranscriptStore::new($this->store)->loadLedger('s'));
    }

    private static function ledger(string $pruned): ContextLedger
    {
        return ContextLedger::new()
            ->withPrune(new PruneEntry($pruned, PruneKind::Output, PruneReason::Aged, PruneAuthor::Strategy, 321))
            ->withDroppedContextRow(ContextLedger::contextRowKey($pruned), 12)
            ->withRefsAssigned([new \SugarCraft\Crush\Messages\ToolResultMessage($pruned, 'x')]);
    }
}
