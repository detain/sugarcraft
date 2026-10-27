<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui\Window;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Theme;
use SugarCraft\Code\Tui\Window\WindowAttention;
use SugarCraft\Code\Tui\Window\WindowEntry;
use SugarCraft\Code\Tui\Window\WindowList;
use SugarCraft\Code\Tui\Window\WindowRef;
use SugarCraft\Code\Tui\Window\WindowStrip;
use SugarCraft\Core\Util\Width;
use SugarCraft\Mouse\Scan;

/**
 * The window strip's render laws (P-TABS.a): the row never over-pays its
 * width, `[+]` is never among the dropped, click zones land exactly on the
 * painted cells at every density, and hostile keys degrade to "not
 * clickable" instead of throwing mid-frame.
 *
 * Every measurement here is in PAINTED CELLS: SGR is markup, and the
 * Private-Use zone sentinels are zero-width content, so the marked twin of
 * a row must strip to the same cells the plain row painted.
 */
final class WindowStripTest extends TestCase
{
    private const MARKUP_PATTERN = '~\e\[[0-9;]*m|\x{e000}(?:[^\x{e001}]{0,})\x{e001}~u';

    public function testEmptyListStillAnswersPlus(): void
    {
        $row = $this->cells(WindowStrip::render(WindowList::empty(), Theme::default(), 80, false));
        self::assertSame(WindowStrip::PLUS_PLAIN, $row);
    }

    public function testFullDensityPaintsNamesBadgesChipsAndCloses(): void
    {
        $row = $this->cells(WindowStrip::render($this->crowded(5), Theme::default(), 240, false));

        self::assertStringContainsString('sess-1', $row);
        self::assertStringContainsString('@explore', $row, 'the focused session chips ride right after its tab');
        self::assertStringContainsString('2', $row, 'the badge count is painted');
        self::assertStringContainsString(WindowStrip::CLOSE_PLAIN, $row);
        self::assertStringNotContainsString("\x{2039}", $row, 'nothing was dropped at 240 cols');
    }

    public function testWidthAndPlusAndMarkedTwinHoldAcrossTheSweep(): void
    {
        $list = $this->crowded(5);
        $theme = Theme::default();

        for ($cols = 20; $cols <= 240; $cols += 10) {
            $plain = WindowStrip::render($list, $theme, $cols, false);
            $marked = $this->cells(WindowStrip::render($list, $theme, $cols, true));

            self::assertLessThanOrEqual($cols, Width::string($this->cells($plain)), 'row over-pays its width at ' . $cols);
            self::assertStringContainsString(WindowStrip::PLUS_PLAIN, $plain, 'the new-session affordance vanished at ' . $cols);
            self::assertSame($this->cells($plain), $marked, 'the marked twin paints different cells at ' . $cols);
        }
    }

    public function testPlusSurvivesEvenWhenTheWholeCrowdIsRefused(): void
    {
        $theme = Theme::default();
        $list = WindowList::empty();
        for ($i = 1; $i <= 30; $i++) {
            $list = $list->add(WindowEntry::session(
                WindowRef::session('s' . $i, 'window number ' . $i),
                WindowAttention::Unseen,
                $i
            ));
        }

        $plain = WindowStrip::render($list, $theme, 20, false);
        self::assertStringContainsString(WindowStrip::PLUS_PLAIN, $plain);
        self::assertLessThanOrEqual(20, Width::string($this->cells($plain)));
        self::assertMatchesRegularExpression('~\x{2039}\d{1,}\x{203a}$~u', $this->cells($plain), 'the overflow count must trail the row');
    }

    public function testOverflowCountsTheSessionsThatDidNotFit(): void
    {
        $theme = Theme::default();
        $list = $this->crowded(5);

        // A width tight enough to drop at least one session: placed sessions
        // come from the zones, the count from the trailing marker, and the
        // two must add up to the whole roster.
        foreach ([24, 28, 32, 40, 48] as $cols) {
            $plain = $this->cells(WindowStrip::render($list, $theme, $cols, false));
            $placed = 0;
            foreach (WindowStrip::zones($list, $cols) as [, , , $id]) {
                if (str_starts_with($id, WindowStrip::ZONE_SESSION_PREFIX)) {
                    $placed++;
                }
            }

            if (!preg_match('~\x{2039}(\d{1,})\x{203a}$~u', $plain, $m)) {
                self::assertSame(5, $placed, 'no marker painted, so every session must have fit at ' . $cols);

                continue;
            }
            self::assertSame(5 - $placed, (int) $m[1], 'the ‹N› must count exactly the dropped sessions at ' . $cols);
            self::assertGreaterThan(0, $placed, 'the marker must not be the only tab at ' . $cols);
        }
    }

    public function testIconDensityDropsNamesButKeepsGlyphsAndCloses(): void
    {
        $theme = Theme::default();
        // Three long-named sessions at 20 cols: full cannot fit (names cost
        // 17 each), icon can (3 + 3*(1+1+2) = 15).
        $list = WindowList::empty()
            ->add(WindowEntry::session(WindowRef::session('s1', 'aaaaaaaaaaaaaaaaaaaa')))
            ->add(WindowEntry::session(WindowRef::session('s2', 'bbbbbbbbbbbbbbbbbbbb')))
            ->add(WindowEntry::session(WindowRef::session('s3', 'cccccccccccccccccccc')));

        $row = $this->cells(WindowStrip::render($list, $theme, 20, false));
        self::assertStringNotContainsString('aaaa', $row);
        self::assertSame(3, substr_count($row, WindowStrip::CLOSE_PLAIN));
        self::assertLessThanOrEqual(20, Width::string($row));
    }

    public function testTerseDensityDropsCloseMarksAndChips(): void
    {
        $theme = Theme::default();
        $list = $this->crowded(6);

        // 20 cols with six sessions and two chips: even icon needs
        // 3 + 6*(1+1+2) = 27. Terse costs 3 + 6*(1) = 9 plus separators.
        $row = $this->cells(WindowStrip::render($list, $theme, 20, false));
        self::assertStringNotContainsString(WindowStrip::CLOSE_PLAIN, $row);
        self::assertStringNotContainsString(WindowStrip::CHIP_PLAIN, $row);
        self::assertStringContainsString(WindowStrip::PLUS_PLAIN, $row);
        self::assertLessThanOrEqual(20, Width::string($row));
    }

    public function testZonesLandOnThePaintedCellsAtEveryDensity(): void
    {
        $theme = Theme::default();
        $list = $this->crowded(4);

        foreach ([20, 24, 32, 48, 80, 160, 240] as $cols) {
            $marked = WindowStrip::render($list, $theme, $cols, true);
            $scanned = (new Scan())->parse($marked);

            $ids = [];
            foreach (WindowStrip::zones($list, $cols) as [$row, $from, $to, $id]) {
                $ids[] = $id;
                self::assertSame(0, $row, 'the strip is one row; its zones ride row 0');
                $zone = $scanned[$id] ?? null;
                self::assertNotNull($zone, $id . ' painted by zones() but not by the scanner at ' . $cols);
                self::assertSame($from + 1, $zone->startCol, $id . ' start at ' . $cols);
                self::assertSame($to, $zone->endCol, $id . ' end at ' . $cols);
                self::assertGreaterThan($from, $to, $id . ' must cover at least one cell at ' . $cols);
            }

            // The reverse direction: the scanner may not know a zone the
            // geometry table never claimed.
            foreach ($scanned as $id => $zone) {
                self::assertContains($id, $ids, $id . ' was emitted with no zones() entry at ' . $cols);
            }
        }
    }

    public function testZoneBodiesCoverTheCellsTheyName(): void
    {
        $theme = Theme::default();
        $list = $this->crowded(2);
        $plain = $this->cells(WindowStrip::render($list, $theme, 240, false));

        foreach (WindowStrip::zones($list, 240) as [, $from, $to, $id]) {
            $slice = $this->sliceCells($plain, $from, $to);
            self::assertNotSame('', $slice, $id . ' covers empty space');
            if (str_starts_with($id, WindowStrip::ZONE_SESSION_PREFIX)) {
                $key = substr($id, strlen(WindowStrip::ZONE_SESSION_PREFIX));
                self::assertStringContainsString('sess-' . ltrim($key, 's'), $slice, $id . ' body must hold its tab name at full density');
                if ($key === 's1') {
                    self::assertStringStartsWith(WindowAttention::Working->glyph(), $slice, 'the focused tab rides its Working glyph');
                }
            }
        }

        // The plus zone is always the opening three cells.
        self::assertSame(WindowStrip::PLUS_PLAIN, $this->sliceCells($plain, 0, 3));
    }

    public function testHostileKeysPaintUnmarkedAndNeverThrow(): void
    {
        $theme = Theme::default();
        // A key outside sugar-mouse's zone-id charset (a space) must paint
        // its tab without a zone rather than throwing mid-frame.
        $list = WindowList::empty()
            ->add(WindowEntry::session(WindowRef::session('bad key', 'trouble')))
            ->add(WindowEntry::session(WindowRef::session('ok', 'fine')));

        $marked = WindowStrip::render($list, $theme, 80, true);
        $scanned = (new Scan())->parse($marked);

        self::assertArrayNotHasKey('win:s:bad key', $scanned);
        self::assertArrayHasKey('win:s:ok', $scanned);
        self::assertArrayHasKey(WindowStrip::ZONE_NEW, $scanned);
        self::assertStringContainsString('trouble', $this->cells($marked));
        self::assertLessThanOrEqual(80, Width::string($this->cells($marked)));

        foreach (WindowStrip::zones($list, 80) as [, , , $id]) {
            self::assertStringNotContainsString('bad key', $id);
        }
    }

    public function testBadgesKeepDistinctGlyphsForEveryAttentionState(): void
    {
        $states = WindowAttention::cases();
        $list = WindowList::empty();
        foreach ($states as $index => $state) {
            $list = $list->add(WindowEntry::session(
                WindowRef::session('s' . $index, 'state-' . $index),
                $state,
                $index
            ));
        }

        $row = $this->cells(WindowStrip::render($list, Theme::default(), 240, false));
        $seen = [];
        foreach ($states as $state) {
            $glyph = $state->glyph();
            self::assertStringContainsString($glyph, $row);
            self::assertArrayNotHasKey($glyph, $seen, $state->name . ' shares a glyph with ' . ($seen[$glyph] ?? ''));
            $seen[$glyph] = $state->name;
        }
    }

    public function testStripSurvivesUnicodeAndWideNames(): void
    {
        $theme = Theme::default();
        $list = WindowList::empty()
            ->add(WindowEntry::session(WindowRef::session('s1', "\x{4f60}\x{597d} \x{1f600} world"), WindowAttention::Waiting, 1));

        foreach ([20, 40, 80] as $cols) {
            $row = $this->cells(WindowStrip::render($list, $theme, $cols, false));
            self::assertLessThanOrEqual($cols, Width::string($row), 'wide glyphs must be budgeted in cells at ' . $cols);
            self::assertStringContainsString(WindowStrip::PLUS_PLAIN, $row);
        }
    }

    /**
     * @return WindowList with $n sessions, the first focused and carrying two agent chips
     */
    private function crowded(int $n): WindowList
    {
        $list = WindowList::empty();
        for ($i = 1; $i <= $n; $i++) {
            $ref = WindowRef::session('s' . $i, 'sess-' . $i);
            $entry = $i === 1
                ? WindowEntry::session($ref, WindowAttention::Working, 2, [
                    WindowEntry::agent(WindowRef::agent('run-1', 'explore', 's1')),
                    WindowEntry::agent(WindowRef::agent('run-2', 'general', 's1')),
                ])
                : WindowEntry::session($ref, WindowAttention::Unseen, $i);
            $list = $list->add($entry);
        }

        return $list->focus('s1');
    }

    /** Painted cells of a row: SGR and zone sentinels are markup, not width. */
    private function cells(string $row): string
    {
        return preg_replace(self::MARKUP_PATTERN, '', $row) ?? '';
    }

    /** Cell-slice [from, to) of an unmarked plain row, counting painted cells. */
    private function sliceCells(string $plain, int $from, int $to): string
    {
        $out = '';
        $col = 0;
        foreach (mb_str_split($plain) as $char) {
            if ($col >= $to) {
                break;
            }
            if ($col >= $from) {
                $out .= $char;
            }
            $col += max(1, Width::string($char));
        }

        return $out;
    }
}
