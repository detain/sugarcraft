<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot;

use SugarCraft\Dash\Plot\RingBuffer;
use PHPUnit\Framework\TestCase;

final class RingBufferTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Construction
    // ═══════════════════════════════════════════════════════════════

    public function testNewCreatesEmptyBuffer(): void
    {
        $buffer = new RingBuffer(10);
        $this->assertTrue($buffer->isEmpty());
        $this->assertFalse($buffer->isFull());
    }

    public function testCapacityReturnsConfiguredSize(): void
    {
        $buffer10 = new RingBuffer(10);
        $buffer60 = new RingBuffer(60);
        $buffer100 = new RingBuffer(100);

        $this->assertSame(10, $buffer10->capacity());
        $this->assertSame(60, $buffer60->capacity());
        $this->assertSame(100, $buffer100->capacity());
    }

    public function testDefaultCapacityIs60(): void
    {
        $buffer = new RingBuffer();
        $this->assertSame(60, $buffer->capacity());
    }

    // ═══════════════════════════════════════════════════════════════
    // Push operations
    // ═══════════════════════════════════════════════════════════════

    public function testPushIncrementsCount(): void
    {
        $buffer = new RingBuffer(10);

        $this->assertSame(0, $buffer->count());

        $buffer->push(1.0);
        $this->assertSame(1, $buffer->count());

        $buffer->push(2.0);
        $this->assertSame(2, $buffer->count());

        $buffer->push(3.0);
        $this->assertSame(3, $buffer->count());
    }

    public function testPushOverwritesOldestWhenFull(): void
    {
        $buffer = new RingBuffer(3);

        $buffer->push(1.0);
        $buffer->push(2.0);
        $buffer->push(3.0);

        $this->assertTrue($buffer->isFull());
        $this->assertSame(3, $buffer->count());

        // Push 4th value - should overwrite the oldest (1.0)
        $buffer->push(4.0);

        // Count stays at capacity
        $this->assertSame(3, $buffer->count());
        $this->assertTrue($buffer->isFull());

        // toArray should return [2.0, 3.0, 4.0] not [1.0, 2.0, 3.0]
        $array = $buffer->toArray();
        $this->assertSame([2.0, 3.0, 4.0], $array);
    }

    public function testCountAfterPushes(): void
    {
        $buffer = new RingBuffer(5);

        for ($i = 1; $i <= 3; $i++) {
            $buffer->push((float) $i);
        }
        $this->assertSame(3, $buffer->count());

        for ($i = 4; $i <= 7; $i++) {
            $buffer->push((float) $i);
        }
        // After 5 pushes, buffer is full, count stays at 5
        $this->assertSame(5, $buffer->count());
    }

    // ═══════════════════════════════════════════════════════════════
    // toArray
    // ═══════════════════════════════════════════════════════════════

    public function testToArrayReturnsChronologicalOrder(): void
    {
        $buffer = new RingBuffer(5);

        $buffer->push(10.0);
        $buffer->push(20.0);
        $buffer->push(30.0);

        $array = $buffer->toArray();

        $this->assertSame([10.0, 20.0, 30.0], $array);
    }

    public function testToArrayAfterPartialFill(): void
    {
        $buffer = new RingBuffer(10);

        $buffer->push(1.0);
        $buffer->push(2.0);
        $buffer->push(3.0);

        $array = $buffer->toArray();

        // Should return only filled slots, in order
        $this->assertCount(3, $array);
        $this->assertSame([1.0, 2.0, 3.0], $array);
    }

    public function testToArrayAfterFullFillAndWrap(): void
    {
        $buffer = new RingBuffer(4);

        // Fill the buffer
        $buffer->push(1.0);
        $buffer->push(2.0);
        $buffer->push(3.0);
        $buffer->push(4.0);

        // Wrap around
        $buffer->push(5.0);
        $buffer->push(6.0);

        $array = $buffer->toArray();

        // Should be in chronological order: oldest to newest
        $this->assertSame([3.0, 4.0, 5.0, 6.0], $array);
    }

    public function testToArrayEmptyBufferReturnsEmptyArray(): void
    {
        $buffer = new RingBuffer(10);
        $array = $buffer->toArray();

        $this->assertSame([], $array);
    }

    // ═══════════════════════════════════════════════════════════════
    // latest / oldest
    // ═══════════════════════════════════════════════════════════════

    public function testLatestReturnsNewest(): void
    {
        $buffer = new RingBuffer(5);

        $buffer->push(10.0);
        $buffer->push(20.0);
        $buffer->push(30.0);

        $this->assertSame(30.0, $buffer->latest());
    }

    public function testLatestAfterWrap(): void
    {
        $buffer = new RingBuffer(3);

        $buffer->push(1.0);
        $buffer->push(2.0);
        $buffer->push(3.0);
        // Buffer now full: [1.0, 2.0, 3.0], index=0

        $buffer->push(4.0);
        // Overwrote 1.0, buffer now: [4.0, 2.0, 3.0], index=1
        $this->assertSame(4.0, $buffer->latest());

        $buffer->push(5.0);
        // Overwrote 2.0, buffer now: [4.0, 5.0, 3.0], index=2
        $this->assertSame(5.0, $buffer->latest());
    }

    public function testLatestEmptyBufferReturnsNull(): void
    {
        $buffer = new RingBuffer(10);
        $this->assertNull($buffer->latest());
    }

    public function testOldestReturnsOldest(): void
    {
        $buffer = new RingBuffer(5);

        $buffer->push(10.0);
        $buffer->push(20.0);
        $buffer->push(30.0);

        $this->assertSame(10.0, $buffer->oldest());
    }

    public function testOldestAfterWrap(): void
    {
        $buffer = new RingBuffer(3);

        $buffer->push(1.0);
        $buffer->push(2.0);
        $buffer->push(3.0);

        // Latest is 3.0, oldest is 1.0
        $this->assertSame(1.0, $buffer->oldest());

        // Wrap around
        $buffer->push(4.0);
        // Oldest is now 2.0 (1.0 was overwritten)
        $this->assertSame(2.0, $buffer->oldest());

        $buffer->push(5.0);
        // Oldest is now 3.0 (2.0 was overwritten)
        $this->assertSame(3.0, $buffer->oldest());
    }

    public function testOldestEmptyBufferReturnsNull(): void
    {
        $buffer = new RingBuffer(10);
        $this->assertNull($buffer->oldest());
    }

    // ═══════════════════════════════════════════════════════════════
    // isEmpty / isFull
    // ═══════════════════════════════════════════════════════════════

    public function testIsEmptyWhenNoData(): void
    {
        $buffer = new RingBuffer(5);
        $this->assertTrue($buffer->isEmpty());
    }

    public function testIsNotEmptyAfterPush(): void
    {
        $buffer = new RingBuffer(5);
        $buffer->push(1.0);
        $this->assertFalse($buffer->isEmpty());
    }

    public function testIsFullWhenAtCapacity(): void
    {
        $buffer = new RingBuffer(3);
        $buffer->push(1.0);
        $buffer->push(2.0);
        $buffer->push(3.0);

        $this->assertTrue($buffer->isFull());
    }

    public function testIsNotFullBeforeCapacity(): void
    {
        $buffer = new RingBuffer(3);
        $buffer->push(1.0);
        $buffer->push(2.0);

        $this->assertFalse($buffer->isFull());
    }

    public function testIsFullAfterWrapAndFill(): void
    {
        $buffer = new RingBuffer(3);

        $buffer->push(1.0);
        $buffer->push(2.0);
        $buffer->push(3.0);
        $buffer->push(4.0); // Wrap, overwrote 1.0

        $this->assertTrue($buffer->isFull());
    }

    // ═══════════════════════════════════════════════════════════════
    // clear
    // ═══════════════════════════════════════════════════════════════

    public function testClearResetsBuffer(): void
    {
        $buffer = new RingBuffer(5);
        $buffer->push(1.0);
        $buffer->push(2.0);
        $buffer->push(3.0);

        $buffer->clear();

        $this->assertTrue($buffer->isEmpty());
        $this->assertFalse($buffer->isFull());
        $this->assertSame(0, $buffer->count());
        $this->assertSame([], $buffer->toArray());
        $this->assertNull($buffer->latest());
        $this->assertNull($buffer->oldest());
    }

    public function testClearThenPushRestarts(): void
    {
        $buffer = new RingBuffer(3);
        $buffer->push(1.0);
        $buffer->push(2.0);
        $buffer->push(3.0);

        $buffer->clear();

        $buffer->push(10.0);
        $buffer->push(20.0);

        $this->assertSame([10.0, 20.0], $buffer->toArray());
        $this->assertSame(20.0, $buffer->latest());
        $this->assertSame(10.0, $buffer->oldest());
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testPushExactCapacityCount(): void
    {
        $buffer = new RingBuffer(3);
        $buffer->push(1.0);
        $buffer->push(2.0);
        $buffer->push(3.0);

        $this->assertSame(3, $buffer->count());
        $this->assertTrue($buffer->isFull());
    }

    public function testSingleElementBuffer(): void
    {
        $buffer = new RingBuffer(1);
        $buffer->push(42.0);

        $this->assertSame(1, $buffer->count());
        $this->assertTrue($buffer->isFull());
        $this->assertSame(42.0, $buffer->latest());
        $this->assertSame(42.0, $buffer->oldest());
        $this->assertSame([42.0], $buffer->toArray());

        // Overwrite
        $buffer->push(43.0);
        $this->assertSame([43.0], $buffer->toArray());
        $this->assertSame(43.0, $buffer->latest());
    }

    public function testZeroCapacityBuffer(): void
    {
        $buffer = new RingBuffer(0);
        $this->assertSame(0, $buffer->capacity());
        $this->assertSame(0, $buffer->count());
        $this->assertSame([], $buffer->toArray());
    }

    public function testPushNegativeValues(): void
    {
        $buffer = new RingBuffer(5);
        $buffer->push(-10.5);
        $buffer->push(-20.5);

        $this->assertSame(-10.5, $buffer->oldest());
        $this->assertSame(-20.5, $buffer->latest());
    }

    public function testPushFloatValues(): void
    {
        $buffer = new RingBuffer(3);
        $buffer->push(1.5);
        $buffer->push(2.7);
        $buffer->push(3.9);

        $array = $buffer->toArray();
        $this->assertSame(1.5, $array[0]);
        $this->assertSame(2.7, $array[1]);
        $this->assertSame(3.9, $array[2]);
    }
}
