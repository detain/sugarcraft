<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\BuiltIn\ProtectFilesHook;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookResult;

/**
 * @see ProtectFilesHook
 */
final class ProtectFilesHookTest extends TestCase
{
    // =========================================================================
    // Basic Interface Tests
    // =========================================================================

    public function testName(): void
    {
        $hook = new ProtectFilesHook();

        $this->assertSame('protect-files', $hook->name());
    }

    public function testEvent(): void
    {
        $hook = new ProtectFilesHook();

        $this->assertSame(HookEvent::PreToolUse, $hook->event());
    }

    public function testMatcher(): void
    {
        $hook = new ProtectFilesHook();

        $this->assertSame('^(bash|Edit)$', $hook->matcher());
    }

    // =========================================================================
    // Protected File Denial Tests
    // =========================================================================

    public function testDenyEnvFile(): void
    {
        $hook = new ProtectFilesHook();
        $context = $this->createContext('bash', 'echo $HOME && nano .env');

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
        $this->assertStringContainsString('.env', $result->message);
    }

    public function testDenyComposerJson(): void
    {
        $hook = new ProtectFilesHook();
        $context = $this->createContext('Edit', 'composer.json');

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
    }

    public function testDenyComposerLock(): void
    {
        $hook = new ProtectFilesHook();
        $context = $this->createContext('Edit', 'composer.lock');

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
    }

    public function testDenyGitConfig(): void
    {
        $hook = new ProtectFilesHook();
        $context = $this->createContext('Edit', '.git/config');

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
        $this->assertStringContainsString('.git', $result->message);
    }

    public function testDenyConfigPhpFile(): void
    {
        $hook = new ProtectFilesHook();
        $context = $this->createContext('Edit', 'config/app.php');

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
        $this->assertStringContainsString('config\\/', $result->message);
    }

    // =========================================================================
    // Non-Protected File Allow Tests
    // =========================================================================

    public function testAllowOther(): void
    {
        $hook = new ProtectFilesHook();
        $context = $this->createContext('bash', 'echo "hello" > /tmp/test.txt');

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
    }

    public function testAllowSrcPhpFile(): void
    {
        $hook = new ProtectFilesHook();
        $context = $this->createContext('Edit', 'src/MyClass.php');

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
    }

    public function testAllowReadme(): void
    {
        $hook = new ProtectFilesHook();
        $context = $this->createContext('bash', 'cat README.md');

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
    }

    // =========================================================================
    // Execute Method Reads ToolInput From Context
    // =========================================================================

    public function testExecuteReadsToolInput(): void
    {
        $hook = new ProtectFilesHook();
        // Same command but different toolInput - the protection should trigger
        $context = $this->createContext('bash', 'nano .env');

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
    }

    public function testExecuteUsesContextToolInput(): void
    {
        $hook = new ProtectFilesHook();
        // With toolInput that doesn't match any protected pattern
        $context = $this->createContext('bash', 'ls -la');

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
    }

    // =========================================================================
    // Edge Case Tests
    // =========================================================================

    public function testAllowEmptyInput(): void
    {
        $hook = new ProtectFilesHook();
        $context = $this->createContext('bash', '');

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
    }

    public function testPartialPathMatchDoesNotTrigger(): void
    {
        $hook = new ProtectFilesHook();
        // Should NOT match .env in middle of path, only exact .env at end
        $context = $this->createContext('bash', 'ls /path/to/.env.backup');

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function createContext(string $toolName, string $toolInput): HookContext
    {
        return new HookContext(
            sessionId: 'test-session-123',
            toolName: $toolName,
            toolArgs: [],
            toolInput: $toolInput,
            toolOutput: '',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: '/tmp/test-project',
        );
    }
}
