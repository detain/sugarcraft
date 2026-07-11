<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Db\Export;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Db\Export\SqlExporter;
use SugarCraft\Query\Db\PreparedStatementInterface;
use SugarCraft\Query\Db\SqliteDatabase;

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

    public function rows(string $table, int $limit = 100): array
    {
        return [['id' => 1, 'label' => 'x']];
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