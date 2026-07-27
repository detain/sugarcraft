<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\CellSize;

final class CellSizeTest extends TestCase
{
    public function testConstructorStoresDimensions(): void
    {
        $cs = new CellSize(8, 20);

        $this->assertSame(8, $cs->cellWidth);
        $this->assertSame(20, $cs->cellHeight);
    }

    public function testDimensionsAreReadonly(): void
    {
        $cs = new CellSize(10, 30);

        $this->assertSame(10, $cs->cellWidth);
        $this->assertSame(30, $cs->cellHeight);
    }

    public function testEqualDimensionsProduceEqualValues(): void
    {
        $a = new CellSize(8, 16);
        $b = new CellSize(8, 16);

        $this->assertSame($a->cellWidth, $b->cellWidth);
        $this->assertSame($a->cellHeight, $b->cellHeight);
    }

    public function testDifferentDimensionsProduceDifferentValues(): void
    {
        $a = new CellSize(8, 16);
        $b = new CellSize(16, 8);

        $this->assertNotSame($a->cellWidth, $b->cellWidth);
        $this->assertNotSame($a->cellHeight, $b->cellHeight);
    }
}
