<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use Psr\Log\LogLevel;
use SugarCraft\Log\Hook\Hook;
use SugarCraft\Log\Hook\HookRegistry;
use SugarCraft\Log\Level;
use SugarCraft\Log\Tests\Support\StderrNoticeCapture;
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

    public function testRemovedHandlerFreesItsSlot(): void
    {
        $registry = new HookRegistry();
        $called = false;

        $id = $registry->onLevel(Level::Info, function () use (&$called): void {
            $called = true;
        });

        // Remove the handler — the map entry must be gone, not tombstoned.
        $registry->remove($id);

        $slots = (new \ReflectionProperty(HookRegistry::class, 'handlers'))->getValue($registry);
        $this->assertSame([], $slots, 'remove() must reclaim the slot');

        // Fire has nothing to call.
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

    public function testThrowingHookNeitherCancelsLaterHooksNorEscapesFire(): void
    {
        // [M7] fire() walks third-party callbacks: one handler detonating must
        // not cancel the handlers registered behind it, and must never surface
        // to the logging caller. Pre-fix the bare foreach propagated the
        // exception, killing the rest of the walk (and, via PsrBridge, the
        // record itself).
        $registry = new HookRegistry();
        $secondCalled = false;

        $registry->onLevel(Level::Info, function (): void {
            throw new \LogicException("multi\nline\x1b[31m forged");
        });
        $registry->onLevel(Level::Info, function () use (&$secondCalled): void {
            $secondCalled = true;
        });

        $registry->fire(Level::Info, 'info', 'msg', []); // must not throw

        $this->assertTrue($secondCalled, 'a failed hook must not cancel the walk');
    }

    public function testThrowingHookAnnouncesSanitizedNoticeOnRealStderr(): void
    {
        // [TEST-SL-01] Second notice shape: '[sugar-log] hook failed:' at
        // HookRegistry.php:107-112. Deleting reportHookFailure()'s announcement
        // must turn THIS red (the walk-completes tests only observe control
        // flow). Child capture asserts the bytes: exact prefix, hostile
        // multi-line + ESC payload flattened and escape-free, one line.
        $result = StderrNoticeCapture::run(<<<'PHP'
            $registry = new SugarCraft\Log\Hook\HookRegistry();
            $registry->onLevel(SugarCraft\Log\Level::Info, function (): void {
                throw new RuntimeException("hook\nboom\x1b[31m forged");
            });
            $registry->fire(SugarCraft\Log\Level::Info, 'info', 'msg', []);
            PHP);

        $this->assertSame(0, $result['exitCode'], 'fire() must not propagate the hook failure: ' . $result['stderr']);
        $this->assertStringStartsWith('[sugar-log] hook failed: ', $result['stderr']);
        $this->assertStringContainsString('RuntimeException', $result['stderr']);
        $this->assertStringContainsString('forged', $result['stderr']);
        $this->assertStringNotContainsString("\x1b", $result['stderr']);
        $this->assertSame(1, StderrNoticeCapture::lineCount($result['stderr']), 'the notice must be exactly one line: ' . $result['stderr']);
    }

    public function testEveryHandlerGuardedIndividually(): void
    {
        // Three hostile hooks in a row: all attempted, fire() silent-returns.
        $registry = new HookRegistry();
        $attempts = 0;

        foreach ([1, 2, 3] as $n) {
            $registry->onLevel(Level::Info, function () use (&$attempts, $n): void {
                $attempts++;
                throw new \RuntimeException('hook ' . $n);
            });
        }

        $registry->fire(Level::Info, 'info', 'msg', []);

        $this->assertSame(3, $attempts);
    }
}
