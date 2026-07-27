<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Schema;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Schema\SchemaColumn;
use SugarCraft\Query\Schema\SchemaForeignKey;
use SugarCraft\Query\Schema\SchemaIndex;
use SugarCraft\Query\Schema\SchemaTable;

/**
 * Tests for SchemaTable value object.
 */
final class SchemaTableTest extends TestCase
{
    public function testConstructionWithEmptyArrays(): void
    {
        $table = new SchemaTable(
            name: 'users',
            columns: [],
            indexes: [],
            foreignKeys: [],
        );

        $this->assertSame('users', $table->name);
        $this->assertEmpty($table->columns);
        $this->assertEmpty($table->indexes);
        $this->assertEmpty($table->foreignKeys);
    }

    public function testConstructionWithColumnsIndexesAndForeignKeys(): void
    {
        $columns = [
            new SchemaColumn(0, 'id', 'INTEGER', true, null, true),
            new SchemaColumn(1, 'name', 'VARCHAR(255)', true, null, false),
        ];
        $indexes = [
            new SchemaIndex('idx_name', false, ['name']),
        ];
        $fk = [
            new SchemaForeignKey(1, 'id', 'users', 'id', 'CASCADE', 'CASCADE'),
        ];

        $table = new SchemaTable(
            name: 'profiles',
            columns: $columns,
            indexes: $indexes,
            foreignKeys: $fk,
        );

        $this->assertCount(2, $table->columns);
        $this->assertCount(1, $table->indexes);
        $this->assertCount(1, $table->foreignKeys);
    }

    public function testColumnReturnsColumnWhenFound(): void
    {
        $columns = [
            new SchemaColumn(0, 'id', 'INTEGER', true, null, true),
            new SchemaColumn(1, 'email', 'VARCHAR(255)', false, null, false),
            new SchemaColumn(2, 'name', 'VARCHAR(100)', true, null, false),
        ];
        $table = new SchemaTable('users', $columns, [], []);

        $found = $table->column('email');

        $this->assertNotNull($found);
        $this->assertSame('email', $found->name);
        $this->assertSame('VARCHAR(255)', $found->type);
    }

    public function testColumnReturnsNullWhenNotFound(): void
    {
        $columns = [
            new SchemaColumn(0, 'id', 'INTEGER', true, null, true),
        ];
        $table = new SchemaTable('users', $columns, [], []);

        $found = $table->column('nonexistent');

        $this->assertNull($found);
    }

    public function testColumnReturnsNullForEmptyTable(): void
    {
        $table = new SchemaTable('empty_table', [], [], []);

        $found = $table->column('any_column');

        $this->assertNull($found);
    }

    public function testColumnIsCaseSensitive(): void
    {
        $columns = [
            new SchemaColumn(0, 'UserName', 'VARCHAR(100)', true, null, false),
        ];
        $table = new SchemaTable('users', $columns, [], []);

        $foundExact = $table->column('UserName');
        $foundLower = $table->column('username');

        $this->assertNotNull($foundExact);
        $this->assertNull($foundLower);
    }

    public function testColumnReturnsFirstMatchWhenDuplicateNames(): void
    {
        // SchemaColumn cid is unique, but column() searches by name
        $columns = [
            new SchemaColumn(0, 'id', 'INTEGER', true, null, true),
            new SchemaColumn(1, 'id', 'BIGINT', true, null, false),
        ];
        $table = new SchemaTable('users', $columns, [], []);

        $found = $table->column('id');

        $this->assertNotNull($found);
        $this->assertSame(0, $found->cid);
    }
}
