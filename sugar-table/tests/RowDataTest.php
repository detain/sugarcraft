<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use SugarCraft\Table\RowData;
use SugarCraft\Table\StyledCell;
use PHPUnit\Framework\TestCase;

final class RowDataTest extends TestCase
{
    public function testFrom(): void
    {
        $data = ['id' => '1', 'name' => 'Alice'];
        $row = RowData::from($data);

        $this->assertInstanceOf(RowData::class, $row);
    }

    public function testGetExistingKey(): void
    {
        $row = RowData::from(['id' => '1', 'name' => 'Alice']);
        $this->assertSame('1', $row->get('id'));
        $this->assertSame('Alice', $row->get('name'));
    }

    public function testGetNonExistingKeyReturnsNull(): void
    {
        $row = RowData::from(['id' => '1']);
        $this->assertNull($row->get('missing'));
        $this->assertNull($row->get('nonexistent'));
    }

    public function testHasExistingKey(): void
    {
        $row = RowData::from(['id' => '1', 'name' => 'Alice']);
        $this->assertTrue($row->has('id'));
        $this->assertTrue($row->has('name'));
    }

    public function testHasNonExistingKey(): void
    {
        $row = RowData::from(['id' => '1']);
        $this->assertFalse($row->has('missing'));
        $this->assertFalse($row->has('name'));
    }

    public function testAll(): void
    {
        $data = ['id' => '1', 'name' => 'Alice', 'city' => 'NYC'];
        $row = RowData::from($data);

        $this->assertSame($data, $row->all());
    }

    public function testWithAddsNewKey(): void
    {
        $row = RowData::from(['id' => '1']);
        $row2 = $row->with('name', 'Alice');

        $this->assertNull($row->get('name'));
        $this->assertSame('Alice', $row2->get('name'));
        $this->assertSame('1', $row2->get('id'));
    }

    public function testWithUpdatesExistingKey(): void
    {
        $row = RowData::from(['id' => '1', 'name' => 'Alice']);
        $row2 = $row->with('name', 'Bob');

        $this->assertSame('Alice', $row->get('name'));
        $this->assertSame('Bob', $row2->get('name'));
        $this->assertSame('1', $row2->get('id'));
    }

    public function testWithReturnsNewInstance(): void
    {
        $row = RowData::from(['id' => '1']);
        $row2 = $row->with('name', 'Alice');

        $this->assertNotSame($row, $row2);
    }

    public function testWithStyledCellValue(): void
    {
        $row = RowData::from(['id' => '1']);
        $styledCell = StyledCell::new('Important', '1;31');
        $row2 = $row->with('status', $styledCell);

        $this->assertInstanceOf(StyledCell::class, $row2->get('status'));
    }

    public function testEmptyData(): void
    {
        $row = RowData::from([]);
        $this->assertNull($row->get('any'));
        $this->assertFalse($row->has('any'));
        $this->assertSame([], $row->all());
    }

    public function testImmutabilityWithWith(): void
    {
        $row = RowData::from(['id' => '1', 'name' => 'Original']);
        $row2 = $row->with('name', 'Updated');

        $this->assertSame('Original', $row->get('name'));
        $this->assertSame('Updated', $row2->get('name'));
    }
}