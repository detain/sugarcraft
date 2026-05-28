<?php

declare(strict_types=1);

namespace SugarCraft\Buffer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Buffer\Position;

/**
 * @covers \SugarCraft\Buffer\Position
 */
final class PositionTest extends TestCase
{
    public function testNew(): void
    {
        $pos = Position::new(5, 10);

        $this->assertSame(5, $pos->col());
        $this->assertSame(10, $pos->row());
    }

    public function testNewViaConstructor(): void
    {
        $pos = new Position(3, 7);

        $this->assertSame(3, $pos->col());
        $this->assertSame(7, $pos->row());
    }

    public function testZeroOrigin(): void
    {
        $pos = Position::new(0, 0);

        $this->assertSame(0, $pos->col());
        $this->assertSame(0, $pos->row());
    }

    public function testNegativeCoordinates(): void
    {
        $pos = Position::new(-5, -10);

        $this->assertSame(-5, $pos->col());
        $this->assertSame(-10, $pos->row());
    }
}
