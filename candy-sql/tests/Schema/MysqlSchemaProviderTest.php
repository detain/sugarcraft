<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Schema;

use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\DatabaseInterface;
use SugarCraft\Sql\Schema\MysqlSchemaProvider;
use SugarCraft\Sql\Schema\SchemaColumn;
use SugarCraft\Sql\Schema\SchemaForeignKey;
use SugarCraft\Sql\Schema\SchemaIndex;
use PHPUnit\Framework\TestCase;

/**
 * MysqlSchemaProvider tests using a mock DatabaseInterface.
 */
final class MysqlSchemaProviderTest extends TestCase
{
    /**
     * PDO-loyal substitute for `PDO::quote()` on pdo_mysql —
     * mysql_real_escape_string family (backslash escapes + quote wrap), not
     * addslashes (which mangles nothing for identifiers but silently differs
     * on `"`, `\0`, `\n`, `\r`, and `\x1a`, letting a hostile name green a
     * mock-only test). SQL-C10.
     */
    private static function pdoQuote(string $value): string
    {
        return "'" . str_replace(
            ["\\", "'", '"', "\0", "\n", "\r", "\x1a"],
            ["\\\\", "\\'", '\\"', '\\0', '\\n', '\\r', '\\Z'],
            $value,
        ) . "'";
    }

    /**
     * Mock whose `query()` records the exact emitted statement and whose
     * `quote()` mirrors pdo_mysql, so golden-SQL pins exercise the same
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
            if (str_contains($sql, 'information_schema.statistics')) {
                return $indexesResult;
            }
            if (str_contains($sql, 'information_schema.key_column_usage')) {
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

        $provider = new MysqlSchemaProvider($db);
        $tables = $provider->tables();

        $this->assertSame(['users', 'posts'], $tables);
    }

    public function testTablesReturnsEmptyArrayWhenNoTables(): void
    {
        $db = $this->createMockDb([], [], [], []);

        $provider = new MysqlSchemaProvider($db);
        $this->assertSame([], $provider->tables());
    }

    public function testColumnsReturnsColumnDetails(): void
    {
        $db = $this->createMockDb(
            [],
            [
                ['column_name' => 'id', 'column_type' => 'int', 'is_nullable' => 'NO', 'column_key' => 'PRI', 'column_default' => null],
                ['column_name' => 'name', 'column_type' => 'varchar(255)', 'is_nullable' => 'NO', 'column_key' => '', 'column_default' => null],
            ],
            [],
            [],
        );

        $provider = new MysqlSchemaProvider($db);
        $columns = $provider->columns('users');

        $this->assertCount(2, $columns);

        $idCol = $columns[0];
        $this->assertInstanceOf(SchemaColumn::class, $idCol);
        $this->assertSame('id', $idCol->name);
        $this->assertSame('int', $idCol->type);
        $this->assertTrue($idCol->primaryKey);
        $this->assertTrue($idCol->notNull);

        $nameCol = $columns[1];
        $this->assertSame('name', $nameCol->name);
        $this->assertTrue($nameCol->notNull);
        $this->assertFalse($nameCol->primaryKey);
    }

    public function testColumnsReturnsEmptyArrayForNonexistentTable(): void
    {
        $db = $this->createMockDb([], [], [], []);

        $provider = new MysqlSchemaProvider($db);
        $this->assertSame([], $provider->columns('nonexistent'));
    }

    public function testIndexesReturnsIndexDetails(): void
    {
        $db = $this->createMockDb(
            [],
            [],
            [
                ['index_name' => 'PRIMARY', 'column_name' => 'id', 'non_unique' => 0],
                ['index_name' => 'idx_email', 'column_name' => 'email', 'non_unique' => 0],
            ],
            [],
        );

        $provider = new MysqlSchemaProvider($db);
        $indexes = $provider->indexes('users');

        $this->assertCount(2, $indexes);

        $primaryIdx = $indexes[0];
        $this->assertInstanceOf(SchemaIndex::class, $primaryIdx);
        $this->assertSame('PRIMARY', $primaryIdx->name);
        $this->assertTrue($primaryIdx->unique);
        $this->assertContains('id', $primaryIdx->columns);

        $emailIdx = $indexes[1];
        $this->assertSame('idx_email', $emailIdx->name);
        $this->assertTrue($emailIdx->unique);
        $this->assertContains('email', $emailIdx->columns);
    }

    public function testIndexesReturnsEmptyArrayForTableWithNoIndexes(): void
    {
        $db = $this->createMockDb([], [], [], []);

        $provider = new MysqlSchemaProvider($db);
        $this->assertSame([], $provider->indexes('simple'));
    }

    public function testForeignKeysReturnsForeignKeyDetails(): void
    {
        // SQL-C09: the arm used to project `NULL as on_update, NULL as on_delete`;
        // the canned row now carries the real referential constraints values the
        // joined query selects, and the VO fields stop being permanently ''.
        $db = $this->createMockDb(
            [],
            [],
            [],
            [
                ['constraint_name' => 'fk_user', 'column_name' => 'user_id', 'referenced_table_name' => 'users', 'referenced_column_name' => 'id', 'on_update' => 'CASCADE', 'on_delete' => 'RESTRICT'],
            ],
        );

        $provider = new MysqlSchemaProvider($db);
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

    public function testForeignKeysQueryJoinsReferentialConstraints(): void
    {
        // can-fail pin: the emitted SQL must join referential_constraints and
        // select the real UPDATE_RULE/DELETE_RULE (was: NULL literals).
        $captured = '';
        $mock = $this->createMock(DatabaseInterface::class);
        $mock->method('quote')->willReturnCallback(self::pdoQuote(...));
        $mock->method('query')->willReturnCallback(function (string $sql) use (&$captured): array {
            $captured = $sql;
            return [];
        });

        $provider = new MysqlSchemaProvider($mock);
        $provider->foreignKeys('posts');

        $this->assertStringContainsString('information_schema.referential_constraints', $captured);
        $this->assertStringContainsString('update_rule', $captured);
        $this->assertStringContainsString('delete_rule', $captured);
        $this->assertStringNotContainsString('NULL as on_update', $captured);
    }

    public function testForeignKeysReturnsEmptyArrayForTableWithNoForeignKeys(): void
    {
        $db = $this->createMockDb([], [], [], []);

        $provider = new MysqlSchemaProvider($db);
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
            ->with('DROP TABLE IF EXISTS `users`');

        $provider = new MysqlSchemaProvider($mock);
        $provider->dropTable('users');
    }

    public function testWithFlavorReturnsNewInstanceWithSpecifiedFlavor(): void
    {
        $db = $this->createMockDb([], [], [], []);
        $provider = new MysqlSchemaProvider($db);

        $result = $provider->withFlavor(Flavor::MariaDB);

        $this->assertInstanceOf(MysqlSchemaProvider::class, $result);
        $this->assertNotSame($provider, $result);
    }

    public function testWithFlavorPreservesMySqlFlavor(): void
    {
        $db = $this->createMockDb([], [], [], []);
        $provider = new MysqlSchemaProvider($db);

        $result = $provider->withFlavor(Flavor::MySQL);

        $this->assertInstanceOf(MysqlSchemaProvider::class, $result);
    }

    /**
     * SQL-C10 golden pin — kill the self-consistent mock universe. The
     * `str_contains` routers in createMockDb() replay canned rows for ANY
     * statement that mentions the table, so alias typos, dropped columns,
     * and lost qualifiers sailed through green. Each provider method now
     * has its exact emitted SQL pinned, one per manual page.
     *
     * MySQL 8.0 Reference Manual §27.2.14 (TABLES): BASE TABLE filter keeps
     * views out, DATABASE() scopes to the current schema.
     */
    public function testTablesEmitsGoldenSql(): void
    {
        $captured = '';
        $provider = new MysqlSchemaProvider($this->capturingDb($captured));
        $provider->tables();

        $this->assertSame(<<<'SQL'
            SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name
            SQL, $captured);
    }

    /**
     * MySQL 8.0 Reference Manual §27.2.8 (COLUMNS): COLUMN_TYPE (not
     * DATA_TYPE) carries display widths, IS_NULLABLE/COLUMN_KEY drive the
     * VO flags, ORDINAL_POSITION keeps declaration order.
     */
    public function testColumnsEmitsGoldenSql(): void
    {
        $captured = '';
        $provider = new MysqlSchemaProvider($this->capturingDb($captured));
        $provider->columns('users');

        $this->assertSame(<<<'SQL'
            SELECT column_name, column_type, is_nullable, column_key, column_default FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' ORDER BY ordinal_position
            SQL, $captured);
    }

    /**
     * MySQL 8.0 Reference Manual §27.2.18 (STATISTICS): one row per indexed
     * column — NON_UNIQUE flips to the unique flag, SEQ_IN_INDEX keeps
     * composite column order inside the group-by-index-name fold.
     */
    public function testIndexesEmitsGoldenSql(): void
    {
        $captured = '';
        $provider = new MysqlSchemaProvider($this->capturingDb($captured));
        $provider->indexes('users');

        $this->assertSame(<<<'SQL'
            SELECT index_name, column_name, non_unique FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'users' ORDER BY index_name, seq_in_index
            SQL, $captured);
    }

    /**
     * MySQL 8.0 Reference Manual §27.2.13 (KEY_COLUMN_USAGE) joined to
     * §27.2.15 (REFERENTIAL_CONSTRAINTS) on the constraint identity — the
     * rc join is what carries real UPDATE_RULE/DELETE_RULE (SQL-C09's cure;
     * golden lock prevents regression to the old `NULL as on_update` arm).
     */
    public function testForeignKeysEmitsGoldenSql(): void
    {
        $captured = '';
        $provider = new MysqlSchemaProvider($this->capturingDb($captured));
        $provider->foreignKeys('posts');

        $this->assertSame(<<<'SQL'
            SELECT kcu.constraint_name, kcu.column_name, kcu.referenced_table_name, kcu.referenced_column_name, rc.update_rule AS on_update, rc.delete_rule AS on_delete FROM information_schema.key_column_usage kcu JOIN information_schema.referential_constraints rc ON rc.constraint_schema = kcu.constraint_schema AND rc.constraint_name = kcu.constraint_name WHERE kcu.table_schema = DATABASE() AND kcu.referenced_table_name IS NOT NULL AND kcu.table_name = 'posts'
            SQL, $captured);
    }

    /**
     * The mock's quote must match PDO semantics, not addslashes: control
     * characters ride the named-escape family (\n \r \Z) instead of raw
     * bytes, and the wrapper/quote arms stay PDO's own.
     */
    public function testQuoteStubMirrorsPdoNotAddslashes(): void
    {
        $withNewline = self::pdoQuote("a\nb");
        $this->assertStringContainsString('\\n', $withNewline);
        $this->assertStringNotContainsString("\n", $withNewline);
        $this->assertStringContainsString('\\Z', self::pdoQuote("a\x1ab"));
        $this->assertSame('\'a\\\'b\'', self::pdoQuote("a'b"));
        $this->assertSame("'a\\\\b'", self::pdoQuote('a\\b'));
        // Divergence proof — the old stub shipped the raw newline through:
        $this->assertNotSame("'" . addslashes("a\nb") . "'", $withNewline);
    }
}
