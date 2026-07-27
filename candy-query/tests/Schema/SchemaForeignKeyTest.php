<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Schema;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Schema\SchemaForeignKey;

/**
 * Tests for SchemaForeignKey value object.
 */
final class SchemaForeignKeyTest extends TestCase
{
    public function testConstructionWithAllFields(): void
    {
        $fk = new SchemaForeignKey(
            id: 1,
            column: 'user_id',
            foreignTable: 'users',
            foreignColumn: 'id',
            onUpdate: 'CASCADE',
            onDelete: 'RESTRICT',
        );

        $this->assertSame(1, $fk->id);
        $this->assertSame('user_id', $fk->column);
        $this->assertSame('users', $fk->foreignTable);
        $this->assertSame('id', $fk->foreignColumn);
        $this->assertSame('CASCADE', $fk->onUpdate);
        $this->assertSame('RESTRICT', $fk->onDelete);
    }

    public function testConstructionWithNoAction(): void
    {
        $fk = new SchemaForeignKey(
            id: 2,
            column: 'category_id',
            foreignTable: 'categories',
            foreignColumn: 'id',
            onUpdate: 'NO ACTION',
            onDelete: 'NO ACTION',
        );

        $this->assertSame('NO ACTION', $fk->onUpdate);
        $this->assertSame('NO ACTION', $fk->onDelete);
    }

    public function testIdIsInteger(): void
    {
        $fk = new SchemaForeignKey(
            id: 100,
            column: 'profile_id',
            foreignTable: 'profiles',
            foreignColumn: 'id',
            onUpdate: 'SET NULL',
            onDelete: 'SET NULL',
        );

        $this->assertIsInt($fk->id);
        $this->assertSame(100, $fk->id);
    }
}
