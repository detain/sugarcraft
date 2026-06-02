<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg;
use SugarCraft\Reel\Decode\RgbFrame;
use SugarCraft\Reel\Msg\FrameMsg;
use SugarCraft\Reel\Msg\TickMsg;

/**
 * Unit tests for FrameMsg and TickMsg.
 *
 * @covers \SugarCraft\Reel\Msg\FrameMsg
 * @covers \SugarCraft\Reel\Msg\TickMsg
 */
final class MsgTest extends TestCase
{
    // -------------------------------------------------------------------------
    // FrameMsg
    // -------------------------------------------------------------------------

    /**
     * @testdox FrameMsg implements Msg interface
     */
    public function testFrameMsgImplementsMsg(): void
    {
        $frame = new RgbFrame("\x00\x00\x00", 1, 1);
        $msg = new FrameMsg($frame);

        $this->assertInstanceOf(Msg::class, $msg);
    }

    /**
     * @testdox FrameMsg carries the given RgbFrame
     */
    public function testFrameMsgCarriesRgbFrame(): void
    {
        $frame = new RgbFrame("\xff\x00\x00", 2, 2);
        $msg = new FrameMsg($frame);

        $this->assertSame($frame, $msg->frame);
        $this->assertSame(2, $msg->frame->w);
        $this->assertSame(2, $msg->frame->h);
    }

    /**
     * @testdox FrameMsg with red pixel frame carries correct bytes
     */
    public function testFrameMsgWithRedPixel(): void
    {
        // 1×1 red pixel: R=255, G=0, B=0
        $frame = new RgbFrame("\xff\x00\x00", 1, 1);
        $msg = new FrameMsg($frame);

        $this->assertSame("\xff\x00\x00", $msg->frame->bytes);
    }

    // -------------------------------------------------------------------------
    // TickMsg
    // -------------------------------------------------------------------------

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
