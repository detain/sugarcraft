<?php

declare(strict_types=1);

namespace SugarCraft\Input\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\Event\MouseEvent;
use SugarCraft\Input\KeyModifier;

/**
 * Tests for MouseEvent static factory methods.
 */
final class MouseEventTest extends TestCase
{
    // ─── scrollUp / scrollDown factories (modifier-defaulted) ─────────────

    public function testScrollUpWithoutModifierDefaultsToNone(): void
    {
        $event = MouseEvent::scrollUp(10, 5);

        $this->assertSame(10, $event->x);
        $this->assertSame(5, $event->y);
        $this->assertSame(96, $event->button);
        $this->assertSame(MouseEvent::ACTION_SCROLL, $event->action);
        $this->assertSame(KeyModifier::NONE, $event->modifiers->value());
        $this->assertInstanceOf(KeyModifier::class, $event->modifiers);
        $this->assertTrue($event->isScroll());
    }

    public function testScrollDownWithoutModifierDefaultsToNone(): void
    {
        $event = MouseEvent::scrollDown(10, 5);

        $this->assertSame(10, $event->x);
        $this->assertSame(5, $event->y);
        $this->assertSame(97, $event->button);
        $this->assertSame(MouseEvent::ACTION_SCROLL, $event->action);
        $this->assertSame(KeyModifier::NONE, $event->modifiers->value());
        $this->assertInstanceOf(KeyModifier::class, $event->modifiers);
        $this->assertTrue($event->isScroll());
    }

    public function testScrollUpWithExplicitModifiers(): void
    {
        $modifiers = KeyModifier::shift();
        $event = MouseEvent::scrollUp(10, 5, $modifiers);

        $this->assertSame(KeyModifier::SHIFT, $event->modifiers->value());
        $this->assertTrue($event->modifiers->includes(KeyModifier::SHIFT));
    }

    public function testScrollDownWithExplicitModifiers(): void
    {
        $modifiers = KeyModifier::ctrl();
        $event = MouseEvent::scrollDown(10, 5, $modifiers);

        $this->assertSame(KeyModifier::CTRL, $event->modifiers->value());
        $this->assertTrue($event->modifiers->includes(KeyModifier::CTRL));
    }

    // ─── isScroll ──────────────────────────────────────────────────────────

    public function testIsScrollReturnsTrueForScrollAction(): void
    {
        $event = MouseEvent::scrollUp(1, 1);
        $this->assertTrue($event->isScroll());
    }

    public function testIsScrollReturnsFalseForPressAction(): void
    {
        $event = new MouseEvent(10, 5, 0, MouseEvent::ACTION_PRESS, KeyModifier::none());
        $this->assertFalse($event->isScroll());
    }

    public function testIsScrollReturnsFalseForDragAction(): void
    {
        $event = new MouseEvent(10, 5, 0, MouseEvent::ACTION_DRAG, KeyModifier::none());
        $this->assertFalse($event->isScroll());
    }

    // ─── Constructor and properties ────────────────────────────────────────

    public function testConstructorSetsProperties(): void
    {
        $modifiers = KeyModifier::alt();
        $event = new MouseEvent(10, 20, MouseEvent::BUTTON_LEFT, MouseEvent::ACTION_PRESS, $modifiers);

        $this->assertSame(10, $event->x);
        $this->assertSame(20, $event->y);
        $this->assertSame(MouseEvent::BUTTON_LEFT, $event->button);
        $this->assertSame(MouseEvent::ACTION_PRESS, $event->action);
        $this->assertSame($modifiers, $event->modifiers);
    }

    public function testButtonConstants(): void
    {
        $this->assertSame(0, MouseEvent::BUTTON_LEFT);
        $this->assertSame(1, MouseEvent::BUTTON_MIDDLE);
        $this->assertSame(2, MouseEvent::BUTTON_RIGHT);
        $this->assertSame(3, MouseEvent::BUTTON_RELEASE);
    }

    public function testActionConstants(): void
    {
        $this->assertSame('press', MouseEvent::ACTION_PRESS);
        $this->assertSame('release', MouseEvent::ACTION_RELEASE);
        $this->assertSame('drag', MouseEvent::ACTION_DRAG);
        $this->assertSame('scroll', MouseEvent::ACTION_SCROLL);
    }
}
