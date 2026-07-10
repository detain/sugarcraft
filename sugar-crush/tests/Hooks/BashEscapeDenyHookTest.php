<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\BuiltIn\BashEscapeDenyHook;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;

/**
 * @see BashEscapeDenyHook
 */
final class BashEscapeDenyHookTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/bash_escape_deny_' . uniqid('', true);
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->root)) {
            rmdir($this->root);
        }
    }

    // =========================================================================
    // Basic Interface Tests
    // =========================================================================

    public function testName(): void
    {
        $this->assertSame('bash-escape-deny', (new BashEscapeDenyHook($this->root))->name());
    }

    public function testEvent(): void
    {
        $this->assertSame(HookEvent::PreToolUse, (new BashEscapeDenyHook($this->root))->event());
    }

    public function testMatcher(): void
    {
        $this->assertSame('^Bash$', (new BashEscapeDenyHook($this->root))->matcher());
    }

    // =========================================================================
    // Escaping-path Denial Tests
    // =========================================================================

    public function testDeniesAbsolutePathOutsideRoot(): void
    {
        $hook = new BashEscapeDenyHook($this->root);

        $result = $hook->execute($this->context('cat /etc/passwd'));

        $this->assertTrue($result->isDenied());
        $this->assertStringContainsString('/etc/passwd', $result->message);
    }

    public function testDeniesRelativeDotDotEscape(): void
    {
        $hook = new BashEscapeDenyHook($this->root);

        $result = $hook->execute($this->context('cat ../../etc/passwd'));

        $this->assertTrue($result->isDenied());
    }

    public function testDeniesRedirectionOutsideRoot(): void
    {
        $hook = new BashEscapeDenyHook($this->root);

        $result = $hook->execute($this->context('echo pwned > /etc/cron.d/x'));

        $this->assertTrue($result->isDenied());
    }

    // =========================================================================
    // In-jail Allow Tests
    // =========================================================================

    public function testAllowsRelativeInJailPath(): void
    {
        $hook = new BashEscapeDenyHook($this->root);

        $result = $hook->execute($this->context('cat notes.txt'));

        $this->assertTrue($result->isAllowed());
    }

    public function testAllowsAbsoluteInJailPath(): void
    {
        $hook = new BashEscapeDenyHook($this->root);

        $result = $hook->execute($this->context('cat ' . $this->root . '/notes.txt'));

        $this->assertTrue($result->isAllowed());
    }

    public function testAllowsCommandWithNoPaths(): void
    {
        $hook = new BashEscapeDenyHook($this->root);

        $result = $hook->execute($this->context('ls -la'));

        $this->assertTrue($result->isAllowed());
    }

    public function testAllowsEmptyCommand(): void
    {
        $hook = new BashEscapeDenyHook($this->root);

        $result = $hook->execute($this->context(''));

        $this->assertTrue($result->isAllowed());
    }

    // =========================================================================
    // Helper
    // =========================================================================

    private function context(string $command): HookContext
    {
        return new HookContext(
            sessionId: 'test-session',
            toolName: 'Bash',
            toolArgs: ['command' => $command],
            toolInput: json_encode(['command' => $command]),
            toolOutput: '',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: $this->root,
        );
    }
}
