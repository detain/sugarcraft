<?php

declare(strict_types=1);

namespace SugarCraft\Bounce\Tests;

use SugarCraft\Bounce\Spring;
use SugarCraft\Bounce\SpringCollection;
use PHPUnit\Framework\TestCase;

final class SpringCollectionTest extends TestCase
{
    public function testAddAndGet(): void
    {
        $collection = new SpringCollection();
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);

        $collection->add('spring1', $spring, 0.0, 0.0, 100.0);

        $this->assertTrue($collection->has('spring1'));
        $this->assertEqualsWithDelta(0.0, $collection->get('spring1'), 1e-9);
    }

    public function testRemove(): void
    {
        $collection = new SpringCollection();
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);

        $collection->add('spring1', $spring);
        $this->assertTrue($collection->has('spring1'));

        $collection->remove('spring1');
        $this->assertFalse($collection->has('spring1'));
    }

    public function testTickUpdatesPositions(): void
    {
        $collection = new SpringCollection();
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);

        $collection->add('spring1', $spring, 0.0, 0.0, 100.0);

        // First tick should move the spring toward target (immutable - returns new collection)
        $newCollection = $collection->tick();
        $positions = $newCollection->all();
        $this->assertGreaterThan(0.0, $positions['spring1']);
    }

    public function testAllReturnsAllPositions(): void
    {
        $collection = new SpringCollection();
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);

        $collection->add('a', $spring, 0.0, 0.0, 10.0);
        $collection->add('b', $spring, 0.0, 0.0, 20.0);

        $all = $collection->all();
        $this->assertCount(2, $all);
        $this->assertArrayHasKey('a', $all);
        $this->assertArrayHasKey('b', $all);
    }

    public function testMultipleSpringsConverge(): void
    {
        $collection = new SpringCollection();
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);

        $collection->add('fast', $spring, 0.0, 0.0, 100.0);
        $collection->add('slow', $spring, 0.0, 0.0, 50.0);

        // Run many ticks (immutable - each tick returns a new collection)
        for ($i = 0; $i < 600; $i++) {
            $collection = $collection->tick();
        }

        $all = $collection->all();
        $this->assertEqualsWithDelta(100.0, $all['fast'], 0.01);
        $this->assertEqualsWithDelta(50.0, $all['slow'], 0.01);
    }

    public function testSetTarget(): void
    {
        $collection = new SpringCollection();
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);

        $collection->add('spring1', $spring, 0.0, 0.0, 10.0);
        $collection->setTarget('spring1', 50.0);

        $this->assertEqualsWithDelta(50.0, $collection->getTarget('spring1'), 1e-9);
    }

    public function testHasReturnsFalseForNonexistent(): void
    {
        $collection = new SpringCollection();
        $this->assertFalse($collection->has('nonexistent'));
    }
}
