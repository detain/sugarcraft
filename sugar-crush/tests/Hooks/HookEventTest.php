<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\HookEvent;

/**
 * @see HookEvent
 */
final class HookEventTest extends TestCase
{
    // =========================================================================
    // Enum Value Tests
    // =========================================================================

    public function testAllElevenEventsExist(): void
    {
        $events = HookEvent::cases();

        $this->assertCount(11, $events);
    }

    public function testPreToolUseValue(): void
    {
        $this->assertSame('PreToolUse', HookEvent::PreToolUse->value);
    }

    public function testPostToolUseValue(): void
    {
        $this->assertSame('PostToolUse', HookEvent::PostToolUse->value);
    }

    public function testStopValue(): void
    {
        $this->assertSame('Stop', HookEvent::Stop->value);
    }

    public function testSubagentStopValue(): void
    {
        $this->assertSame('SubagentStop', HookEvent::SubagentStop->value);
    }

    public function testSessionStartValue(): void
    {
        $this->assertSame('SessionStart', HookEvent::SessionStart->value);
    }

    public function testSessionEndValue(): void
    {
        $this->assertSame('SessionEnd', HookEvent::SessionEnd->value);
    }

    public function testUserPromptSubmitValue(): void
    {
        $this->assertSame('UserPromptSubmit', HookEvent::UserPromptSubmit->value);
    }

    public function testPreCompactValue(): void
    {
        $this->assertSame('PreCompact', HookEvent::PreCompact->value);
    }

    public function testTeammateIdleValue(): void
    {
        $this->assertSame('TeammateIdle', HookEvent::TeammateIdle->value);
    }

    public function testTaskCreatedValue(): void
    {
        $this->assertSame('TaskCreated', HookEvent::TaskCreated->value);
    }

    public function testTaskCompletedValue(): void
    {
        $this->assertSame('TaskCompleted', HookEvent::TaskCompleted->value);
    }

    // =========================================================================
    // blocksOnPreAction() Tests
    // =========================================================================

    public function testBlocksOnPreActionForPreToolUse(): void
    {
        $this->assertTrue(HookEvent::PreToolUse->blocksOnPreAction());
    }

    public function testBlocksOnPreActionForStop(): void
    {
        $this->assertTrue(HookEvent::Stop->blocksOnPreAction());
    }

    public function testBlocksOnPreActionForTaskCreated(): void
    {
        $this->assertTrue(HookEvent::TaskCreated->blocksOnPreAction());
    }

    public function testBlocksOnPreActionReturnsFalseForPostToolUse(): void
    {
        $this->assertFalse(HookEvent::PostToolUse->blocksOnPreAction());
    }

    public function testBlocksOnPreActionReturnsFalseForSessionStart(): void
    {
        $this->assertFalse(HookEvent::SessionStart->blocksOnPreAction());
    }

    // =========================================================================
    // usesContinueOnBlockOnBlock() Tests
    // =========================================================================

    public function testUsesContinueOnBlockOnBlockForPostToolUse(): void
    {
        $this->assertTrue(HookEvent::PostToolUse->usesContinueOnBlockOnBlock());
    }

    public function testUsesContinueOnBlockOnBlockForSubagentStop(): void
    {
        $this->assertTrue(HookEvent::SubagentStop->usesContinueOnBlockOnBlock());
    }

    public function testUsesContinueOnBlockOnBlockForTaskCompleted(): void
    {
        $this->assertTrue(HookEvent::TaskCompleted->usesContinueOnBlockOnBlock());
    }

    public function testUsesContinueOnBlockOnBlockReturnsFalseForPreToolUse(): void
    {
        $this->assertFalse(HookEvent::PreToolUse->usesContinueOnBlockOnBlock());
    }

    public function testUsesContinueOnBlockOnBlockReturnsFalseForStop(): void
    {
        $this->assertFalse(HookEvent::Stop->usesContinueOnBlockOnBlock());
    }

    // =========================================================================
    // discardsOnBlock() Tests
    // =========================================================================

    public function testDiscardsOnBlockForUserPromptSubmit(): void
    {
        $this->assertTrue(HookEvent::UserPromptSubmit->discardsOnBlock());
    }

    public function testDiscardsOnBlockReturnsFalseForOtherEvents(): void
    {
        foreach (HookEvent::cases() as $event) {
            if ($event !== HookEvent::UserPromptSubmit) {
                $this->assertFalse($event->discardsOnBlock(), "{$event->name} should not discard on block");
            }
        }
    }

    // =========================================================================
    // stderrToUserOnly() Tests
    // =========================================================================

    public function testStderrToUserOnlyForPreCompact(): void
    {
        $this->assertTrue(HookEvent::PreCompact->stderrToUserOnly());
    }

    public function testStderrToUserOnlyForSessionStart(): void
    {
        $this->assertTrue(HookEvent::SessionStart->stderrToUserOnly());
    }

    public function testStderrToUserOnlyReturnsFalseForPreToolUse(): void
    {
        $this->assertFalse(HookEvent::PreToolUse->stderrToUserOnly());
    }

    public function testStderrToUserOnlyReturnsFalseForPostToolUse(): void
    {
        $this->assertFalse(HookEvent::PostToolUse->stderrToUserOnly());
    }

    // =========================================================================
    // Exhaustive Coverage - All 11 events tested
    // =========================================================================

    public function testAllEventsHaveValue(): void
    {
        foreach (HookEvent::cases() as $event) {
            $this->assertNotEmpty($event->value);
        }
    }

    public function testAllEventsHaveBlocksOnPreAction(): void
    {
        foreach (HookEvent::cases() as $event) {
            // Should not throw
            $result = $event->blocksOnPreAction();
            $this->assertIsBool($result);
        }
    }

    public function testAllEventsHaveUsesContinueOnBlockOnBlock(): void
    {
        foreach (HookEvent::cases() as $event) {
            $result = $event->usesContinueOnBlockOnBlock();
            $this->assertIsBool($result);
        }
    }

    public function testAllEventsHaveDiscardsOnBlock(): void
    {
        foreach (HookEvent::cases() as $event) {
            $result = $event->discardsOnBlock();
            $this->assertIsBool($result);
        }
    }

    public function testAllEventsHaveStderrToUserOnly(): void
    {
        foreach (HookEvent::cases() as $event) {
            $result = $event->stderrToUserOnly();
            $this->assertIsBool($result);
        }
    }
}
