<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Db;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Db\PreviewQuery;
use SugarCraft\Query\Db\SqliteDatabase;

/**
 * Tests for the blob-safe browse-preview SQL builder.
 *
 * Regression cover for the "cursor onto a LONGBLOB table freezes for minutes"
 * bug: a row preview must never pull blob/binary bytes over the wire — it lists
 * scalars verbatim and replaces blob columns with a size placeholder.
 */
final class PreviewQueryTest extends TestCase
{
    public function testMysqlBuildElidesBlobAndListsScalars(): void
    {
        $cols = PreviewQuery::classify(Flavor::MySQL, [
            ['COLUMN_NAME' => 'media_id', 'DATA_TYPE' => 'int'],
            ['COLUMN_NAME' => 'media_filename', 'DATA_TYPE' => 'varchar'],
            ['COLUMN_NAME' => 'shot_image', 'DATA_TYPE' => 'longblob'],
        ]);
        $sql = PreviewQuery::build(Flavor::MySQL, 'asset_media', $cols, 100);

        // Scalars are listed by name; the LONGBLOB is replaced by a size probe,
        // never selected as raw bytes, and we never fall back to SELECT *.
        $this->assertStringContainsString('`media_id`', $sql);
        $this->assertStringContainsString('`media_filename`', $sql);
        $this->assertStringContainsString("CONCAT('[longblob ', OCTET_LENGTH(`shot_image`), ' bytes]') AS `shot_image`", $sql);
        $this->assertStringNotContainsString('SELECT *', $sql);
        $this->assertStringContainsString('FROM `asset_media` LIMIT 100', $sql);
    }

    public function testMysqlElidesEveryBlobBinarySpatialAndLargeTextType(): void
    {
        foreach (['tinyblob', 'blob', 'mediumblob', 'longblob', 'binary', 'varbinary', 'mediumtext', 'longtext', 'json', 'geometry'] as $type) {
            $cols = PreviewQuery::classify(Flavor::MySQL, [['COLUMN_NAME' => 'c', 'DATA_TYPE' => $type]]);
            $this->assertTrue($cols[0]['elide'], "$type should be elided");
            $this->assertStringContainsString('OCTET_LENGTH(`c`)', PreviewQuery::build(Flavor::MySQL, 't', $cols));
        }
        // Bounded string types stay verbatim.
        foreach (['int', 'varchar', 'char', 'text', 'datetime', 'decimal'] as $type) {
            $cols = PreviewQuery::classify(Flavor::MySQL, [['COLUMN_NAME' => 'c', 'DATA_TYPE' => $type]]);
            $this->assertFalse($cols[0]['elide'], "$type should NOT be elided");
        }
    }

    public function testMysqlColumnsSqlEscapesTableName(): void
    {
        $sql = PreviewQuery::columnsSql(Flavor::MySQL, "o'brien");
        // Table name uses IDENTIFIER escaping (backtick quoting), not VALUE escaping
        $this->assertStringContainsString('table_name = `o\'brien`', $sql);
        $this->assertStringContainsString('DATABASE()', $sql);
    }

    public function testPostgresBuildElidesByteaAndCastsJson(): void
    {
        $cols = PreviewQuery::classify(Flavor::Postgres, [
            ['column_name' => 'id', 'data_type' => 'integer'],
            ['column_name' => 'blob', 'data_type' => 'bytea'],
            ['column_name' => 'doc', 'data_type' => 'jsonb'],
        ]);
        $sql = PreviewQuery::build(Flavor::Postgres, 'files', $cols);

        $this->assertStringContainsString('"id"', $sql);
        // bytea: raw octet_length; jsonb: cast to text first.
        $this->assertStringContainsString('octet_length("blob")', $sql);
        $this->assertStringContainsString('octet_length(("doc")::text)', $sql);
        $this->assertStringContainsString('CURRENT_SCHEMA()', PreviewQuery::columnsSql(Flavor::Postgres, 'files'));
    }

    public function testBuildFallsBackToSelectStarWhenIntrospectionEmpty(): void
    {
        // No column metadata (table not visible / no perms) → still render something.
        $this->assertSame(
            'SELECT * FROM `t` LIMIT 100',
            PreviewQuery::build(Flavor::MySQL, 't', [], 100),
        );
    }

    public function testIdentifierQuotingIsEscaped(): void
    {
        $cols = PreviewQuery::classify(Flavor::MySQL, [['COLUMN_NAME' => 'we`ird', 'DATA_TYPE' => 'int']]);
        $this->assertStringContainsString('`we``ird`', PreviewQuery::build(Flavor::MySQL, 'ta`ble', $cols));
        $this->assertStringContainsString('`ta``ble`', PreviewQuery::build(Flavor::MySQL, 'ta`ble', $cols));
    }

    public function testLimitIsClampedToAtLeastOne(): void
    {
        $cols = PreviewQuery::classify(Flavor::Sqlite, [['name' => 'a', 'type' => 'TEXT']]);
        $this->assertStringContainsString('LIMIT 1', PreviewQuery::build(Flavor::Sqlite, 't', $cols, 0));
    }

    /**
     * The real regression proof: against a live in-memory SQLite holding a
     * ~300 KB BLOB, the preview returns a tiny size placeholder — the raw blob
     * bytes never come back. Before the fix a `SELECT *` pulled all the bytes
     * (×100 rows over a remote link) and froze the TUI for minutes.
     */
    public function testSqliteIntegrationDoesNotTransferBlobBytes(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE media (id INTEGER, label TEXT, payload BLOB)');
        $big = str_repeat("\x00\x01\x02\x03", 75000); // ~300 KB of binary
        $stmt = $pdo->prepare('INSERT INTO media VALUES (1, ?, ?)');
        $stmt->bindValue(1, 'pic');
        $stmt->bindValue(2, $big, \PDO::PARAM_LOB);
        $stmt->execute();
        // A second row with a NULL blob, to prove NULL stays NULL.
        $pdo->exec("INSERT INTO media VALUES (2, 'none', NULL)");

        $db = new SqliteDatabase($pdo);
        $columns = PreviewQuery::classify(
            Flavor::Sqlite,
            $db->query(PreviewQuery::columnsSql(Flavor::Sqlite, 'media')),
        );
        $rows = $db->query(PreviewQuery::build(Flavor::Sqlite, 'media', $columns, 100));

        $this->assertCount(2, $rows);
        // Scalar columns are intact.
        $this->assertSame(1, $rows[0]['id']);
        $this->assertSame('pic', $rows[0]['label']);
        // The blob came back as a size placeholder, NOT the 300 KB of bytes.
        $payload = (string) $rows[0]['payload'];
        $this->assertStringStartsWith('[blob ', $payload);
        $this->assertStringContainsString('300000 bytes]', $payload);
        $this->assertLessThan(64, strlen($payload), 'placeholder must be tiny, not the blob');
        $this->assertStringNotContainsString("\x00\x01\x02", $payload, 'raw blob bytes must never be transferred');
        // NULL blob stays NULL (not "[blob  bytes]").
        $this->assertNull($rows[1]['payload']);
    }
}
