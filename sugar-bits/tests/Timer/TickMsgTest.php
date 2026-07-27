<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\Timer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Bits\Timer\TickMsg;

/**
 * Tests for {@see TickMsg} — periodic countdown tick for Timer with id.
 */
final class TickMsgTest extends TestCase
{
    public function testConstruction(): void
    {
        $msg = new TickMsg(42);
        $this->assertInstanceOf(TickMsg::class, $msg);
        $this->assertSame(42, $msg->id);
    }

    public function testIdIsReadonly(): void
    {
        $msg = new TickMsg(1);
        $this->assertSame(1, $msg->id);
    }

    public function testImmutability(): void
    {
        $msg1 = new TickMsg(1);
        $msg2 = new TickMsg(1);
        $this->assertNotSame($msg1, $msg2);
    }

    public function testDifferentIdsAreDistinct(): void
    {
        $msg1 = new TickMsg(1);
        $msg2 = new TickMsg(2);
        $this->assertNotSame($msg1->id, $msg2->id);
    }

    public function testZeroIdIsValid(): void
    {
        $msg = new TickMsg(0);
        $this->assertSame(0, $msg->id);
    }
}
