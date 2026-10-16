<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg;
use SugarCraft\Video\Msg\TickMsg;

/**
 * Unit tests for TickMsg.
 *
 * @covers \SugarCraft\Video\Msg\TickMsg
 */
final class MsgTest extends TestCase
{
    /**
     * @testdox TickMsg implements Msg interface
     */
    public function testTickMsgImplementsMsg(): void
    {
        $msg = new TickMsg();

        $this->assertInstanceOf(Msg::class, $msg);
    }

    /**
     * @testdox TickMsg has no public properties (empty signal)
     */
    public function testTickMsgIsEmptySignal(): void
    {
        $msg = new TickMsg();

        // TickMsg should have no public properties
        $reflection = new \ReflectionClass($msg);
        $props = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);
        $this->assertCount(0, $props);
    }

    /**
     * @testdox Two TickMsg instances are equal (identical empty signals)
     */
    public function testTickMsgInstancesAreEqual(): void
    {
        $msg1 = new TickMsg();
        $msg2 = new TickMsg();

        $this->assertEquals($msg1, $msg2);
    }
}
