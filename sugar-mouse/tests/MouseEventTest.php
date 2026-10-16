<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mouse\MouseAction;
use SugarCraft\Mouse\MouseEvent;

final class MouseEventTest extends TestCase
{
    public function testPressFactorySetsCorrectFields(): void
    {
        $event = MouseEvent::press(1, 2, 3);

        self::assertSame(1, $event->x);
        self::assertSame(2, $event->y);
        self::assertSame(3, $event->button);
        self::assertSame(MouseAction::Press, $event->action);
    }

    public function testPressFactoryDefaultButton(): void
    {
        $event = MouseEvent::press(5, 6);
        self::assertSame(0, $event->button);
    }

    public function testReleaseFactorySetsCorrectFields(): void
    {
        $event = MouseEvent::release(1, 2, 3);

        self::assertSame(1, $event->x);
        self::assertSame(2, $event->y);
        self::assertSame(3, $event->button);
        self::assertSame(MouseAction::Release, $event->action);
    }

    public function testReleaseFactoryDefaultButton(): void
    {
        $event = MouseEvent::release(5, 6);
        self::assertSame(0, $event->button);
    }

    public function testDragFactorySetsCorrectFields(): void
    {
        $event = MouseEvent::drag(1, 2, 3);

        self::assertSame(1, $event->x);
        self::assertSame(2, $event->y);
        self::assertSame(3, $event->button);
        self::assertSame(MouseAction::Drag, $event->action);
    }

    public function testDragFactoryDefaultButton(): void
    {
        $event = MouseEvent::drag(5, 6);
        self::assertSame(0, $event->button);
    }

    public function testScrollFactorySetsCorrectFields(): void
    {
        // Scroll events use the button field to encode scroll direction.
        $event = MouseEvent::scroll(3, 4, 2);

        self::assertSame(3, $event->x);
        self::assertSame(4, $event->y);
        self::assertSame(2, $event->button);
        self::assertSame(MouseAction::Scroll, $event->action);
    }

    public function testScrollFactoryDefaultButton(): void
    {
        $event = MouseEvent::scroll(3, 4);
        self::assertSame(0, $event->button);
    }
}
