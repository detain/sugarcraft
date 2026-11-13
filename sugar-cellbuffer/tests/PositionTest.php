<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CellBuffer\Position;

/**
 * @covers \SugarCraft\CellBuffer\Position
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

    public function testEqualsComparesBothAxesByValue(): void
    {
        $a = Position::new(3, 4);

        $this->assertTrue($a->equals(Position::new(3, 4)));
        $this->assertTrue($a->equals(new Position(3, 4))); // same factory-less shape
        $this->assertTrue(Position::new(-1, -2)->equals(Position::new(-1, -2)));

        $this->assertFalse($a->equals(Position::new(4, 4))); // col shifted one
        $this->assertFalse($a->equals(Position::new(3, 5))); // row shifted one
        $this->assertFalse($a->equals(Position::new(4, 5))); // both shifted
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function missingKeyProvider(): array
    {
        return ['col' => ['col'], 'row' => ['row']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('missingKeyProvider')]
    public function testUnserializeRefusesEachMissingKey(string $missing): void
    {
        $payload = ['col' => 3, 'row' => 4];
        unset($payload[$missing]);
        $fresh = (new \ReflectionClass(Position::class))->newInstanceWithoutConstructor();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Position payload missing key '{$missing}'");
        $fresh->__unserialize($payload);
    }

    public function testUnserializeKeepsNegativeCoordinatesFromCompletePayloads(): void
    {
        // A present key is still just an int — negativity is Position law
        // (relative offsets), so the missing-key guard must not eat it.
        $fresh = (new \ReflectionClass(Position::class))->newInstanceWithoutConstructor();
        $fresh->__unserialize(['col' => -2, 'row' => -9]);

        $this->assertSame(-2, $fresh->col());
        $this->assertSame(-9, $fresh->row());
    }
}
