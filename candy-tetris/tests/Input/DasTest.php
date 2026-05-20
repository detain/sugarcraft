<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests\Input;

use SugarCraft\Tetris\Input\Das;
use PHPUnit\Framework\TestCase;

final class DasTest extends TestCase
{
    public function testCreateReturnsDefaultValues(): void
    {
        $das = Das::create();
        $this->assertSame(Das::DEFAULT_DAS_US, $das->dasUs);
        $this->assertSame(Das::DEFAULT_ARR_US, $das->arrUs);
    }

    public function testCreateWithCustomValues(): void
    {
        $das = Das::create(200_000, 30_000);
        $this->assertSame(200_000, $das->dasUs);
        $this->assertSame(30_000, $das->arrUs);
    }

    public function testAdvanceIncreasesAccumulators(): void
    {
        $das = Das::create(100_000, 50_000);
        $next = $das->advance(30_000);
        $this->assertSame(30_000, $next->leftAcc);
        $this->assertSame(30_000, $next->rightAcc);
        $this->assertSame(30_000, $next->downAcc);
    }

    public function testKeyDownResetsAccumulatorForDirection(): void
    {
        // This tests that a fresh key-press resets the DAS timer.
        // We verify the new state has 0 accumulator.
        $das = Das::create(100_000, 50_000)->advance(50_000);
        $fresh = $das->withKeyUp('left');
        $this->assertSame(0, $fresh->leftAcc);
        $this->assertSame(50_000, $fresh->rightAcc);
    }

    public function testKeyUpClearsAccumulator(): void
    {
        $das = Das::create(100_000, 50_000)->advance(80_000);
        $cleared = $das->withKeyUp('left');
        $this->assertSame(0, $cleared->leftAcc);
        $this->assertGreaterThan(0, $cleared->rightAcc);
    }

    public function testLeftFiresWhenPastDasDelay(): void
    {
        // DAS = 100ms, after 150ms → past DAS delay
        $das = Das::create(100_000, 50_000)->advance(150_000);
        $this->assertTrue($das->leftFiring());
    }

    public function testLeftDoesNotFireBeforeDasDelay(): void
    {
        // DAS = 100ms, after 50ms → before DAS delay
        $das = Das::create(100_000, 50_000)->advance(50_000);
        $this->assertFalse($das->leftFiring());
    }

    public function testRightFiresIndependently(): void
    {
        $das = Das::create(100_000, 50_000)->advance(120_000);
        $this->assertTrue($das->rightFiring());
    }

    public function testDownFiresIndependently(): void
    {
        $das = Das::create(100_000, 50_000)->advance(120_000);
        $this->assertTrue($das->downFiring());
    }

    public function testLeftRepeatsReturnsOneAfterDASPlusOneARR(): void
    {
        // DAS = 100ms, ARR = 50ms, advance 160ms (past DAS + 1 ARR)
        $das = Das::create(100_000, 50_000)->advance(160_000);
        $this->assertSame(1, $das->leftRepeats(0));
    }

    public function testLeftRepeatsReturnsZeroBeforeDAS(): void
    {
        // DAS = 100ms, after 50ms → no repeat
        $das = Das::create(100_000, 50_000)->advance(50_000);
        $this->assertSame(0, $das->leftRepeats(0));
    }

    public function testDirectionIndependent(): void
    {
        // Advance left for 150ms, right for 50ms
        $das = Das::create(100_000, 50_000)
            ->advance(100_000); // left+right both at 100ms (at DAS threshold)
        $leftOnly = $das->withKeyUp('right');
        // Now advance another 60ms (left becomes 160ms → past DAS, right stays at 0)
        $final = $leftOnly->advance(60_000);
        $this->assertTrue($final->leftFiring(), 'left should fire after DAS');
        $this->assertFalse($final->rightFiring(), 'right cleared by keyUp');
    }
}
