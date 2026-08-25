<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\BuiltIn\AuditHook;
use SugarCraft\Crush\Hooks\BuiltIn\ConfirmRemoveHook;
use SugarCraft\Crush\Hooks\BuiltIn\ProtectFilesHook;
use SugarCraft\Crush\Hooks\HookConfig;
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
        $tempFile = sys_get_temp_dir() . '/test_hooks_' . uniqid((string) getmypid(), true) . '.yaml';
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

    /**
     * `disabled: true` MEANS NOT IN THE CHAIN. The key was accepted and
     * silently ignored before, so a user who wrote the natural thing — the
     * registry has a first-class disable()/isDisabled() pair — got a hook that
     * ran anyway.
     */
    public function testADisabledEntryIsNotRegistered(): void
    {
        $tempFile = $this->writeHookFile(<<<'YAML'
hooks:
  PreToolUse:
    - name: off-guard
      command: 'true'
      disabled: true
    - name: on-guard
      command: 'true'
YAML);

        try {
            $this->manager->loadFromFile($tempFile);

            $this->assertNull($this->registry->get('PreToolUse', 'off-guard'));
            $this->assertNotNull($this->registry->get('PreToolUse', 'on-guard'));
        } finally {
            unlink($tempFile);
        }
    }

    /**
     * A disabled entry is still fully VALIDATED. `disabled: true` beside a
     * misspelled key must not become a way to smuggle an unreadable entry past
     * the checks by declaring it inert.
     */
    public function testADisabledEntryIsStillValidated(): void
    {
        $tempFile = $this->writeHookFile(<<<'YAML'
hooks:
  PreToolUse:
    - name: off-guard
      command: 'true'
      disabled: true
      mather: '^Bash$'
YAML);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/is not a key a hook entry/');

            $this->manager->loadFromFile($tempFile);
        } finally {
            unlink($tempFile);
        }
    }

    /**
     * The seam {@see \SugarCraft\Crush\Cli\Bootstrap::hookFileEntries()} uses to
     * replay ONE read of a hook file into every hook manager a launch builds —
     * which is what stops a file written mid-session installing itself into the
     * chain on the next provider switch. Same registration contract as
     * {@see HookManager::loadFromFile()}, including the refusal to displace
     * anything already in the chain, with $source only naming the origin for
     * the message.
     */
    public function testLoadEntriesRegistersWithoutTouchingDisk(): void
    {
        $this->manager->registerBuiltIns();
        $this->manager->loadEntries(
            HookConfig::parse("hooks:\n  PreToolUse:\n    - name: replayed\n      command: 'true'\n"),
            '/some/hooks.yaml',
        );

        $this->assertNotNull($this->manager->hook('PreToolUse', 'replayed'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#/some/hooks\.yaml: a hook named .replayed. is already registered#');

        $this->manager->loadEntries(
            HookConfig::parse("hooks:\n  PreToolUse:\n    - name: replayed\n      command: 'true'\n"),
            '/some/hooks.yaml',
        );
    }

    /**
     * A hook file may ADD to the chain and may never replace what is in it:
     * `name: confirm-rm` would otherwise overwrite the registry entry
     * {@see ConfirmRemoveHook} occupies — a config file uninstalling a guard by
     * naming it.
     */
    public function testLoadFromFileRefusesToDisplaceABuiltInGuard(): void
    {
        $this->manager->registerBuiltIns();

        $tempFile = $this->writeHookFile(<<<'YAML'
hooks:
  PreToolUse:
    - name: confirm-rm
      matcher: '.*'
      command: 'true'
YAML);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/may not replace/');

            $this->manager->loadFromFile($tempFile);
        } finally {
            unlink($tempFile);
        }
    }

    /**
     * The guard survives the refusal — the point is that the built-in is still
     * the hook registered under that name, not merely that something threw.
     */
    public function testABuiltInGuardSurvivesARefusedDisplacement(): void
    {
        $this->manager->registerBuiltIns();

        $tempFile = $this->writeHookFile(<<<'YAML'
hooks:
  PreToolUse:
    - name: confirm-rm
      matcher: '.*'
      command: 'true'
YAML);

        try {
            $this->manager->loadFromFile($tempFile);
            $this->fail('expected the displacement to be refused');
        } catch (\InvalidArgumentException) {
            $this->assertInstanceOf(
                ConfirmRemoveHook::class,
                $this->registry->get('PreToolUse', 'confirm-rm'),
            );
        } finally {
            unlink($tempFile);
        }
    }

    /**
     * The reserved permission-gate name is refused by {@see HookRegistry::register()}
     * itself, so it cannot be claimed even by a file loaded BEFORE the gate is
     * installed — which is the order {@see \SugarCraft\Crush\Cli\Bootstrap::hooks()}
     * uses.
     */
    public function testLoadFromFileCannotClaimThePermissionGateName(): void
    {
        $tempFile = $this->writeHookFile(<<<'YAML'
hooks:
  PreToolUse:
    - name: permission-gate
      matcher: '.*'
      command: 'true'
YAML);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/reserved for the permission gate/');

            $this->manager->loadFromFile($tempFile);
        } finally {
            unlink($tempFile);
        }
    }

    /**
     * Two files, no collision: both files' hooks end up in one chain, which is
     * what makes a project file additive to the user's rather than a
     * replacement for it.
     */
    public function testTwoHookFilesBothAddToTheChain(): void
    {
        $userFile = $this->writeHookFile(<<<'YAML'
hooks:
  PreToolUse:
    - name: user-guard
      command: 'true'
YAML);
        $projectFile = $this->writeHookFile(<<<'YAML'
hooks:
  PreToolUse:
    - name: project-guard
      command: 'true'
YAML);

        try {
            $this->manager->loadFromFile($userFile);
            $this->manager->loadFromFile($projectFile);

            $this->assertNotNull($this->manager->hook('PreToolUse', 'user-guard'));
            $this->assertNotNull($this->manager->hook('PreToolUse', 'project-guard'));
        } finally {
            unlink($userFile);
            unlink($projectFile);
        }
    }

    /**
     * ...and a collision BETWEEN the two files is refused too, in whichever
     * order they are loaded, so neither file can disarm the other's hook by
     * reusing its name.
     */
    public function testACollisionBetweenTwoHookFilesIsRefused(): void
    {
        $userFile = $this->writeHookFile(<<<'YAML'
hooks:
  PreToolUse:
    - name: guard
      command: 'user.sh'
YAML);
        $projectFile = $this->writeHookFile(<<<'YAML'
hooks:
  PreToolUse:
    - name: guard
      command: 'project.sh'
YAML);

        try {
            $this->manager->loadFromFile($userFile);

            $this->expectException(\InvalidArgumentException::class);
            $this->manager->loadFromFile($projectFile);
        } finally {
            unlink($userFile);
            unlink($projectFile);
        }
    }

    private function writeHookFile(string $yaml): string
    {
        $path = sys_get_temp_dir() . '/test_hooks_' . uniqid('', true) . '.yaml';
        file_put_contents($path, $yaml);

        return $path;
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
    // ask() Passthrough Tests
    // =========================================================================

    public function testPreToolUsePassesAskThrough(): void
    {
        $this->manager->register($this->createAskHook('confirm-bash', 'Bash', 'Run this command?'));

        $result = $this->manager->preToolUse($this->createContext('Bash', 'rm -rf /tmp/x'));

        $this->assertTrue($result->isAsk());
        $this->assertSame('Run this command?', $result->message);
        // The gate must not read an unanswered question as permission.
        $this->assertFalse($result->permitsExecution());
    }

    // =========================================================================
    // resolveAsk Tests
    // =========================================================================

    public function testResolveAskApproved(): void
    {
        $ask = HookResult::ask('Run this command?');

        $result = $this->manager->resolveAsk($ask, true);

        $this->assertTrue($result->isAllowed());
        $this->assertTrue($result->permitsExecution());
        $this->assertSame('Run this command?', $result->message);
    }

    public function testResolveAskRejected(): void
    {
        $ask = HookResult::ask('Run this command?');

        $result = $this->manager->resolveAsk($ask, false);

        $this->assertTrue($result->isDenied());
        $this->assertFalse($result->permitsExecution());
    }

    public function testResolveAskRejectedWithFeedbackReplacesMessage(): void
    {
        $ask = HookResult::ask('Run this command?');

        $result = $this->manager->resolveAsk($ask, false, 'Use the test fixture path instead.');

        $this->assertTrue($result->isDenied());
        $this->assertSame('Use the test fixture path instead.', $result->message);
    }

    public function testResolveAskApprovedWithFeedbackReplacesMessage(): void
    {
        $ask = HookResult::ask('Run this command?');

        $result = $this->manager->resolveAsk($ask, true, 'Approved for this session.');

        $this->assertTrue($result->isAllowed());
        $this->assertSame('Approved for this session.', $result->message);
    }

    public function testResolveAskRejectsAnAlreadySettledDeny(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot resolve a 'deny' hook result");

        // Resolving a settled decision would be a DENY→ALLOW path.
        $this->manager->resolveAsk(HookResult::deny('Protected file'), true);
    }

    public function testResolveAskRejectsAnAlreadySettledAllow(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->manager->resolveAsk(HookResult::allow(), false);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function createAskHook(string $name, string $matcher, string $question): \SugarCraft\Crush\Hooks\HookInterface
    {
        return new class($name, $matcher, $question) implements \SugarCraft\Crush\Hooks\HookInterface {
            public function __construct(
                private string $name,
                private string $matcher,
                private string $question,
            ) {}

            public function name(): string
            {
                return $this->name;
            }

            public function event(): HookEvent
            {
                return HookEvent::PreToolUse;
            }

            public function matcher(): string
            {
                return $this->matcher;
            }

            public function execute(HookContext $context): HookResult
            {
                return HookResult::ask($this->question);
            }
        };
    }

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
