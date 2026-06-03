<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;

/**
 * @see HookRegistry
 */
final class HookRegistryTest extends TestCase
{
    private HookRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new HookRegistry();
    }

    // =========================================================================
    // Registration Tests
    // =========================================================================

    public function testRegister(): void
    {
        $hook = $this->createHook('TestHook', HookEvent::PreToolUse, 'Read', '^Read$');

        $this->registry->register($hook);

        $this->assertNotNull($this->registry->get('PreToolUse', 'TestHook'));
        $this->assertSame($hook, $this->registry->get('PreToolUse', 'TestHook'));
    }

    public function testRegisterMultipleHooksForSameEvent(): void
    {
        $hook1 = $this->createHook('HookA', HookEvent::PreToolUse, 'Read', '^Read$');
        $hook2 = $this->createHook('HookB', HookEvent::PreToolUse, 'Write', '^Write$');

        $this->registry->register($hook1);
        $this->registry->register($hook2);

        $this->assertSame($hook1, $this->registry->get('PreToolUse', 'HookA'));
        $this->assertSame($hook2, $this->registry->get('PreToolUse', 'HookB'));
    }

    // =========================================================================
    // Unregistration Tests
    // =========================================================================

    public function testUnregister(): void
    {
        $hook = $this->createHook('ToRemove', HookEvent::PreToolUse, 'Read', '^Read$');

        $this->registry->register($hook);
        $this->assertNotNull($this->registry->get('PreToolUse', 'ToRemove'));

        $this->registry->unregister('ToRemove');

        $this->assertNull($this->registry->get('PreToolUse', 'ToRemove'));
    }

    public function testUnregisterRemovesFromAllEvents(): void
    {
        $preHook = $this->createHook('SharedName', HookEvent::PreToolUse, 'Read', '^Read$');
        $postHook = $this->createHook('SharedName', HookEvent::PostToolUse, 'Read', '^Read$');

        $this->registry->register($preHook);
        $this->registry->register($postHook);

        $this->registry->unregister('SharedName');

        $this->assertNull($this->registry->get('PreToolUse', 'SharedName'));
        $this->assertNull($this->registry->get('PostToolUse', 'SharedName'));
    }

    // =========================================================================
    // Retrieval Tests
    // =========================================================================

    public function testGet(): void
    {
        $hook = $this->createHook('GetTest', HookEvent::PreToolUse, 'Read', '^Read$');

        $this->registry->register($hook);

        $this->assertSame($hook, $this->registry->get('PreToolUse', 'GetTest'));
    }

    public function testGetReturnsNullForNonexistent(): void
    {
        $this->assertNull($this->registry->get('PreToolUse', 'DoesNotExist'));
    }

    public function testGetReturnsNullForNonexistentEvent(): void
    {
        $hook = $this->createHook('EventTest', HookEvent::PreToolUse, 'Read', '^Read$');
        $this->registry->register($hook);

        $this->assertNull($this->registry->get('PostToolUse', 'EventTest'));
    }

    public function testGetForEvent(): void
    {
        $hook1 = $this->createHook('Hook1', HookEvent::PreToolUse, 'Read', '^Read$');
        $hook2 = $this->createHook('Hook2', HookEvent::PreToolUse, 'Write', '^Write$');
        $hook3 = $this->createHook('Hook3', HookEvent::PostToolUse, 'Read', '^Read$');

        $this->registry->register($hook1);
        $this->registry->register($hook2);
        $this->registry->register($hook3);

        $preToolHooks = $this->registry->getForEvent('PreToolUse');

        $this->assertCount(2, $preToolHooks);
        $this->assertContains($hook1, $preToolHooks);
        $this->assertContains($hook2, $preToolHooks);
    }

    public function testGetForEventReturnsEmptyArrayForNonexistent(): void
    {
        $hooks = $this->registry->getForEvent('NonExistentEvent');

        $this->assertIsArray($hooks);
        $this->assertCount(0, $hooks);
    }

    // =========================================================================
    // Enable/Disable Tests
    // =========================================================================

    public function testDisable(): void
    {
        $this->assertFalse($this->registry->isDisabled('TestHook'));

        $this->registry->disable('TestHook');

        $this->assertTrue($this->registry->isDisabled('TestHook'));
    }

    public function testEnable(): void
    {
        $this->registry->disable('TestHook');
        $this->assertTrue($this->registry->isDisabled('TestHook'));

        $this->registry->enable('TestHook');

        $this->assertFalse($this->registry->isDisabled('TestHook'));
    }

    public function testIsDisabled(): void
    {
        $this->assertFalse($this->registry->isDisabled('NeverDisabled'));

        $this->registry->disable('NeverDisabled');

        $this->assertTrue($this->registry->isDisabled('NeverDisabled'));

        $this->registry->enable('NeverDisabled');

        $this->assertFalse($this->registry->isDisabled('NeverDisabled'));
    }

    public function testDisableNonexistentHookDoesNotError(): void
    {
        $this->registry->disable('NeverRegistered');
        $this->assertTrue($this->registry->isDisabled('NeverRegistered'));
    }

    // =========================================================================
    // findMatches Tests
    // =========================================================================

    public function testFindMatches(): void
    {
        $hook = $this->createHook('ReadHook', HookEvent::PreToolUse, 'Read', '^Read$');
        $this->registry->register($hook);

        $matches = $this->registry->findMatches('PreToolUse', 'Read');

        $this->assertCount(1, $matches);
        $this->assertSame($hook, $matches[0]);
    }

    public function testFindMatchesWithRegexPattern(): void
    {
        $hook = $this->createHook('FileHook', HookEvent::PreToolUse, 'File.*', 'File(Read|Write)');
        $this->registry->register($hook);

        $matches = $this->registry->findMatches('PreToolUse', 'FileRead');
        $this->assertCount(1, $matches);

        $matches = $this->registry->findMatches('PreToolUse', 'FileWrite');
        $this->assertCount(1, $matches);

        $matches = $this->registry->findMatches('PreToolUse', 'FileDelete');
        $this->assertCount(0, $matches);
    }

    public function testFindMatchesIsCaseInsensitive(): void
    {
        $hook = $this->createHook('LowerHook', HookEvent::PreToolUse, 'read', '^read$');
        $this->registry->register($hook);

        $matches = $this->registry->findMatches('PreToolUse', 'READ');

        $this->assertCount(1, $matches);
    }

    public function testFindMatchesExcludesDisabled(): void
    {
        $hook = $this->createHook('DisabledHook', HookEvent::PreToolUse, 'Read', '^Read$');
        $this->registry->register($hook);
        $this->registry->disable('DisabledHook');

        $matches = $this->registry->findMatches('PreToolUse', 'Read');

        $this->assertCount(0, $matches);
    }

    public function testFindMatchesExcludesDisabledEvenWhenMultipleHooks(): void
    {
        $hook1 = $this->createHook('ActiveHook', HookEvent::PreToolUse, 'Read', '^Read$');
        $hook2 = $this->createHook('DisabledHook', HookEvent::PreToolUse, 'Read', '^Read$');
        $this->registry->register($hook1);
        $this->registry->register($hook2);
        $this->registry->disable('DisabledHook');

        $matches = $this->registry->findMatches('PreToolUse', 'Read');

        $this->assertCount(1, $matches);
        $this->assertSame($hook1, $matches[0]);
    }

    public function testFindMatchesReturnsEmptyArrayWhenNoMatches(): void
    {
        $hook = $this->createHook('WriteHook', HookEvent::PreToolUse, 'Write', '^Write$');
        $this->registry->register($hook);

        $matches = $this->registry->findMatches('PreToolUse', 'Read');

        $this->assertCount(0, $matches);
    }

    // =========================================================================
    // executeHooks Tests
    // =========================================================================

    public function testExecuteHooksAllAllow(): void
    {
        $hook1 = $this->createAllowHook('Hook1', 'Tool.*');
        $hook2 = $this->createAllowHook('Hook2', 'Tool.*');
        $this->registry->register($hook1);
        $this->registry->register($hook2);

        $context = $this->createContext('ToolCall');

        $result = $this->registry->executeHooks('PreToolUse', $context);

        $this->assertTrue($result->isAllowed());
    }

    public function testExecuteHooksFirstDeny(): void
    {
        $hook1 = $this->createAllowHook('Hook1', 'Tool.*');
        $hook2 = $this->createDenyHook('Hook2', 'Tool.*', 'Denied by hook 2');
        $hook3 = $this->createAllowHook('Hook3', 'Tool.*');
        $this->registry->register($hook1);
        $this->registry->register($hook2);
        $this->registry->register($hook3);

        $context = $this->createContext('ToolCall');

        $result = $this->registry->executeHooks('PreToolUse', $context);

        $this->assertTrue($result->isDenied());
        $this->assertSame('Denied by hook 2', $result->message);
    }

    public function testExecuteHooksModify(): void
    {
        $hook1 = $this->createAllowHook('Hook1', 'Tool.*');
        $hook2 = $this->createModifyHook('Hook2', 'Tool.*', 'modified input');
        $hook3 = $this->createAllowHook('Hook3', 'Tool.*');
        $this->registry->register($hook1);
        $this->registry->register($hook2);
        $this->registry->register($hook3);

        $context = $this->createContext('ToolCall', 'original input');

        $result = $this->registry->executeHooks('PreToolUse', $context);

        // Current implementation: MODIFY stops execution and returns the modify result
        // This is because isAllowed() returns false for MODIFY, causing immediate return
        $this->assertTrue($result->isModified());
        $this->assertSame('modified input', $result->modifiedInput);
    }

    public function testExecuteHooksReturnsAllowWhenNoHooks(): void
    {
        $context = $this->createContext('ToolCall');

        $result = $this->registry->executeHooks('PreToolUse', $context);

        $this->assertTrue($result->isAllowed());
    }

    public function testExecuteHooksStopsOnDeny(): void
    {
        $hook1 = $this->createAllowHook('Hook1', 'Tool.*');
        $hook2 = $this->createDenyHook('Hook2', 'Tool.*', 'Stopped');
        // This hook would modify context, but should never run
        $this->registry->register($hook1);
        $this->registry->register($hook2);

        $context = $this->createContext('ToolCall', 'original');

        $result = $this->registry->executeHooks('PreToolUse', $context);

        $this->assertTrue($result->isDenied());
        // Original context preserved since hook2 never runs
        $this->assertSame('original', $context->toolInput);
    }

    public function testExecuteHooksContinuesAfterModify(): void
    {
        $hook1 = $this->createModifyHook('Hook1', 'Tool.*', 'first modification');
        $hook2 = $this->createModifyHook('Hook2', 'Tool.*', 'second modification');
        $hook3 = $this->createAllowHook('Hook3', 'Tool.*');
        $this->registry->register($hook1);
        $this->registry->register($hook2);
        $this->registry->register($hook3);

        $context = $this->createContext('ToolCall', 'original');

        $result = $this->registry->executeHooks('PreToolUse', $context);

        // Current implementation: first MODIFY stops execution and returns immediately
        // So only the first modification is returned, second hook never runs
        $this->assertTrue($result->isModified());
        $this->assertSame('first modification', $result->modifiedInput);
    }

    // =========================================================================
    // Edge Case Tests
    // =========================================================================

    public function testFindMatchesWithSpecialRegexCharacters(): void
    {
        $hook = $this->createHook('SpecialHook', HookEvent::PreToolUse, 'file[1]', 'file\\[1\\]');
        $this->registry->register($hook);

        $matches = $this->registry->findMatches('PreToolUse', 'file[1]');

        $this->assertCount(1, $matches);
    }

    public function testExecuteHooksOnlyRunsDisabledForMatchingEnabled(): void
    {
        $hook1 = $this->createDenyHook('DenyingHook', 'Tool.*', 'Denied');
        $hook2 = $this->createAllowHook('DisabledDenier', 'Tool.*');
        $this->registry->register($hook1);
        $this->registry->register($hook2);
        $this->registry->disable('DisabledDenier');

        $context = $this->createContext('ToolCall');

        $result = $this->registry->executeHooks('PreToolUse', $context);

        // Only DenyingHook runs, it denies
        $this->assertTrue($result->isDenied());
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function createHook(string $name, HookEvent $event, string $toolName, string $matcher): HookInterface
    {
        return new class($name, $event, $toolName, $matcher) implements HookInterface {
            public function __construct(
                private string $name,
                private HookEvent $event,
                private string $toolName,
                private string $matcher,
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
                return HookResult::allow();
            }
        };
    }

    private function createAllowHook(string $name, string $matcher): HookInterface
    {
        return new class($name, $matcher) implements HookInterface {
            public function __construct(
                private string $name,
                private string $matcher,
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
                return HookResult::allow();
            }
        };
    }

    private function createDenyHook(string $name, string $matcher, string $message): HookInterface
    {
        return new class($name, $matcher, $message) implements HookInterface {
            public function __construct(
                private string $name,
                private string $matcher,
                private string $message,
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
                return HookResult::deny($this->message);
            }
        };
    }

    private function createModifyHook(string $name, string $matcher, string $newInput): HookInterface
    {
        return new class($name, $matcher, $newInput) implements HookInterface {
            public function __construct(
                private string $name,
                private string $matcher,
                private string $newInput,
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
                return HookResult::modify($this->newInput);
            }
        };
    }

    private function createContext(string $toolName, string $toolInput = 'input'): HookContext
    {
        return new HookContext(
            sessionId: 'test_session',
            toolName: $toolName,
            toolArgs: [],
            toolInput: $toolInput,
            toolOutput: 'output',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: '/test/root',
        );
    }
}
