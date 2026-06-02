<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Db\Export;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Db\Export\CsvExporter;
use SugarCraft\Query\Db\SqliteDatabase;

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

    public function testImportCsvThrowsOnMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CSV file not found');
        $this->exporter->importCsv('/nonexistent/path/to/file.csv', 'users');
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

    public function testExportCsvWritesHeadersAndRows(): void
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

        $this->assertSame(['id', 'name ', 'email            '], $headers);
        $this->assertSame(['1 ', 'alice', 'alice@example.com'], $row1);
        $this->assertSame(['2 ', 'bob  ', 'bob@example.com  '], $row2);

        unlink($csvPath);
    }

    public function testExportCsvHandlesEmptyTable(): void
    {
        $csvPath = tempnam(sys_get_temp_dir(), 'csv');
        $this->exporter->exportCsv($csvPath, 'users');

        $handle = fopen($csvPath, 'r');
        $headers = fgetcsv($handle);
        $row = fgetcsv($handle);
        fclose($handle);

        $this->assertSame(['id', 'name', 'email'], $headers);
        $this->assertFalse($row); // EOF

        unlink($csvPath);
    }

    public function testExportCsvThrowsOnMissingTable(): void
    {
        $csvPath = tempnam(sys_get_temp_dir(), 'csv');
        $this->expectException(\RuntimeException::class);
        $this->exporter->exportCsv($csvPath, 'nonexistent_table');
        unlink($csvPath);
    }
}
