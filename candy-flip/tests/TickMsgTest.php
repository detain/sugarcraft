<?php

declare(strict_types=1);

namespace SugarCraft\Flip\Tests;

use SugarCraft\Core\Msg;
use SugarCraft\Flip\TickMsg;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TickMsg - the per-frame animation tick message.
 */
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

    public function testMultipleInstancesAreDistinct(): void
    {
        $a = new TickMsg();
        $b = new TickMsg();
        $this->assertNotSame($a, $b);
    }
}
