<?php

declare(strict_types=1);

namespace CandyCore\Query\Tests;

use CandyCore\Query\Database;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    private function memoryDb(): Database
    {
        return new Database(new \PDO('sqlite::memory:'));
    }

    public function testOpenInMemory(): void
    {
        $db = Database::open(':memory:');
        $this->assertInstanceOf(Database::class, $db);
    }

    public function testOpenMissingFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no such SQLite file');
        Database::open('/this/file/does/not/exist.sqlite');
    }

    public function testTablesListsUserTablesAndViews(): void
    {
        $db = $this->memoryDb();
        $db->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $db->pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT)');
        $db->pdo->exec('CREATE VIEW recent_posts AS SELECT * FROM posts');
        $tables = $db->tables();
        $this->assertSame(['posts', 'recent_posts', 'users'], $tables);
    }

    public function testTablesExcludesSqliteSystemTables(): void
    {
        $db = $this->memoryDb();
        $db->pdo->exec('CREATE TABLE foo (a TEXT)');
        // sqlite_sequence is auto-created when using AUTOINCREMENT;
        // sqlite_master is implicit. Make sure it's filtered out.
        $db->pdo->exec('CREATE TABLE bar (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        $tables = $db->tables();
        foreach ($tables as $t) {
            $this->assertStringStartsNotWith('sqlite_', $t);
        }
    }

    public function testTablesEmptyOnFreshDatabase(): void
    {
        $db = $this->memoryDb();
        $this->assertSame([], $db->tables());
    }

    public function testRowsReturnsAssocArrays(): void
    {
        $db = $this->memoryDb();
        $db->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $db->pdo->exec("INSERT INTO users VALUES (1, 'alice'), (2, 'bob')");
        $rows = $db->rows('users');
        $this->assertCount(2, $rows);
        $this->assertSame('alice', $rows[0]['name']);
        $this->assertSame(1, $rows[0]['id']);
    }

    public function testRowsRespectsLimit(): void
    {
        $db = $this->memoryDb();
        $db->pdo->exec('CREATE TABLE n (v INTEGER)');
        for ($i = 0; $i < 50; $i++) {
            $db->pdo->exec("INSERT INTO n VALUES ($i)");
        }
        $rows = $db->rows('n', limit: 5);
        $this->assertCount(5, $rows);
    }

    public function testRowsEscapesQuotedTableName(): void
    {
        $db = $this->memoryDb();
        $db->pdo->exec('CREATE TABLE "tbl with spaces" (a TEXT)');
        $db->pdo->exec('INSERT INTO "tbl with spaces" VALUES (\'hi\')');
        $rows = $db->rows('tbl with spaces');
        $this->assertCount(1, $rows);
        $this->assertSame('hi', $rows[0]['a']);
    }

    public function testQueryReturnsSelectResults(): void
    {
        $db = $this->memoryDb();
        $db->pdo->exec('CREATE TABLE n (v INTEGER)');
        $db->pdo->exec('INSERT INTO n VALUES (1), (2), (3)');
        $rows = $db->query('SELECT v FROM n ORDER BY v DESC');
        $this->assertSame([['v' => 3], ['v' => 2], ['v' => 1]], $rows);
    }

    public function testQueryReturnsAffectedRowsForNonSelect(): void
    {
        $db = $this->memoryDb();
        $db->pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $rows = $db->query('INSERT INTO t VALUES (1), (2), (3)');
        $this->assertSame([['affected' => 3]], $rows);
    }

    public function testQueryThrowsOnInvalidSql(): void
    {
        $db = $this->memoryDb();
        $this->expectException(\PDOException::class);
        $db->query('SELECT * FROM nonexistent');
    }
}
