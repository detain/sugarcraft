<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Progress;

use PHPUnit\Framework\TestCase;
use SugarCraft\Widgets\Progress\SpringTickMsg;

/**
 * Tests for {@see SpringTickMsg} — the id/epoch-routed spring tick.
 */
final class SpringTickMsgTest extends TestCase
{
    public function testConstruction(): void
    {
        $msg = new SpringTickMsg();
        $this->assertInstanceOf(SpringTickMsg::class, $msg);
    }

    public function testImmutability(): void
    {
        $msg1 = new SpringTickMsg();
        $msg2 = new SpringTickMsg();
        // Each instance is independent — no fields to compare
        $this->assertNotSame($msg1, $msg2);
    }

    public function testCarriesRoutingIdentity(): void
    {
        $msg = new SpringTickMsg(7, 2);
        $this->assertSame(7, $msg->id);
        $this->assertSame(2, $msg->epoch);
    }
}
