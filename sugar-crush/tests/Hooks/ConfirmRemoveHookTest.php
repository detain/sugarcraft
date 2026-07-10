<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

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

        $this->assertSame('^Bash$', $hook->matcher());
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
    // Extended Destructive-Form Denial Tests (long flags + other tools)
    // =========================================================================

    public function testDenyLongFormRecursiveRm(): void
    {
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext('rm --recursive /important');

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
    }

    public function testDenyLongFormForceRm(): void
    {
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext('rm --force secret.txt');

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
    }

    public function testDenyFindDelete(): void
    {
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext("find . -name '*.log' -delete");

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
    }

    public function testDenyShred(): void
    {
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext('shred -u secret.key');

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
    }

    public function testDenyDdWithOutputFile(): void
    {
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext('dd if=/dev/zero of=/dev/sda bs=1M');

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
    }

    public function testShellIndirectionIsNotDetected(): void
    {
        // Documents the acknowledged blind spot: regex cannot see through
        // variable indirection, so `x=rf; rm -$x` slips past. This asserts the
        // heuristic's known limit, NOT a desired behaviour.
        $hook = new ConfirmRemoveHook();
        $context = $this->createContext('x=rf; rm -$x /tmp/data');

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
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

    private function createContext(string $command): HookContext
    {
        return new HookContext(
            sessionId: 'test-session-456',
            toolName: 'Bash',
            toolArgs: ['command' => $command],
            toolInput: json_encode(['command' => $command]),
            toolOutput: '',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: '/tmp/test-project',
        );
    }
}
