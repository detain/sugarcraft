<?php

declare(strict_types=1);

namespace SugarCraft\Flap\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Flap\TickMsg;
use SugarCraft\Core\Msg;

final class TickMsgTest extends TestCase
{
    public function testCanBeInstantiated(): void
    {
        $msg = new TickMsg();
        $this->assertInstanceOf(TickMsg::class, $msg);
    }

    public function testImplementsMsgInterface(): void
    {
        $msg = new TickMsg();
        $this->assertInstanceOf(Msg::class, $msg);
    }

    public function testIsFinalClass(): void
    {
        $reflection = new \ReflectionClass(TickMsg::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function testTwoInstancesAreDistinctObjects(): void
    {
        $a = new TickMsg();
        $b = new TickMsg();
        $this->assertNotSame($a, $b);
    }
}
