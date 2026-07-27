<?php

declare(strict_types=1);

namespace SugarCraft\Input\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\Event\FocusEvent;
use SugarCraft\Input\Event;

/**
 * Tests for FocusEvent — terminal focus gained/lost events.
 */
final class FocusEventTest extends TestCase
{
    public function testGainedEventHasTrueGained(): void
    {
        $event = new FocusEvent(true);

        $this->assertTrue($event->gained);
    }

    public function testLostEventHasFalseGained(): void
    {
        $event = new FocusEvent(false);

        $this->assertFalse($event->gained);
    }

    public function testImplementsEventInterface(): void
    {
        $event = new FocusEvent(true);

        $this->assertInstanceOf(Event::class, $event);
    }

    public function testConstructorSetsProperties(): void
    {
        $event = new FocusEvent(true);

        $this->assertSame(true, $event->gained);
    }

    public function testConstructorFalseSetsProperties(): void
    {
        $event = new FocusEvent(false);

        $this->assertSame(false, $event->gained);
    }
}
