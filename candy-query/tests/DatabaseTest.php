<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests;

use SugarCraft\Query\Database;
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

    public function testImportCsvInsertsRows(): void
    {
        $db = $this->memoryDb();
        $db->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');

        $csvPath = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($csvPath, "id,name,email\n1,alice,alice@example.com\n2,bob,bob@example.com\n");

        $db->importCsv($csvPath, 'users');

        $rows = $db->rows('users');
        $this->assertCount(2, $rows);
        $this->assertSame('alice', $rows[0]['name']);
        $this->assertSame('bob', $rows[1]['name']);

        unlink($csvPath);
    }

    public function testImportCsvThrowsOnMissingFile(): void
    {
        $db = $this->memoryDb();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CSV file not found');
        $db->importCsv('/nonexistent/path/to/file.csv', 'users');
    }

    public function testExportCsvWritesHeadersAndRows(): void
    {
        $db = $this->memoryDb();
        $db->pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, price REAL)');
        $db->pdo->exec("INSERT INTO products VALUES (1, 'Widget', 19.99), (2, 'Gadget', 29.99)");

        $csvPath = tempnam(sys_get_temp_dir(), 'csv');
        $db->exportCsv($csvPath, 'products');

        $handle = fopen($csvPath, 'r');
        $headers = fgetcsv($handle);
        $row1 = fgetcsv($handle);
        $row2 = fgetcsv($handle);
        fclose($handle);

        $this->assertSame(['id', 'name', 'price'], $headers);
        $this->assertSame(['1', 'Widget', '19.99'], $row1);
        $this->assertSame(['2', 'Gadget', '29.99'], $row2);

        unlink($csvPath);
    }

    public function testExportCsvThrowsOnMissingTable(): void
    {
        $db = $this->memoryDb();
        $csvPath = tempnam(sys_get_temp_dir(), 'csv');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Table not found');
        $db->exportCsv($csvPath, 'nonexistent_table');
        unlink($csvPath);
    }

    public function testExportSqlCreatesDumpFile(): void
    {
        $db = $this->memoryDb();
        $db->pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, description TEXT)');
        $db->pdo->exec("INSERT INTO items VALUES (1, 'First item'), (2, 'Second item')");

        $sqlPath = tempnam(sys_get_temp_dir(), 'sql');
        $db->exportSql($sqlPath);

        $content = file_get_contents($sqlPath);
        $this->assertStringContainsString('-- SugarCraft Database Dump', $content);
        $this->assertStringContainsString('CREATE TABLE', $content);
        $this->assertStringContainsString('INSERT INTO', $content);
        $this->assertStringContainsString('First item', $content);

        unlink($sqlPath);
    }

    public function testExportSqlHandlesMultipleTables(): void
    {
        $db = $this->memoryDb();
        $db->pdo->exec('CREATE TABLE t1 (id INTEGER PRIMARY KEY, data TEXT)');
        $db->pdo->exec("INSERT INTO t1 VALUES (1, 'data1')");
        $db->pdo->exec('CREATE TABLE t2 (id INTEGER PRIMARY KEY, info TEXT)');
        $db->pdo->exec("INSERT INTO t2 VALUES (2, 'info2')");

        $sqlPath = tempnam(sys_get_temp_dir(), 'sql');
        $db->exportSql($sqlPath);

        $content = file_get_contents($sqlPath);
        $this->assertStringContainsString('CREATE TABLE', $content);
        $this->assertStringContainsString('t1', $content);
        $this->assertStringContainsString('t2', $content);
        $this->assertStringContainsString('data1', $content);
        $this->assertStringContainsString('info2', $content);

        unlink($sqlPath);
    }
}
