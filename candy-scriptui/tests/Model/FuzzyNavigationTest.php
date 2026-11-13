<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Model;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\ScriptUi\Model\FilterModel;

/**
 * scriptui-12 (finding 011): in fuzzy mode the PAINTED list is the
 * Smith-Waterman match set, while the inner ItemList's cursor belongs to
 * its own substring filter — a different set whenever a query fuzzy-matches
 * rows substring would drop (e.g. "bna" hits banana/bandana, matches none
 * of them as a substring). Before the fix, arrows were dead (cursor pinned
 * against the empty substring set) and Enter submitted whatever the stuck
 * cursor happened to resolve.
 */
final class FuzzyNavigationTest extends TestCase
{
    /** @param list<string> $options */
    private static function typeFilter(FilterModel $m, string $query): FilterModel
    {
        foreach (mb_str_split($query, 1, 'UTF-8') as $ch) {
            [$m, ] = $m->update(new KeyMsg(KeyType::Char, $ch));
        }
        return $m;
    }

    private static function press(FilterModel $m, KeyType $key): FilterModel
    {
        [$m, ] = $m->update(new KeyMsg($key, ''));
        return $m;
    }

    public function testArrowsTraverseExactlyThePaintedFuzzyRows(): void
    {
        // "bna" is not a substring of either banana/bandana — the inner
        // ItemList's own visible set is EMPTY for the painted rows;
        // "fig" shares no scoring material with the query.
        $m = self::typeFilter(
            FilterModel::fromOptions(['fig', 'banana', 'bandana'], fuzzy: true),
            'bna',
        );
        self::assertSame(
            [],
            $m->list->visibleItems(),
            'precondition: the substring space sees nothing of what is painted',
        );
        $visible = array_map(static fn ($i) => $i->title(), $m->fuzzyVisibleItems());
        self::assertSame(['banana', 'bandana'], $visible);

        // Enter on row 0 submits the painted row.
        $submitted = $m->update(new KeyMsg(KeyType::Enter, ''))[0];
        self::assertSame('banana', $submitted->selected());

        // Down moves onto the second PAINTED row — Enter now submits it.
        $m = self::press($m, KeyType::Down);
        $submitted = $m->update(new KeyMsg(KeyType::Enter, ''))[0];
        self::assertSame('bandana', $submitted->selected());

        // Up returns; End jumps to the last row; Home back to the first.
        $m = self::press($m, KeyType::End);
        self::assertSame('bandana', $m->update(new KeyMsg(KeyType::Enter, ''))[0]->selected());
        $m = self::press($m, KeyType::Home);
        self::assertSame('banana', $m->update(new KeyMsg(KeyType::Enter, ''))[0]->selected());
    }

    public function testNarrowingRestartSelectionAtTopRow(): void
    {
        $m = self::typeFilter(
            FilterModel::fromOptions(['fig', 'banana', 'bandana'], fuzzy: true),
            'bana',
        );
        self::assertCount(2, $m->fuzzyVisibleItems());
        $m = self::press($m, KeyType::Down);
        self::assertSame(1, $m->fuzzyCursor);

        // One more char narrows the set to just `banana` — selection
        // restarts at the top painted row (ItemList's edit arms do the
        // same: cursor 0).
        $m = self::typeFilter($m, 'n');
        self::assertSame(0, $m->fuzzyCursor);
        $order = array_map(static fn ($i) => $i->title(), $m->fuzzyVisibleItems());
        self::assertSame(
            'banana',
            $m->update(new KeyMsg(KeyType::Enter, ''))[0]->selected(),
            'cursor 0 must resolve inside the (possibly narrower) painted set: ' . implode(',', $order),
        );
    }

    public function testPagedStepsStayInsideWindow(): void
    {
        $titles = array_map(static fn (int $i) => "item-x-$i", range(0, 9));
        $m      = self::typeFilter(
            FilterModel::fromOptions($titles, fuzzy: true, height: 3),
            'x',
        );
        self::assertCount(10, $m->fuzzyVisibleItems());

        $m = self::press($m, KeyType::PageDown);
        self::assertSame(3, $m->fuzzyCursor);
        $m = self::press($m, KeyType::PageDown);
        $m = self::press($m, KeyType::PageDown);
        self::assertSame(9, $m->fuzzyCursor, 'paging clamps at the last painted row');
        $m = self::press($m, KeyType::PageUp);
        self::assertSame(6, $m->fuzzyCursor);

        // The window follows the cursor: the highlighted row is the cursor row.
        $frame = $m->view();
        self::assertMatchesRegularExpression(
            '/> \x1b\[7m/',
            $frame,
            'a cursor-prefixed REVERSE row exists in the paged frame',
        );
    }

    public function testNonFuzzyNavigationUnchanged(): void
    {
        // Belt: the substring lane keeps its old inner-ItemList contract.
        $m = self::typeFilter(FilterModel::fromOptions(['banana', 'bandana', 'coconut']), 'ban');
        self::assertCount(2, $m->list->visibleItems());
        $m = self::press($m, KeyType::Down);
        $submitted = $m->update(new KeyMsg(KeyType::Enter, ''))[0];
        self::assertSame('bandana', $submitted->selected());
    }
}
