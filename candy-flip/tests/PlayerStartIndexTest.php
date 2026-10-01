<?php

declare(strict_types=1);

namespace SugarCraft\Flip\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Flip\Frame;
use SugarCraft\Flip\Player;

/**
 * Fail-fast pin for the Player start index: the ctor must refuse an index
 * that no update() or view() could ever honor — negative, past-the-end on a
 * non-empty reel, or any non-zero index on an empty one — while the empty +
 * zero combination stays legal (the reel-less Player still answers the Model
 * contract). Without these guards a wiring bug surfaces as a PHP warning
 * inside view(), not as a construction error.
 */
final class PlayerStartIndexTest extends TestCase
{
    /** @return list<Frame> */
    private function frames(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = new Frame([[$i, $i, $i]], 10);
        }
        return $out;
    }

    public function testNegativeStartIndexIsRefusedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Player($this->frames(3), index: -1);
    }

    public function testStartIndexPastTheEndIsRefusedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Player($this->frames(3), index: 3);
    }

    public function testAnyNonZeroIndexOnAnEmptyReelIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Player([], index: 1);
    }

    public function testEmptyReelWithIndexZeroStaysLegal(): void
    {
        $player = new Player([]);
        $this->assertSame(0, $player->index);
        $this->assertNull($player->init());
    }

    public function testLastValidIndexIsAccepted(): void
    {
        $player = new Player($this->frames(3), index: 2);
        $this->assertSame(2, $player->index);
    }
}
