<?php

declare(strict_types=1);

namespace SugarCraft\DatePicker\Tests;

use SugarCraft\CellBuffer\Style;
use SugarCraft\DatePicker\DatePicker;
use SugarCraft\DatePicker\PickerEvent;
use SugarCraft\DatePicker\EventStore;
use PHPUnit\Framework\TestCase;

/**
 * Behavior pins for the 2026-11 review remediation wave. Kept in their own
 * file so the fix contracts they lock in are individually discoverable.
 */
final class SemanticsPinsTest extends TestCase
{
    /** Invoke the private buildCells() grid for a picker. */
    private static function cellsOf(DatePicker $dp): array
    {
        $build = new \ReflectionMethod(DatePicker::class, 'buildCells');
        $build->setAccessible(true);

        /** @var list<array{0:string,1:Style|null}> $cells */
        return $build->invoke($dp);
    }

    public function testOutOfBoundsDayRendersFaint(): void
    {
        // D12 (dp-01): a day outside the configured [min,max] window is dimmed
        // so the disabled state is visible, not just silently unselectable —
        // AND the faint cue composes onto state styles, so a disabled TODAY
        // cell carries its colour attrs and faint simultaneously: a stale or
        // out-of-window highlight must never masquerade as selectable.
        // Inspecting the cell grid directly (not the delta-encoded ANSI stream,
        // whose 38;2 true-colour introducers make ';2' substring matching
        // ambiguous) pins the buildCells() faint branch itself.
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withMinDate(new \DateTimeImmutable('2026-05-10'))
            ->withToday(new \DateTimeImmutable('2026-05-05')); // inside the May view, outside the bounds

        $cells = self::cellsOf($dp);

        // May 2026 starts Friday (firstDow 5): day 5 → grid idx 9 (disabled
        // AND today), day 15 → grid idx 19 (in-bounds, plain).
        $this->assertNotNull($cells[9][1], 'the disabled day carries a style');
        $this->assertNotSame(
            0,
            $cells[9][1]->attrs() & Style::ATTR_FAINT,
            'a disabled day must render faint'
        );
        $this->assertNotNull($cells[9][1]->fg(), 'a disabled today cell keeps its today colour');
        $this->assertNotSame(
            0,
            $cells[9][1]->attrs() & Style::ATTR_BOLD,
            'a disabled today cell keeps the bold half of the today style'
        );
        $this->assertNull($cells[19][1], 'an in-bounds plain day carries no style');
    }

    public function testRangeArmedStartIsStyled(): void
    {
        // D13: after the first range Enter, the armed start is the ONLY visible
        // anchor (buildRange is null until both ends land) — it must paint with
        // the selected style so the user can see where the range began.
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withToday(new \DateTimeImmutable('2026-12-31'))
            ->withRangeMode(true)
            ->MoveToDate(new \DateTimeImmutable('2026-05-10'))
            ->handleKey(DatePicker::KEY_ENTER);

        $this->assertNotNull($dp->rangeStart());
        $this->assertNull($dp->rangeEnd());

        $cells = self::cellsOf($dp);
        // day 10 → grid idx 14; armed start renders with the bold selected fg.
        $this->assertNotNull($cells[14][1], 'the armed range start must be styled');
        $this->assertNotSame(
            0,
            $cells[14][1]->attrs() & Style::ATTR_BOLD,
            'the armed start carries the selected (bold) treatment'
        );
    }

    public function testEventStoreEvictsOldestBeyondCap(): void
    {
        // MT7: record() is bounded — a host that never drains cannot grow the
        // ring unbounded.
        $store = new EventStore(static fn (): int => 0);
        $total = EventStore::MAX_EVENTS + 25;
        for ($i = 0; $i < $total; $i++) {
            $store->record(PickerEvent::CURSOR_MOVED, ['seq' => $i]);
        }

        $this->assertSame(EventStore::MAX_EVENTS, $store->count());
        $released = $store->release();
        $this->assertCount(EventStore::MAX_EVENTS, $released);
        // The 25 oldest were evicted; the retained head is seq 25.
        $this->assertSame(25, $released[0]['payload']['seq']);
        $this->assertSame($total - 1, $released[count($released) - 1]['payload']['seq']);
    }

    public function testInBoundsIsTimezoneSafeAcrossZones(): void
    {
        // D10: bounds compare on the calendar day, not the absolute instant.
        // A maxDate of 2026-12-31 UTC must accept a 2026-12-31 probed from a
        // negative-offset zone even though that instant is already Jan 1 UTC.
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withMaxDate(new \DateTimeImmutable('2026-12-31T00:00:00Z'));

        $sameDayOtherZone = new \DateTimeImmutable('2026-12-31T20:00:00-05:00');
        $this->assertTrue(
            $dp->inBounds($sameDayOtherZone),
            'the same wall date in another zone is in bounds'
        );

        $after = new \DateTimeImmutable('2027-01-01T00:00:00Z');
        $this->assertFalse($dp->inBounds($after), 'the next calendar day is out of bounds');
    }
}
