<?php

declare(strict_types=1);

namespace CandyCore\Sprinkles\Tests\Table;

use CandyCore\Sprinkles\Table\StringData;
use CandyCore\Sprinkles\Table\Table;
use PHPUnit\Framework\TestCase;

final class StringDataTest extends TestCase
{
    public function testRowsAndColumns(): void
    {
        $d = StringData::fromMatrix([
            ['Alice', '32'],
            ['Bob',   '41'],
        ]);
        $this->assertSame(2, $d->rows());
        $this->assertSame(2, $d->columns());
    }

    public function testAtReadsCells(): void
    {
        $d = StringData::fromMatrix([['a', 'b'], ['c', 'd']]);
        $this->assertSame('a', $d->at(0, 0));
        $this->assertSame('d', $d->at(1, 1));
    }

    public function testAtOutOfRangeReturnsEmpty(): void
    {
        $d = StringData::fromMatrix([['a']]);
        $this->assertSame('', $d->at(5, 5));
    }

    public function testRaggedRowsPadToMaxColumns(): void
    {
        $d = StringData::fromMatrix([
            ['a'],            // 1 col
            ['b', 'c', 'd'],  // 3 cols
        ]);
        $this->assertSame(3, $d->columns());
        $this->assertSame('', $d->at(0, 1));
        $this->assertSame('', $d->at(0, 2));
    }

    public function testCoercesNonStringCells(): void
    {
        $d = StringData::fromMatrix([[1, 2.5, true, null]]);
        $this->assertSame('1',   $d->at(0, 0));
        $this->assertSame('2.5', $d->at(0, 1));
        $this->assertSame('1',   $d->at(0, 2));
        $this->assertSame('',    $d->at(0, 3));
    }

    public function testEmptyHelperReturnsZeroDimensions(): void
    {
        $d = StringData::empty();
        $this->assertSame(0, $d->rows());
        $this->assertSame(0, $d->columns());
    }

    public function testAppendIsImmutable(): void
    {
        $a = StringData::fromMatrix([['a']]);
        $b = $a->append('b');
        $this->assertSame(1, $a->rows());
        $this->assertSame(2, $b->rows());
        $this->assertSame('b', $b->at(1, 0));
    }

    public function testTableConsumesStringData(): void
    {
        $data = StringData::fromMatrix([['Alice', '32'], ['Bob', '41']]);
        $rendered = Table::new()
            ->headers('Name', 'Age')
            ->data($data)
            ->render();
        $this->assertStringContainsString('Alice', $rendered);
        $this->assertStringContainsString('Bob',   $rendered);
        $this->assertStringContainsString('32',    $rendered);
        $this->assertStringContainsString('41',    $rendered);
    }

    public function testTableClearRowsKeepsHeaders(): void
    {
        $t = Table::new()
            ->headers('a', 'b')
            ->row('1', '2')
            ->row('3', '4')
            ->clearRows();
        $rendered = $t->render();
        $this->assertStringContainsString('a', $rendered);
        $this->assertStringContainsString('b', $rendered);
        $this->assertStringNotContainsString('1', $rendered);
        $this->assertStringNotContainsString('3', $rendered);
    }
}
