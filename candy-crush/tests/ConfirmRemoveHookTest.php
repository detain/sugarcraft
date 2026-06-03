<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\BuiltIn\ConfirmRemoveHook;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookResult;

/**
 * @see ConfirmRemoveHook
 */
final class ConfirmRemoveHookTest extends TestCase
{
    // =========================================================================
    // Basic Interface Tests
    // =========================================================================

    public function testName(): void
    {
        $hook = new ConfirmRemoveHook();

        $this->assertSame('confirm-rm', $hook->name());
    }

    public function testEvent(): void
    {
        $hook = new ConfirmRemoveHook();

        $this->assertSame(HookEvent::PreToolUse, $hook->event());
    }

    public function testMatcher(): void
    {
        $hook = new ConfirmRemoveHook();

        $this->assertSame('^rm$', $hook->matcher());
    }

    // =========================================================================
    // Dangerous rm Command Denial Tests
    // =========================================================================

    public function testDenyRecursiveRm(): void
    {
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext('rm -rf /important');

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
        $this->assertStringContainsString('recursive', $result->message);
    }

    public function testDenyRecursiveRmWithSpace(): void
    {
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext('rm -r /important');

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
    }

    public function testDenyForceRm(): void
    {
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext('rm -f file.txt');

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
        $this->assertStringContainsString('force', $result->message);
    }

    public function testDenyRecursiveForceRm(): void
    {
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext('rm -rf ./my-project');

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
    }

    public function testDenyCombinedFlags(): void
    {
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext('rm -r -f -v directory');

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
    }

    // =========================================================================
    // Safe rm Command Allow Tests
    // =========================================================================

    public function testAllowSimpleRm(): void
    {
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext('rm file.txt');

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
    }

    public function testAllowRmWithSpaces(): void
    {
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext('rm  file.txt');

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
    }

    public function testAllowRmSingleFile(): void
    {
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext('rm single-file.txt');

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
    }

    // =========================================================================
    // Edge Case Tests
    // =========================================================================

    public function testAllowEmptyInput(): void
    {
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext('');

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
    }

    public function testAllowInteractiveRm(): void
    {
        $hook = new ConfirmRemoveHook();
        // Interactive rm (no flags) should be allowed
        $context = $this->createContext('rm -i file.txt');

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
    }

    public function testAllowVerboseRm(): void
    {
        $hook = new ConfirmRemoveHook();
        // Verbose flag only (not recursive or force) should be allowed
        $context = $this->createContext('rm -v file.txt');

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
    }

    public function testAllowRmWithOtherSafeFlags(): void
    {
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext('rm -iv file.txt');

        $result = $hook->execute($context);

        // -i is interactive (safe), -v is verbose (safe) - only r/f should deny
        $this->assertTrue($result->isAllowed());
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function createContext(string $toolInput): HookContext
    {
        return new HookContext(
            sessionId: 'test-session-456',
            toolName: 'rm',
            toolArgs: [],
            toolInput: $toolInput,
            toolOutput: '',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: '/tmp/test-project',
        );
    }
}
