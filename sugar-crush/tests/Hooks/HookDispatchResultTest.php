<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookDispatchResult;
use SugarCraft\Crush\Hooks\HookEvent;

/**
 * @see HookDispatchResult
 */
final class HookDispatchResultTest extends TestCase
{
    private HookContext $context;

    protected function setUp(): void
    {
        $this->context = new HookContext(
            sessionId: 'test_session',
            toolName: 'Read',
            toolArgs: [],
            toolInput: 'test_input',
            toolOutput: 'test_output',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: '/test/root',
        );
    }

    // =========================================================================
    // Exit Code Constants Tests
    // =========================================================================

    public function testExitAllowConstant(): void
    {
        $this->assertSame(0, HookDispatchResult::EXIT_ALLOW);
    }

    public function testExitDenyConstant(): void
    {
        $this->assertSame(1, HookDispatchResult::EXIT_DENY);
    }

    public function testExitBlockConstant(): void
    {
        $this->assertSame(2, HookDispatchResult::EXIT_BLOCK);
    }

    // =========================================================================
    // Factory Method Tests - allow()
    // =========================================================================

    public function testAllowFactory(): void
    {
        $result = HookDispatchResult::allow(HookEvent::PreToolUse, $this->context);

        $this->assertSame(HookDispatchResult::EXIT_ALLOW, $result->exitCode);
        $this->assertSame(HookEvent::PreToolUse, $result->event);
        $this->assertSame('', $result->message);
        $this->assertFalse($result->continueOnBlock);
        $this->assertNull($result->modifiedInput);
    }

    public function testAllowFactoryWithMessage(): void
    {
        $result = HookDispatchResult::allow(HookEvent::PreToolUse, $this->context, 'custom message');

        $this->assertSame(HookDispatchResult::EXIT_ALLOW, $result->exitCode);
        $this->assertSame('custom message', $result->message);
    }

    // =========================================================================
    // Factory Method Tests - deny()
    // =========================================================================

    public function testDenyFactory(): void
    {
        $result = HookDispatchResult::deny(HookEvent::PreToolUse, $this->context, 'denied');

        $this->assertSame(HookDispatchResult::EXIT_DENY, $result->exitCode);
        $this->assertSame(HookEvent::PreToolUse, $result->event);
        $this->assertSame('denied', $result->message);
        $this->assertFalse($result->continueOnBlock);
    }

    // =========================================================================
    // Factory Method Tests - block()
    // =========================================================================

    public function testBlockFactoryWithoutContinueOnBlock(): void
    {
        $result = HookDispatchResult::block(HookEvent::PreToolUse, $this->context, 'blocked', false);

        $this->assertSame(HookDispatchResult::EXIT_BLOCK, $result->exitCode);
        $this->assertSame(HookEvent::PreToolUse, $result->event);
        $this->assertSame('blocked', $result->message);
        $this->assertFalse($result->continueOnBlock);
    }

    public function testBlockFactoryWithContinueOnBlock(): void
    {
        $result = HookDispatchResult::block(HookEvent::PostToolUse, $this->context, 'blocked', true);

        $this->assertSame(HookDispatchResult::EXIT_BLOCK, $result->exitCode);
        $this->assertSame(HookEvent::PostToolUse, $result->event);
        $this->assertSame('blocked', $result->message);
        $this->assertTrue($result->continueOnBlock);
    }

    // =========================================================================
    // Predicate Method Tests - isAllowed()
    // =========================================================================

    public function testIsAllowedReturnsTrueForAllow(): void
    {
        $result = HookDispatchResult::allow(HookEvent::PreToolUse, $this->context);

        $this->assertTrue($result->isAllowed());
    }

    public function testIsAllowedReturnsFalseForDeny(): void
    {
        $result = HookDispatchResult::deny(HookEvent::PreToolUse, $this->context, 'denied');

        $this->assertFalse($result->isAllowed());
    }

    public function testIsAllowedReturnsFalseForBlock(): void
    {
        $result = HookDispatchResult::block(HookEvent::PreToolUse, $this->context, 'blocked');

        $this->assertFalse($result->isAllowed());
    }

    // =========================================================================
    // Predicate Method Tests - isDeny()
    // =========================================================================

    public function testIsDenyReturnsTrueForDeny(): void
    {
        $result = HookDispatchResult::deny(HookEvent::PreToolUse, $this->context, 'denied');

        $this->assertTrue($result->isDeny());
    }

    public function testIsDenyReturnsFalseForAllow(): void
    {
        $result = HookDispatchResult::allow(HookEvent::PreToolUse, $this->context);

        $this->assertFalse($result->isDeny());
    }

    public function testIsDenyReturnsFalseForBlock(): void
    {
        $result = HookDispatchResult::block(HookEvent::PreToolUse, $this->context, 'blocked');

        $this->assertFalse($result->isDeny());
    }

    // =========================================================================
    // Predicate Method Tests - isBlock()
    // =========================================================================

    public function testIsBlockReturnsTrueForBlock(): void
    {
        $result = HookDispatchResult::block(HookEvent::PreToolUse, $this->context, 'blocked');

        $this->assertTrue($result->isBlock());
    }

    public function testIsBlockReturnsFalseForAllow(): void
    {
        $result = HookDispatchResult::allow(HookEvent::PreToolUse, $this->context);

        $this->assertFalse($result->isBlock());
    }

    public function testIsBlockReturnsFalseForDeny(): void
    {
        $result = HookDispatchResult::deny(HookEvent::PreToolUse, $this->context, 'denied');

        $this->assertFalse($result->isBlock());
    }

    // =========================================================================
    // Predicate Method Tests - shouldContinueOnBlock()
    // =========================================================================

    public function testShouldContinueOnBlockReturnsTrueWhenBlockWithContinueOnBlock(): void
    {
        $result = HookDispatchResult::block(HookEvent::PostToolUse, $this->context, 'blocked', true);

        $this->assertTrue($result->shouldContinueOnBlock());
    }

    public function testShouldContinueOnBlockReturnsFalseWhenBlockWithoutContinueOnBlock(): void
    {
        $result = HookDispatchResult::block(HookEvent::PreToolUse, $this->context, 'blocked', false);

        $this->assertFalse($result->shouldContinueOnBlock());
    }

    public function testShouldContinueOnBlockReturnsFalseForAllow(): void
    {
        $result = HookDispatchResult::allow(HookEvent::PreToolUse, $this->context);

        $this->assertFalse($result->shouldContinueOnBlock());
    }

    public function testShouldContinueOnBlockReturnsFalseForDeny(): void
    {
        $result = HookDispatchResult::deny(HookEvent::PreToolUse, $this->context, 'denied');

        $this->assertFalse($result->shouldContinueOnBlock());
    }

    // =========================================================================
    // Event Preservation Tests
    // =========================================================================

    public function testPreservesEvent(): void
    {
        $events = HookEvent::cases();

        foreach ($events as $event) {
            $result = HookDispatchResult::allow($event, $this->context);

            $this->assertSame($event, $result->event);
        }
    }

    // =========================================================================
    // Immutability Tests
    // =========================================================================

    public function testResultIsReadonly(): void
    {
        $result = HookDispatchResult::allow(HookEvent::PreToolUse, $this->context, 'test');

        $this->assertSame(HookDispatchResult::EXIT_ALLOW, $result->exitCode);
        $this->assertSame(HookEvent::PreToolUse, $result->event);
        $this->assertSame('test', $result->message);
    }
}
