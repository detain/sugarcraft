<?php

declare(strict_types=1);

namespace SugarCraft\Constraints\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Constraints\Direction;

/**
 * SC-07 pins: Direction carries the same self-answering flip the Dock
 * vocabulary already ships (Side::other()), so call sites stop hard-coding
 * the counterpart axis.
 */
final class DirectionTest extends TestCase
{
    public function testOtherFlipsEachAxis(): void
    {
        $this->assertSame(Direction::Vertical, Direction::Horizontal->other());
        $this->assertSame(Direction::Horizontal, Direction::Vertical->other());
    }

    public function testDoubleFlipIsIdentity(): void
    {
        $this->assertSame(Direction::Horizontal, Direction::Horizontal->other()->other());
        $this->assertSame(Direction::Vertical, Direction::Vertical->other()->other());
    }
}
