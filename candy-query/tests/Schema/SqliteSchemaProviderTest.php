<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Schema;

use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Db\SqliteDatabase;
use SugarCraft\Query\Schema\SchemaColumn;
use SugarCraft\Query\Schema\SchemaForeignKey;
use SugarCraft\Query\Schema\SchemaIndex;
use SugarCraft\Query\Schema\SqliteSchemaProvider;
use PHPUnit\Framework\TestCase;

final class SqliteSchemaProviderTest extends TestCase
{
    private function memoryDb(): SqliteDatabase
    {
        return SqliteDatabase::open(':memory:');
    }

    private function setupSchema(SqliteDatabase $db): void
    {
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT)');
        $db->exec('CREATE UNIQUE INDEX idx_email ON users(email)');
        $db->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER REFERENCES users(id), title TEXT)');
    }

    public function testTablesReturnsTableNames(): void
    {
        $db = $this->memoryDb();
        $this->setupSchema($db);

        $provider = new SqliteSchemaProvider($db);
        $tables = $provider->tables();

        $this->assertCount(2, $tables);
        $this->assertSame(['posts', 'users'], $tables);
    }

    public function testTablesExcludesSqliteSystemTables(): void
    {
        $db = $this->memoryDb();
        $db->exec('CREATE TABLE foo (a TEXT)');

        $provider = new SqliteSchemaProvider($db);
        $tables = $provider->tables();

        foreach ($tables as $t) {
            $this->assertStringStartsNotWith('sqlite_', $t);
        }
    }

    public function testTablesReturnsEmptyArrayWhenNoTables(): void
    {
        $db = $this->memoryDb();
        $provider = new SqliteSchemaProvider($db);

        $this->assertSame([], $provider->tables());
    }

    public function testColumnsReturnsColumnDetails(): void
    {
        $db = $this->memoryDb();
        $this->setupSchema($db);

        $provider = new SqliteSchemaProvider($db);
        $columns = $provider->columns('users');

        $this->assertCount(3, $columns);

        $idCol = $columns[0];
        $this->assertInstanceOf(SchemaColumn::class, $idCol);
        $this->assertSame('id', $idCol->name);
        $this->assertSame('INTEGER', $idCol->type);
        $this->assertTrue($idCol->primaryKey);
        $this->assertFalse($idCol->notNull);

        $nameCol = $columns[1];
        $this->assertSame('name', $nameCol->name);
        $this->assertSame('TEXT', $nameCol->type);
        $this->assertTrue($nameCol->notNull);
    }

    public function testColumnsReturnsEmptyArrayForNonexistentTable(): void
    {
        $db = $this->memoryDb();
        $provider = new SqliteSchemaProvider($db);

        $this->assertSame([], $provider->columns('nonexistent'));
    }

    public function testIndexesReturnsIndexDetails(): void
    {
        $db = $this->memoryDb();
        $this->setupSchema($db);

        $provider = new SqliteSchemaProvider($db);
        $indexes = $provider->indexes('users');

        $this->assertNotEmpty($indexes);

        $emailIdx = null;
        foreach ($indexes as $idx) {
            if ($idx->name === 'idx_email') {
                $emailIdx = $idx;
                break;
            }
        }

        $this->assertNotNull($emailIdx);
        $this->assertInstanceOf(SchemaIndex::class, $emailIdx);
        $this->assertTrue($emailIdx->unique);
        $this->assertContains('email', $emailIdx->columns);
    }

    public function testIndexesReturnsEmptyArrayForTableWithNoIndexes(): void
    {
        $db = $this->memoryDb();
        $db->exec('CREATE TABLE simple (id INTEGER PRIMARY KEY)');

        $provider = new SqliteSchemaProvider($db);
        $indexes = $provider->indexes('simple');

        $this->assertSame([], $indexes);
    }

    public function testForeignKeysReturnsForeignKeyDetails(): void
    {
        $db = $this->memoryDb();
        $this->setupSchema($db);

        $provider = new SqliteSchemaProvider($db);
        $fks = $provider->foreignKeys('posts');

        $this->assertNotEmpty($fks);

        $fk = $fks[0];
        $this->assertInstanceOf(SchemaForeignKey::class, $fk);
        $this->assertSame('user_id', $fk->column);
        $this->assertSame('users', $fk->foreignTable);
        $this->assertSame('id', $fk->foreignColumn);
    }

    public function testForeignKeysReturnsEmptyArrayForTableWithNoForeignKeys(): void
    {
        $db = $this->memoryDb();
        $db->exec('CREATE TABLE simple (id INTEGER PRIMARY KEY)');

        $provider = new SqliteSchemaProvider($db);
        $fks = $provider->foreignKeys('simple');

        $this->assertSame([], $fks);
    }

    public function testDropTableRemovesTable(): void
    {
        $db = $this->memoryDb();
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $db->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY)');

        $provider = new SqliteSchemaProvider($db);
        $this->assertCount(2, $provider->tables());

        $provider->dropTable('posts');

        $this->assertCount(1, $provider->tables());
        $this->assertSame(['users'], $provider->tables());
    }

    public function testDropTableOnNonexistentTableDoesNotThrow(): void
    {
        $db = $this->memoryDb();
        $provider = new SqliteSchemaProvider($db);

        $provider->dropTable('nonexistent');

        $this->assertSame([], $provider->tables());
    }

    public function testWithFlavorReturnsSelfForSqlite(): void
    {
        $db = $this->memoryDb();
        $provider = new SqliteSchemaProvider($db);

        // Sqlite flavor doesn't change behavior, so same instance is returned
        $result = $provider->withFlavor(Flavor::MariaDB);

        $this->assertInstanceOf(SqliteSchemaProvider::class, $result);
        $this->assertSame($provider, $result);
    }

    public function testWithFlavorReturnsSameClassForSqlite(): void
    {
        $db = $this->memoryDb();
        $provider = new SqliteSchemaProvider($db);

        $result = $provider->withFlavor(Flavor::Sqlite);

        $this->assertInstanceOf(SqliteSchemaProvider::class, $result);
    }
}
