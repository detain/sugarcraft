<?php

declare(strict_types=1);

namespace SugarCraft\Spring\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Spring\Spring;

/**
 * The constructor's fourth parameter ?bool $reducedMotionOverride pins the
 * reduced-motion decision per spring, independent of the environment lookup
 * (docs-fix-1023 spring MAJOR-F: documented nowhere and tested by no one).
 *
 * @group parallel-unsafe
 */
final class ReducedMotionOverrideTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('REDUCE_MOTION');
        putenv('PREFERS_REDUCED_MOTION');
    }

    protected function tearDown(): void
    {
        putenv('REDUCE_MOTION');
        putenv('PREFERS_REDUCED_MOTION');
        parent::tearDown();
    }

    public function testTrueOverrideSnapsEvenWithNoEnvironmentSignal(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0, true);
        [$pos, $vel] = $spring->update(0.0, 0.0, 100.0);

        $this->assertSame(100.0, $pos);
        $this->assertSame(0.0, $vel);
    }

    public function testFalseOverrideAnimatesEvenWithReducedMotionInEnvironment(): void
    {
        putenv('REDUCE_MOTION=1');

        $spring = new Spring(1.0 / 60.0, 6.0, 1.0, false);
        [$pos] = $spring->update(0.0, 0.0, 100.0);

        $this->assertLessThan(100.0, $pos, 'override false must win over REDUCE_MOTION=1');
    }

    public function testNullOverrideDefersToTheEnvironmentBothWays(): void
    {
        $unpinned = new Spring(1.0 / 60.0, 6.0, 1.0, null);
        [$quietPos] = $unpinned->update(0.0, 0.0, 100.0);
        $this->assertLessThan(100.0, $quietPos, 'no env signal + null override must animate');

        putenv('PREFERS_REDUCED_MOTION=1');
        [$loudPos] = $unpinned->update(0.0, 0.0, 100.0);
        $this->assertSame(100.0, $loudPos, 'env signal + null override must snap at call time');
    }
}
