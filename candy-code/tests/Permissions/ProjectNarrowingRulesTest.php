<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\Permissions\ProjectNarrowingRules;

/**
 * The deny/ask-only partition behind C-SEC-6.b — what a trusted project's
 * shared settings file may contribute to the gate, and what it may not.
 *
 * Fails before the fix by the plainest of reasons: the class does not exist
 * yet. Every case here is one sentence of the trust floor made checkable: a
 * project may NARROW a session, never WIDEN it, and a dropped `allow` is
 * reported rather than swallowed.
 */
final class ProjectNarrowingRulesTest extends TestCase
{
    public function testDenyAndAskSurviveThePartitionAndAllowDoesNot(): void
    {
        $ask = new PermissionRule('Write', PermissionAction::Ask);
        $allow = new PermissionRule('Bash', PermissionAction::Allow);
        $deny = new PermissionRule('Read', PermissionAction::Deny);

        $narrowing = new ProjectNarrowingRules([$ask, $allow, $deny]);

        self::assertSame([$ask, $deny], $narrowing->kept);
        self::assertSame([$allow], $narrowing->droppedAllows);
    }

    /**
     * First-match-wins is the whole of the "a user allow cannot beat a
     * project deny" promise, and it rides on ORDER. A partition that
     * reordered the survivors would silently rewrite which rule answers
     * first, so the order is pinned here at the filter, not only at the
     * gate that consumes it.
     */
    public function testTheOriginalOrderOfTheKeptEntriesIsPreservedAcrossDroppedOnes(): void
    {
        $first = new PermissionRule('Read', PermissionAction::Deny);
        $second = new PermissionRule('Write', PermissionAction::Ask);
        $third = new PermissionRule('Edit', PermissionAction::Deny);

        $narrowing = new ProjectNarrowingRules([$first, new PermissionRule('Bash', PermissionAction::Allow), $second, $third]);

        self::assertSame([$first, $second, $third], $narrowing->kept);
        self::assertSame(1, $narrowing->allowsDropped());
    }

    /**
     * An all-allow project tier contributes NOTHING to the gate — the
     * self-grant floor in its strictest form — but the drop count is still
     * nonzero, which is the half the launch notice says out loud. A filter
     * that both emptied `kept` and zeroed `droppedAllows` would make the
     * silence look like an empty file.
     */
    public function testAnAllAllowTierNarrowsNothingAndReportsEveryDrop(): void
    {
        $narrowing = new ProjectNarrowingRules([
            new PermissionRule('Bash', PermissionAction::Allow),
            new PermissionRule('WebFetch', PermissionAction::Allow),
        ]);

        self::assertSame([], $narrowing->kept);
        self::assertSame(0, $narrowing->narrowedRules());
        self::assertSame(2, $narrowing->allowsDropped());
    }

    public function testAnEmptyTierKeepsAndDropsNothing(): void
    {
        $narrowing = new ProjectNarrowingRules([]);

        self::assertSame([], $narrowing->kept);
        self::assertSame([], $narrowing->droppedAllows);
        self::assertSame(0, $narrowing->narrowedRules());
        self::assertSame(0, $narrowing->allowsDropped());
    }

    /**
     * The count accessors report the SAME figures the arrays hold — the
     * notice sprintf reads the accessors while the merge reads the lists, so
     * a divergence between the two would print a promise the gate does not
     * keep.
     */
    public function testTheCountsAgreeWithTheListsTheyDescribe(): void
    {
        $rules = [
            new PermissionRule('Read', PermissionAction::Deny),
            new PermissionRule('Edit', PermissionAction::Ask),
            new PermissionRule('Bash', PermissionAction::Allow),
            new PermissionRule('Write', PermissionAction::Allow),
            new PermissionRule('WebFetch', PermissionAction::Deny),
        ];

        $narrowing = new ProjectNarrowingRules($rules);

        self::assertSame(count($narrowing->kept), $narrowing->narrowedRules());
        self::assertSame(count($narrowing->droppedAllows), $narrowing->allowsDropped());
        self::assertSame(count($rules), $narrowing->narrowedRules() + $narrowing->allowsDropped());
    }
}
