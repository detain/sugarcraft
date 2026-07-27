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

    public function testSerialize(): void
    {
        $pos = Position::new(3, 7);

        $data = $pos->__serialize();

        $this->assertSame(3, $data['col']);
        $this->assertSame(7, $data['row']);
    }

    public function testSerializeNegativeCoordinates(): void
    {
        $pos = Position::new(-1, -4);

        $data = $pos->__serialize();

        $this->assertSame(-1, $data['col']);
        $this->assertSame(-4, $data['row']);
    }

    public function testUnserializeRoundTrip(): void
    {
        $original = Position::new(5, 12);
        $data = $original->__serialize();

        // Reconstruct via constructor call
        $reconstituted = new Position($data['col'], $data['row']);

        $this->assertSame($original->col(), $reconstituted->col());
        $this->assertSame($original->row(), $reconstituted->row());
    }

    public function testJsonSerialize(): void
    {
        $pos = Position::new(3, 7);

        $json = json_encode($pos);
        $decoded = json_decode($json, true);

        $this->assertSame(3, $decoded['col']);
        $this->assertSame(7, $decoded['row']);
    }

    public function testJsonSerializeRoundTrip(): void
    {
        $original = Position::new(42, 99);

        $json = json_encode($original);
        $decoded = json_decode($json, true);
        $reconstituted = new Position($decoded['col'], $decoded['row']);

        $this->assertSame($original->col(), $reconstituted->col());
        $this->assertSame($original->row(), $reconstituted->row());
    }
}
