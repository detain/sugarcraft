<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use Psr\Log\LogLevel;
use SugarCraft\Log\Hook\Hook;
use SugarCraft\Log\Hook\HookRegistry;
use SugarCraft\Log\Level;
use PHPUnit\Framework\TestCase;

final class HookRegistryTest extends TestCase
{
    public function testOnLevelRegistersCallback(): void
    {
        $registry = new HookRegistry();

        $id = $registry->onLevel(Level::Info, function (): void {
            // Callback registered; actual invocation tested separately
        });

        $this->assertIsInt($id);
    }

    public function testFireInvokesRegisteredHandler(): void
    {
        $registry = new HookRegistry();
        $invokedLevel = null;
        $invokedMessage = null;

        $registry->onLevel(Level::Info, function (Level $level, string $psrLevel, string $message) use (&$invokedLevel, &$invokedMessage): void {
            $invokedLevel = $level;
            $invokedMessage = $message;
        });

        $registry->fire(Level::Info, 'info', 'hello hook', []);

        $this->assertSame(Level::Info, $invokedLevel);
        $this->assertSame('hello hook', $invokedMessage);
    }

    public function testFireOnlyAboveMinLevel(): void
    {
        $registry = new HookRegistry();
        $called = false;

        $registry->onLevel(Level::Warn, function () use (&$called): void {
            $called = true;
        });

        // Info is below Warn threshold — should NOT fire
        $registry->fire(Level::Info, 'info', 'hello', []);
        $this->assertFalse($called);

        // Warn meets the threshold — should fire
        $registry->fire(Level::Warn, 'warning', 'careful', []);
        $this->assertTrue($called);
    }

    public function testMultipleHandlersForSameLevel(): void
    {
        $registry = new HookRegistry();
        $count = 0;

        $registry->onLevel(Level::Info, function () use (&$count): void {
            $count++;
        });
        $registry->onLevel(Level::Info, function () use (&$count): void {
            $count++;
        });

        $registry->fire(Level::Info, 'info', 'msg', []);

        $this->assertSame(2, $count);
    }

    public function testHookReceivesCorrectArguments(): void
    {
        $registry = new HookRegistry();
        $receivedLevel = null;
        $receivedPsrLevel = null;
        $receivedMessage = null;
        $receivedContext = null;

        $registry->onLevel(Level::Debug, function (Level $level, string $psrLevel, string $message, array $context) use (&$receivedLevel, &$receivedPsrLevel, &$receivedMessage, &$receivedContext): void {
            $receivedLevel = $level;
            $receivedPsrLevel = $psrLevel;
            $receivedMessage = $message;
            $receivedContext = $context;
        });

        $registry->fire(Level::Debug, LogLevel::DEBUG, 'msg', ['key' => 'val']);

        $this->assertSame(Level::Debug, $receivedLevel);
        $this->assertSame(LogLevel::DEBUG, $receivedPsrLevel);
        $this->assertSame('msg', $receivedMessage);
        $this->assertSame(['key' => 'val'], $receivedContext);
    }

    public function testAddHookRegistersStructuredHook(): void
    {
        $registry = new HookRegistry();

        // Create a concrete Hook implementation
        $receivedLevel = null;
        $receivedMessage = null;

        $hook = new class($receivedLevel, $receivedMessage) implements Hook {
            public function __construct(
                private mixed &$levelRef,
                private mixed &$msgRef,
            ) {}

            public function onLevel(Level $level, string $psrLevel, string $message, array $context): void
            {
                $this->levelRef = $level;
                $this->msgRef = $message;
            }
        };

        $id = $registry->addHook(Level::Info, $hook);
        $this->assertIsInt($id);

        $registry->fire(Level::Info, 'info', 'hello hook', []);

        $this->assertSame(Level::Info, $receivedLevel);
        $this->assertSame('hello hook', $receivedMessage);
    }

    public function testRemoveUnregistersHandler(): void
    {
        $registry = new HookRegistry();
        $called = false;

        $id = $registry->onLevel(Level::Info, function () use (&$called): void {
            $called = true;
        });

        // Verify it fires before removal
        $registry->fire(Level::Info, 'info', 'msg', []);
        $this->assertTrue($called);

        // Remove the handler
        $called = false;
        $registry->remove($id);

        // Verify it no longer fires after removal
        $registry->fire(Level::Info, 'info', 'msg', []);
        $this->assertFalse($called);
    }

    public function testRemoveWithInvalidIdIsNoOp(): void
    {
        $registry = new HookRegistry();
        $called = false;

        $registry->onLevel(Level::Info, function () use (&$called): void {
            $called = true;
        });

        // Remove with invalid ID should not affect the registered handler
        $registry->remove(9999);
        $registry->remove(-1);

        $registry->fire(Level::Info, 'info', 'msg', []);
        $this->assertTrue($called);
    }

    public function testFireSkipsNullHandlersInArray(): void
    {
        $registry = new HookRegistry();
        $called = false;

        $id = $registry->onLevel(Level::Info, function () use (&$called): void {
            $called = true;
        });

        // Remove the handler, creating a null slot
        $registry->remove($id);

        // Fire should skip the null slot and not call anything
        $registry->fire(Level::Info, 'info', 'msg', []);
        $this->assertFalse($called);
    }

    public function testFireAtHigherLevelTriggersLowerThresholdHandler(): void
    {
        $registry = new HookRegistry();
        $called = false;

        // Handler registered for Info level
        $registry->onLevel(Level::Info, function () use (&$called): void {
            $called = true;
        });

        // Fire at Error level (higher than Info) - should still trigger
        $registry->fire(Level::Error, 'error', 'msg', []);
        $this->assertTrue($called);
    }
}
