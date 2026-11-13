<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Canvas;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\Canvas\Graph;

/**
 * Caller-controlled sampling cannot drive allocation past the internal
 * ceiling (sugar-charts-114): samples/radius arrive straight from user
 * arguments, and the WithLimit variants used to compute the FULL circle
 * before slicing.
 */
final class GraphCapsTest extends TestCase
{
    private const CEILING = 4096;

    public function testHugeSampleCountIsBoundedAndFast(): void
    {
        $start = hrtime(true);
        $pts = Graph::getCirclePoints(0, 0, 50, 1_000_000_000);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        self::assertCount(self::CEILING, $pts, 'the producer must clamp BEFORE allocating');
        self::assertLessThan(50.0, $elapsedMs, "clamped sampling must be instant, took {$elapsedMs}ms");
    }

    public function testJustOverTheCeilingDropsToIt(): void
    {
        // Fast discriminating leg for the same contract: 20 000 requested
        // must come back 4 096, not 20 000 minus nothing.
        self::assertCount(self::CEILING, Graph::getCirclePoints(0, 0, 10, 20_000));
        self::assertCount(20_000 > self::CEILING ? self::CEILING : 20_000, Graph::getCirclePointsWithLimit(0, 0, 10, 999_999, 20_000));
    }

    public function testHugeRadiusEnumerationIsBounded(): void
    {
        // Public-boundary contract pin: with BOTH clamps removed the
        // 4×r enumeration allocates 400 000 points in ~550ms (measured
        // during mutation testing). The producer clamp alone already
        // protects this path, so the enumeration clamp here is honest
        // defence-in-depth (a single-clamp mutation is absorbed by the
        // other); the composite is what callers may rely on.
        $start = hrtime(true);
        $pts = Graph::getFullCirclePoints(0, 0, 1_000_000);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        self::assertLessThanOrEqual(self::CEILING, count($pts));
        self::assertLessThan(50.0, $elapsedMs, "4×r enumeration must clamp pre-allocation, took {$elapsedMs}ms");
    }

    public function testLimitVariantsNoLongerAllocateThenCap(): void
    {
        $pts = Graph::getFullCirclePointsWithLimit(0, 0, 1_000_000, 1_000_000_000);
        self::assertLessThanOrEqual(self::CEILING, count($pts));

        $circle = Graph::getCirclePointsWithLimit(0, 0, 10, PHP_INT_MAX, PHP_INT_MAX);
        self::assertLessThanOrEqual(self::CEILING, count($circle));
    }

    public function testSubCeilingSamplingBehaviourIsUntouched(): void
    {
        // The clamp must be invisible to every legitimate caller.
        self::assertCount(32, Graph::getCirclePoints(0, 0, 5));
        self::assertCount(16, Graph::getCirclePoints(0, 0, 5, 16));
        self::assertCount(8, Graph::getCirclePoints(0, 0, 5, 3), 'the old max(8,…) floor still applies');
        $full = Graph::getFullCirclePoints(4, 4, 3);
        self::assertGreaterThan(0, count($full));
        self::assertLessThanOrEqual(16, count($full));
    }

    public function testCeilingConstantPinned(): void
    {
        $ref = new \ReflectionClass(Graph::class);
        self::assertSame(self::CEILING, $ref->getConstant('MAX_SAMPLES'));
    }
}
