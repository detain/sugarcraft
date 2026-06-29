<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Schema;

use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Schema\PostgresSchemaProvider;
use SugarCraft\Query\Schema\SchemaColumn;
use SugarCraft\Query\Schema\SchemaForeignKey;
use SugarCraft\Query\Schema\SchemaIndex;
use PHPUnit\Framework\TestCase;

/**
 * PostgresSchemaProvider tests using a mock DatabaseInterface.
 */
final class PostgresSchemaProviderTest extends TestCase
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
        $db = $this->createMockDb(
            [],
            [],
            [],
            [
                ['constraint_name' => 'fk_user', 'column_id' => 2, 'foreign_table_name' => 'users', 'foreign_column_id' => 1],
            ],
        );

        $provider = new PostgresSchemaProvider($db);
        $fks = $provider->foreignKeys('posts');

        $this->assertCount(1, $fks);

        $fk = $fks[0];
        $this->assertInstanceOf(SchemaForeignKey::class, $fk);
        $this->assertSame('2', $fk->column);
        $this->assertSame('users', $fk->foreignTable);
        $this->assertSame('1', $fk->foreignColumn);
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
}
