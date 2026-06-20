<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\BuiltIn\AuditHook;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookResult;

/**
 * @see AuditHook
 */
final class AuditHookTest extends TestCase
{
    private string $tempLogFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempLogFile = sys_get_temp_dir() . '/audit-hook-test-' . uniqid() . '.log';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (file_exists($this->tempLogFile)) {
            unlink($this->tempLogFile);
        }
    }

    // =========================================================================
    // Basic Interface Tests
    // =========================================================================

    public function testName(): void
    {
        $hook = new AuditHook();

        $this->assertSame('audit', $hook->name());
    }

    public function testEvent(): void
    {
        $hook = new AuditHook();

        $this->assertSame(HookEvent::PostToolUse, $hook->event());
    }

    public function testMatcher(): void
    {
        $hook = new AuditHook();

        $this->assertSame('.*', $hook->matcher());
    }

    // =========================================================================
    // Log File Creation Tests
    // =========================================================================

    public function testExecuteCreatesLogFile(): void
    {
        $this->assertFileDoesNotExist($this->tempLogFile);

        $hook = new AuditHook($this->tempLogFile);
        $context = $this->createContext();
        $hook->execute($context);

        $this->assertFileExists($this->tempLogFile);
    }

    public function testExecuteCreatesDefaultLogFileWhenNoneProvided(): void
    {
        $hook = new AuditHook();
        $context = $this->createContext();

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
        $defaultLogFile = sys_get_temp_dir() . '/sugar-crush-audit.log';
        $this->assertFileExists($defaultLogFile);
        // Clean up default log file
        if (file_exists($defaultLogFile)) {
            unlink($defaultLogFile);
        }
    }

    // =========================================================================
    // Log Entry Format Tests
    // =========================================================================

    public function testExecuteWritesEntry(): void
    {
        $hook = new AuditHook($this->tempLogFile);
        $context = new HookContext(
            sessionId: 'session-789',
            toolName: 'bash',
            toolArgs: [],
            toolInput: 'echo "hello world"',
            toolOutput: 'hello world',
            model: 'claude-3-5-sonnet',
            provider: 'anthropic',
            projectRoot: '/home/user/project',
        );

        $hook->execute($context);

        $logContent = file_get_contents($this->tempLogFile);
        $this->assertNotEmpty($logContent);

        // Verify log entry format: [timestamp] sessionId toolName toolInput => truncatedOutput
        $this->assertStringStartsWith('[', $logContent);
        $this->assertStringContainsString('session-789', $logContent);
        $this->assertStringContainsString('bash', $logContent);
        $this->assertStringContainsString('echo "hello world"', $logContent);
    }

    public function testExecuteIncludesTimestamp(): void
    {
        $hook = new AuditHook($this->tempLogFile);
        $context = $this->createContext();

        $hook->execute($context);

        $logContent = file_get_contents($this->tempLogFile);
        // Timestamp format: [2026-06-03 12:34:56]
        $this->assertMatchesRegularExpression('/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/', $logContent);
    }

    public function testExecuteIncludesAllContextFields(): void
    {
        $hook = new AuditHook($this->tempLogFile);
        $context = new HookContext(
            sessionId: 'my-session-id',
            toolName: 'Edit',
            toolArgs: ['file' => 'test.php'],
            toolInput: 'nano src/Controller.php',
            toolOutput: 'File edited successfully',
            model: 'gpt-4',
            provider: 'openai',
            projectRoot: '/workspace/myapp',
        );

        $hook->execute($context);

        $logContent = file_get_contents($this->tempLogFile);
        $this->assertStringContainsString('my-session-id', $logContent);
        $this->assertStringContainsString('Edit', $logContent);
        $this->assertStringContainsString('nano src/Controller.php', $logContent);
        $this->assertStringContainsString('File edited successfully', $logContent);
    }

    // =========================================================================
    // Output Truncation Tests
    // =========================================================================

    public function testExecuteTruncatesOutput(): void
    {
        $hook = new AuditHook($this->tempLogFile);
        // Create a very long output (more than 200 chars)
        $longOutput = str_repeat('x', 500);
        $context = $this->createContext(toolOutput: $longOutput);

        $hook->execute($context);

        $logContent = file_get_contents($this->tempLogFile);
        // Find the "=>" marker and extract what follows
        $arrowPos = strpos($logContent, '=>');
        $this->assertNotFalse($arrowPos);
        $outputAfterArrow = trim(substr($logContent, $arrowPos + 2));
        // The truncated output should be at most 200 chars (plus newline)
        $this->assertLessThanOrEqual(200, strlen($outputAfterArrow));
    }

    public function testExecuteDoesNotTruncateShortOutput(): void
    {
        $hook = new AuditHook($this->tempLogFile);
        $context = $this->createContext(toolOutput: 'short output');

        $hook->execute($context);

        $logContent = file_get_contents($this->tempLogFile);
        $this->assertStringContainsString('short output', $logContent);
    }

    public function testExecuteTruncatesExactlyAt200Chars(): void
    {
        $hook = new AuditHook($this->tempLogFile);
        // Exactly 250 chars
        $exactOutput = str_repeat('a', 250);
        $context = $this->createContext(toolOutput: $exactOutput);

        $hook->execute($context);

        $logContent = file_get_contents($this->tempLogFile);
        $arrowPos = strpos($logContent, '=>');
        $outputAfterArrow = trim(substr($logContent, $arrowPos + 2));
        // Should be truncated to 200 chars
        $this->assertSame(200, strlen($outputAfterArrow));
    }

    // =========================================================================
    // Atomic Append Tests
    // =========================================================================

    public function testExecuteAppendsToLogFile(): void
    {
        $hook = new AuditHook($this->tempLogFile);
        $context1 = $this->createContext(toolInput: 'first command');
        $context2 = $this->createContext(toolInput: 'second command');

        $hook->execute($context1);
        $hook->execute($context2);

        $logContent = file_get_contents($this->tempLogFile);
        $this->assertStringContainsString('first command', $logContent);
        $this->assertStringContainsString('second command', $logContent);
        // Should have two entries
        $entryCount = substr_count($logContent, '[');
        $this->assertSame(2, $entryCount);
    }

    // =========================================================================
    // Allow Action Tests
    // =========================================================================

    public function testExecuteAlwaysAllows(): void
    {
        $hook = new AuditHook($this->tempLogFile);
        $context = $this->createContext();

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
    }

    // =========================================================================
    // Empty Output Tests
    // =========================================================================

    public function testExecuteHandlesEmptyOutput(): void
    {
        $hook = new AuditHook($this->tempLogFile);
        $context = $this->createContext(toolOutput: '');

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
        $logContent = file_get_contents($this->tempLogFile);
        $this->assertStringContainsString('=>', $logContent);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function createContext(
        string $sessionId = 'test-session',
        string $toolName = 'bash',
        string $toolInput = 'echo test',
        string $toolOutput = 'test output',
    ): HookContext {
        return new HookContext(
            sessionId: $sessionId,
            toolName: $toolName,
            toolArgs: [],
            toolInput: $toolInput,
            toolOutput: $toolOutput,
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: '/tmp/test-project',
        );
    }
}
