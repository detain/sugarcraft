<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Schema;

use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Schema\MysqlSchemaProvider;
use SugarCraft\Query\Schema\SchemaColumn;
use SugarCraft\Query\Schema\SchemaForeignKey;
use SugarCraft\Query\Schema\SchemaIndex;
use PHPUnit\Framework\TestCase;

/**
 * MysqlSchemaProvider tests using a mock DatabaseInterface.
 */
final class MysqlSchemaProviderTest extends TestCase
{
    private function createMockDb(array $tablesResult, array $columnsResult, array $indexesResult, array $fksResult): DatabaseInterface
    {
        $mock = $this->createMock(DatabaseInterface::class);

        $mock->method('quote')->willReturnCallback(fn($v) => "'" . addslashes((string)$v) . "'");

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
        $db = $this->createMockDb(
            [],
            [],
            [],
            [
                ['constraint_name' => 'fk_user', 'column_name' => 'user_id', 'referenced_table_name' => 'users', 'referenced_column_name' => 'id'],
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

        $mock->expects($this->once())
            ->method('quote')
            ->with('users')
            ->willReturn("'users'");

        $mock->expects($this->once())
            ->method('exec')
            ->with("DROP TABLE IF EXISTS 'users'");

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
}
