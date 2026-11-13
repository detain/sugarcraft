<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Table\CellLineMemo;
use SugarCraft\Table\Column;

/**
 * Per-render wrap memo (audit ST-32): the store/complete/replay contract the
 * two multiline passes shake on. Lifetime is one frame, so no invalidation
 * surface exists to test — only the group legality rules.
 */
final class CellLineMemoTest extends TestCase
{
    public function testReplayIsBlockedUntilTheGroupIsComplete(): void
    {
        $memo = new CellLineMemo();
        $column = Column::new('k', 'K', 4);

        $memo->store($column, 4, ['first line']);

        $this->assertNull($memo->replayNext($column, 4), 'an open group must not replay');

        $memo->complete($column, 4);

        $this->assertSame(['first line'], $memo->replayNext($column, 4));
    }

    public function testReplayFollowsStoreOrderThenExhausts(): void
    {
        $memo = new CellLineMemo();
        $column = Column::new('k', 'K', 4);

        $memo->store($column, 4, ['a']);
        $memo->store($column, 4, ['b', 'c']);
        $memo->complete($column, 4);

        $this->assertSame(['a'], $memo->replayNext($column, 4));
        $this->assertSame(['b', 'c'], $memo->replayNext($column, 4));
        $this->assertNull($memo->replayNext($column, 4), 'an exhausted group falls back to a fresh wrap');
    }

    public function testWidthsPartitionGroupsOfTheSameColumn(): void
    {
        $memo = new CellLineMemo();
        $column = Column::new('k', 'K', 4);

        $memo->store($column, 4, ['wide']);
        $memo->store($column, 3, ['narrow']);
        $memo->complete($column, 4);

        $this->assertSame(['wide'], $memo->replayNext($column, 4));
        $this->assertNull($memo->replayNext($column, 3), 'an incomplete width group never replays');
    }

    public function testColumnsAreKeyedByIdentityNotValue(): void
    {
        $memo = new CellLineMemo();
        $one = Column::new('k', 'K', 4);
        $other = Column::new('k', 'K', 4); // equal field-for-field, distinct instance

        $memo->store($one, 4, ['mine']);
        $memo->complete($one, 4);
        $memo->complete($other, 4);

        $this->assertNull($memo->replayNext($other, 4), 'a distinct column sees no other column\'s entries');
        $this->assertSame(['mine'], $memo->replayNext($one, 4));
    }

    public function testEmptyGroupReplaysNothing(): void
    {
        $memo = new CellLineMemo();
        $column = Column::new('k', 'K', 4);

        $memo->complete($column, 4);

        $this->assertNull($memo->replayNext($column, 4));
    }
}
