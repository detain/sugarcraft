<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\RepoMap;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\RepoMap\PageRank;

/**
 * Roadmap 5.5-3: personalised weighted PageRank with networkx's semantics.
 * The expected figures are solved in closed form in each test, not copied
 * from the implementation.
 */
final class PageRankTest extends TestCase
{
    public function testAnEmptyGraphHasNoRanks(): void
    {
        self::assertSame([], PageRank::new()->rank([], []));
    }

    public function testASymmetricCycleRanksEveryNodeEqually(): void
    {
        $ranks = PageRank::new()->rank(['a', 'b', 'c'], ['a' => ['b' => 1.0], 'b' => ['c' => 1.0], 'c' => ['a' => 1.0]]);

        foreach ($ranks as $rank) {
            self::assertEqualsWithDelta(1 / 3, $rank, 1e-6);
        }
    }

    public function testADanglingNodeRedistributesItsRankThroughTheTeleportVector(): void
    {
        // a -> b, b dangling, uniform teleport: a = 0.075 + 0.425 b, a + b = 1.
        $ranks = PageRank::new()->rank(['a', 'b'], ['a' => ['b' => 1.0]]);

        self::assertEqualsWithDelta(0.5 / 1.425, $ranks['a'], 1e-5);
        self::assertEqualsWithDelta(1 - 0.5 / 1.425, $ranks['b'], 1e-5);
        self::assertEqualsWithDelta(1.0, array_sum($ranks), 1e-9);
    }

    public function testPersonalisationPullsRankTowardItsNodes(): void
    {
        // Cycle a -> b -> c -> a teleporting only to a:
        // a = 0.15 + 0.85 c, b = 0.85 a, c = 0.85 b  =>  a = 0.15 / (1 - 0.85^3).
        $ranks = PageRank::new()->rank(
            ['a', 'b', 'c'],
            ['a' => ['b' => 1.0], 'b' => ['c' => 1.0], 'c' => ['a' => 1.0]],
            ['a' => 7.0],
        );

        $a = 0.15 / (1 - 0.85 ** 3);
        self::assertEqualsWithDelta($a, $ranks['a'], 1e-5);
        self::assertEqualsWithDelta(0.85 * $a, $ranks['b'], 1e-5);
        self::assertEqualsWithDelta(0.85 * 0.85 * $a, $ranks['c'], 1e-5);
    }

    public function testEdgeWeightsSplitASourcesRankProportionally(): void
    {
        // a -> b (3), a -> c (1); b and c dangling. By symmetry of the
        // dangling redistribution, b - c = 0.85 * a * (3/4 - 1/4).
        $ranks = PageRank::new()->rank(['a', 'b', 'c'], ['a' => ['b' => 3.0, 'c' => 1.0]]);

        self::assertGreaterThan($ranks['c'], $ranks['b']);
        self::assertEqualsWithDelta(0.85 * $ranks['a'] * 0.5, $ranks['b'] - $ranks['c'], 1e-5);
    }

    public function testAZeroWeightEdgeContributesNothing(): void
    {
        $split = PageRank::new()->rank(['a', 'b', 'c'], ['a' => ['b' => 2.0, 'c' => 2.0]]);
        $self = PageRank::new()->rank(['a', 'b', 'c'], ['a' => ['b' => 2.0, 'c' => 2.0, 'a' => 0.0]]);

        self::assertEqualsWithDelta($split['b'], $self['b'], 1e-12);
    }

    public function testAPersonalisationWeighingNoNodeFallsBackToUniform(): void
    {
        $plain = PageRank::new()->rank(['a', 'b'], ['a' => ['b' => 1.0]]);
        $ghost = PageRank::new()->rank(['a', 'b'], ['a' => ['b' => 1.0]], ['zzz' => 5.0]);

        self::assertEqualsWithDelta($plain['a'], $ghost['a'], 1e-12);
    }

    public function testEdgesToUnknownNodesAreIgnored(): void
    {
        $ranks = PageRank::new()->rank(['a'], ['a' => ['ghost' => 1.0], 'ghost' => ['a' => 1.0]]);

        self::assertSame(['a'], array_keys($ranks));
        self::assertEqualsWithDelta(1.0, $ranks['a'], 1e-9);
    }

    public function testTheIterationCapReturnsTheLastIterateRatherThanThrowing(): void
    {
        $ranks = PageRank::new(maxIterations: 1)->rank(['a', 'b'], ['a' => ['b' => 1.0]]);

        self::assertEqualsWithDelta(1.0, array_sum($ranks), 1e-9);
        self::assertGreaterThan($ranks['a'], $ranks['b']);
    }

    public function testInvalidParametersAreRefused(): void
    {
        foreach ([[1.0, 100, 1e-6], [0.0, 100, 1e-6], [0.85, 0, 1e-6], [0.85, 10, 0.0]] as [$d, $i, $t]) {
            try {
                PageRank::new($d, $i, $t);
                self::fail("accepted damping {$d}, iterations {$i}, tolerance {$t}");
            } catch (\InvalidArgumentException) {
                self::assertSame(0.85, PageRank::new()->damping());
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        PageRank::new()->rank(['a', 'b'], ['a' => ['b' => -1.0]]);
    }
}
