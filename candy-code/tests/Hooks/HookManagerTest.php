<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Hooks\BuiltIn\AuditHook;
use SugarCraft\Code\Hooks\BuiltIn\ConfirmRemoveHook;
use SugarCraft\Code\Hooks\BuiltIn\ProtectFilesHook;
use SugarCraft\Code\Hooks\HookConfig;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Hooks\HookResult;

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
        $this->registry->register(new class implements \SugarCraft\Code\Hooks\HookInterface {
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
        $this->registry->register(new class implements \SugarCraft\Code\Hooks\HookInterface {
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
        $this->registry->register(new class implements \SugarCraft\Code\Hooks\HookInterface {
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
        $this->registry->register(new class implements \SugarCraft\Code\Hooks\HookInterface {
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
     * The seam {@see \SugarCraft\Code\Cli\Bootstrap::hookFileEntries()} uses to
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
     * installed — which is the order {@see \SugarCraft\Code\Cli\Bootstrap::hooks()}
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
        $this->registry->register(new class implements \SugarCraft\Code\Hooks\HookInterface {
            public function name(): string { return 'pre_deny'; }
            public function event(): HookEvent { return HookEvent::PreToolUse; }
            public function matcher(): string { return '.*'; }
            public function execute(HookContext $context): HookResult {
                return HookResult::deny('pre deny');
            }
        });

        $this->registry->register(new class implements \SugarCraft\Code\Hooks\HookInterface {
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
    // sessionStart() / userPromptSubmit() Tests (P7.S2)
    //
    // The load-bearing question for a 2-line dispatch clone is "did the RIGHT
    // enum case reach the registry", because every one of these clones is
    // byte-identical to its siblings except the case named in it. Each test
    // therefore registers a hook that answers differently per event and asserts
    // the event-specific bytes come back — a clone dispatching the wrong case
    // cannot pass, and neither can one dispatching nothing.
    // =========================================================================

    public function testSessionStartDispatchesTheSessionStartEvent(): void
    {
        $this->registry->register($this->createTurnHook('ss-note', HookEvent::SessionStart, '', 'deployment target is production'));
        // Registered for the SIBLING event: if sessionStart() dispatched
        // UserPromptSubmit (the copy-paste failure this clone invites), this hook's
        // note would be the one that came back.
        $this->registry->register($this->createTurnHook('ups-note', HookEvent::UserPromptSubmit, '', 'WRONG EVENT'));

        $result = $this->manager->sessionStart($this->createTurnContext('SessionStart', '{"prompt":"hi","source":"startup"}'));

        $this->assertTrue($result->isAllowed());
        $this->assertSame('deployment target is production', $result->additionalContext);
    }

    public function testUserPromptSubmitDispatchesTheUserPromptSubmitEvent(): void
    {
        $this->registry->register($this->createTurnHook('ss-note', HookEvent::SessionStart, '', 'WRONG EVENT'));
        $this->registry->register($this->createTurnHook('ups-note', HookEvent::UserPromptSubmit, '', 'repo uses bun test'));

        $result = $this->manager->userPromptSubmit($this->createTurnContext('UserPromptSubmit', '{"prompt":"hi"}'));

        $this->assertTrue($result->isAllowed());
        $this->assertSame('repo uses bun test', $result->additionalContext);
    }

    public function testSessionStartWithNothingRegisteredForItProducesNoNote(): void
    {
        // The other polarity, and the no-op pin at this layer: a chain that holds
        // only a UserPromptSubmit hook must give sessionStart() an empty note, or
        // every session would start with someone else's context attached.
        $this->registry->register($this->createTurnHook('ups-note', HookEvent::UserPromptSubmit, '', 'not for you'));

        $result = $this->manager->sessionStart($this->createTurnContext('SessionStart', '{"prompt":"hi","source":"startup"}'));

        $this->assertTrue($result->isAllowed());
        $this->assertSame('', $result->additionalContext);
        $this->assertSame('', $result->message);
    }

    public function testUserPromptSubmitWithNothingRegisteredForItProducesNoNote(): void
    {
        $this->registry->register($this->createTurnHook('ss-note', HookEvent::SessionStart, '', 'not for you'));

        $result = $this->manager->userPromptSubmit($this->createTurnContext('UserPromptSubmit', '{"prompt":"hi"}'));

        $this->assertTrue($result->isAllowed());
        $this->assertSame('', $result->additionalContext);
    }

    public function testATurnHookWhoseMatcherMissesTheSentinelDoesNotFire(): void
    {
        // findMatches() tests the hook's matcher against toolName, and the sentinel
        // IS the toolName for these events — so a matcher written for a tool name
        // must not fire on a turn event. This is the pin on decision C's claim that
        // the sentinel slot is what matching actually happens in.
        $this->registry->register($this->createTurnHook('bash-only', HookEvent::UserPromptSubmit, '^Bash$', 'should never fire'));

        $result = $this->manager->userPromptSubmit($this->createTurnContext('UserPromptSubmit', '{"prompt":"hi"}'));

        $this->assertSame('', $result->additionalContext);
    }

    public function testSessionStartPassesTheSmuggledSentinelAndPromptToTheHook(): void
    {
        $captured = null;
        $this->registry->register($this->createCapturingTurnHook(HookEvent::SessionStart, $captured));

        $this->manager->sessionStart($this->createTurnContext(
            'SessionStart',
            '{"prompt":"first prompt of the run","source":"startup"}',
        ));

        $this->assertInstanceOf(HookContext::class, $captured);
        $this->assertSame('SessionStart', $captured->toolName);
        $this->assertSame('first prompt of the run', json_decode($captured->toolInput, true)['prompt']);
        $this->assertSame('startup', json_decode($captured->toolInput, true)['source']);
        // Empty by contract (gateToolCall precedent), not unset.
        $this->assertSame('', $captured->model);
        $this->assertSame('', $captured->provider);
    }

    public function testUserPromptSubmitPassesTheSmuggledSentinelAndPromptToTheHook(): void
    {
        $captured = null;
        $this->registry->register($this->createCapturingTurnHook(HookEvent::UserPromptSubmit, $captured));

        $this->manager->userPromptSubmit($this->createTurnContext('UserPromptSubmit', '{"prompt":"deploy it"}'));

        $this->assertInstanceOf(HookContext::class, $captured);
        $this->assertSame('UserPromptSubmit', $captured->toolName);
        $decoded = json_decode($captured->toolInput, true);
        $this->assertSame('deploy it', $decoded['prompt']);
        // SessionStart's `"source":"startup"` must NOT leak into this event's payload:
        // a script that branches on source has to be able to tell the two apart.
        $this->assertSame(['prompt' => 'deploy it'], $decoded);
    }

    public function testAnEmptyPromptStillReachesAUserPromptSubmitHookAsValidJson(): void
    {
        // Pathological input: Chat guards the empty DRAFT above submit(), so the
        // hook should never see '' — but the smuggled payload must stay parseable if
        // it ever does, because the script protocol decodes CRUSH_TOOL_INPUT as JSON.
        $captured = null;
        $this->registry->register($this->createCapturingTurnHook(HookEvent::UserPromptSubmit, $captured));

        $this->manager->userPromptSubmit($this->createTurnContext('UserPromptSubmit', '{"prompt":""}'));

        $this->assertInstanceOf(HookContext::class, $captured);
        $this->assertSame('{"prompt":""}', $captured->toolInput);
        $this->assertSame('', json_decode($captured->toolInput, true)['prompt']);
    }

    public function testUserPromptSubmitDenyPassesThroughVerbatim(): void
    {
        // discardsOnBlock() is acted on by Chat, not here; this layer's contract is
        // that the verdict survives dispatch unchanged, which is what makes the
        // consumer's block handling testable at all.
        $this->registry->register($this->createTurnHook('deny-submit', HookEvent::UserPromptSubmit, '', '', HookResult::deny('no prompts on a Friday')));

        $result = $this->manager->userPromptSubmit($this->createTurnContext('UserPromptSubmit', '{"prompt":"hi"}'));

        $this->assertTrue($result->isDenied());
        $this->assertFalse($result->permitsExecution());
        $this->assertSame('no prompts on a Friday', $result->message);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * A turn-event hook that answers with a fixed note (or a fixed verdict).
     *
     * @param string $matcher a PHP regex WITH delimiters, matching HookRegistry::findMatches()
     */
    private function createTurnHook(
        string $name,
        HookEvent $event,
        string $matcher,
        string $note,
        ?HookResult $verdict = null,
    ): \SugarCraft\Code\Hooks\HookInterface {
        return new class($name, $event, $matcher, $note, $verdict) implements \SugarCraft\Code\Hooks\HookInterface {
            public function __construct(
                private string $name,
                private HookEvent $event,
                private string $matcher,
                private string $note,
                private ?HookResult $verdict,
            ) {}

            public function name(): string
            {
                return $this->name;
            }

            public function event(): HookEvent
            {
                return $this->event;
            }

            public function matcher(): string
            {
                return $this->matcher;
            }

            public function execute(HookContext $context): HookResult
            {
                return $this->verdict ?? HookResult::allow('', $this->note);
            }
        };
    }

    /**
     * Records the context it was dispatched with so the smuggling itself can be
     * asserted rather than inferred from a note coming back.
     */
    private function createCapturingTurnHook(HookEvent $event, ?HookContext &$captured): \SugarCraft\Code\Hooks\HookInterface
    {
        return new class($event, $captured) implements \SugarCraft\Code\Hooks\HookInterface {
            /** @param HookContext|null $captured */
            public function __construct(private HookEvent $event, private ?HookContext &$captured) {}

            public function name(): string
            {
                return 'capture-' . strtolower($this->event->name);
            }

            public function event(): HookEvent
            {
                return $this->event;
            }

            public function matcher(): string
            {
                return '';
            }

            public function execute(HookContext $context): HookResult
            {
                $this->captured = $context;

                return HookResult::allow();
            }
        };
    }

    private function createTurnContext(string $sentinel, string $toolInput): HookContext
    {
        return new HookContext(
            sessionId: '',
            toolName: $sentinel,
            toolArgs: [],
            toolInput: $toolInput,
            toolOutput: '',
            model: '',
            provider: '',
            projectRoot: '/tmp',
        );
    }

    private function createAskHook(string $name, string $matcher, string $question): \SugarCraft\Code\Hooks\HookInterface
    {
        return new class($name, $matcher, $question) implements \SugarCraft\Code\Hooks\HookInterface {
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
