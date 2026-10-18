<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Overlay;

use SugarCraft\Top\Input\KeyRow;
use SugarCraft\Top\Input\KeyTable;
use SugarCraft\Top\Lang;
use SugarCraft\Top\Overlay\HelpMenu;
use SugarCraft\Top\View\Surface;

/**
 * The help overlay's grouping contract: KeyTable::SECTIONS is metadata on
 * top of the flat roster — every row lands in exactly one section, the
 * stream order is stable, and sections() can never carry a roster of its
 * own ({@see \SugarCraft\Top\Input\KeyTable::sections()}).
 */
final class HelpSectionsTest extends OverlayTestCase
{
    public function testHeadingsAreOrderedAndResolveToText(): void
    {
        $this->assertSame([
            'help.section.general',
            'help.section.panels',
            'help.section.navigation',
            'help.section.process_list',
            'help.section.filter',
            'help.section.net',
            'help.section.actions',
            'help.section.overlays',
            'help.section.mouse',
            'help.section.info',
        ], array_keys(KeyTable::sections()));
        foreach (array_keys(KeyTable::SECTIONS) as $heading) {
            $text = Lang::t($heading);
            $this->assertNotSame($heading, $text, $heading . ' has no lang text');
            $this->assertNotSame('', trim($text), $heading . ' resolves empty');
        }
    }

    public function testEveryRowLandsInExactlyOneSection(): void
    {
        $flat = [];
        foreach (KeyTable::sections() as $rows) {
            foreach ($rows as $row) {
                $flat[] = [$row->keys, $row->text];
            }
        }
        $roster = [];
        foreach (KeyTable::rows() as $row) {
            $roster[] = [$row->keys, $row->text];
        }
        $this->assertCount(count($roster), $flat, 'the sections hold every row once and nothing more');
        sort($flat);
        sort($roster);
        $this->assertSame($roster, $flat, 'section contents are exactly the roster');
    }

    public function testSectionsDeriveFromTheRosterWithoutReorderingIt(): void
    {
        $rows = KeyTable::rows();
        foreach (KeyTable::SECTIONS as $heading => $indexes) {
            $expected = array_map(
                static fn (int $i): array => [$rows[$i]->keys, $rows[$i]->text],
                $indexes,
            );
            $actual = array_map(
                static fn (KeyRow $r): array => [$r->keys, $r->text],
                KeyTable::sections()[$heading],
            );
            $this->assertSame($expected, $actual, $heading);
        }
        $flat = array_merge(...array_values(KeyTable::SECTIONS));
        $this->assertSame($flat, array_unique($flat, SORT_REGULAR), 'no row is indexed twice');
    }

    /** Frozen stream order: heading => key labels, exactly as the grid renders them. */
    public function testStableSectionOrder(): void
    {
        $ladder = [];
        foreach (KeyTable::sections() as $heading => $rows) {
            $ladder[Lang::t($heading)] = array_map(static fn (KeyRow $r): string => $r->keyLabel(), $rows);
        }
        $this->assertSame([
            'General' => ['Esc, m', 'ctrl + r', 'q, ctrl + c', '+, -, ='],
            'Panels & views' => ['p', 'shift + p', '1', '2', '3', '4', '5, 6, 7, 8, 9, 0', 'x', '[, ]', 'v', 's, S', 'shift + i', 'd', 'c', 'r', 'e', 'E', '%', 'O', 'g, ctrl + g'],
            'Navigation & scrolling' => ['Up, Down', 'Pg Up, Pg Down', 'Home, End', 'h, j, k, l', 'g, G'],
            'Process list' => ['Enter', 'Spacebar', 'C', 'Left, Right'],
            'Search & filter' => ['f, /', 'F', 'u', 'delete'],
            'Net & io' => ['b, n', 'i', 'z', 'a', 'y'],
            'Process actions' => ['Selected +, -', 'Selected t', 'Selected k (vim K)', 'Selected s', 'Selected N', 'Shift + Left, Right', 'Alt+Shift+Left/Right', 'Ctrl+Shift+Left', 'Ctrl+Shift+Right', 'Ctrl+Shift+Down', 'H, K'],
            'Overlays' => ['F2, o', 'F1, ?, h'],
            'Mouse' => ['Mouse 1', 'Mouse scroll'],
            'Info' => ['', '', ''],
        ], $ladder);
    }

    /**
     * A 120x80 terminal holds the whole grid on one page: paint must show
     * every section heading exactly once, in stream order, and no row may
     * leak between pages (there is no second page to leak to).
     */
    /**
     * A 120x80 terminal holds the whole grid on one page. The stream order
     * (every heading once, declared section order) is checked on the grid
     * itself; the paint then pins the widened box: at 120 cols the borders
     * span the full WIDE_WIDTH.
     */
    public function testPaintStreamsEverySectionOnce(): void
    {
        $grid = new \ReflectionMethod(HelpMenu::class, 'gridLines');
        $headings = array_map(static fn (string $k): string => Lang::t($k), array_keys(KeyTable::SECTIONS));
        $seen = [];
        foreach ($grid->invoke(null, HelpMenu::WIDE_WIDTH) as [$label, $description, $isHeading]) {
            if ($isHeading) {
                $this->assertSame('', $description, 'heading lines carry no description');
                $seen[] = $label;
            }
        }
        $this->assertSame($headings, $seen, 'every heading enters the stream exactly once, in order');

        $surface = Surface::new(120, 80);
        HelpMenu::new()->paint($surface, self::ctx(120, 80));
        $top = $surface->plainLines()[11];
        $left = mb_strpos($top, '╭');
        $this->assertSame(1, $left, 'box centred at x=2 (1-based)');
        $this->assertSame($left + HelpMenu::WIDE_WIDTH - 1, mb_strpos($top, '╮'), 'the 117-wide box');
    }
}
