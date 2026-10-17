<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Testing\Input\TickMsg;

final class TickMsgTest extends TestCase
{
    public function testConstructorSetsSeconds(): void
    {
        $tick = new TickMsg(1.5);

        $this->assertSame(1.5, $tick->seconds);
    }

    public function testConstructorSetsZeroSeconds(): void
    {
        $tick = new TickMsg(0.0);

        $this->assertSame(0.0, $tick->seconds);
    }

    public function testImplementsMsgInterface(): void
    {
        $tick = new TickMsg(1.0);

        $this->assertInstanceOf(\SugarCraft\Core\Msg::class, $tick);
    }

    public function testIsReadonly(): void
    {
        $tick = new TickMsg(2.5);

        $reflection = new \ReflectionClass($tick);
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testSecondsCanBeFractional(): void
    {
        $tick = new TickMsg(0.016); // 16ms

        $this->assertSame(0.016, $tick->seconds);
    }
}
