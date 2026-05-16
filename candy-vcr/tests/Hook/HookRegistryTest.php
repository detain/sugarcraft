<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Hook;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;
use SugarCraft\Vcr\Hook\Hook;
use SugarCraft\Vcr\Hook\HookRegistry;
use SugarCraft\Vcr\Hook\MetadataHook;
use SugarCraft\Vcr\Hook\SanitizingHook;
use SugarCraft\Vcr\Recorder;

/**
 * @covers \SugarCraft\Vcr\Hook\HookRegistry
 */
final class HookRegistryTest extends TestCase
{
    public function testEmptyRegistryPassesEventThrough(): void
    {
        $registry = new HookRegistry();
        $event = new Event(t: 0.1, kind: EventKind::Output, payload: ['b' => 'hello']);

        $result = $registry->beforeSave($event);

        $this->assertNotNull($result);
        $this->assertSame('hello', $result->payload['b']);
    }

    public function testBeforeSaveCallsHooksInOrder(): void
    {
        $calls = [];
        $hook1 = new class($calls, 1) implements Hook {
            public function __construct(private array &$calls, private int $order) {}
            public function beforeSave(Event $event): ?Event
            {
                $this->calls[] = $this->order;
                return $event;
            }
            public function afterCapture(Event $event): void {}
        };
        $hook2 = new class($calls, 2) implements Hook {
            public function __construct(private array &$calls, private int $order) {}
            public function beforeSave(Event $event): ?Event
            {
                $this->calls[] = $this->order;
                return $event;
            }
            public function afterCapture(Event $event): void {}
        };

        $registry = new HookRegistry();
        $registry->addHook($hook1);
        $registry->addHook($hook2);

        $event = new Event(t: 0.1, kind: EventKind::Output, payload: ['b' => 'test']);
        $registry->beforeSave($event);

        $this->assertSame([1, 2], $calls);
    }

    public function testNullFromHookSuppressesEvent(): void
    {
        $suppressingHook = new class implements Hook {
            public function beforeSave(Event $event): ?Event { return null; }
            public function afterCapture(Event $event): void {}
        };

        $registry = new HookRegistry();
        $registry->addHook($suppressingHook);

        $event = new Event(t: 0.1, kind: EventKind::Output, payload: ['b' => 'test']);
        $result = $registry->beforeSave($event);

        $this->assertNull($result);
    }

    public function testAfterCaptureCallsAllHooks(): void
    {
        $calls = [];
        $hook1 = new class($calls) implements Hook {
            public function __construct(private array &$calls) {}
            public function beforeSave(Event $event): ?Event { return $event; }
            public function afterCapture(Event $event): void { $this->calls[] = 1; }
        };
        $hook2 = new class($calls) implements Hook {
            public function __construct(private array &$calls) {}
            public function beforeSave(Event $event): ?Event { return $event; }
            public function afterCapture(Event $event): void { $this->calls[] = 2; }
        };

        $registry = new HookRegistry();
        $registry->addHook($hook1);
        $registry->addHook($hook2);

        $event = new Event(t: 0.1, kind: EventKind::Output, payload: ['b' => 'test']);
        $registry->afterCapture($event);

        $this->assertSame([1, 2], $calls);
    }

    public function testAfterCaptureErrorsAreSwallowed(): void
    {
        $throwingHook = new class implements Hook {
            public function beforeSave(Event $event): ?Event { return $event; }
            public function afterCapture(Event $event): void { throw new \RuntimeException('oops'); }
        };

        $registry = new HookRegistry();
        $registry->addHook($throwingHook);

        $event = new Event(t: 0.1, kind: EventKind::Output, payload: ['b' => 'test']);

        // Should not throw
        $registry->afterCapture($event);
        $this->assertTrue(true);
    }

    public function testChainedTransformations(): void
    {
        $doubleHook = new class implements Hook {
            public function beforeSave(Event $event): ?Event
            {
                $payload = $event->payload;
                if (isset($payload['value'])) {
                    $payload['value'] = $payload['value'] * 2;
                }
                return new Event($event->t, $event->kind, $payload);
            }
            public function afterCapture(Event $event): void {}
        };

        $registry = new HookRegistry();
        $registry->addHook($doubleHook);
        $registry->addHook($doubleHook);

        $event = new Event(t: 0.1, kind: EventKind::Output, payload: ['value' => 5]);
        $result = $registry->beforeSave($event);

        $this->assertSame(20, $result->payload['value']);
    }

    public function testCountAndClear(): void
    {
        $registry = new HookRegistry();
        $this->assertSame(0, $registry->count());

        $registry->addHook(new class implements Hook {
            public function beforeSave(Event $event): ?Event { return $event; }
            public function afterCapture(Event $event): void {}
        });
        $this->assertSame(1, $registry->count());

        $registry->clear();
        $this->assertSame(0, $registry->count());
    }
}
