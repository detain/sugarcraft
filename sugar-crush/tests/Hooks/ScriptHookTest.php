<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Hooks\ScriptHook;

/**
 * @see ScriptHook
 */
final class ScriptHookTest extends TestCase
{
    // =========================================================================
    // fromConfig Tests
    // =========================================================================

    public function testFromConfig(): void
    {
        $config = [
            'event' => 'PreToolUse',
            'matcher' => '^Read$',
            'command' => 'my_script.sh',
            'description' => 'Test hook',
        ];

        $hook = ScriptHook::fromConfig($config);

        $this->assertInstanceOf(ScriptHook::class, $hook);
        $this->assertSame('my_script.sh', $hook->name());
        $this->assertSame(HookEvent::PreToolUse, $hook->event());
        $this->assertSame('^Read$', $hook->matcher());
    }

    public function testFromConfigPostToolUseEvent(): void
    {
        $config = [
            'event' => 'PostToolUse',
            'command' => 'post_hook.sh',
        ];

        $hook = ScriptHook::fromConfig($config);

        $this->assertSame(HookEvent::PostToolUse, $hook->event());
    }

    public function testFromConfigInvalidEventFallsBackToPreToolUse(): void
    {
        $config = [
            'event' => 'InvalidEvent',
            'command' => 'test.sh',
        ];

        $hook = ScriptHook::fromConfig($config);

        $this->assertSame(HookEvent::PreToolUse, $hook->event());
    }

    // =========================================================================
    // Accessor Tests
    // =========================================================================

    public function testName(): void
    {
        $hook = new ScriptHook(
            name: 'my_hook_name',
            event: HookEvent::PreToolUse,
            matcher: '^Read$',
            command: 'echo test',
            description: 'Test description',
        );

        $this->assertSame('my_hook_name', $hook->name());
    }

    public function testEvent(): void
    {
        $hook = new ScriptHook(
            name: 'test',
            event: HookEvent::PostToolUse,
            matcher: '.*',
            command: 'echo test',
            description: '',
        );

        $this->assertSame(HookEvent::PostToolUse, $hook->event());
    }

    public function testMatcher(): void
    {
        $hook = new ScriptHook(
            name: 'test',
            event: HookEvent::PreToolUse,
            matcher: '^File(Read|Write)$',
            command: 'echo test',
            description: '',
        );

        $this->assertSame('^File(Read|Write)$', $hook->matcher());
    }

    // =========================================================================
    // execute Tests
    // =========================================================================

    public function testExecuteAllow(): void
    {
        $hook = new ScriptHook(
            name: 'allow_hook',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'printf "allowed"',
            description: '',
        );

        $context = $this->createContext();

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
        $this->assertSame('allowed', $result->message);
    }

    public function testExecuteDeny(): void
    {
        $hook = new ScriptHook(
            name: 'deny_hook',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'printf "access denied" >&2 && exit 1',
            description: '',
        );

        $context = $this->createContext();

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
        $this->assertSame('access denied', $result->message);
    }

    public function testExecuteDenyWithExitCode(): void
    {
        $hook = new ScriptHook(
            name: 'deny_hook',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'exit 42',
            description: '',
        );

        $context = $this->createContext();

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
        $this->assertSame('Hook exited with code 42', $result->message);
    }

    public function testExecuteAllowWithEmptyOutput(): void
    {
        $hook = new ScriptHook(
            name: 'empty_hook',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'true',
            description: '',
        );

        $context = $this->createContext();

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
        $this->assertSame('', $result->message);
    }

    public function testExecutePassesEnvironmentVariables(): void
    {
        // This test verifies the hook receives env vars by running a script that outputs them
        $hook = new ScriptHook(
            name: 'env_check',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'printf "$CRUSH_TOOL_NAME:$CRUSH_SESSION_ID"',
            description: '',
        );

        $context = $this->createContext();

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
        $this->assertSame('TestTool:test_session_123', $result->message);
    }

    public function testExecuteWithWhitespaceOutput(): void
    {
        $hook = new ScriptHook(
            name: 'whitespace_hook',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'printf "  hello world  \n\t"',
            description: '',
        );

        $context = $this->createContext();

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
        $this->assertSame('hello world', $result->message);
    }

    // =========================================================================
    // Fail-closed Tests — an unusable projectRoot must not become an allow
    // =========================================================================

    /**
     * The regression this pins: `proc_open()` refuses to start a process whose
     * `cwd` does not exist, and ScriptHook used to translate that failure into
     * `HookResult::allow()`. A `--root /typo` therefore turned every DENYING
     * hook into an allow — the one direction a gate must never fail in
     * (crush_code.md Phase 0 item 6).
     */
    public function testADenyingHookStaysDenyingWhenTheProjectRootDoesNotExist(): void
    {
        $hook = new ScriptHook(
            name: 'deny_hook',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'printf "access denied" >&2 && exit 1',
            description: '',
        );

        $result = $hook->execute($this->createContext(
            sys_get_temp_dir() . '/sugarcrush_no_such_root_' . uniqid('', true),
        ));

        $this->assertTrue($result->isDenied(), 'a bogus --root must not downgrade a deny into an allow');
        $this->assertSame('access denied', $result->message);
    }

    /**
     * `TaskList::makeHookContext()` used to hardcode `projectRoot: ''`, which
     * hits the same `proc_open()` failure as a bogus `--root`.
     */
    public function testADenyingHookStaysDenyingWhenTheProjectRootIsEmpty(): void
    {
        $hook = new ScriptHook(
            name: 'deny_hook',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'exit 42',
            description: '',
        );

        $result = $hook->execute($this->createContext(''));

        $this->assertTrue($result->isDenied());
        $this->assertSame('Hook exited with code 42', $result->message);
    }

    /**
     * The fallback only replaces the DIRECTORY, never the verdict — a usable
     * root is still the directory the script runs in.
     */
    public function testAUsableProjectRootIsStillTheScriptsWorkingDirectory(): void
    {
        $root = sys_get_temp_dir() . '/sugarcrush_root_' . uniqid('', true);
        mkdir($root, 0755, true);

        $hook = new ScriptHook(
            name: 'pwd_hook',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'pwd',
            description: '',
        );

        try {
            $result = $hook->execute($this->createContext($root));

            $this->assertTrue($result->isAllowed());
            $this->assertSame(realpath($root), realpath($result->message));
        } finally {
            rmdir($root);
        }
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function createContext(string $projectRoot = '/tmp'): HookContext
    {
        return new HookContext(
            sessionId: 'test_session_123',
            toolName: 'TestTool',
            toolArgs: [],
            toolInput: 'test input',
            toolOutput: 'test output',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: $projectRoot,
        );
    }
}
