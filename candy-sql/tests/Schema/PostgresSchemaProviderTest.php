<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Schema;

use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\DatabaseInterface;
use SugarCraft\Sql\Schema\PostgresSchemaProvider;
use SugarCraft\Sql\Schema\SchemaColumn;
use SugarCraft\Sql\Schema\SchemaForeignKey;
use SugarCraft\Sql\Schema\SchemaIndex;
use PHPUnit\Framework\TestCase;

/**
 * PostgresSchemaProvider tests using a mock DatabaseInterface.
 */
final class PostgresSchemaProviderTest extends TestCase
{
    /**
     * PDO-loyal substitute for `PDO::quote()` on pdo_pgsql — PQescapeString
     * style: single quotes DOUBLE ('' not \'), backslashes stay literal
     * under standard_conforming_strings=on (PG server default since 9.1).
     * addslashes is the wrong grammar here: it backslash-escapes quotes the
     * way the MySQL driver does and would green a hostile-name case that a
     * live PG server rejects. SQL-C10.
     */
    private static function pdoQuote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * Mock whose `query()` records the exact emitted statement and whose
     * `quote()` mirrors pdo_pgsql, so golden-SQL pins exercise the same
     * string the provider would ship to a live server. SQL-C10.
     */
    private function capturingDb(string &$captured): DatabaseInterface
    {
        $mock = $this->createMock(DatabaseInterface::class);
        $mock->method('quote')->willReturnCallback(self::pdoQuote(...));
        $mock->method('query')->willReturnCallback(function (string $sql) use (&$captured): array {
            $captured = $sql;

            return [];
        });

        return $mock;
    }

    private function createMockDb(array $tablesResult, array $columnsResult, array $indexesResult, array $fksResult): DatabaseInterface
    {
        $mock = $this->createMock(DatabaseInterface::class);

        $mock->method('quote')->willReturnCallback(self::pdoQuote(...));

        $mock->method('query')->willReturnCallback(function (string $sql) use ($tablesResult, $columnsResult, $indexesResult, $fksResult) {
            if (str_contains($sql, 'information_schema.tables')) {
                return $tablesResult;
            }
            if (str_contains($sql, 'information_schema.columns')) {
                return $columnsResult;
            }
            if (str_contains($sql, 'pg_indexes')) {
                return $indexesResult;
            }
            if (str_contains($sql, 'pg_constraint')) {
                return $fksResult;
            }
            return [];
        });

        return $mock;
    }

    public function testTablesReturnsTableNames(): void
    {
        $db = $this->createMockDb(
            [['table_name' => 'users'], ['table_name' => 'posts']],
            [],
            [],
            [],
        );

        $provider = new PostgresSchemaProvider($db);
        $tables = $provider->tables();

        $this->assertSame(['users', 'posts'], $tables);
    }

    public function testTablesReturnsEmptyArrayWhenNoTables(): void
    {
        $db = $this->createMockDb([], [], [], []);

        $provider = new PostgresSchemaProvider($db);
        $this->assertSame([], $provider->tables());
    }

    public function testColumnsReturnsColumnDetails(): void
    {
        $db = $this->createMockDb(
            [],
            [
                ['column_name' => 'id', 'data_type' => 'integer', 'is_nullable' => 'NO', 'column_default' => null, 'ordinal_position' => 1],
                ['column_name' => 'name', 'data_type' => 'text', 'is_nullable' => 'NO', 'column_default' => null, 'ordinal_position' => 2],
            ],
            [],
            [],
        );

        $provider = new PostgresSchemaProvider($db);
        $columns = $provider->columns('users');

        $this->assertCount(2, $columns);

        $idCol = $columns[0];
        $this->assertInstanceOf(SchemaColumn::class, $idCol);
        $this->assertSame('id', $idCol->name);
        $this->assertSame('integer', $idCol->type);
        $this->assertTrue($idCol->notNull);
        $this->assertFalse($idCol->primaryKey);

        $nameCol = $columns[1];
        $this->assertSame('name', $nameCol->name);
        $this->assertSame('text', $nameCol->type);
        $this->assertTrue($nameCol->notNull);
    }

    public function testColumnsReturnsEmptyArrayForNonexistentTable(): void
    {
        $db = $this->createMockDb([], [], [], []);

        $provider = new PostgresSchemaProvider($db);
        $this->assertSame([], $provider->columns('nonexistent'));
    }

    public function testIndexesReturnsIndexDetails(): void
    {
        $db = $this->createMockDb(
            [],
            [],
            [
                ['indexname' => 'users_pkey', 'indexdef' => 'CREATE UNIQUE INDEX users_pkey ON users USING btree (id)'],
                ['indexname' => 'idx_email', 'indexdef' => 'CREATE INDEX idx_email ON users USING btree (email)'],
            ],
            [],
        );

        $provider = new PostgresSchemaProvider($db);
        $indexes = $provider->indexes('users');

        $this->assertCount(2, $indexes);

        $primaryIdx = $indexes[0];
        $this->assertInstanceOf(SchemaIndex::class, $primaryIdx);
        $this->assertSame('users_pkey', $primaryIdx->name);
        $this->assertTrue($primaryIdx->unique);
        $this->assertContains('id', $primaryIdx->columns);

        $emailIdx = $indexes[1];
        $this->assertSame('idx_email', $emailIdx->name);
        $this->assertFalse($emailIdx->unique);
        $this->assertContains('email', $emailIdx->columns);
    }

    public function testIndexesReturnsEmptyArrayForTableWithNoIndexes(): void
    {
        $db = $this->createMockDb([], [], [], []);

        $provider = new PostgresSchemaProvider($db);
        $this->assertSame([], $provider->indexes('simple'));
    }

    public function testForeignKeysReturnsForeignKeyDetails(): void
    {
        // DISCLOSED golden shift (SQL-C09): the canned row used to carry pg attnum
        // integers ('2'/'1' asserted literally) because the query unnested
        // conkey/confkey without joining pg_attribute. The provider now answers
        // column NAMES + real referential actions like its MySQL/SQLite siblings.
        $db = $this->createMockDb(
            [],
            [],
            [],
            [
                ['constraint_name' => 'fk_user', 'column_name' => 'user_id', 'foreign_table_name' => 'users', 'foreign_column_name' => 'id', 'on_update' => 'CASCADE', 'on_delete' => 'RESTRICT'],
            ],
        );

        $provider = new PostgresSchemaProvider($db);
        $fks = $provider->foreignKeys('posts');

        $this->assertCount(1, $fks);

        $fk = $fks[0];
        $this->assertInstanceOf(SchemaForeignKey::class, $fk);
        $this->assertSame('user_id', $fk->column);
        $this->assertSame('users', $fk->foreignTable);
        $this->assertSame('id', $fk->foreignColumn);
        $this->assertSame('CASCADE', $fk->onUpdate);
        $this->assertSame('RESTRICT', $fk->onDelete);
    }

    public function testForeignKeysQueryResolvesAttnumsThroughPgAttribute(): void
    {
        // can-fail pin for the C09 defect itself: capture the emitted SQL and
        // demand the pg_attribute joins + confupdtype/confdeltype mapping —
        // a revert to bare unnest(conkey) projection turns this red.
        $captured = '';
        $mock = $this->createMock(DatabaseInterface::class);
        $mock->method('quote')->willReturnCallback(self::pdoQuote(...));
        $mock->method('query')->willReturnCallback(function (string $sql) use (&$captured): array {
            $captured = $sql;
            return [];
        });

        $provider = new PostgresSchemaProvider($mock);
        $provider->foreignKeys('posts');

        $this->assertStringContainsString('pg_attribute', $captured);
        $this->assertStringContainsString('WITH ORDINALITY', $captured);
        $this->assertStringContainsString('confupdtype', $captured);
        $this->assertStringContainsString('confdeltype', $captured);
        $this->assertStringNotContainsString('unnest(conkey)', $captured);
    }

    public function testForeignKeysReturnsEmptyArrayForTableWithNoForeignKeys(): void
    {
        $db = $this->createMockDb([], [], [], []);

        $provider = new PostgresSchemaProvider($db);
        $this->assertSame([], $provider->foreignKeys('simple'));
    }

    public function testDropTableExecutesDropStatement(): void
    {
        $mock = $this->createMock(DatabaseInterface::class);

        // Identifier::quote() is used instead of DatabaseInterface::quote(),
        // so no quote() call is expected on the database mock.
        $mock->expects($this->never())
            ->method('quote');

        $mock->expects($this->once())
            ->method('exec')
            ->with('DROP TABLE IF EXISTS "users"');

        $provider = new PostgresSchemaProvider($mock);
        $provider->dropTable('users');
    }

    public function testWithFlavorReturnsNewInstance(): void
    {
        $db = $this->createMockDb([], [], [], []);
        $provider = new PostgresSchemaProvider($db);

        // Flavor doesn't change behavior for Postgres, but still returns new instance per interface contract
        $result = $provider->withFlavor(Flavor::Postgres);

        $this->assertInstanceOf(PostgresSchemaProvider::class, $result);
        // Note: Postgres returns $this (same object) since flavor doesn't affect queries
        $this->assertIsObject($result);
    }

    /**
     * SQL-C10 golden pins — kill the self-consistent mock universe. The
     * `str_contains` routers in createMockDb() replay canned rows for ANY
     * statement that mentions the catalog, so alias typos, dropped columns,
     * and lost qualifiers sailed through green. Each provider method now
     * has its exact emitted SQL pinned, one per manual page.
     *
     * PostgreSQL docs info-schema-tables: information_schema.TABLES is
     * session-visible-only, so the query pins schema 'public' explicitly
     * (not current_schema(), matching the provider contract elsewhere).
     */
    public function testTablesEmitsGoldenSql(): void
    {
        $captured = '';
        $provider = new PostgresSchemaProvider($this->capturingDb($captured));
        $provider->tables();

        $this->assertSame(<<<'SQL'
            SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' ORDER BY table_name
            SQL, $captured);
    }

    /**
     * PostgreSQL docs info-schema-columns: DATA_TYPE (no display widths —
     * PG reports 'character varying', unlike MySQL's COLUMN_TYPE), plus
     * ORDINAL_POSITION which the VO re-bases to a zero-indexed cid.
     */
    public function testColumnsEmitsGoldenSql(): void
    {
        $captured = '';
        $provider = new PostgresSchemaProvider($this->capturingDb($captured));
        $provider->columns('users');

        $this->assertSame(<<<'SQL'
            SELECT column_name, data_type, is_nullable, column_default, ordinal_position FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'users' ORDER BY ordinal_position
            SQL, $captured);
    }

    /**
     * PostgreSQL docs pg-indexes view: pg_indexes exposes indexname/indexdef
     * per pg_class row; the VO unique flag is parsed from the definition
     * text, so INDEXDEF must ride the projection (dropping it greens the
     * mock and breaks production parse).
     */
    public function testIndexesEmitsGoldenSql(): void
    {
        $captured = '';
        $provider = new PostgresSchemaProvider($this->capturingDb($captured));
        $provider->indexes('users');

        $this->assertSame(<<<'SQL'
            SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'users' ORDER BY indexname
            SQL, $captured);
    }

    /**
     * PostgreSQL docs catalog-pg-constraint: conkey/confkey are attnum
     * vectors resolved through pg_attribute (WITH ORDINALITY keeps composite
     * legs aligned — SQL-C09's cure); confupdtype/confdeltype map to SQL
     * keyword display values; conrelid::regclass is regclass-cast from the
     * quoted table name.
     */
    public function testForeignKeysEmitsGoldenSql(): void
    {
        $captured = '';
        $provider = new PostgresSchemaProvider($this->capturingDb($captured));
        $provider->foreignKeys('posts');

        $this->assertSame(<<<'SQL'
            SELECT con.conname AS constraint_name, con.confrelid::regclass AS foreign_table_name, a.attname AS column_name, fa.attname AS foreign_column_name, CASE con.confupdtype WHEN 'a' THEN 'NO ACTION' WHEN 'r' THEN 'RESTRICT' WHEN 'c' THEN 'CASCADE' WHEN 'n' THEN 'SET NULL' WHEN 'd' THEN 'SET DEFAULT' END AS on_update, CASE con.confdeltype WHEN 'a' THEN 'NO ACTION' WHEN 'r' THEN 'RESTRICT' WHEN 'c' THEN 'CASCADE' WHEN 'n' THEN 'SET NULL' WHEN 'd' THEN 'SET DEFAULT' END AS on_delete FROM pg_constraint con JOIN unnest(con.conkey, con.confkey) WITH ORDINALITY AS cols(local_attnum, ref_attnum, ord) ON true JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = cols.local_attnum JOIN pg_attribute fa ON fa.attrelid = con.confrelid AND fa.attnum = cols.ref_attnum WHERE con.contype = 'f' AND con.conrelid = 'posts'::regclass
            SQL, $captured);
    }

    /**
     * The mock's quote must match pdo_pgsql semantics, not addslashes: a
     * single quote DOUBLES, a backslash stays literal (standard_conforming_
     * strings=on), and the old MySQL-grammar stub is observably different.
     */
    public function testQuoteStubMirrorsPdoPgsqlNotAddslashes(): void
    {
        $this->assertSame("'o''b'", self::pdoQuote("o'b"));
        $this->assertSame("'a\\b'", self::pdoQuote('a\\b'));
        // Divergence proof — addslashes would backslash-escape the quote:
        $this->assertNotSame("'" . addslashes("o'b") . "'", self::pdoQuote("o'b"));
    }
}
