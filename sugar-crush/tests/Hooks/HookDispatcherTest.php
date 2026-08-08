<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookDispatchResult;
use SugarCraft\Crush\Hooks\HookDispatcher;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;

/**
 * @see HookDispatcher
 */
final class HookDispatcherTest extends TestCase
{
    private HookRegistry $registry;
    private HookDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->registry = new HookRegistry();
        $this->dispatcher = new HookDispatcher($this->registry);
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    public function testConstructorRequiresRegistry(): void
    {
        $dispatcher = new HookDispatcher($this->registry);

        $this->assertInstanceOf(HookDispatcher::class, $dispatcher);
    }

    // =========================================================================
    // dispatch() - No Hooks Tests
    // =========================================================================

    public function testDispatchReturnsAllowWhenNoHooksRegistered(): void
    {
        $context = $this->createContext('Read');

        $result = $this->dispatcher->dispatch(HookEvent::PreToolUse, $context);

        $this->assertTrue($result->isAllowed());
        $this->assertSame(HookEvent::PreToolUse, $result->event);
    }

    // =========================================================================
    // dispatch() - All Allow Tests
    // =========================================================================

    public function testDispatchReturnsAllowWhenAllHooksAllow(): void
    {
        $hook = $this->createAllowHook('AllowHook', HookEvent::PreToolUse, 'Read', '^Read$');
        $this->registry->register($hook);
        $context = $this->createContext('Read');

        $result = $this->dispatcher->dispatch(HookEvent::PreToolUse, $context);

        $this->assertTrue($result->isAllowed());
        $this->assertSame('', $result->message);
    }

    // =========================================================================
    // dispatch() - Non-blocking Deny Tests
    // =========================================================================

    public function testDispatchReturnsAllowAfterNonBlockingDenyOnly(): void
    {
        // Non-blocking deny (exit code 1) doesn't block - execution continues
        // Since there's no subsequent block, the result is allow
        $hook = $this->createNonBlockingDenyHook('DenyHook', HookEvent::PreToolUse, 'Read', '^Read$');
        $this->registry->register($hook);
        $context = $this->createContext('Read');

        $result = $this->dispatcher->dispatch(HookEvent::PreToolUse, $context);

        // Non-blocking deny: execution continues, final result is allow
        $this->assertTrue($result->isAllowed());
    }

    public function testDispatchContinuesAfterNonBlockingDenyToAllow(): void
    {
        $hook1 = $this->createNonBlockingDenyHook('DenyHook', HookEvent::PreToolUse, 'Read', '^Read$');
        $hook2 = $this->createAllowHook('AllowHook', HookEvent::PreToolUse, 'Read', '^Read$');
        $this->registry->register($hook1);
        $this->registry->register($hook2);
        $context = $this->createContext('Read');

        $result = $this->dispatcher->dispatch(HookEvent::PreToolUse, $context);

        // Non-blocking deny continues to next hook, then final allow
        $this->assertTrue($result->isAllowed());
    }

    // =========================================================================
    // dispatch() - Block (Hard Block) Tests
    // =========================================================================

    public function testExitCode2BlocksPreToolUse(): void
    {
        // blocksOnPreAction(): the tool call never executes, and the hook's
        // stderr is fed back — visible on the result — for the agent to act on.
        $hook = $this->createHardBlockHook('BlockHook', HookEvent::PreToolUse, 'Read', '^Read$');
        $this->registry->register($hook);
        $context = $this->createContext('Read');

        $result = $this->dispatcher->dispatch(HookEvent::PreToolUse, $context);

        $this->assertTrue($result->isBlock());
        $this->assertFalse($result->shouldContinueOnBlock());
        // Message should have [exit-2] prefix stripped, and is fed back
        // verbatim — untagged, unlike stderrToUserOnly() — since
        // blocksOnPreAction() never discards it.
        $this->assertSame('Hard block message', $result->message);
    }

    public function testExitCode2OnPostToolUseUsesContinueOnBlock(): void
    {
        // usesContinueOnBlockOnBlock(): the action already ran, so the block
        // surfaces the problem via continueOnBlock rather than discarding
        // the (already-produced) result or its message.
        $hook = $this->createHardBlockHook('BlockHook', HookEvent::PostToolUse, 'Read', '^Read$');
        $this->registry->register($hook);
        $context = $this->createContext('Read');

        $result = $this->dispatcher->dispatch(HookEvent::PostToolUse, $context);

        $this->assertTrue($result->isBlock());
        $this->assertTrue($result->shouldContinueOnBlock());
        $this->assertStringContainsString('Hard block message', $result->message);
    }

    public function testDispatchReturnsBlockWithContinueOnBlockForSubagentStop(): void
    {
        $hook = $this->createHardBlockHook('BlockHook', HookEvent::SubagentStop, 'Read', '^Read$');
        $this->registry->register($hook);
        // SubagentStop is not tool-scoped, so no matcher needed
        $context = $this->createContext('Read');

        $result = $this->dispatcher->dispatch(HookEvent::SubagentStop, $context);

        $this->assertTrue($result->isBlock());
        $this->assertTrue($result->shouldContinueOnBlock());
    }

    public function testDispatchReturnsBlockWithContinueOnBlockForTaskCompleted(): void
    {
        $hook = $this->createHardBlockHook('BlockHook', HookEvent::TaskCompleted, 'Read', '^Read$');
        $this->registry->register($hook);
        $context = $this->createContext('Read');

        $result = $this->dispatcher->dispatch(HookEvent::TaskCompleted, $context);

        $this->assertTrue($result->isBlock());
        $this->assertTrue($result->shouldContinueOnBlock());
    }

    // =========================================================================
    // dispatch() - Event-specific Behavior Tests
    // =========================================================================

    public function testDispatchForSessionStartAllowsWhenNoHooks(): void
    {
        $context = $this->createContext('SessionStart');

        $result = $this->dispatcher->dispatch(HookEvent::SessionStart, $context);

        $this->assertTrue($result->isAllowed());
    }

    public function testDispatchForSessionEndAllowsWhenNoHooks(): void
    {
        $context = $this->createContext('SessionEnd');

        $result = $this->dispatcher->dispatch(HookEvent::SessionEnd, $context);

        $this->assertTrue($result->isAllowed());
    }

    public function testUserPromptSubmitExitCode2DiscardsPrompt(): void
    {
        // discardsOnBlock(): the prompt is discarded entirely — the hook's
        // message never reaches the agent (unlike blocksOnPreAction(),
        // where the same message would be preserved and fed back). This is
        // the empirical difference between "discard" and a generic block.
        $hook = $this->createHardBlockHook('BlockHook', HookEvent::UserPromptSubmit, 'Read', '^Read$');
        $this->registry->register($hook);
        $context = $this->createContext('Read');

        $result = $this->dispatcher->dispatch(HookEvent::UserPromptSubmit, $context);

        $this->assertTrue($result->isBlock());
        $this->assertFalse($result->shouldContinueOnBlock());
        $this->assertSame('', $result->message, 'UserPromptSubmit must discard the hook message, not surface it');
    }

    public function testUserPromptSubmitDiscardDiffersFromPreToolUseBlock(): void
    {
        // Same hook message, same exit code (2), different HookEvent — proves
        // the discard behavior is event-specific, not a hardcoded blank message.
        $promptContext = $this->createContext('Read');
        $preToolContext = $this->createContext('Read');
        $this->registry->register($this->createHardBlockHook('BlockHook', HookEvent::UserPromptSubmit, 'Read', '^Read$'));

        $discardResult = $this->dispatcher->dispatch(HookEvent::UserPromptSubmit, $promptContext);

        $secondRegistry = new HookRegistry();
        $secondRegistry->register($this->createHardBlockHook('BlockHook', HookEvent::PreToolUse, 'Read', '^Read$'));
        $secondDispatcher = new HookDispatcher($secondRegistry);
        $blockResult = $secondDispatcher->dispatch(HookEvent::PreToolUse, $preToolContext);

        $this->assertSame('', $discardResult->message);
        $this->assertStringContainsString('Hard block message', $blockResult->message);
        $this->assertNotSame($discardResult->message, $blockResult->message);
    }

    public function testExitCode2OnPreCompactStderrReachesUserOnlyDistinctFromAgentBlock(): void
    {
        // stderrToUserOnly(): there's no agent turn at PreCompact, so the
        // message is preserved (it has to reach the user somehow) but is
        // tagged with HookDispatcher::STDERR_ONLY_PREFIX — that tag is the
        // empirical proof this differs from blocksOnPreAction(), where the
        // exact same hook message would be fed back untagged.
        $hook = $this->createHardBlockHook('BlockHook', HookEvent::PreCompact, 'PreCompact', '^PreCompact$');
        $this->registry->register($hook);
        $context = $this->createContext('PreCompact');

        $result = $this->dispatcher->dispatch(HookEvent::PreCompact, $context);

        $preToolRegistry = new HookRegistry();
        $preToolRegistry->register($this->createHardBlockHook('BlockHook', HookEvent::PreToolUse, 'Read', '^Read$'));
        $preToolDispatcher = new HookDispatcher($preToolRegistry);
        $preToolResult = $preToolDispatcher->dispatch(HookEvent::PreToolUse, $this->createContext('Read'));

        $this->assertTrue($result->isBlock());
        $this->assertFalse($result->shouldContinueOnBlock());
        $this->assertNotSame('', $result->message);
        $this->assertStringStartsWith(HookDispatcher::STDERR_ONLY_PREFIX, $result->message);
        $this->assertStringContainsString('Hard block message', $result->message);

        // Same underlying hook message, different HookEvent — the tag must
        // NOT appear on the blocksOnPreAction() side, proving the two
        // categories genuinely differ in runtime effect, not just metadata.
        $this->assertStringStartsNotWith(HookDispatcher::STDERR_ONLY_PREFIX, $preToolResult->message);
        $this->assertNotSame($result->message, $preToolResult->message);
    }

    public function testExitCode2OnSessionStartStderrReachesUserOnlyDistinctFromAgentBlock(): void
    {
        $hook = $this->createHardBlockHook('BlockHook', HookEvent::SessionStart, 'SessionStart', '^SessionStart$');
        $this->registry->register($hook);
        $context = $this->createContext('SessionStart');

        $result = $this->dispatcher->dispatch(HookEvent::SessionStart, $context);

        $this->assertTrue($result->isBlock());
        $this->assertFalse($result->shouldContinueOnBlock());
        $this->assertNotSame('', $result->message);
        $this->assertStringStartsWith(HookDispatcher::STDERR_ONLY_PREFIX, $result->message);
        $this->assertStringContainsString('Hard block message', $result->message);
    }

    public function testStderrToUserOnlyTagAbsentFromBlocksOnPreActionMessage(): void
    {
        // Names the 5th exit-code-semantics case explicitly: an event
        // matching neither discardsOnBlock(), stderrToUserOnly(), nor
        // usesContinueOnBlockOnBlock() (here, blocksOnPreAction() itself)
        // must never carry the stderr-only tag.
        $hook = $this->createHardBlockHook('BlockHook', HookEvent::Stop, 'Stop', '^Stop$');
        $this->registry->register($hook);
        $context = $this->createContext('Stop');

        $result = $this->dispatcher->dispatch(HookEvent::Stop, $context);

        $this->assertTrue($result->isBlock());
        $this->assertFalse($result->shouldContinueOnBlock());
        $this->assertSame('Hard block message', $result->message);
    }

    public function testBlocksOnPreActionDiffersFromUncategorizedFallbackEvent(): void
    {
        // blocksOnPreAction() (Stop) and an event matching none of the four
        // HookEvent metadata methods (SessionEnd) must produce genuinely
        // different HookDispatchResult messages for byte-identical hook
        // output. Without this, blocksOnPreAction() is read but never
        // actually influences dispatch() output — dead metadata, exactly
        // the gap it exists to close.
        $stopRegistry = new HookRegistry();
        $stopRegistry->register($this->createHardBlockHook('BlockHook', HookEvent::Stop, 'Stop', '^Stop$'));
        $stopDispatcher = new HookDispatcher($stopRegistry);
        $stopResult = $stopDispatcher->dispatch(HookEvent::Stop, $this->createContext('Stop'));

        $sessionEndRegistry = new HookRegistry();
        $sessionEndRegistry->register($this->createHardBlockHook('BlockHook', HookEvent::SessionEnd, 'SessionEnd', '^SessionEnd$'));
        $sessionEndDispatcher = new HookDispatcher($sessionEndRegistry);
        $sessionEndResult = $sessionEndDispatcher->dispatch(HookEvent::SessionEnd, $this->createContext('SessionEnd'));

        $this->assertTrue($stopResult->isBlock());
        $this->assertTrue($sessionEndResult->isBlock());
        $this->assertSame('Hard block message', $stopResult->message);
        $this->assertStringStartsWith(HookDispatcher::UNSPECIFIED_BLOCK_PREFIX, $sessionEndResult->message);
        $this->assertStringContainsString('Hard block message', $sessionEndResult->message);
        $this->assertNotSame($stopResult->message, $sessionEndResult->message);
    }

    public function testTeammateIdleFallbackAlsoGetsUnspecifiedBlockTag(): void
    {
        // TeammateIdle is the other event matching none of the four
        // HookEvent metadata methods — confirm the fallback tagging isn't
        // a one-off special case for SessionEnd alone.
        $hook = $this->createHardBlockHook('BlockHook', HookEvent::TeammateIdle, 'Read', '^Read$');
        $this->registry->register($hook);
        $context = $this->createContext('Read');

        $result = $this->dispatcher->dispatch(HookEvent::TeammateIdle, $context);

        $this->assertTrue($result->isBlock());
        $this->assertStringStartsWith(HookDispatcher::UNSPECIFIED_BLOCK_PREFIX, $result->message);
        $this->assertStringContainsString('Hard block message', $result->message);
    }

    // =========================================================================
    // dispatch() - Multiple Hooks Tests
    // =========================================================================

    public function testDispatchStopsOnFirstHardBlock(): void
    {
        $hook1 = $this->createHardBlockHook('BlockHook1', HookEvent::PreToolUse, 'Read', '^Read$');
        $hook2 = $this->createAllowHook('AllowHook', HookEvent::PreToolUse, 'Read', '^Read$');
        $this->registry->register($hook1);
        $this->registry->register($hook2);
        $context = $this->createContext('Read');

        $result = $this->dispatcher->dispatch(HookEvent::PreToolUse, $context);

        $this->assertTrue($result->isBlock());
        $this->assertStringContainsString('Hard block message', $result->message);
    }

    public function testDispatchDisabledHooksAreSkipped(): void
    {
        $hook = $this->createHardBlockHook('BlockHook', HookEvent::PreToolUse, 'Read', '^Read$');
        $this->registry->register($hook);
        $this->registry->disable('BlockHook');
        $context = $this->createContext('Read');

        $result = $this->dispatcher->dispatch(HookEvent::PreToolUse, $context);

        $this->assertTrue($result->isAllowed());
    }

    // =========================================================================
    // Convenience Dispatch Method Tests
    // =========================================================================

    public function testDispatchPreToolUse(): void
    {
        $context = $this->createContext('Read');

        $result = $this->dispatcher->dispatchPreToolUse($context);

        $this->assertTrue($result->isAllowed());
        $this->assertSame(HookEvent::PreToolUse, $result->event);
    }

    public function testDispatchPostToolUse(): void
    {
        $context = $this->createContext('Write');

        $result = $this->dispatcher->dispatchPostToolUse($context);

        $this->assertTrue($result->isAllowed());
        $this->assertSame(HookEvent::PostToolUse, $result->event);
    }

    public function testDispatchStop(): void
    {
        $context = $this->createContext('Stop');

        $result = $this->dispatcher->dispatchStop($context);

        $this->assertTrue($result->isAllowed());
        $this->assertSame(HookEvent::Stop, $result->event);
    }

    public function testDispatchSubagentStop(): void
    {
        $context = $this->createContext('SubagentStop');

        $result = $this->dispatcher->dispatchSubagentStop($context);

        $this->assertTrue($result->isAllowed());
        $this->assertSame(HookEvent::SubagentStop, $result->event);
    }

    public function testDispatchSessionStart(): void
    {
        $context = $this->createContext('SessionStart');

        $result = $this->dispatcher->dispatchSessionStart($context);

        $this->assertTrue($result->isAllowed());
        $this->assertSame(HookEvent::SessionStart, $result->event);
    }

    public function testDispatchSessionEnd(): void
    {
        $context = $this->createContext('SessionEnd');

        $result = $this->dispatcher->dispatchSessionEnd($context);

        $this->assertTrue($result->isAllowed());
        $this->assertSame(HookEvent::SessionEnd, $result->event);
    }

    public function testDispatchUserPromptSubmit(): void
    {
        $context = $this->createContext('UserPromptSubmit');

        $result = $this->dispatcher->dispatchUserPromptSubmit($context);

        $this->assertTrue($result->isAllowed());
        $this->assertSame(HookEvent::UserPromptSubmit, $result->event);
    }

    public function testDispatchPreCompact(): void
    {
        $context = $this->createContext('PreCompact');

        $result = $this->dispatcher->dispatchPreCompact($context);

        $this->assertTrue($result->isAllowed());
        $this->assertSame(HookEvent::PreCompact, $result->event);
    }

    public function testDispatchTeammateIdle(): void
    {
        $context = $this->createContext('TeammateIdle');

        $result = $this->dispatcher->dispatchTeammateIdle($context);

        $this->assertTrue($result->isAllowed());
        $this->assertSame(HookEvent::TeammateIdle, $result->event);
    }

    public function testDispatchTaskCreated(): void
    {
        $context = $this->createContext('TaskCreated');

        $result = $this->dispatcher->dispatchTaskCreated($context);

        $this->assertTrue($result->isAllowed());
        $this->assertSame(HookEvent::TaskCreated, $result->event);
    }

    public function testDispatchTaskCompleted(): void
    {
        $context = $this->createContext('TaskCompleted');

        $result = $this->dispatcher->dispatchTaskCompleted($context);

        $this->assertTrue($result->isAllowed());
        $this->assertSame(HookEvent::TaskCompleted, $result->event);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function createContext(string $toolName): HookContext
    {
        return new HookContext(
            sessionId: 'test_session',
            toolName: $toolName,
            toolArgs: [],
            toolInput: 'test_input',
            toolOutput: 'test_output',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: '/test/root',
        );
    }

    private function createAllowHook(string $name, HookEvent $event, string $toolName, string $matcher): HookInterface
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

    private function createNonBlockingDenyHook(string $name, HookEvent $event, string $toolName, string $matcher): HookInterface
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
                return HookResult::deny('[exit-1] Non-blocking deny message');
            }
        };
    }

    private function createHardBlockHook(string $name, HookEvent $event, string $toolName, string $matcher): HookInterface
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
                return HookResult::deny('[exit-2] Hard block message');
            }
        };
    }
}
