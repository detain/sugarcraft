<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\DelegationSlots;
use SugarCraft\Code\Tools\BuiltIn\TaskTool;

/**
 * WF-A6 (RS-AGENT A6): pin the refusal edges of the delegation doors — the
 * half of "refuse vs queue" that lives OUTSIDE the pool queue.
 *
 * The two doors the settings-era law runs through:
 *  - TaskTool::withDelegationLimits(), the config-time door: null is the only
 *    spelling of "uncapped", every nonsense cap (negative, and zero — the
 *    zero-uncapped fold belongs to the SETTINGS layer, which rewrites <1 to
 *    null before the door ever sees it) is refused, never clamped;
 *  - DelegationSlots::acquire(), the seat door, whose own law is "REFUSE,
 *    NEVER WAIT": a null or below-one cap enforces nothing and — this is the
 *    leg nothing pinned before — it says so by returning null without ever
 *    touching the slot root, so an uncapped session leaves no on-disk
 *    delegation state at all.
 *
 * The positive side (acquire returning false once every seat is held, the
 * seat-per-run lifecycle, and the engine-level refused-not-queued message) is
 * already pinned by TaskNestingDepthCapTest; this file adds the complementary
 * edges, not a copy.
 */
final class PoolQueueRefusalEdgeTest extends TestCase
{
    public function testANegativeDepthCapIsRefusedAtTheDelegationDoor(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new TaskTool())->withDelegationLimits(-1, 8);
    }

    public function testANegativeWidthCapIsRefusedAtTheDelegationDoor(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new TaskTool())->withDelegationLimits(3, -1);
    }

    public function testAZeroWidthCapIsRefusedAtTheDoorBecauseNullIsTheOnlyUncappedSpelling(): void
    {
        // The contrast with AgentPoolConfig::withSettings() is deliberate:
        // there 0 means "no cap" because the settings layer folds <1 to null
        // first (pinned by SubagentSettingsTest); raw at the door, 0 is
        // nonsense and refuses loudly instead of silently widening.
        $this->expectException(\InvalidArgumentException::class);

        (new TaskTool())->withDelegationLimits(3, 0);
    }

    public function testNullWidthSpellsUncappedAndIsCarriedToTheToolVerbatim(): void
    {
        $bound = (new TaskTool())->withDelegationLimits(3, null);

        self::assertNotSame((new TaskTool()), $bound, 'the door returns a re-bound clone');
        self::assertNull(self::doorBound($bound, 'maxConcurrentAgents'), 'null forwards as the uncapped verdict');
        self::assertSame(3, self::doorBound($bound, 'maxDelegationDepth'));
    }

    public function testTheSeatDoorTreatsNullZeroAndNegativeCapsAsNoCapWithoutTouchingTheRoot(): void
    {
        // A path that does not exist is the point: the no-cap verdict
        // short-circuits before any slot directory is created, so an uncapped
        // session provably leaves no delegation state behind.
        $absentRoot = sys_get_temp_dir() . '/wf-a6-seats-' . bin2hex(random_bytes(6));

        self::assertNull(DelegationSlots::acquire('sess-uncapped', null, $absentRoot));
        self::assertNull(DelegationSlots::acquire('sess-uncapped', 0, $absentRoot));
        self::assertNull(DelegationSlots::acquire('sess-uncapped', -2, $absentRoot));
        self::assertFalse(is_dir($absentRoot), 'a no-cap acquire must never create the slot root');
    }

    /** @return mixed the promoted property value inside the re-bound tool */
    private static function doorBound(TaskTool $tool, string $name): mixed
    {
        return (new \ReflectionProperty($tool, $name))->getValue($tool);
    }
}
