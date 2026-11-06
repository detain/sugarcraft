<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests;

use SugarCraft\Sql\Db\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class SqliteDatabaseTest extends TestCase
{
    /** @var list<string> temp databases, unlinked in tearDown even on failure paths */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    private function makeInMemory(): SqliteDatabase
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return new SqliteDatabase($pdo, ':memory:');
    }

    // ── rows() identifier escaping ───────────────────────────────────────────

    public function testRowsEscapesDoubleQuoteInTableName(): void
    {
        $db = $this->makeInMemory();
        $db->exec('CREATE TABLE "test""table" (id INTEGER)');
        $db->exec("INSERT INTO \"test\"\"table\" VALUES (42)");

        // SqliteDatabase::rows uses sprintf('SELECT * FROM "%s" LIMIT %d', str_replace('"', '""', $table), $limit)
        // which should properly escape the internal double-quote.
        $rows = $db->rows('test"table');
        $this->assertCount(1, $rows);
        $this->assertSame(42, $rows[0]['id']);
    }

    public function testRowsReturnsEmptyArrayForNonexistentTable(): void
    {
        // Note: SqliteDatabase::rows() throws PDOException if table doesn't exist.
        // This is expected behavior - this test just confirms it doesn't silently
        // return a wrong value. The existing DatabaseTest.php covers this case.
        $this->expectException(\PDOException::class);
        $db = $this->makeInMemory();
        $db->rows('nonexistent_table_xyz');
    }

    public function testRowsRespectsLimit(): void
    {
        $db = $this->makeInMemory();
        $db->exec('CREATE TABLE t (id INTEGER)');
        for ($i = 1; $i <= 10; $i++) {
            $db->exec("INSERT INTO t VALUES ($i)");
        }

        $rows = $db->rows('t', 3);
        $this->assertCount(3, $rows);
    }

    // ── query() ────────────────────────────────────────────────────────────────

    public function testQueryReturnsRows(): void
    {
        $db = $this->makeInMemory();
        $db->exec('CREATE TABLE users (id INTEGER, name TEXT)');
        $db->exec("INSERT INTO users VALUES (1, 'Alice')");
        $db->exec("INSERT INTO users VALUES (2, 'Bob')");

        $rows = $db->query('SELECT * FROM users ORDER BY id');
        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows[0]['id']);
        $this->assertSame('Alice', $rows[0]['name']);
    }

    public function testQueryReturnsAffectedForNonSelect(): void
    {
        $db = $this->makeInMemory();
        $db->exec('CREATE TABLE t (id INTEGER)');
        $result = $db->query('INSERT INTO t VALUES (99)');
        $this->assertSame([['affected' => 1]], $result);
    }

    public function testQueryReturnsNullWhenClosed(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db = new SqliteDatabase($pdo, ':memory:');
        $db->close();

        $result = $db->query('SELECT 1');
        $this->assertNull($result);
    }

    // ── exec() ────────────────────────────────────────────────────────────────

    public function testExecReturnsAffectedRowCount(): void
    {
        $db = $this->makeInMemory();
        $db->exec('CREATE TABLE t (id INTEGER, name TEXT)');
        $count = $db->exec("INSERT INTO t VALUES (1, 'a'), (2, 'b'), (3, 'c')");
        $this->assertSame(3, $count);
    }

    public function testExecReturnsZeroForNoAffectedRows(): void
    {
        $db = $this->makeInMemory();
        $db->exec('CREATE TABLE t (id INTEGER)');
        $db->exec('CREATE TABLE t2 (id INTEGER)'); // Different table
        $count = $db->exec('DELETE FROM t2'); // No rows in t2
        $this->assertSame(0, $count);
    }

    // ── open() missing file ───────────────────────────────────────────────────

    public function testOpenThrowsForMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        SqliteDatabase::open('/nonexistent/path/to/db.sqlite');
    }

    public function testOpenWorksWithMemoryDatabase(): void
    {
        $db = SqliteDatabase::open(':memory:');
        $this->assertSame('sqlite::memory:', $db->dsn());
        // Empty in-memory DB has no tables
        $this->assertSame([], $db->tables());
    }

    // ── databases() ───────────────────────────────────────────────────────────

    public function testDatabasesReturnsMemoryForInMemoryDb(): void
    {
        $db = $this->makeInMemory();
        $this->assertSame(['memory'], $db->databases());
    }

    public function testDatabasesReturnsBasenameForFileDb(): void
    {
        $tmpPath = '/tmp/test_db_' . uniqid() . '.db';
        $this->tempFiles[] = $tmpPath;
        $pdo = new \PDO('sqlite:' . $tmpPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db = new SqliteDatabase($pdo, $tmpPath);
        $databases = $db->databases();
        $this->assertSame(basename($tmpPath), $databases[0]);
    }

    // ── close() ──────────────────────────────────────────────────────────────

    public function testCloseSetsPdoToNull(): void
    {
        $db = $this->makeInMemory();
        $this->assertTrue($db->ping());
        $db->close();
        $this->assertFalse($db->ping());
    }

    // ── serverVersion() ─────────────────────────────────────────────────────

    public function testServerVersionReturnsVersionString(): void
    {
        $db = $this->makeInMemory();
        $version = $db->serverVersion();
        $this->assertStringContainsString('SQLite', $version);
    }

    // ── driverName() ─────────────────────────────────────────────────────────

    public function testDriverNameReturnsSqlite(): void
    {
        $db = $this->makeInMemory();
        $this->assertSame('sqlite', $db->driverName());
    }

    // ── ping() ───────────────────────────────────────────────────────────────

    public function testPingReturnsTrueWhenOpen(): void
    {
        $db = $this->makeInMemory();
        $this->assertTrue($db->ping());
    }

    public function testPingReturnsFalseAfterClose(): void
    {
        $db = $this->makeInMemory();
        $db->close();
        $this->assertFalse($db->ping());
    }

    // ── client pragmas (user-database neutrality) ────────────────────────────

    public function testOpenArmsThePerConnectionClientPragmas(): void
    {
        $tmpPath = '/tmp/candy_sql_pragma_' . uniqid() . '.db';
        $this->tempFiles[] = $tmpPath;
        (new \PDO('sqlite:' . $tmpPath))->exec('CREATE TABLE t (x INTEGER)');
        $db = SqliteDatabase::open($tmpPath);
        try {
            $busy = $db->query('PRAGMA busy_timeout');
            $cache = $db->query('PRAGMA cache_size');
            $journal = $db->query('PRAGMA journal_mode');
            $foreign = $db->query('PRAGMA foreign_keys');
            $this->assertSame(5000, $busy[0]['timeout']);
            $this->assertSame(-64000, $cache[0]['cache_size']);
            // The negative pins: a fresh file database answers the header
            // default 'delete' — the instant the client set WAL it would
            // answer 'wal'; foreign_keys stays 0, the SQLite and sqlite3 CLI
            // default this client refuses to override.
            $this->assertSame('delete', $journal[0]['journal_mode']);
            $this->assertSame(0, $foreign[0]['foreign_keys']);
        } finally {
            $db->close();
        }
        // The file-header law, pinned on disk: a bare re-open of the SAME file
        // still answers 'delete' — opening through this client rewrote nothing
        // persistent.
        $bare = new \PDO('sqlite:' . $tmpPath);
        $reopened = $bare->query('PRAGMA journal_mode')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertSame('delete', $reopened[0]['journal_mode']);
    }

    public function testDeprecatedAliasArmsTheSamePostureThroughItsDelegate(): void
    {
        $tmpPath = '/tmp/candy_sql_pragma_alias_' . uniqid() . '.db';
        $this->tempFiles[] = $tmpPath;
        (new \PDO('sqlite:' . $tmpPath))->exec('CREATE TABLE t (x INTEGER)');
        $db = \SugarCraft\Sql\Database::open($tmpPath);
        try {
            $busy = $db->query('PRAGMA busy_timeout');
            $this->assertSame(5000, $busy[0]['timeout'], 'the alias forwards to the same armed delegate — no second copy of the posture');
        } finally {
            $db->close();
        }
    }
}
