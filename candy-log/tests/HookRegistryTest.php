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

        $called = false;
        $id = $registry->onLevel(Level::Info, function () use (&$called): void {
            $called = true;
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
}
