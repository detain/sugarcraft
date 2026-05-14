<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Module;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Module\TickEpoch;

final class TickEpochTest extends TestCase
{
    public function testBumpIncrements(): void
    {
        $epoch = new TickEpoch();
        $this->assertSame(0, $epoch->value());

        $bumped = $epoch->bump();
        $this->assertSame(1, $bumped->value());
        $this->assertSame(0, $epoch->value()); // original unchanged
    }

    public function testIsStaleReturnsTrueWhenReceivedIsOlder(): void
    {
        $epoch = new TickEpoch(5);

        $this->assertTrue($epoch->isStale(3));
        $this->assertTrue($epoch->isStale(4));
        $this->assertTrue($epoch->isStale(0));
    }

    public function testIsStaleReturnsFalseWhenReceivedIsNewer(): void
    {
        $epoch = new TickEpoch(5);

        $this->assertFalse($epoch->isStale(5));
        $this->assertFalse($epoch->isStale(6));
        $this->assertFalse($epoch->isStale(100));
    }

    public function testShouldRefresh(): void
    {
        $epoch = new TickEpoch(5);

        $this->assertTrue($epoch->shouldRefresh(3));
        $this->assertTrue($epoch->shouldRefresh(5));
        $this->assertFalse($epoch->shouldRefresh(6));
    }
}
