<?php

declare(strict_types=1);

namespace SugarCraft\Bounce\Tests;

use SugarCraft\Bounce\Spring;
use SugarCraft\Bounce\SpringChain;
use PHPUnit\Framework\TestCase;

final class SpringChainTest extends TestCase
{
    public function testEmptyChainIsComplete(): void
    {
        $chain = SpringChain::build([]);
        $this->assertTrue($chain->isComplete());
        $this->assertEquals([], $chain->currentPositions());
    }

    public function testSingleStageCompletesWhenSettled(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $chain = SpringChain::build([[$spring, 0.0, 0.0, 100.0]]);

        $this->assertFalse($chain->isComplete());
        $this->assertEquals(0, $chain->activeStage());

        // Advance until settled (immutable - each tick returns new chain)
        $positions = [];
        for ($i = 0; $i < 600; $i++) {
            [$positions, $complete, $chain] = $chain->tick();
            if ($complete) {
                break;
            }
        }

        $this->assertTrue($chain->isComplete());
        $this->assertEqualsWithDelta(100.0, $positions[0], 0.01);
    }

    public function testSecondStageStartsAfterFirstSettles(): void
    {
        $spring1 = new Spring(1.0 / 60.0, 6.0, 1.0);
        $spring2 = new Spring(1.0 / 60.0, 6.0, 1.0);

        $chain = SpringChain::build([
            [$spring1, 0.0, 0.0, 100.0],
            [$spring2, 0.0, 0.0, 50.0],
        ]);

        // First stage is active
        $this->assertEquals(0, $chain->activeStage());

        // Advance first stage until settled (immutable - each tick returns new chain)
        for ($i = 0; $i < 600; $i++) {
            [, , $chain] = $chain->tick();
            if ($chain->activeStage() > 0) {
                break;
            }
        }

        // Second stage should now be active
        $this->assertEquals(1, $chain->activeStage());

        // First stage position should be preserved at target
        $positions = $chain->currentPositions();
        $this->assertEqualsWithDelta(100.0, $positions[0], 0.01);
    }

    public function testChainCompletesAllStages(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);

        $chain = SpringChain::build([
            [$spring, 0.0, 0.0, 100.0],
            [$spring, 0.0, 0.0, 50.0],
            [$spring, 0.0, 0.0, 75.0],
        ]);

        $complete = false;
        $positions = [];
        for ($i = 0; $i < 2000 && !$complete; $i++) {
            [$positions, $complete, $chain] = $chain->tick();
        }

        $this->assertTrue($complete);
        $finalPositions = $chain->currentPositions();
        $this->assertEqualsWithDelta(100.0, $finalPositions[0], 0.01);
        $this->assertEqualsWithDelta(50.0, $finalPositions[1], 0.01);
        $this->assertEqualsWithDelta(75.0, $finalPositions[2], 0.01);
    }

    public function testWithStageAddsNewStage(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $chain = SpringChain::build([
            [$spring, 0.0, 0.0, 100.0],
        ])->withStage($spring, 0.0, 0.0, 50.0);

        $this->assertCount(2, $chain->currentPositions());
    }

    public function testTickReturnsPositionsAndCompleteStatus(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $chain = SpringChain::build([[$spring, 0.0, 0.0, 100.0]]);

        [$positions, $complete, $newChain] = $chain->tick();

        $this->assertIsArray($positions);
        $this->assertFalse($complete);
        $this->assertInstanceOf(SpringChain::class, $newChain);
    }
}
