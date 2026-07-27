<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\Progress;

use PHPUnit\Framework\TestCase;
use SugarCraft\Bits\Progress\SpringTickMsg;

/**
 * Tests for {@see SpringTickMsg} — sentinel Msg for AnimatedProgress spring ticks.
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
}
