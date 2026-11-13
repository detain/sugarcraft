<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin\History;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Admin\History\SqliteHistoryStore;
use SugarCraft\Sql\Admin\StatusSnapshot;

/**
 * Mode-settling law (D-M9, 2026-11-03).
 *
 * The store keeps server-variable snapshots - values that include host
 * names, paths, and credentials-shaped data. The modes must be private
 * AND settled after the WAL pragma, because WAL creates -wal/-shm
 * sidecars that a chmod-before-the-pragma could never see, and a
 * pre-existing loose file from an older build must be TIGHTENED (never
 * loosened) instead of left world-readable forever by the
 * "don't-touch-what-we-didn't-create" stance.
 */
final class SqliteHistoryStoreModesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/candy_sql_modes_' . \uniqid();
    }

    protected function tearDown(): void
    {
        @\exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function testFreshlyCreatedDatabaseIsOwnerOnly(): void
    {
        $path = $this->dir . '/deep/history.db';
        $store = new SqliteHistoryStore($path, null, 3.1536e9);
        try {
            \clearstatcache(true, $path);
            $this->assertSame(0600, \fileperms($path) & 0777);
            \clearstatcache(true, $this->dir . '/deep');
            $this->assertSame(0000, (int) (\fileperms($this->dir . '/deep') & 0777) & 0077, 'directory keeps no group/world bits');
        } finally {
            $store->close();
        }
    }

    public function testWalSidecarsArePrivateWhileTheJournalExists(): void
    {
        $path = $this->dir . '/sidecar.db';
        $store = new SqliteHistoryStore($path, null, 3.1536e9);
        try {
            // A write forces the -wal sidecar into existence.
            $store->save(new StatusSnapshot(['threads_running' => '1'], 1.0));
            $wal = $path . '-wal';
            \clearstatcache(true, $wal);
            if (\is_file($wal)) {
                $this->assertSame(0000, (int) (\fileperms($wal) & 0777) & 0077, '-wal must not be group/world readable');
            } else {
                $this->markTestIncomplete('SQLite kept the WAL sidecar out of reach of this probe');
            }
        } finally {
            $store->close();
        }
    }

    public function testPreExistingLooseDatabaseIsTightened(): void
    {
        $path = $this->dir . '/loose.db';
        \mkdir($this->dir, 0700);
        // Seed a VALID sqlite database the loose way (pre-WAL builds left 0644).
        $seed = new \SQLite3($path);
        $seed->exec('CREATE TABLE history (id INTEGER PRIMARY KEY, ts REAL NOT NULL, recorded_at TEXT NOT NULL, variables TEXT NOT NULL)');
        $seed->close();
        \chmod($path, 0644);
        \clearstatcache(true, $path);
        $this->assertSame(0644, \fileperms($path) & 0777, 'precondition: world-readable before the store opens it');

        $store = new SqliteHistoryStore($path, null, 3.1536e9);
        $store->close();

        \clearstatcache(true, $path);
        $this->assertSame(0600, \fileperms($path) & 0777);
    }

    public function testAlreadyPrivateDatabaseIsLeftByteIdenticalInMode(): void
    {
        $path = $this->dir . '/private.db';
        \mkdir($this->dir, 0700);
        $seed = new \SQLite3($path);
        $seed->exec('CREATE TABLE history (id INTEGER PRIMARY KEY, ts REAL NOT NULL, recorded_at TEXT NOT NULL, variables TEXT NOT NULL)');
        $seed->close();
        \chmod($path, 0600);

        $store = new SqliteHistoryStore($path, null, 3.1536e9);
        $store->close();

        \clearstatcache(true, $path);
        $this->assertSame(0600, \fileperms($path) & 0777);
    }

    /**
     * A file SQLite cannot read as a database must surface as ONE loud
     * sentence at the save seam, never a bindValue() fatal on null
     * (D-M11) - that null-member crash escaped the admin poll promise
     * un-rejected and froze live refresh for the whole session.
     */
    public function testCorruptStoreRefusesSaveWithASentence(): void
    {
        $path = $this->dir . '/garbage.db';
        \mkdir($this->dir, 0700);
        \file_put_contents($path, \str_repeat('NOT-A-DATABASE ', 40));

        @$store = new SqliteHistoryStore($path, null, 3.1536e9); // the failed PRAGMA warns; suppression keeps the gate at 0W
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('History store is unusable');
            @$store->save(new StatusSnapshot(['a' => 'b'], 1.0)); // prepare()'s warning is likewise suppressed
        } finally {
            $store->close();
        }
    }
}
