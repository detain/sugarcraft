<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Db\Export;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Db\DatabaseInterface;
use SugarCraft\Sql\Db\Export\SqlExporter;
use SugarCraft\Sql\Db\PreparedStatementInterface;
use SugarCraft\Sql\Db\SqliteDatabase;

/**
 * Tests for SqlExporter using in-memory SQLite.
 */
final class SqlExporterTest extends TestCase
{
    private SqliteDatabase $db;
    private SqlExporter $exporter;

    protected function setUp(): void
    {
        $this->db = SqliteDatabase::open(':memory:');
        $this->exporter = new SqlExporter($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    public function testExportSqlGeneratesInsertStatements(): void
    {
        $this->db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
        $this->db->exec("INSERT INTO users VALUES (1, 'alice', 'alice@example.com')");
        $this->db->exec("INSERT INTO users VALUES (2, 'bob', 'bob@example.com')");

        $sqlPath = tempnam(sys_get_temp_dir(), 'sql');
        $this->exporter->exportSql($sqlPath);

        $content = file_get_contents($sqlPath);

        // Should contain header
        $this->assertStringContainsString('-- SugarCraft Database Dump', $content);
        $this->assertStringContainsString('-- Generated:', $content);

        // Should contain INSERT statements with driver-correct identifier
        // quoting. SQLite uses standard double-quote identifiers (not MySQL
        // backticks), derived from the connection's driver.
        $this->assertStringContainsString('INSERT INTO "users" ("id", "name", "email") VALUES (1, \'alice\', \'alice@example.com\');', $content);
        $this->assertStringContainsString('INSERT INTO "users" ("id", "name", "email") VALUES (2, \'bob\', \'bob@example.com\');', $content);
        // Regression guard: the hardcoded-MySQL bug emitted backticks on SQLite.
        $this->assertStringNotContainsString('`users`', $content);

        unlink($sqlPath);
    }

    public function testExportSqlHandlesNullValues(): void
    {
        $this->db->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT, description TEXT)');
        $this->db->exec("INSERT INTO items VALUES (1, 'item1', 'has description')");
        $this->db->exec("INSERT INTO items VALUES (2, 'item2', NULL)");

        $sqlPath = tempnam(sys_get_temp_dir(), 'sql');
        $this->exporter->exportSql($sqlPath);

        $content = file_get_contents($sqlPath);

        // NULL values should be emitted as NULL keyword
        $this->assertStringContainsString('NULL', $content);

        unlink($sqlPath);
    }

    public function testExportSqlHandlesEmptyDatabase(): void
    {
        $sqlPath = tempnam(sys_get_temp_dir(), 'sql');
        $this->exporter->exportSql($sqlPath);

        $content = file_get_contents($sqlPath);

        // Should have header but no INSERT statements
        $this->assertStringContainsString('-- SugarCraft Database Dump', $content);
        $this->assertStringNotContainsString('INSERT', $content);

        unlink($sqlPath);
    }

    public function testExportSqlQuotesValuesCorrectly(): void
    {
        $this->db->exec('CREATE TABLE test (id INTEGER PRIMARY KEY, value TEXT)');
        // Insert a value that needs escaping (single quote)
        $this->db->exec("INSERT INTO test VALUES (1, 'O''Brien')");

        $sqlPath = tempnam(sys_get_temp_dir(), 'sql');
        $this->exporter->exportSql($sqlPath);

        $content = file_get_contents($sqlPath);

        // db->quote should escape the single quote properly
        // SQLite's quote function returns 'O''Brien' (with escaped quote)
        $this->assertStringContainsString("'O''Brien'", $content);

        unlink($sqlPath);
    }

    public function testExportSqlNoDoubleQuoting(): void
    {
        $this->db->exec('CREATE TABLE test (id INTEGER PRIMARY KEY, value TEXT)');
        $this->db->exec("INSERT INTO test VALUES (1, 'simple')");

        $sqlPath = tempnam(sys_get_temp_dir(), 'sql');
        $this->exporter->exportSql($sqlPath);

        $content = file_get_contents($sqlPath);

        // Should NOT have ''value'' (double quoting bug)
        $this->assertStringNotContainsString("''simple''", $content);
        // Should have single quoted value
        $this->assertStringContainsString("'simple'", $content);

        unlink($sqlPath);
    }

    public function testExportSqlUsesPostgresQuotingForPostgresDriver(): void
    {
        // A PostgreSQL-flavored connection must yield double-quoted identifiers;
        // MySQL backticks are a syntax error in PostgreSQL. Quoting is derived
        // from the connection's driver, not hardcoded to MySQL.
        $db = new SqlExporterFakePgDb();
        $exporter = new SqlExporter($db);

        $sqlPath = tempnam(sys_get_temp_dir(), 'sql');
        $exporter->exportSql($sqlPath);
        $content = file_get_contents($sqlPath);
        unlink($sqlPath);

        $this->assertStringContainsString('INSERT INTO "widgets" ("id", "label")', $content);
        $this->assertStringNotContainsString('`widgets`', $content);
        $this->assertStringNotContainsString('`id`', $content);
    }

    public function testExportSqlDumpsEveryRowNotJustTheFirstHundred(): void
    {
        // SQL-C04 (client-004): the dump used to stop at rows()' 100-row
        // default — through the REAL driver: 250 in, 250 INSERT lines out.
        $this->db->exec('CREATE TABLE logs (id INTEGER PRIMARY KEY, msg TEXT)');
        for ($i = 1; $i <= 250; $i++) {
            $this->db->exec("INSERT INTO logs (id, msg) VALUES ($i, 'line $i')");
        }

        $sqlPath = tempnam(sys_get_temp_dir(), 'sql');
        $this->exporter->exportSql($sqlPath);
        $content = (string) file_get_contents($sqlPath);
        unlink($sqlPath);

        $inserts = preg_grep('/^INSERT INTO/', explode("\n", $content));
        $this->assertCount(250, $inserts, 'every row dumped (was: 100 at most)');
        $this->assertStringContainsString("'line 250'", $content, 'the last page must reach the file');
    }

    public function testExportFakeHonoursThePagingWindowNegativePin(): void
    {
        // SQL-C04 acceptance: the export fakes stopped accepting-and-ignoring
        // $limit — they window now, so the exporter loop is actually exercised.
        $db = new SqlExporterFakePgDb();
        $this->assertCount(1, $db->rows('widgets', 100, 0));
        $this->assertCount(0, $db->rows('widgets', 100, 1));
        $this->assertCount(0, $db->rows('widgets', 0, 0));
    }

    public function testExportSqlRefusesLoudlyWhenADriverIgnoresTheOffsetWindow(): void
    {
        // SQL-C04 belt: a dialect that drops the OFFSET would re-serve one
        // full page forever; the COUNT verify must halt the loop and DELETE
        // the partial file instead of shipping duplicated rows as complete.
        $db = new OffsetDroppingSqlFakeDb();
        $exporter = new SqlExporter($db);

        $sqlPath = tempnam(sys_get_temp_dir(), 'sql');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('refusing to present a partial file as complete');
            $exporter->exportSql($sqlPath);
        } finally {
            $this->assertFalse(file_exists($sqlPath), 'the refused partial dump is deleted');
        }
    }
}

/**
 * Driver fake that IGNORES the paging offset (SQL-C04 belt negative pin):
 * every window request answers with a full identical page, exactly what a
 * dialect without OFFSET would do. COUNT answers 250, so the exporter's
 * verify leg must trip.
 */
final class OffsetDroppingSqlFakeDb implements DatabaseInterface
{
    public function tables(): array
    {
        return ['users'];
    }

    public function rows(string $table, int $limit = 100, int $offset = 0): array
    {
        $page = [];
        for ($i = 0; $i < max(0, $limit); $i++) {
            $page[] = ['id' => 1];
        }
        return $page;
    }

    public function query(string $sql): array
    {
        return str_contains($sql, 'COUNT') ? [['export_count' => 250]] : [['id' => 1]];
    }

    public function exec(string $sql): int
    {
        return 0;
    }

    public function lastInsertId(): string|int
    {
        return 0;
    }

    public function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    public function close(): void
    {
    }

    public function serverVersion(): string
    {
        return 'SQLite version 3.45.0';
    }

    public function driverName(): string
    {
        return 'sqlite';
    }

    public function ping(): bool
    {
        return true;
    }

    public function databases(): array
    {
        return [];
    }

    public function prepare(string $sql): ?PreparedStatementInterface
    {
        return null;
    }

    public function dsn(): string
    {
        return '';
    }

    public function username(): string
    {
        return '';
    }
}

/**
 * Minimal PostgreSQL-flavored fake: reports driverName() 'pgsql' and returns a
 * single widgets row so SqlExporter emits one INSERT with driver-correct quoting.
 */
final class SqlExporterFakePgDb implements DatabaseInterface
{
    public function tables(): array
    {
        return ['widgets'];
    }

    public function rows(string $table, int $limit = 100, int $offset = 0): array
    {
        // Honour the paging window (SQL-C04): a fake that ignores $limit/$offset
        // makes the exporter's page loop untestable and the suite blind.
        return array_slice([['id' => 1, 'label' => 'x']], $offset, $limit);
    }

    public function query(string $sql): array
    {
        return [['id' => 1, 'label' => 'x']];
    }

    public function exec(string $sql): int
    {
        return 0;
    }

    public function lastInsertId(): string|int
    {
        return 0;
    }

    public function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    public function close(): void
    {
    }

    public function serverVersion(): string
    {
        return 'PostgreSQL 16.2';
    }

    public function driverName(): string
    {
        return 'pgsql';
    }

    public function ping(): bool
    {
        return true;
    }

    public function databases(): array
    {
        return [];
    }

    public function prepare(string $sql): ?PreparedStatementInterface
    {
        return null;
    }

    public function dsn(): string
    {
        return '';
    }

    public function username(): string
    {
        return '';
    }
}