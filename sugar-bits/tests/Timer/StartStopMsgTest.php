<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\Timer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Bits\Timer\StartStopMsg;

/**
 * Tests for {@see StartStopMsg} — internal signal when Timer/Stopwatch running flag flips.
 */
final class StartStopMsgTest extends TestCase
{
    public function testConstruction(): void
    {
        $msg = new StartStopMsg(42, true);
        $this->assertInstanceOf(StartStopMsg::class, $msg);
        $this->assertSame(42, $msg->id);
        $this->assertTrue($msg->running);
    }

    public function testIdIsReadonly(): void
    {
        $msg = new StartStopMsg(1, false);
        $this->assertSame(1, $msg->id);
    }

    public function testRunningIsReadonly(): void
    {
        $msg = new StartStopMsg(1, true);
        $this->assertTrue($msg->running);

        $msg2 = new StartStopMsg(1, false);
        $this->assertFalse($msg2->running);
    }

    public function testImmutability(): void
    {
        $msg1 = new StartStopMsg(1, true);
        $msg2 = new StartStopMsg(1, true);
        $this->assertNotSame($msg1, $msg2);
    }

    public function testDifferentIdsAreDistinct(): void
    {
        $msg1 = new StartStopMsg(1, true);
        $msg2 = new StartStopMsg(2, true);
        $this->assertNotSame($msg1->id, $msg2->id);
    }

    public function testFalseRunningState(): void
    {
        $msg = new StartStopMsg(5, false);
        $this->assertSame(5, $msg->id);
        $this->assertFalse($msg->running);
    }

    public function testZeroIdIsValid(): void
    {
        $msg = new StartStopMsg(0, true);
        $this->assertSame(0, $msg->id);
        $this->assertTrue($msg->running);
    }
}
