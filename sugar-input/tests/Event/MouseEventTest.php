<?php

declare(strict_types=1);

namespace SugarCraft\Input\Tests\Event;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\Event\MouseEvent;
use SugarCraft\Input\KeyModifier;

/**
 * Unit tests for the MouseEvent value object: the action door (n2), the
 * documented wire→stored wheel-code normalization (m9), the static scroll
 * factories, and the constructor/constants vocabulary.
 */
final class MouseEventTest extends TestCase
{
    public function testAcceptsEveryDocumentedAction(): void
    {
        foreach ([MouseEvent::ACTION_PRESS, MouseEvent::ACTION_RELEASE, MouseEvent::ACTION_DRAG, MouseEvent::ACTION_SCROLL] as $action) {
            $event = new MouseEvent(1, 2, 0, $action, KeyModifier::none());
            self::assertSame($action, $event->action);
        }
    }

    public function testRejectsUnknownActionVocabulary(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MouseEvent(1, 2, 0, 'hover', KeyModifier::none());
    }

    public function testScrollFactoriesNormalizeWheelCodes(): void
    {
        // Wire codes 64/65/66/67 normalize to stored 96/97/98/99 (m9).
        $cases = [
            'scrollUp' => 96,
            'scrollDown' => 97,
            'scrollLeft' => 98,
            'scrollRight' => 99,
        ];

        foreach ($cases as $factory => $stored) {
            $event = MouseEvent::$factory(3, 4);
            self::assertSame($stored, $event->button, $factory . ' stores ' . $stored);
            self::assertTrue($event->isScroll());
            self::assertSame(3, $event->x);
            self::assertSame(4, $event->y);
        }
    }

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
