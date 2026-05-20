<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests;

use SugarCraft\Query\SchemaBrowser;
use SugarCraft\Query\SchemaColumn;
use SugarCraft\Query\SchemaForeignKey;
use SugarCraft\Query\SchemaIndex;
use SugarCraft\Query\SchemaTable;
use PHPUnit\Framework\TestCase;

final class SchemaBrowserTest extends TestCase
{
    private function memoryPdo(): \PDO
    {
        return new \PDO('sqlite::memory:');
    }

    private function setupSchema(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT)');
        $pdo->exec('CREATE UNIQUE INDEX idx_email ON users(email)');
        $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER REFERENCES users(id), title TEXT)');
    }

    public function testRefreshReturnsSchemaTableList(): void
    {
        $pdo = $this->memoryPdo();
        $this->setupSchema($pdo);

        $browser = (new SchemaBrowser($pdo))->refresh();

        $this->assertCount(2, $browser->tables);
        $names = array_map(fn(SchemaTable $t) => $t->name, $browser->tables);
        $this->assertSame(['posts', 'users'], $names);
    }

    public function testLoadColumnsReturnsCorrectSchema(): void
    {
        $pdo = $this->memoryPdo();
        $this->setupSchema($pdo);

        $browser = (new SchemaBrowser($pdo))->refresh();
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
        $pdo = $this->memoryPdo();
        $this->setupSchema($pdo);

        $browser = (new SchemaBrowser($pdo))->refresh();
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
        $pdo = $this->memoryPdo();
        $this->setupSchema($pdo);

        $browser = (new SchemaBrowser($pdo))->refresh();
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
        $pdo = $this->memoryPdo();
        $this->setupSchema($pdo);

        $browser = (new SchemaBrowser($pdo))->refresh();
        $this->assertCount(2, $browser->tables);

        $browser = $browser->dropTable('posts');

        $this->assertCount(1, $browser->tables);
        $this->assertSame('users', $browser->tables[0]->name);
    }

    public function testEmptyDatabaseReturnsNoTables(): void
    {
        $pdo = $this->memoryPdo();
        $browser = (new SchemaBrowser($pdo))->refresh();
        $this->assertSame([], $browser->tables);
    }

    public function testSchemaTableColumnReturnsNullForMissingColumn(): void
    {
        $pdo = $this->memoryPdo();
        $this->setupSchema($pdo);

        $browser = (new SchemaBrowser($pdo))->refresh();
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
