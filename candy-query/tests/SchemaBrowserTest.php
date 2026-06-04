<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests;

use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Db\SqliteDatabase;
use SugarCraft\Query\SchemaBrowser;
use SugarCraft\Query\Schema\SchemaColumn;
use SugarCraft\Query\Schema\SchemaForeignKey;
use SugarCraft\Query\Schema\SchemaIndex;
use SugarCraft\Query\Schema\SchemaTable;
use PHPUnit\Framework\TestCase;

final class SchemaBrowserTest extends TestCase
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

    public function testRefreshReturnsSchemaTableList(): void
    {
        $db = $this->memoryDb();
        $this->setupSchema($db);

        $browser = SchemaBrowser::new($db, Flavor::Sqlite)->refresh();

        $this->assertCount(2, $browser->tables);
        $names = array_map(fn(SchemaTable $t) => $t->name, $browser->tables);
        $this->assertSame(['posts', 'users'], $names);
    }

    public function testLoadColumnsReturnsCorrectSchema(): void
    {
        $db = $this->memoryDb();
        $this->setupSchema($db);

        $browser = SchemaBrowser::new($db, Flavor::Sqlite)->refresh();
        $users = null;
        foreach ($browser->tables as $t) {
            if ($t->name === 'users') {
                $users = $t;
                break;
            }
        }

        $this->assertNotNull($users);
        $this->assertCount(3, $users->columns);

        $idCol = $users->column('id');
        $this->assertInstanceOf(SchemaColumn::class, $idCol);
        $this->assertSame('INTEGER', $idCol->type);
        $this->assertTrue($idCol->primaryKey);

        $nameCol = $users->column('name');
        $this->assertInstanceOf(SchemaColumn::class, $nameCol);
        $this->assertTrue($nameCol->notNull);
        $this->assertSame('TEXT', $nameCol->type);
    }

    public function testLoadIndexesReturnsCorrectSchema(): void
    {
        $db = $this->memoryDb();
        $this->setupSchema($db);

        $browser = SchemaBrowser::new($db, Flavor::Sqlite)->refresh();
        $users = null;
        foreach ($browser->tables as $t) {
            if ($t->name === 'users') {
                $users = $t;
                break;
            }
        }

        $this->assertNotNull($users);
        $idxs = $users->indexes;
        $this->assertNotEmpty($idxs);

        $emailIdx = null;
        foreach ($idxs as $idx) {
            if ($idx->name === 'idx_email') {
                $emailIdx = $idx;
                break;
            }
        }

        $this->assertInstanceOf(SchemaIndex::class, $emailIdx);
        $this->assertTrue($emailIdx->unique);
        $this->assertContains('email', $emailIdx->columns);
    }

    public function testLoadForeignKeysReturnsCorrectSchema(): void
    {
        $db = $this->memoryDb();
        $this->setupSchema($db);

        $browser = SchemaBrowser::new($db, Flavor::Sqlite)->refresh();
        $posts = null;
        foreach ($browser->tables as $t) {
            if ($t->name === 'posts') {
                $posts = $t;
                break;
            }
        }

        $this->assertNotNull($posts);
        $this->assertNotEmpty($posts->foreignKeys);

        $fk = $posts->foreignKeys[0];
        $this->assertInstanceOf(SchemaForeignKey::class, $fk);
        $this->assertSame('user_id', $fk->column);
        $this->assertSame('users', $fk->foreignTable);
        $this->assertSame('id', $fk->foreignColumn);
    }

    public function testDropTableRefreshesSchema(): void
    {
        $db = $this->memoryDb();
        $this->setupSchema($db);

        $browser = SchemaBrowser::new($db, Flavor::Sqlite)->refresh();
        $this->assertCount(2, $browser->tables);

        $browser = $browser->dropTable('posts');

        $this->assertCount(1, $browser->tables);
        $this->assertSame('users', $browser->tables[0]->name);
    }

    public function testEmptyDatabaseReturnsNoTables(): void
    {
        $db = $this->memoryDb();
        $browser = SchemaBrowser::new($db, Flavor::Sqlite)->refresh();
        $this->assertSame([], $browser->tables);
    }

    public function testSchemaTableColumnReturnsNullForMissingColumn(): void
    {
        $db = $this->memoryDb();
        $this->setupSchema($db);

        $browser = SchemaBrowser::new($db, Flavor::Sqlite)->refresh();
        $users = null;
        foreach ($browser->tables as $t) {
            if ($t->name === 'users') {
                $users = $t;
                break;
            }
        }

        $this->assertNotNull($users);
        $this->assertNull($users->column('nonexistent'));
    }
}
