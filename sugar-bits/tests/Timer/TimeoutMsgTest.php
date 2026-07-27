<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\Timer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Bits\Timer\TimeoutMsg;

/**
 * Tests for {@see TimeoutMsg} — emitted once when Timer's remaining time reaches zero.
 */
final class TimeoutMsgTest extends TestCase
{
    public function testConstruction(): void
    {
        $msg = new TimeoutMsg(42);
        $this->assertInstanceOf(TimeoutMsg::class, $msg);
        $this->assertSame(42, $msg->id);
    }

    public function testIdIsReadonly(): void
    {
        $msg = new TimeoutMsg(1);
        $this->assertSame(1, $msg->id);
    }

    public function testImmutability(): void
    {
        $msg1 = new TimeoutMsg(1);
        $msg2 = new TimeoutMsg(1);
        $this->assertNotSame($msg1, $msg2);
    }

    public function testDifferentIdsAreDistinct(): void
    {
        $msg1 = new TimeoutMsg(1);
        $msg2 = new TimeoutMsg(2);
        $this->assertNotSame($msg1->id, $msg2->id);
    }

    public function testZeroIdIsValid(): void
    {
        $msg = new TimeoutMsg(0);
        $this->assertSame(0, $msg->id);
    }
}
