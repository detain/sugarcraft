<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Model;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\ScriptUi\Model\ChooseModel;
use SugarCraft\ScriptUi\Model\FilterModel;

/**
 * scriptui-13 (findings 017 + 041): the checked set must key through ONE
 * index space (original item positions, resolved by row IDENTITY). Before
 * the fix the toggle wrote title-first positions while selectedAll() read
 * them back through the re-indexed fuzzy subset — toggled rows silently
 * dropped or emitted a colliding option — and duplicate titles ticked the
 * FIRST copy regardless of where the cursor stood.
 */
final class SelectionKeyingTest extends TestCase
{
    /** @param list<string> $options */
    private static function typeFilter(FilterModel $m, string $query): FilterModel
    {
        foreach (mb_str_split($query, 1, 'UTF-8') as $ch) {
            [$m, ] = $m->update(new KeyMsg(KeyType::Char, $ch));
        }
        return $m;
    }

    public function testFuzzyNarrowedToggleSurvivesToThePayload(): void
    {
        // "bna" fuzzy-matches banana + bandana; the fuzzy subset is
        // 2-wide while the original list is 3-wide. Keying through the
        // subset (the old shape) shifts or drops every position ≥ the
        // first gap.
        $m = self::typeFilter(
            FilterModel::fromOptions(['fig', 'banana', 'bandana'], noLimit: true, fuzzy: true),
            'bna',
        );
        self::assertSame(['banana', 'bandana'], array_map(
            static fn ($i) => $i->title(),
            $m->fuzzyVisibleItems(),
        ));

        // Tab row 0, Down, Tab row 1.
        [$m, ] = $m->update(new KeyMsg(KeyType::Tab, ''));
        [$m, ] = $m->update(new KeyMsg(KeyType::Down, ''));
        [$m, ] = $m->update(new KeyMsg(KeyType::Tab, ''));

        $submitted = $m->update(new KeyMsg(KeyType::Enter, ''))[0];
        self::assertSame(
            ['banana', 'bandana'],
            $submitted->selectedAll(),
            'both toggled painted rows must reach the payload, in original order',
        );
    }

    public function testUntickRemovesExactlyTheToggledRow(): void
    {
        $m = self::typeFilter(
            FilterModel::fromOptions(['coconut', 'banana', 'bandana'], noLimit: true, fuzzy: true),
            'bna',
        );
        [$m, ] = $m->update(new KeyMsg(KeyType::Tab, ''));   // tick banana
        [$m, ] = $m->update(new KeyMsg(KeyType::Tab, ''));   // untick it again
        $submitted = $m->update(new KeyMsg(KeyType::Enter, ''))[0];
        self::assertSame([], $submitted->selectedAll());
    }

    public function testDuplicateTitlesTickPerRow(): void
    {
        // Three identical titles: each ROW is its own checked slot.
        $m = ChooseModel::fromOptions(['a', 'a', 'a'], noLimit: true);
        [$m, ] = $m->update(new KeyMsg(KeyType::Space, '')); // row 0
        [$m, ] = $m->update(new KeyMsg(KeyType::Down, ''));
        [$m, ] = $m->update(new KeyMsg(KeyType::Space, '')); // row 1
        $submitted = $m->update(new KeyMsg(KeyType::Enter, ''))[0];
        self::assertSame(
            ['a', 'a'],
            $submitted->selectedAll(),
            'toggling two duplicate-title rows yields TWO entries, not zero (old untick-the-first-copy collapse)',
        );
        // Row 2 was never touched — marks paint on BOTH ticked rows now:
        // scriptui-44's per-row visibility leg puts the tick under the
        // cursor too (a multi user must see what they just ticked without
        // moving away). Row 0 is the ticked non-cursor row, row 1 the
        // ticked cursor row.
        self::assertSame(2, substr_count($m->view(), "\u{2713}"));
        $lines = explode("\n", $m->view());
        self::assertStringStartsWith("\u{2713}", $lines[0]);
        self::assertStringStartsWith("> \u{2713}", $lines[1], 'the cursor row carries glyph, tick, then reverse video');
        self::assertStringStartsWith('  ', $lines[2], 'unticked row 2 keeps the plain prefix');
    }

    public function testLimitCountsPerRowOnDuplicates(): void
    {
        $m = ChooseModel::fromOptions(['a', 'a', 'b'], limit: 2);
        [$m, ] = $m->update(new KeyMsg(KeyType::Space, '')); // tick row 0
        [$m, ] = $m->update(new KeyMsg(KeyType::Down, ''));
        [$m, ] = $m->update(new KeyMsg(KeyType::Space, '')); // tick row 1 → at cap
        [$m, ] = $m->update(new KeyMsg(KeyType::Down, ''));
        [$m, ] = $m->update(new KeyMsg(KeyType::Space, '')); // over cap → refused
        $submitted = $m->update(new KeyMsg(KeyType::Enter, ''))[0];
        self::assertSame(['a', 'a'], $submitted->selectedAll());
        self::assertStringContainsString('[2/2 selected]', $submitted->view());
    }

    public function testMarkedBodyMarksTheIdentityRow(): void
    {
        // Tick row 1 of duplicate titles: the mark must sit on a
        // non-cursor row (cursor lives on row 0 after the Down+tick+Up).
        $m = ChooseModel::fromOptions(['a', 'a', 'z'], noLimit: true);
        [$m, ] = $m->update(new KeyMsg(KeyType::Down, ''));
        [$m, ] = $m->update(new KeyMsg(KeyType::Space, '')); // tick row 1
        [$m, ] = $m->update(new KeyMsg(KeyType::Up, ''));    // cursor back to row 0
        $lines = explode("\n", $m->view());
        // lines: [0]='> ' REVERSE a, [1]='✓ a' (row 1), [2]='  z'
        self::assertStringStartsWith("\u{2713}", $lines[1]);
        self::assertStringStartsWith('  ', $lines[2]);
        // Row 0 (the cursor row) never shows a mark glyph.
        self::assertStringNotContainsString("\u{2713}", $lines[0]);
    }
}
