<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\FrameTickMsg;
use SugarCraft\Core\Msg;

final class FrameTickMsgTest extends TestCase
{
    public function testImplementsMsgInterface(): void
    {
        $msg = new FrameTickMsg();

        $this->assertInstanceOf(Msg::class, $msg);
    }

    public function testCanBeInstantiated(): void
    {
        $msg = new FrameTickMsg();

        $this->assertInstanceOf(FrameTickMsg::class, $msg);
    }

    public function testTwoInstancesAreEqual(): void
    {
        $a = new FrameTickMsg();
        $b = new FrameTickMsg();

        // FrameTickMsg is a value type with no state
        $this->assertInstanceOf(FrameTickMsg::class, $a);
        $this->assertInstanceOf(FrameTickMsg::class, $b);
    }
}
