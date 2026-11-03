<?php

declare(strict_types=1);

namespace SugarCraft\Input\Tests\Event;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\Event\MouseEvent;
use SugarCraft\Input\KeyModifier;

/**
 * Unit tests for the MouseEvent value object: the action door (n2) and the
 * documented wire→stored wheel-code normalization (m9).
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
}
