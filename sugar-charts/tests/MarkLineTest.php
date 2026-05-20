<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use SugarCraft\Charts\MarkLine;
use PHPUnit\Framework\TestCase;

final class MarkLineTest extends TestCase
{
    public function testAtCreatesExplicitMarkLine(): void
    {
        $ml = MarkLine::at(42.0, 'answer', MarkLine::STYLE_SOLID);
        $this->assertSame(42.0, $ml->value);
        $this->assertSame('answer', $ml->label);
        $this->assertSame(MarkLine::STYLE_SOLID, $ml->style);
    }

    public function testFromDatasetMin(): void
    {
        $ml = MarkLine::fromDataset([3, 1, 4, 1, 5, 9, 2, 6], MarkLine::MIN);
        $this->assertSame(1.0, $ml->value);
        $this->assertSame('min', $ml->label);
    }

    public function testFromDatasetMax(): void
    {
        $ml = MarkLine::fromDataset([3, 1, 4, 1, 5, 9, 2, 6], MarkLine::MAX);
        $this->assertSame(9.0, $ml->value);
        $this->assertSame('max', $ml->label);
    }

    public function testFromDatasetAverage(): void
    {
        $ml = MarkLine::fromDataset([2, 4, 6, 8], MarkLine::AVERAGE);
        $this->assertSame(5.0, $ml->value);
        $this->assertSame('average', $ml->label);
    }

    public function testMinShortcut(): void
    {
        $ml = MarkLine::min([10, 5, 8, 3]);
        $this->assertSame(3.0, $ml->value);
        $this->assertSame('min', $ml->label);
    }

    public function testMaxShortcut(): void
    {
        $ml = MarkLine::max([10, 5, 8, 3]);
        $this->assertSame(10.0, $ml->value);
        $this->assertSame('max', $ml->label);
    }

    public function testAverageShortcut(): void
    {
        $ml = MarkLine::average([10, 5, 8, 3]);
        $this->assertSame(6.5, $ml->value);
        $this->assertSame('average', $ml->label);
    }

    public function testFromDatasetRejectsEmptyData(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MarkLine::fromDataset([], MarkLine::MIN);
    }

    public function testFromDatasetRejectsInvalidType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MarkLine::fromDataset([1, 2, 3], 'median');
    }

    public function testDashedStyleIsDefault(): void
    {
        $ml = MarkLine::at(5.0);
        $this->assertSame(MarkLine::STYLE_DASHED, $ml->style);
    }
}
