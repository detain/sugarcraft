<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use SugarCraft\Log\PartsOrder;
use PHPUnit\Framework\TestCase;

final class PartsOrderTest extends TestCase
{
    public function testDefaultPartsOrder(): void
    {
        $order = PartsOrder::default();

        $this->assertSame([
            PartsOrder::PART_TIMESTAMP,
            PartsOrder::PART_LEVEL,
            PartsOrder::PART_PREFIX,
            PartsOrder::PART_CALLER,
            PartsOrder::PART_MESSAGE,
            PartsOrder::PART_FIELDS,
        ], $order->parts);
    }

    public function testSyslogOrder(): void
    {
        $order = PartsOrder::syslog();

        $this->assertNotContains(PartsOrder::PART_PREFIX, $order->parts);
        $this->assertNotContains(PartsOrder::PART_CALLER, $order->parts);
        $this->assertContains(PartsOrder::PART_TIMESTAMP, $order->parts);
        $this->assertContains(PartsOrder::PART_LEVEL, $order->parts);
        $this->assertContains(PartsOrder::PART_MESSAGE, $order->parts);
        $this->assertContains(PartsOrder::PART_FIELDS, $order->parts);
    }

    public function testMessageFirstOrder(): void
    {
        $order = PartsOrder::messageFirst();

        $this->assertSame(PartsOrder::PART_MESSAGE, $order->parts[0]);
        $this->assertSame(PartsOrder::PART_LEVEL, $order->parts[1]);
    }

    public function testHasReturnsTrueForContainedPart(): void
    {
        $order = PartsOrder::default();

        $this->assertTrue($order->has(PartsOrder::PART_TIMESTAMP));
        $this->assertTrue($order->has(PartsOrder::PART_LEVEL));
        $this->assertTrue($order->has(PartsOrder::PART_MESSAGE));
    }

    public function testHasReturnsFalseForMissingPart(): void
    {
        $order = PartsOrder::syslog();

        $this->assertFalse($order->has(PartsOrder::PART_PREFIX));
        $this->assertFalse($order->has(PartsOrder::PART_CALLER));
    }

    public function testCustomPartsOrder(): void
    {
        $order = new PartsOrder([PartsOrder::PART_MESSAGE, PartsOrder::PART_LEVEL, PartsOrder::PART_FIELDS]);

        $this->assertCount(3, $order->parts);
        $this->assertSame(PartsOrder::PART_MESSAGE, $order->parts[0]);
        $this->assertSame(PartsOrder::PART_LEVEL, $order->parts[1]);
        $this->assertSame(PartsOrder::PART_FIELDS, $order->parts[2]);
    }
}
