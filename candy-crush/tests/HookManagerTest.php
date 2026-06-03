<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\BuiltIn\AuditHook;
use SugarCraft\Crush\Hooks\BuiltIn\ConfirmRemoveHook;
use SugarCraft\Crush\Hooks\BuiltIn\ProtectFilesHook;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;

/**
 * @see HookManager
 */
final class HookManagerTest extends TestCase
{
    private HookRegistry $registry;
    private HookManager $manager;

    protected function setUp(): void
    {
        $this->registry = new HookRegistry();
        $this->manager = new HookManager($this->registry);
    }

    // =========================================================================
    // registerBuiltIns Tests
    // =========================================================================

    public function testRegisterBuiltIns(): void
    {
        $this->manager->registerBuiltIns();

        // Verify ProtectFilesHook is registered (name is 'protect-files')
        $protectHook = $this->registry->get('PreToolUse', 'protect-files');
        $this->assertNotNull($protectHook);
        $this->assertInstanceOf(ProtectFilesHook::class, $protectHook);

        // Verify ConfirmRemoveHook is registered (name is 'confirm-rm')
        $confirmHook = $this->registry->get('PreToolUse', 'confirm-rm');
        $this->assertNotNull($confirmHook);
        $this->assertInstanceOf(ConfirmRemoveHook::class, $confirmHook);

        // Verify AuditHook is registered (name is 'audit')
        $auditHook = $this->registry->get('PostToolUse', 'audit');
        $this->assertNotNull($auditHook);
        $this->assertInstanceOf(AuditHook::class, $auditHook);
    }

    public function testRegisterBuiltInsCanBeCalledMultipleTimes(): void
    {
        $this->manager->registerBuiltIns();
        $this->manager->registerBuiltIns(); // Should not throw

        // Hooks should still be registered (possibly duplicated by name but that's registry's job)
        $protectHook = $this->registry->get('PreToolUse', 'protect-files');
        $this->assertNotNull($protectHook);
    }

    // =========================================================================
    // preToolUse Tests
    // =========================================================================

    public function testPreToolUse(): void
    {
        $context = $this->createContext('TestTool', 'input');

        $result = $this->manager->preToolUse($context);

        // Should return allow since no hooks are registered
        $this->assertTrue($result->isAllowed());
    }

    public function testPreToolUseDelegatesToRegistry(): void
    {
        // Register a hook that denies
        $this->registry->register(new class implements \SugarCraft\Crush\Hooks\HookInterface {
            public function name(): string { return 'deny_all'; }
            public function event(): HookEvent { return HookEvent::PreToolUse; }
            public function matcher(): string { return '.*'; }
            public function execute(HookContext $context): HookResult {
                return HookResult::deny('Denied by test hook');
            }
        });

        $context = $this->createContext('TestTool', 'input');

        $result = $this->manager->preToolUse($context);

        $this->assertTrue($result->isDenied());
        $this->assertSame('Denied by test hook', $result->message);
    }

    // =========================================================================
    // postToolUse Tests
    // =========================================================================

    public function testPostToolUse(): void
    {
        $context = $this->createContext('TestTool', 'input');

        $result = $this->manager->postToolUse($context);

        // Should return allow since no hooks are registered
        $this->assertTrue($result->isAllowed());
    }

    public function testPostToolUseDelegatesToRegistry(): void
    {
        // Register a hook that denies for PostToolUse
        $this->registry->register(new class implements \SugarCraft\Crush\Hooks\HookInterface {
            public function name(): string { return 'deny_post'; }
            public function event(): HookEvent { return HookEvent::PostToolUse; }
            public function matcher(): string { return '.*'; }
            public function execute(HookContext $context): HookResult {
                return HookResult::deny('Denied by post hook');
            }
        });

        $context = $this->createContext('TestTool', 'input');

        $result = $this->manager->postToolUse($context);

        $this->assertTrue($result->isDenied());
        $this->assertSame('Denied by post hook', $result->message);
    }

    // =========================================================================
    // applyPreHooks Tests
    // =========================================================================

    public function testApplyPreHooks(): void
    {
        $context = $this->createContext('TestTool', 'original input');

        $result = $this->manager->applyPreHooks('TestTool', 'modified input', $context);

        $this->assertTrue($result->isAllowed());
    }

    public function testApplyPreHooksCreatesContextWithToolInput(): void
    {
        // Register a modify hook
        $this->registry->register(new class implements \SugarCraft\Crush\Hooks\HookInterface {
            public function name(): string { return 'modify_input'; }
            public function event(): HookEvent { return HookEvent::PreToolUse; }
            public function matcher(): string { return '.*'; }
            public function execute(HookContext $context): HookResult {
                // If the context has our modified input, return modified result
                if ($context->toolInput === 'custom input') {
                    return HookResult::modify('modified by hook', 'Input modified');
                }
                return HookResult::allow();
            }
        });

        $baseContext = $this->createContext('TestTool', 'original');
        $input = 'custom input';

        $result = $this->manager->applyPreHooks('TestTool', $input, $baseContext);

        $this->assertTrue($result->isModified());
        $this->assertSame('modified by hook', $result->modifiedInput);
    }

    public function testApplyPreHooksWithMatchingHook(): void
    {
        // Register a hook that only matches specific tool
        $this->registry->register(new class implements \SugarCraft\Crush\Hooks\HookInterface {
            public function name(): string { return 'deny_delete'; }
            public function event(): HookEvent { return HookEvent::PreToolUse; }
            public function matcher(): string { return '^Delete$'; }
            public function execute(HookContext $context): HookResult {
                return HookResult::deny('Cannot delete');
            }
        });

        $baseContext = $this->createContext('Delete', '');

        // Should match and deny
        $result = $this->manager->applyPreHooks('Delete', '', $baseContext);
        $this->assertTrue($result->isDenied());

        // Other tool should not match
        $otherContext = $this->createContext('Read', '');
        $result = $this->manager->applyPreHooks('Read', '', $otherContext);
        $this->assertTrue($result->isAllowed());
    }

    // =========================================================================
    // loadFromFile Tests
    // =========================================================================

    public function testLoadFromFileNotFound(): void
    {
        $this->manager->loadFromFile('/nonexistent/hooks.yaml');

        // Should not throw, just no hooks loaded
        $result = $this->manager->preToolUse($this->createContext('Test', ''));
        $this->assertTrue($result->isAllowed());
    }

    public function testLoadFromFileWithValidYaml(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_hooks_' . uniqid() . '.yaml';
        file_put_contents($tempFile, <<<'YAML'
hooks:
  PreToolUse:
    - matcher: '^Test$'
      command: 'printf "loaded"'
      description: 'Test hook'
YAML);

        try {
            $this->manager->loadFromFile($tempFile);

            $result = $this->manager->preToolUse($this->createContext('Test', ''));
            // ScriptHook returns allow - registry aggregates but allows through
            $this->assertTrue($result->isAllowed());
        } finally {
            unlink($tempFile);
        }
    }

    // =========================================================================
    // Integration Tests
    // =========================================================================

    public function testBuiltInsAndPreToolUseWorkTogether(): void
    {
        $this->manager->registerBuiltIns();

        $context = $this->createContext('TestTool', 'input');

        $result = $this->manager->preToolUse($context);

        // Should still return allow even with built-in hooks registered
        // (unless one of them denies, which they shouldn't for arbitrary tools)
        $this->assertTrue($result->isAllowed());
    }

    public function testPreToolUseAndPostToolUseIndependent(): void
    {
        // Register different hooks for pre and post
        $this->registry->register(new class implements \SugarCraft\Crush\Hooks\HookInterface {
            public function name(): string { return 'pre_deny'; }
            public function event(): HookEvent { return HookEvent::PreToolUse; }
            public function matcher(): string { return '.*'; }
            public function execute(HookContext $context): HookResult {
                return HookResult::deny('pre deny');
            }
        });

        $this->registry->register(new class implements \SugarCraft\Crush\Hooks\HookInterface {
            public function name(): string { return 'post_allow'; }
            public function event(): HookEvent { return HookEvent::PostToolUse; }
            public function matcher(): string { return '.*'; }
            public function execute(HookContext $context): HookResult {
                return HookResult::allow('post allow');
            }
        });

        $context = $this->createContext('Test', '');

        $preResult = $this->manager->preToolUse($context);
        $this->assertTrue($preResult->isDenied());

        $postResult = $this->manager->postToolUse($context);
        $this->assertTrue($postResult->isAllowed());
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function createContext(string $toolName, string $toolInput): HookContext
    {
        return new HookContext(
            sessionId: 'test_session',
            toolName: $toolName,
            toolArgs: [],
            toolInput: $toolInput,
            toolOutput: '',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: '/tmp',
        );
    }
}
