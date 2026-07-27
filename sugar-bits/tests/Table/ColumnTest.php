<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\Table;

use PHPUnit\Framework\TestCase;
use SugarCraft\Bits\Table\Column;

/**
 * Tests for {@see Column} — header column descriptor for Table::setColumns().
 */
final class ColumnTest extends TestCase
{
    public function testConstructorWithTitleOnly(): void
    {
        $col = new Column('Name');
        $this->assertSame('Name', $col->title);
        $this->assertSame(0, $col->width);
    }

    public function testConstructorWithTitleAndWidth(): void
    {
        $col = new Column('Age', 5);
        $this->assertSame('Age', $col->title);
        $this->assertSame(5, $col->width);
    }

    public function testWidthDefaultsToZeroForAutoSizing(): void
    {
        $col = new Column('Description');
        $this->assertSame(0, $col->width);
    }

    public function testNegativeWidthRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Column('Bad', -1);
    }

    public function testZeroWidthIsAllowed(): void
    {
        $col = new Column('Flexible', 0);
        $this->assertSame(0, $col->width);
    }

    public function testImmutability(): void
    {
        $col = new Column('x', 10);
        // title and width are readonly
        $this->assertSame('x', $col->title);
        $this->assertSame(10, $col->width);
    }
}
