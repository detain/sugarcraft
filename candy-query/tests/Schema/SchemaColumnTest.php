<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Schema;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Schema\SchemaColumn;

/**
 * Tests for SchemaColumn value object.
 */
final class SchemaColumnTest extends TestCase
{
    public function testConstructionWithAllFields(): void
    {
        $column = new SchemaColumn(
            cid: 0,
            name: 'id',
            type: 'INTEGER',
            notNull: true,
            defaultValue: null,
            primaryKey: true,
        );

        $this->assertSame(0, $column->cid);
        $this->assertSame('id', $column->name);
        $this->assertSame('INTEGER', $column->type);
        $this->assertTrue($column->notNull);
        $this->assertNull($column->defaultValue);
        $this->assertTrue($column->primaryKey);
    }

    public function testConstructionWithNullableColumn(): void
    {
        $column = new SchemaColumn(
            cid: 1,
            name: 'email',
            type: 'VARCHAR(255)',
            notNull: false,
            defaultValue: null,
            primaryKey: false,
        );

        $this->assertFalse($column->notNull);
        $this->assertFalse($column->primaryKey);
    }

    public function testConstructionWithDefaultValue(): void
    {
        $column = new SchemaColumn(
            cid: 2,
            name: 'status',
            type: 'VARCHAR(50)',
            notNull: true,
            defaultValue: 'active',
            primaryKey: false,
        );

        $this->assertSame('active', $column->defaultValue);
    }

    public function testCidIsInteger(): void
    {
        $column = new SchemaColumn(
            cid: 5,
            name: 'id',
            type: 'BIGINT',
            notNull: true,
            defaultValue: null,
            primaryKey: true,
        );

        $this->assertSame(5, $column->cid);
        $this->assertIsInt($column->cid);
    }

    public function testTypePreservesCase(): void
    {
        $column = new SchemaColumn(
            cid: 0,
            name: 'price',
            type: 'DECIMAL(10,2)',
            notNull: false,
            defaultValue: null,
            primaryKey: false,
        );

        $this->assertSame('DECIMAL(10,2)', $column->type);
    }
}
