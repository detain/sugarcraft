<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Schema;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Schema\SchemaIndex;

/**
 * Tests for SchemaIndex value object.
 */
final class SchemaIndexTest extends TestCase
{
    public function testConstructionWithAllFields(): void
    {
        $index = new SchemaIndex(
            name: 'idx_user_email',
            unique: false,
            columns: ['email', 'status'],
        );

        $this->assertSame('idx_user_email', $index->name);
        $this->assertFalse($index->unique);
        $this->assertSame(['email', 'status'], $index->columns);
    }

    public function testUniqueIndex(): void
    {
        $index = new SchemaIndex(
            name: 'uniq_user_email',
            unique: true,
            columns: ['email'],
        );

        $this->assertTrue($index->unique);
    }

    public function testSingleColumnIndex(): void
    {
        $index = new SchemaIndex(
            name: 'idx_id',
            unique: false,
            columns: ['id'],
        );

        $this->assertCount(1, $index->columns);
        $this->assertSame('id', $index->columns[0]);
    }

    public function testColumnsIsArray(): void
    {
        $index = new SchemaIndex(
            name: 'idx_test',
            unique: false,
            columns: ['col1', 'col2', 'col3'],
        );

        $this->assertIsArray($index->columns);
    }

    public function testCompositeIndexWithMultipleColumns(): void
    {
        $index = new SchemaIndex(
            name: 'idx_composite',
            unique: true,
            columns: ['tenant_id', 'type', 'created_at'],
        );

        $this->assertCount(3, $index->columns);
    }
}
