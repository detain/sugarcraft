<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Events;

use SugarCraft\Dash\Events\KeyEvent;
use SugarCraft\Dash\Events\MouseEvent;
use SugarCraft\Dash\Events\ResizeEvent;
use SugarCraft\Dash\Events\FocusEvent;
use SugarCraft\Dash\Events\PasteEvent;
use SugarCraft\Dash\Events\Event;
use SugarCraft\Dash\Events\EventDispatcher;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // KeyEvent
    // ═══════════════════════════════════════════════════════════════

    public function testKeyEventGetType(): void
    {
        $event = new KeyEvent(time(), 'a');
        $this->assertSame('key', $event->getType());
    }

    public function testKeyEventIs(): void
    {
        $event = new KeyEvent(time(), 'c', ctrl: true);

        $this->assertTrue($event->is('c', ctrl: true));
        $this->assertFalse($event->is('c', ctrl: false));
        $this->assertFalse($event->is('a'));
    }

    public function testKeyEventIsSpecial(): void
    {
        $arrowEvent = new KeyEvent(time(), 'ArrowUp');
        $charEvent = new KeyEvent(time(), 'a');

        $this->assertTrue($arrowEvent->isSpecial());
        $this->assertFalse($charEvent->isSpecial());
    }

    public function testKeyEventIsType(): void
    {
        $event = new KeyEvent(time(), 'a');
        $this->assertTrue($event->isType('key'));
        $this->assertFalse($event->isType('mouse'));
    }

    // ═══════════════════════════════════════════════════════════════
    // MouseEvent
    // ═══════════════════════════════════════════════════════════════

    public function testMouseEventGetType(): void
    {
        $event = new MouseEvent(time(), 10, 20, MouseEvent::BUTTON_LEFT);
        $this->assertSame('mouse', $event->getType());
    }

    public function testMouseEventIsClick(): void
    {
        $clickEvent = new MouseEvent(time(), 10, 20, MouseEvent::BUTTON_LEFT);
        $scrollEvent = new MouseEvent(time(), 10, 20, MouseEvent::WHEEL_UP);

        $this->assertTrue($clickEvent->isClick());
        $this->assertFalse($scrollEvent->isClick());
    }

    public function testMouseEventIsScroll(): void
    {
        $scrollUpEvent = new MouseEvent(time(), 10, 20, MouseEvent::WHEEL_UP);
        $scrollDownEvent = new MouseEvent(time(), 10, 20, MouseEvent::WHEEL_DOWN);
        $clickEvent = new MouseEvent(time(), 10, 20, MouseEvent::BUTTON_LEFT);

        $this->assertTrue($scrollUpEvent->isScroll());
        $this->assertTrue($scrollDownEvent->isScroll());
        $this->assertFalse($clickEvent->isScroll());
    }

    public function testMouseEventIsDrag(): void
    {
        $dragEvent = new MouseEvent(time(), 10, 20, MouseEvent::BUTTON_RELEASE);
        $clickEvent = new MouseEvent(time(), 10, 20, MouseEvent::BUTTON_LEFT);

        $this->assertTrue($dragEvent->isDrag());
        $this->assertFalse($clickEvent->isDrag());
    }

    // ═══════════════════════════════════════════════════════════════
    // ResizeEvent
    // ═══════════════════════════════════════════════════════════════

    public function testResizeEventGetType(): void
    {
        $event = new ResizeEvent(time(), 80, 24);
        $this->assertSame('resize', $event->getType());
    }

    public function testResizeEventProperties(): void
    {
        $event = new ResizeEvent(time(), 120, 40);
        $this->assertSame(120, $event->width);
        $this->assertSame(40, $event->height);
    }

    // ═══════════════════════════════════════════════════════════════
    // FocusEvent
    // ═══════════════════════════════════════════════════════════════

    public function testFocusEventGetType(): void
    {
        $event = new FocusEvent(time(), true);
        $this->assertSame('focus', $event->getType());
    }

    public function testFocusEventGained(): void
    {
        $gainedEvent = new FocusEvent(time(), true);
        $lostEvent = new FocusEvent(time(), false);

        $this->assertTrue($gainedEvent->gained);
        $this->assertFalse($lostEvent->gained);
    }

    // ═══════════════════════════════════════════════════════════════
    // PasteEvent
    // ═══════════════════════════════════════════════════════════════

    public function testPasteEventGetType(): void
    {
        $event = new PasteEvent(time(), 'pasted text');
        $this->assertSame('paste', $event->getType());
    }

    public function testPasteEventText(): void
    {
        $event = new PasteEvent(time(), 'Hello World');
        $this->assertSame('Hello World', $event->text);
    }

    // ═══════════════════════════════════════════════════════════════
    // EventDispatcher
    // ═══════════════════════════════════════════════════════════════

    public function testEventDispatcherNew(): void
    {
        $dispatcher = EventDispatcher::new();
        $this->assertInstanceOf(EventDispatcher::class, $dispatcher);
    }

    public function testOnRegistersHandler(): void
    {
        $dispatcher = EventDispatcher::new();
        $called = false;

        $handler = function (KeyEvent $event) use (&$called) {
            $called = true;
        };

        $dispatcher = $dispatcher->on('key', $handler);
        $this->assertTrue($dispatcher->hasListeners('key'));
    }

    public function testOnReturnsNewInstance(): void
    {
        $original = EventDispatcher::new();
        $handler = fn(KeyEvent $e) => $e;
        $modified = $original->on('key', $handler);

        $this->assertNotSame($original, $modified);
    }

    public function testDispatchCallsHandler(): void
    {
        $called = false;
        $handler = function (KeyEvent $event) use (&$called) {
            $called = true;
            return $event;
        };

        $dispatcher = EventDispatcher::new()->on('key', $handler);
        $event = new KeyEvent(time(), 'a');

        $dispatcher->dispatch($event);

        $this->assertTrue($called);
    }

    public function testDispatchReturnsModifiedEvent(): void
    {
        $originalEvent = new KeyEvent(time(), 'a');
        $newEvent = new KeyEvent(time(), 'b');

        $handler = function (KeyEvent $event) use ($newEvent) {
            return $newEvent;
        };

        $dispatcher = EventDispatcher::new()->on('key', $handler);
        [$returnedEvent, $newDispatcher] = $dispatcher->dispatch($originalEvent);

        $this->assertSame($newEvent, $returnedEvent);
    }

    public function testDispatchToMultipleHandlers(): void
    {
        $callOrder = [];

        $handler1 = function (KeyEvent $event) use (&$callOrder) {
            $callOrder[] = 'first';
            return $event;
        };

        $handler2 = function (KeyEvent $event) use (&$callOrder) {
            $callOrder[] = 'second';
            return $event;
        };

        $dispatcher = EventDispatcher::new()
            ->on('key', $handler1)
            ->on('key', $handler2);

        $event = new KeyEvent(time(), 'a');
        $dispatcher->dispatch($event);

        $this->assertCount(2, $callOrder);
        $this->assertSame(['first', 'second'], $callOrder);
    }

    public function testOffRemovesHandler(): void
    {
        $called = false;
        $handler = function (KeyEvent $event) use (&$called) {
            $called = true;
            return $event;
        };

        $dispatcher = EventDispatcher::new()
            ->on('key', $handler)
            ->off('key', $handler);

        $event = new KeyEvent(time(), 'a');
        $dispatcher->dispatch($event);

        $this->assertFalse($called);
    }

    public function testOffAllHandlersForType(): void
    {
        $handler1 = fn(KeyEvent $e) => $e;
        $handler2 = fn(KeyEvent $e) => $e;

        $dispatcher = EventDispatcher::new()
            ->on('key', $handler1)
            ->on('key', $handler2)
            ->off('key');

        $this->assertFalse($dispatcher->hasListeners('key'));
    }

    public function testClearRemovesAllHandlers(): void
    {
        $handler1 = fn(KeyEvent $e) => $e;
        $handler2 = fn(MouseEvent $e) => $e;

        $dispatcher = EventDispatcher::new()
            ->on('key', $handler1)
            ->on('mouse', $handler2)
            ->clear();

        $this->assertFalse($dispatcher->hasListeners('key'));
        $this->assertFalse($dispatcher->hasListeners('mouse'));
    }

    public function testGetEventTypes(): void
    {
        $handler = fn(Event $e) => $e;

        $dispatcher = EventDispatcher::new()
            ->on('key', $handler)
            ->on('mouse', $handler)
            ->on('resize', $handler);

        $types = $dispatcher->getEventTypes();

        $this->assertCount(3, $types);
        $this->assertContains('key', $types);
        $this->assertContains('mouse', $types);
        $this->assertContains('resize', $types);
    }

    public function testOnceHandlerCalledOnlyOnce(): void
    {
        $callCount = 0;
        $handler = function (KeyEvent $event) use (&$callCount) {
            $callCount++;
            return $event;
        };

        $dispatcher = EventDispatcher::new()->once('key', $handler);

        $event = new KeyEvent(time(), 'a');
        // dispatch() returns [Event, newDispatcher] — use the returned dispatcher
        // to get the immutable behavior where once-handlers are removed after dispatch
        [$event, $dispatcher] = $dispatcher->dispatch($event);
        [$event, $dispatcher] = $dispatcher->dispatch($event);
        [$event, $dispatcher] = $dispatcher->dispatch($event);

        $this->assertSame(1, $callCount);
    }

    public function testHasListenersFalseWhenEmpty(): void
    {
        $dispatcher = EventDispatcher::new();
        $this->assertFalse($dispatcher->hasListeners('key'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Event class hierarchy
    // ═══════════════════════════════════════════════════════════════

    public function testAllEventsHaveTimestamp(): void
    {
        $timestamp = time();

        $keyEvent = new KeyEvent($timestamp, 'a');
        $mouseEvent = new MouseEvent($timestamp, 0, 0, 0);
        $resizeEvent = new ResizeEvent($timestamp, 80, 24);
        $focusEvent = new FocusEvent($timestamp, true);
        $pasteEvent = new PasteEvent($timestamp, 'text');

        $this->assertSame($timestamp, $keyEvent->timestamp);
        $this->assertSame($timestamp, $mouseEvent->timestamp);
        $this->assertSame($timestamp, $resizeEvent->timestamp);
        $this->assertSame($timestamp, $focusEvent->timestamp);
        $this->assertSame($timestamp, $pasteEvent->timestamp);
    }
}
