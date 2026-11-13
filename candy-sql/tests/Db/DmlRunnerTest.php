<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Db;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Db\DmlBuilder;
use SugarCraft\Sql\Db\DmlRunner;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\SqliteDatabase;

/**
 * SQL-O03 transaction semantics, against a real SQLite connection: commit on
 * exactly the promised row count, roll back on any other exit, and leave the
 * data byte-identical after a refusal.
 */
final class DmlRunnerTest extends TestCase
{
    private \PDO $pdo;
    private SqliteDatabase $db;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, tag TEXT)');
        $this->pdo->exec("INSERT INTO users (id, name, tag) VALUES (1, 'ann', 'x'), (2, 'bob', 'x')");
        $this->db = new SqliteDatabase($this->pdo);
    }

    /** @return list<array<string,mixed>> */
    private function dump(): array
    {
        return $this->pdo->query('SELECT * FROM users ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function testUpdateCommitsOneRow(): void
    {
        $n = DmlRunner::run($this->db, DmlBuilder::new(Flavor::Sqlite)->update('users', ['id' => 2], 'name', 'robert'));

        $this->assertSame(1, $n);
        $this->assertSame('robert', $this->dump()[1]['name']);
        $this->assertFalse($this->pdo->inTransaction(), 'the transaction is closed');
    }

    public function testInsertAndDeleteRoundTrip(): void
    {
        $b = DmlBuilder::new(Flavor::Sqlite);
        $this->assertSame(1, DmlRunner::run($this->db, $b->insert('users', ['name' => 'cy', 'tag' => null])));
        $this->assertCount(3, $this->dump());
        $this->assertNull($this->dump()[2]['tag']);

        $this->assertSame(1, DmlRunner::run($this->db, $b->delete('users', ['id' => 1])));
        $this->assertSame([2, 3], array_map('intval', array_column($this->dump(), 'id')));
    }

    public function testUpdateWritingTheSameValueIsBenignZeroOrOne(): void
    {
        $n = DmlRunner::run($this->db, DmlBuilder::new(Flavor::Sqlite)->update('users', ['id' => 1], 'name', 'ann'));

        $this->assertContains($n, [0, 1], 'SQLite reports the matched row, MySQL reports 0 for an identical value');
        $this->assertSame('ann', $this->dump()[0]['name']);
    }

    public function testDeleteOfAMissingRowRollsBackAndRefuses(): void
    {
        $before = $this->dump();
        try {
            DmlRunner::run($this->db, DmlBuilder::new(Flavor::Sqlite)->delete('users', ['id' => 99]));
            $this->fail('0 rows on a delete is not the promised 1');
        } catch (\RangeException $e) {
            $this->assertSame('0', $e->getMessage());
        }
        $this->assertSame($before, $this->dump());
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function testStatementTouchingTooManyRowsNeverCommits(): void
    {
        // A hand-built statement that ignores the key: the count guard is the
        // last line of defence behind the PK addressing.
        $loose = new \SugarCraft\Sql\Db\DmlStatement(
            \SugarCraft\Sql\Db\DmlStatement::UPDATE,
            'UPDATE "users" SET "tag" = ?',
            ['changed'],
            'UPDATE ...',
        );
        $before = $this->dump();

        try {
            DmlRunner::run($this->db, $loose);
            $this->fail('2 rows touched while 1 promised');
        } catch (\RangeException $e) {
            $this->assertSame('2', $e->getMessage());
        }
        $this->assertSame($before, $this->dump(), 'rolled back — no row changed');
    }

    public function testConstraintViolationRollsBackAndSurfaces(): void
    {
        $before = $this->dump();

        $this->expectException(\PDOException::class);
        try {
            DmlRunner::run($this->db, DmlBuilder::new(Flavor::Sqlite)->update('users', ['id' => 1], 'name', null));
        } finally {
            $this->assertSame($before, $this->dump());
            $this->assertFalse($this->pdo->inTransaction());
        }
    }

    public function testReadOnlyDatabaseRefusesWithoutChangingData(): void
    {
        $this->pdo->exec('PRAGMA query_only = ON');
        $before = $this->dump();

        try {
            DmlRunner::run($this->db, DmlBuilder::new(Flavor::Sqlite)->update('users', ['id' => 1], 'name', 'zed'));
            $this->fail('a read-only database must refuse the write');
        } catch (\PDOException $e) {
            $this->assertStringContainsStringIgnoringCase('readonly', $e->getMessage());
        }
        $this->assertSame($before, $this->dump());
    }
}
