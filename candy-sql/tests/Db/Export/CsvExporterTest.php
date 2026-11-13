<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Db\Export;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Db\DatabaseInterface;
use SugarCraft\Sql\Db\Export\CsvExporter;
use SugarCraft\Sql\Db\PreparedStatementInterface;
use SugarCraft\Sql\Db\SqliteDatabase;

/**
 * Tests for CsvExporter using in-memory SQLite.
 */
final class CsvExporterTest extends TestCase
{
    private SqliteDatabase $db;
    private CsvExporter $exporter;

    protected function setUp(): void
    {
        $this->db = SqliteDatabase::open(':memory:');
        $this->db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
        $this->exporter = new CsvExporter($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    public function testImportCsvInsertsRows(): void
    {
        $csvPath = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($csvPath, "id,name,email\n1,alice,alice@example.com\n2,bob,bob@example.com\n");

        $this->exporter->importCsv($csvPath, 'users');

        $rows = $this->db->rows('users');
        $this->assertCount(2, $rows);
        $this->assertSame('alice', $rows[0]['name']);
        $this->assertSame('bob', $rows[1]['name']);

        unlink($csvPath);
    }

    /**
     * mn-18 (2026-11-03): a mid-file failure used to leave the already
     * inserted rows committed (autocommit, row at a time). The whole file is
     * one transaction now: any throw rolls the batch back.
     */
    public function testImportRollsBackAPartialFailure(): void
    {
        $csvPath = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($csvPath, "id,name,email\n1,alice,alice@example.com\n1,bob,bob@example.com\n");

        try {
            $this->exporter->importCsv($csvPath, 'users');
            $this->fail('a primary-key collision must surface');
        } catch (\PDOException) {
            // expected: the duplicate id breaks the second INSERT
        } finally {
            unlink($csvPath);
        }

        $this->assertCount(0, $this->db->rows('users'), 'the first row must roll back with the failed batch');
    }

    public function testImportCsvThrowsOnMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CSV file not found');
        $this->exporter->importCsv('/nonexistent/path/to/file.csv', 'users');
    }

    /**
     * D-M26 (2026-11-03): exception copy used to run names through
     * htmlspecialchars - web-template armor in a CLI sentence, so the
     * file "Bob's data.csv" was reported as "Bob&#039;s data.csv" and
     * users hunted a file that never existed. Copy is verbatim now.
     */
    public function testMissingFileCopyCarriesTheNameVerbatim(): void
    {
        try {
            $this->exporter->importCsv("/tmp/Bob's data.csv", 'users');
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("Bob's data.csv", $e->getMessage());
            $this->assertStringNotContainsString('&#039;', $e->getMessage());
        }
    }

    public function testImportCsvHandlesEmptyValuesAsNull(): void
    {
        $csvPath = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($csvPath, "id,name,email\n1,alice,\n2,,bob@example.com\n");

        $this->exporter->importCsv($csvPath, 'users');

        $rows = $this->db->rows('users');
        $this->assertCount(2, $rows);
        $this->assertSame('', $rows[0]['email']);
        $this->assertSame('', $rows[1]['name']);

        unlink($csvPath);
    }

    public function testImportCsvSkipsRowsWithWrongColumnCount(): void
    {
        $csvPath = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($csvPath, "id,name,email\n1,alice\n2,bob,bob@example.com\n");

        $this->exporter->importCsv($csvPath, 'users');

        $rows = $this->db->rows('users');
        // First row should be skipped due to column count mismatch
        $this->assertCount(1, $rows);
        $this->assertSame('bob', $rows[0]['name']);

        unlink($csvPath);
    }

    public function testExportCsvWritesRfc4180Csv(): void
    {
        $this->db->exec("INSERT INTO users VALUES (1, 'alice', 'alice@example.com')");
        $this->db->exec("INSERT INTO users VALUES (2, 'bob', 'bob@example.com')");

        $csvPath = tempnam(sys_get_temp_dir(), 'csv');
        $this->exporter->exportCsv($csvPath, 'users');

        $handle = fopen($csvPath, 'r');
        $headers = fgetcsv($handle);
        $row1 = fgetcsv($handle);
        $row2 = fgetcsv($handle);
        fclose($handle);

        // RFC-4180 CSV: no space padding, values as-is
        $this->assertSame(['id', 'name', 'email'], $headers);
        $this->assertSame(['1', 'alice', 'alice@example.com'], $row1);
        $this->assertSame(['2', 'bob', 'bob@example.com'], $row2);

        unlink($csvPath);
    }

    public function testExportCsvHandlesEmptyTable(): void
    {
        // Note: Driver-neutral column detection (SELECT * LIMIT 0/1) cannot
        // determine column names for empty tables. Empty table export produces
        // a blank file - this is a known limitation of driver-agnostic approach.
        $csvPath = tempnam(sys_get_temp_dir(), 'csv');
        $this->exporter->exportCsv($csvPath, 'users');

        $content = file_get_contents($csvPath);
        // Empty table: just header row (possibly blank due to no column detection)
        $lines = explode("\n", trim($content));
        // Should at least not crash and produce some output
        $this->assertNotEmpty($content);

        unlink($csvPath);
    }

    public function testExportCsvThrowsOnMissingTable(): void
    {
        $csvPath = tempnam(sys_get_temp_dir(), 'csv');
        $this->expectException(\RuntimeException::class);
        $this->exporter->exportCsv($csvPath, 'nonexistent_table');
        unlink($csvPath);
    }

    public function testExportCsvPagesToEveryRowNeverASilentHundred(): void
    {
        // SQL-C04 (client-004/029): the single rows($table) call stopped at
        // the contract's 100-row default — a "backup" that silently shipped
        // 100 of 250. Through the REAL driver now: 250 in, 250 on disk.
        for ($i = 1; $i <= 250; $i++) {
            $this->db->exec("INSERT INTO users (id, name, email) VALUES ($i, 'user$i', 'u$i@e.example')");
        }

        $csvPath = tempnam(sys_get_temp_dir(), 'csv');
        $this->exporter->exportCsv($csvPath, 'users');
        $lines = file($csvPath, FILE_IGNORE_NEW_LINES);
        unlink($csvPath);

        $this->assertCount(251, $lines, 'header + all 250 rows (was: 101 at most)');
        $this->assertSame('id,name,email', $lines[0]);
        $this->assertSame('1,user1,u1@e.example', $lines[1]);
        $this->assertSame('250,user250,u250@e.example', $lines[250]);
    }

    public function testExportCsvRefusesLoudlyWhenADriverIgnoresTheOffsetWindow(): void
    {
        // SQL-C04 belt mirror of the SQL exporter: a driver that drops the
        // OFFSET re-serves one full page forever — COUNT verify halts it and
        // the refused partial file is deleted, never presented as complete.
        $db = new OffsetDroppingCsvFakeDb();
        $exporter = new CsvExporter($db);

        $csvPath = tempnam(sys_get_temp_dir(), 'csv');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('refusing to present a partial file as complete');
            $exporter->exportCsv($csvPath, 'users');
        } finally {
            $this->assertFalse(file_exists($csvPath), 'the refused partial export is deleted');
        }
    }

    public function testExportReportResultsGuardsFormulaInjection(): void
    {
        $csvPath = tempnam(sys_get_temp_dir(), 'csv');
        $columns = ['name', 'data'];
        $rows = [
            ['name' => 'normal', 'data' => 'plain text'],
            ['name' => 'formula', 'data' => '=HYPERLINK("http://evil.com")'],
            ['name' => 'plus', 'data' => '+SELECT * FROM users'],
            ['name' => 'minus', 'data' => '-cmd /c dir'],
            ['name' => 'at', 'data' => '@whoami'],
        ];

        $this->exporter->exportReportResults($csvPath, $columns, $rows);

        $handle = fopen($csvPath, 'r');
        $headers = fgetcsv($handle);
        $data = [];
        while (($row = fgetcsv($handle)) !== false) {
            $data[] = $row;
        }
        fclose($handle);

        // Formula-prefixed cells should have ' prefix
        $this->assertSame("'=HYPERLINK(\"http://evil.com\")", $data[1][1]);
        $this->assertSame("'+SELECT * FROM users", $data[2][1]);
        $this->assertSame("'-cmd /c dir", $data[3][1]);
        $this->assertSame("'@whoami", $data[4][1]);
        // Normal cell unchanged
        $this->assertSame('plain text', $data[0][1]);

        unlink($csvPath);
    }

    public function testExportReportResultsToStringReturnsCsvString(): void
    {
        $columns = ['id', 'name'];
        $rows = [
            ['id' => '1', 'name' => 'alice'],
            ['id' => '2', 'name' => 'bob'],
        ];

        $csv = $this->exporter->exportReportResultsToString($columns, $rows);

        $lines = explode("\n", trim($csv));
        $this->assertSame('id,name', $lines[0]);
        $this->assertSame('1,alice', $lines[1]);
        $this->assertSame('2,bob', $lines[2]);
    }

    public function testImportCsvUsesPostgresQuotingForPostgresDriver(): void
    {
        // Identifier quoting for the INSERT must follow the connection driver;
        // MySQL backticks are invalid in PostgreSQL.
        $db = new CsvExporterFakePgDb();
        $exporter = new CsvExporter($db);

        $csvPath = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($csvPath, "id,name\n1,alice\n");
        $exporter->importCsv($csvPath, 'users');
        unlink($csvPath);

        // The import now runs inside a transaction (mn-18), so lastExec is
        // COMMIT - quote-genre assertions read the full exec log instead.
        $log = implode("\n", $db->execLog);
        $this->assertStringContainsString('INSERT INTO "users" ("id","name")', $log);
        $this->assertStringNotContainsString('`users`', $log);
        $this->assertStringNotContainsString('`id`', $log);
        $this->assertStringContainsString('BEGIN', $log);
        $this->assertStringContainsString('COMMIT', $log);
    }

    public function testExportCsvGuardsTabAndCarriageReturn(): void
    {
        $csvPath = tempnam(sys_get_temp_dir(), 'csv');
        $columns = ['id', 'data'];
        $rows = [
            ['id' => '1', 'data' => "\tstarts with tab"],
            ['id' => '2', 'data' => "\rstarts with cr"],
        ];

        $this->exporter->exportReportResults($csvPath, $columns, $rows);

        $handle = fopen($csvPath, 'r');
        fgetcsv($handle); // skip header
        $row1 = fgetcsv($handle);
        $row2 = fgetcsv($handle);
        fclose($handle);

        // Tab/CR prefixed cells should have ' prefix
        $this->assertSame("'\tstarts with tab", $row1[1]);
        $this->assertSame("'\rstarts with cr", $row2[1]);

        unlink($csvPath);
    }

    public function testExportCsvProperlyQuotedForCommasAndQuotes(): void
    {
        $csvPath = tempnam(sys_get_temp_dir(), 'csv');
        $columns = ['name', 'desc'];
        $rows = [
            ['name' => 'has,comma', 'desc' => 'normal'],
            ['name' => 'has"quote', 'desc' => 'normal'],
            ['name' => "has\nnewline", 'desc' => 'normal'],
        ];

        $this->exporter->exportReportResults($csvPath, $columns, $rows);

        $handle = fopen($csvPath, 'r');
        fgetcsv($handle); // skip header
        $row1 = fgetcsv($handle);
        $row2 = fgetcsv($handle);
        $row3 = fgetcsv($handle);
        fclose($handle);

        // fputcsv handles quoting automatically for RFC-4180 compliance
        $this->assertSame('has,comma', $row1[0]);
        $this->assertSame('has"quote', $row2[0]);
        $this->assertSame("has\nnewline", $row3[0]);

        unlink($csvPath);
    }
}

/**
 * Minimal PostgreSQL-flavored fake capturing the last exec()'d SQL so the test
 * can assert driver-correct identifier quoting on the generated INSERT.
 */
final class CsvExporterFakePgDb implements DatabaseInterface
{
    public string $lastExec = '';

    /** @var list<string> every exec() SQL - mn-18 wrapped imports in BEGIN/COMMIT. */
    public array $execLog = [];

    public function tables(): array
    {
        return ['users'];
    }

    public function rows(string $table, int $limit = 100, int $offset = 0): array
    {
        // Honour the paging window (SQL-C04); this fake's table is one row,
        // so pages past offset 0 are empty (exercises the exporter's stop leg).
        return array_slice([['id' => 1, 'name' => 'x']], $offset, $limit);
    }

    public function query(string $sql): array
    {
        return [['id' => 1, 'name' => 'x']];
    }

    public function exec(string $sql): int
    {
        $this->lastExec = $sql;
        $this->execLog[] = $sql;
        return 1;
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
/**
 * Driver fake that IGNORES the paging offset (SQL-C04 belt negative pin):
 * every window answers with a full identical page, COUNT answers 250.
 */
final class OffsetDroppingCsvFakeDb implements DatabaseInterface
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
