<?php

declare(strict_types=1);

namespace SugarCraft\Input\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\Event;

/**
 * Tests for the base Event interface.
 */
final class EventInterfaceTest extends TestCase
{
    public function testEventIsAnInterface(): void
    {
        $this->assertTrue(interface_exists(Event::class));
    }
}
