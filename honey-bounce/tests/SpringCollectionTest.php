<?php

declare(strict_types=1);

namespace SugarCraft\Bounce\Tests;

use SugarCraft\Bounce\Spring;
use SugarCraft\Bounce\SpringCollection;
use PHPUnit\Framework\TestCase;

final class SpringCollectionTest extends TestCase
{
    public function testWithSpringAndGet(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $collection = (new SpringCollection())->withSpring('spring1', $spring, 0.0, 0.0, 100.0);

        $this->assertTrue($collection->has('spring1'));
        $this->assertEqualsWithDelta(0.0, $collection->get('spring1'), 1e-9);
    }

    public function testWithSpringReturnsNewInstanceLeavingOriginalUnchanged(): void
    {
        $original = new SpringCollection();
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);

        $added = $original->withSpring('a', $spring, 0.0, 0.0, 10.0);

        $this->assertNotSame($original, $added);
        $this->assertTrue($added->has('a'));
        $this->assertFalse($original->has('a'), 'withSpring() must not mutate the original');
    }

    public function testWithoutReturnsNewInstanceLeavingOriginalUnchanged(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $withSpring = (new SpringCollection())->withSpring('a', $spring);

        $without = $withSpring->without('a');

        $this->assertNotSame($withSpring, $without);
        $this->assertFalse($without->has('a'));
        $this->assertTrue($withSpring->has('a'), 'without() must not mutate the original');
    }

    public function testWithTargetReturnsNewInstanceLeavingOriginalUnchanged(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $original = (new SpringCollection())->withSpring('a', $spring, 0.0, 0.0, 10.0);

        $retargeted = $original->withTarget('a', 50.0);

        $this->assertNotSame($original, $retargeted);
        $this->assertEqualsWithDelta(50.0, $retargeted->getTarget('a'), 1e-9);
        $this->assertEqualsWithDelta(10.0, $original->getTarget('a'), 1e-9, 'withTarget() must not mutate the original');
    }

    public function testTickUpdatesPositions(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $collection = (new SpringCollection())->withSpring('spring1', $spring, 0.0, 0.0, 100.0);

        // First tick should move the spring toward target (immutable - returns new collection).
        $newCollection = $collection->tick();
        $positions = $newCollection->all();
        $this->assertGreaterThan(0.0, $positions['spring1']);
        // Original is untouched by tick().
        $this->assertEqualsWithDelta(0.0, $collection->get('spring1'), 1e-9);
    }

    public function testAllReturnsAllPositions(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $collection = (new SpringCollection())
            ->withSpring('a', $spring, 0.0, 0.0, 10.0)
            ->withSpring('b', $spring, 0.0, 0.0, 20.0);

        $all = $collection->all();
        $this->assertCount(2, $all);
        $this->assertArrayHasKey('a', $all);
        $this->assertArrayHasKey('b', $all);
    }

    public function testMultipleSpringsConverge(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $collection = (new SpringCollection())
            ->withSpring('fast', $spring, 0.0, 0.0, 100.0)
            ->withSpring('slow', $spring, 0.0, 0.0, 50.0);

        // Run many ticks (immutable - each tick returns a new collection).
        for ($i = 0; $i < 600; $i++) {
            $collection = $collection->tick();
        }

        $all = $collection->all();
        $this->assertEqualsWithDelta(100.0, $all['fast'], 0.01);
        $this->assertEqualsWithDelta(50.0, $all['slow'], 0.01);
    }

    public function testWithTarget(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $collection = (new SpringCollection())
            ->withSpring('spring1', $spring, 0.0, 0.0, 10.0)
            ->withTarget('spring1', 50.0);

        $this->assertEqualsWithDelta(50.0, $collection->getTarget('spring1'), 1e-9);
    }

    public function testHasReturnsFalseForNonexistent(): void
    {
        $collection = new SpringCollection();
        $this->assertFalse($collection->has('nonexistent'));
    }

    // ── Deprecated in-place shims (retained one release) ────────────────────

    /**
     * add()/remove()/setTarget() still mutate in place for backward
     * compatibility. Deprecations are swallowed so the result assertions stay
     * clean under failOnWarning; the notices themselves are asserted below.
     */
    public function testDeprecatedShimsStillMutateInPlace(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $collection = new SpringCollection();

        set_error_handler(static fn(): bool => true, E_USER_DEPRECATED);
        try {
            $collection->add('spring1', $spring, 0.0, 0.0, 10.0);
            $this->assertTrue($collection->has('spring1'), 'add() must mutate in place');

            $collection->setTarget('spring1', 42.0);
            $this->assertEqualsWithDelta(42.0, $collection->getTarget('spring1'), 1e-9, 'setTarget() must mutate in place');

            $collection->remove('spring1');
            $this->assertFalse($collection->has('spring1'), 'remove() must mutate in place');
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @dataProvider deprecatedCalls
     */
    public function testDeprecatedShimsEmitDeprecation(callable $call, string $replacement): void
    {
        $captured = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            $captured[] = [$errno, $errstr];
            return true;
        }, E_USER_DEPRECATED);
        try {
            $call();
        } finally {
            restore_error_handler();
        }

        $this->assertCount(1, $captured, 'shim must emit exactly one deprecation');
        $this->assertSame(E_USER_DEPRECATED, $captured[0][0]);
        $this->assertStringContainsStringIgnoringCase('deprecated', $captured[0][1]);
        $this->assertStringContainsString($replacement, $captured[0][1]);
    }

    /** @return iterable<string, array{0: callable, 1: string}> */
    public static function deprecatedCalls(): iterable
    {
        // withSpring() is used for setup because it does not emit a deprecation,
        // so only the shim under test contributes to the captured notice.
        yield 'add' => [
            static fn() => (new SpringCollection())->add('x', new Spring(1.0 / 60.0, 6.0, 1.0)),
            'withSpring()',
        ];
        yield 'remove' => [
            static fn() => (new SpringCollection())
                ->withSpring('x', new Spring(1.0 / 60.0, 6.0, 1.0))
                ->remove('x'),
            'without()',
        ];
        yield 'setTarget' => [
            static fn() => (new SpringCollection())
                ->withSpring('x', new Spring(1.0 / 60.0, 6.0, 1.0))
                ->setTarget('x', 5.0),
            'withTarget()',
        ];
    }
}
