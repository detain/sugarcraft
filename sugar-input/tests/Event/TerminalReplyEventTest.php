<?php

declare(strict_types=1);

namespace SugarCraft\Input\Tests\Event;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\Event\TerminalReplyEvent;
use SugarCraft\Input\Event;

/**
 * Tests for TerminalReplyEvent — the typed drain for unsolicited terminal
 * replies, including the m11 hostile-reply parameter bound.
 */
final class TerminalReplyEventTest extends TestCase
{
    public function testParametersBelowTheCapRideUnchanged(): void
    {
        $params = range(1, TerminalReplyEvent::MAX_PARAMS);
        $event = new TerminalReplyEvent(TerminalReplyEvent::FAMILY_WINDOW_REPORT, $params);

        $this->assertSame($params, $event->params);
        $this->assertSame(0, $event->paramsDropped);
    }

    public function testParametersBeyondTheCapAreCountedNotBuffered(): void
    {
        // A hostile or buggy terminal can pack a reply with hundreds of
        // semicolon-separated ints; the head of the list rides, the excess is
        // counted so memory stays bounded against attacker bytes (m11).
        $event = new TerminalReplyEvent(TerminalReplyEvent::FAMILY_CURSOR_POSITION, range(1, 40));

        $this->assertCount(TerminalReplyEvent::MAX_PARAMS, $event->params);
        $this->assertSame(range(1, TerminalReplyEvent::MAX_PARAMS), $event->params);
        $this->assertSame(8, $event->paramsDropped);
    }

    public function testEmptyParametersAreLegal(): void
    {
        $event = new TerminalReplyEvent(TerminalReplyEvent::FAMILY_KITTY_FLAGS, [], "\x1b[?u");

        $this->assertSame([], $event->params);
        $this->assertSame(0, $event->paramsDropped);
        $this->assertSame("\x1b[?u", $event->raw);
    }

    public function testFamiliesRosterMatchesConstants(): void
    {
        $this->assertSame([
            TerminalReplyEvent::FAMILY_DEVICE_ATTRIBUTES,
            TerminalReplyEvent::FAMILY_CURSOR_POSITION,
            TerminalReplyEvent::FAMILY_DSR_STATUS,
            TerminalReplyEvent::FAMILY_WINDOW_REPORT,
            TerminalReplyEvent::FAMILY_KITTY_FLAGS,
            TerminalReplyEvent::FAMILY_MODE_REPORT,
            TerminalReplyEvent::FAMILY_CSI_PRIVATE,
            TerminalReplyEvent::FAMILY_STRING,
        ], TerminalReplyEvent::FAMILIES);
    }

    public function testIsMatchesFamilyExactly(): void
    {
        $event = new TerminalReplyEvent(TerminalReplyEvent::FAMILY_DSR_STATUS, [0]);

        $this->assertTrue($event->is(TerminalReplyEvent::FAMILY_DSR_STATUS));
        $this->assertFalse($event->is(TerminalReplyEvent::FAMILY_WINDOW_REPORT));
    }

    public function testImplementsEventInterface(): void
    {
        $event = new TerminalReplyEvent(TerminalReplyEvent::FAMILY_STRING, [], '', 'x');

        $this->assertInstanceOf(Event::class, $event);
    }

    public function testEveryRosterFamilyConstructs(): void
    {
        foreach (TerminalReplyEvent::FAMILIES as $family) {
            $event = new TerminalReplyEvent($family);

            $this->assertSame($family, $event->family);
        }
    }

    public function testUnknownFamilyThrowsNamingOffenderAndRoster(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Unknown terminal reply family "cursor"; expected one of '
            . implode(', ', TerminalReplyEvent::FAMILIES) . '.',
        );

        new TerminalReplyEvent('cursor');
    }
}
