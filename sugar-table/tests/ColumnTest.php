<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use SugarCraft\Table\Column;
use PHPUnit\Framework\TestCase;

final class ColumnTest extends TestCase
{
    public function testNew(): void
    {
        $col = Column::new('id', 'ID', 10);
        $this->assertSame('id', $col->key);
        $this->assertSame('ID', $col->title);
        $this->assertSame(10, $col->width);
        $this->assertSame(0, $col->flexibleWidth);
        $this->assertSame(0, $col->maxWidth);
        $this->assertFalse($col->filterable);
        $this->assertFalse($col->alignLeft);
        $this->assertSame('', $col->style);
    }

    public function testWithFlexibleWidth(): void
    {
        $col = Column::new('name', 'Name', 20);
        $col2 = $col->withFlexibleWidth(1);

        $this->assertSame(0, $col->flexibleWidth);
        $this->assertSame(1, $col2->flexibleWidth);
        $this->assertSame('name', $col2->key);
        $this->assertSame('Name', $col2->title);
        $this->assertSame(20, $col2->width);
    }

    public function testWithMaxWidth(): void
    {
        $col = Column::new('desc', 'Description', 50);
        $col2 = $col->withMaxWidth(30);

        $this->assertSame(0, $col->maxWidth);
        $this->assertSame(30, $col2->maxWidth);
        $this->assertSame('desc', $col2->key);
        $this->assertSame('Description', $col2->title);
    }

    public function testWithFilterable(): void
    {
        $col = Column::new('email', 'Email', 30);
        $col2 = $col->withFilterable();
        $col3 = $col2->withFilterable(false);

        $this->assertFalse($col->filterable);
        $this->assertTrue($col2->filterable);
        $this->assertFalse($col3->filterable);
    }

    public function testWithAlignLeft(): void
    {
        $col = Column::new('name', 'Name', 20);
        $col2 = $col->withAlignLeft();
        $col3 = $col2->withAlignLeft(false);

        $this->assertFalse($col->alignLeft);
        $this->assertTrue($col2->alignLeft);
        $this->assertFalse($col3->alignLeft);
    }

    public function testWithStyle(): void
    {
        $col = Column::new('status', 'Status', 15);
        $col2 = $col->withStyle('1;31');

        $this->assertSame('', $col->style);
        $this->assertSame('1;31', $col2->style);
        $this->assertSame('status', $col2->key);
    }

    public function testRenderHeaderDefaultWidth(): void
    {
        $col = Column::new('id', 'ID', 5);
        $header = $col->renderHeader();

        // Default alignment is right-align
        $this->assertSame('   ID', $header);
    }

    public function testRenderHeaderWithTotalWidth(): void
    {
        $col = Column::new('name', 'Name', 10);
        $header = $col->renderHeader(15);

        // Default alignment is right-align, title padded to 15 chars
        $this->assertSame('           Name', $header);
    }

    public function testRenderHeaderTruncatesTitle(): void
    {
        $col = Column::new('desc', 'Description', 5);
        $header = $col->renderHeader();

        $this->assertSame('Descr', $header);
    }

    public function testRenderHeaderAlignLeft(): void
    {
        $col = Column::new('name', 'Name', 10)->withAlignLeft();
        $header = $col->renderHeader();

        $this->assertSame('Name      ', $header);
    }

    public function testRenderCellScalarValue(): void
    {
        $col = Column::new('count', 'Count', 8);
        $cell = $col->renderCell(42);

        $this->assertSame('      42', $cell);
    }

    public function testRenderCellStringValue(): void
    {
        $col = Column::new('name', 'Name', 10);
        $cell = $col->renderCell('Alice');

        $this->assertSame('     Alice', $cell);
    }

    public function testRenderCellWithCustomWidth(): void
    {
        $col = Column::new('val', 'Val', 5);
        $cell = $col->renderCell(123, 10);

        $this->assertSame('       123', $cell);
    }

    public function testRenderCellTruncatesLongValue(): void
    {
        $col = Column::new('name', 'Name', 5);
        $cell = $col->renderCell('Christopher');

        // Default alignment is right-align, truncates from end
        $this->assertSame('Chris', $cell);
    }

    public function testRenderCellWithObjectHavingToString(): void
    {
        $col = Column::new('obj', 'Obj', 10);
        $obj = new class {
            public function __toString(): string
            {
                return 'TestObject';
            }
        };
        $cell = $col->renderCell($obj);

        // 'TestObject' is exactly 10 chars, no padding needed
        $this->assertSame('TestObject', $cell);
    }

    public function testRenderCellWithStyle(): void
    {
        $col = Column::new('status', 'Status', 10)->withStyle('1;32');
        $cell = $col->renderCell('Active');

        $this->assertStringStartsWith("\x1b[1;32m", $cell);
        $this->assertStringEndsWith("\x1b[0m", $cell);
        $this->assertStringContainsString('Active', $cell);
    }

    public function testRenderCellEmptyForNonScalarWithoutToString(): void
    {
        $col = Column::new('arr', 'Arr', 10);
        $cell = $col->renderCell(['nested', 'array']);

        $this->assertSame('          ', $cell);
    }

    public function testImmutabilityWithMethods(): void
    {
        $col = Column::new('id', 'ID', 5);
        $col2 = $col->withFlexibleWidth(1);
        $col3 = $col->withMaxWidth(20);
        $col4 = $col->withFilterable(true);
        $col5 = $col->withAlignLeft(true);
        $col6 = $col->withStyle('1');

        $this->assertNotSame($col, $col2);
        $this->assertNotSame($col, $col3);
        $this->assertNotSame($col, $col4);
        $this->assertNotSame($col, $col5);
        $this->assertNotSame($col, $col6);

        $this->assertSame(0, $col->flexibleWidth);
        $this->assertSame(0, $col->maxWidth);
        $this->assertFalse($col->filterable);
        $this->assertFalse($col->alignLeft);
        $this->assertSame('', $col->style);
    }
}