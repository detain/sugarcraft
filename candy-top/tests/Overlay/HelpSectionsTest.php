<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Overlay;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
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
     * A tall 120-column terminal holds the whole grid on one page. The plan
     * order (every heading once, in declared section order) is checked on
     * the pure box plan itself — with a tall enough column every section is
     * a single atomic box, so each heading appears exactly once; the paint
     * then pins the adaptive borders: a cols-2 box at 120 columns, the full
     * WIDE_WIDTH cap once the terminal saturates it.
     */
    public function testPaintStreamsEverySectionOnce(): void
    {
        $headings = array_map(static fn (string $k): string => Lang::t($k), array_keys(KeyTable::SECTIONS));
        $seen = [];
        foreach (HelpMenu::plan(HelpMenu::WIDE_WIDTH, 1000) as $columns) {
            foreach ($columns as $boxes) {
                foreach ($boxes as $box) {
                    $seen[] = $box['heading'];
                }
            }
        }
        $this->assertSame($headings, $seen, 'every heading enters the plan exactly once, in order, unsegmented');

        $surface = Surface::new(120, 80);
        HelpMenu::new()->paint($surface, self::ctx(120, 80));
        $top = $surface->plainLines()[11];
        $left = mb_strpos($top, '╭');
        $this->assertSame(0, $left, 'x=1 (1-based): the 118-cell box hugs the left of a 120-col terminal');
        $this->assertSame($left + HelpMenu::widthFor(120) - 1, mb_strpos($top, '╮'), 'the cols-2 box at 120 columns');

        $wide = Surface::new(170, 80);
        HelpMenu::new()->paint($wide, self::ctx(170, 80));
        $top = $wide->plainLines()[11];
        $left = mb_strpos($top, '╭');
        $this->assertSame(1, $left, 'x=2 (1-based) centring of the saturated box');
        $this->assertSame($left + HelpMenu::WIDE_WIDTH - 1, mb_strpos($top, '╮'), 'the full 166-wide box at the cap');
    }

    /**
     * The three help trailer rows (the blank line, help.footer, help.url)
     * land inside the Info box — on the last grid page — so no first-page
     * golden has ever shown them. Drive the real key handler to the last
     * page at 120x40 and require the footer, the URL, and the boxes around
     * them to appear together in one frame. Descriptions word-wrap, and
     * the framed columns run side by side, so a flat string match cannot
     * see them; {@see self::strippedColumns()} reads each column window as
     * a vertical strip, borders and all, then drops the whitespace and box
     * glyphs the frames contribute.
     */
    public function testTheFinalPageCarriesTheTrailerRows(): void
    {
        $ctx = self::ctx(120, 40);
        $pages = HelpMenu::geometry(120, 40)[3];
        $this->assertGreaterThan(1, $pages, '120x40 must paginate so the trailer needs the last page');

        $menu = HelpMenu::new();
        for ($i = 1; $i < $pages; ++$i) {
            $menu = $menu->update(new KeyMsg(KeyType::Down), $ctx)->overlay;
        }

        $surface = Surface::new(120, 40);
        $menu->paint($surface, $ctx);
        $columns = self::strippedColumns($surface->plainLines(), 120, 40);

        $this->assertTrue(self::anyColumnCarries($columns, Lang::t('help.footer')), 'the footer lands on the last page');
        $this->assertTrue(self::anyColumnCarries($columns, Lang::t('help.url')), 'the URL lands on the last page');
        $this->assertTrue(self::anyColumnCarries($columns, Lang::t('help.section.info')), 'the Info heading shares the last frame with its trailer');
        $this->assertTrue(self::anyColumnCarries($columns, Lang::t('help.section.mouse')), 'the box before Info is still on the same frame');

        $firstPage = Surface::new(120, 40);
        HelpMenu::new()->paint($firstPage, $ctx);
        $this->assertFalse(
            self::anyColumnCarries(self::strippedColumns($firstPage->plainLines(), 120, 40), Lang::t('help.footer')),
            'the footer is absent from page 1 — the pin proves the page turn is real',
        );
    }

    /**
     * At 120x80 the whole grid fits on a single page, so that one frame must
     * already carry the footer, the URL, and the Info heading.
     */
    public function testTheSinglePageCarriesTheTrailerRows(): void
    {
        $this->assertSame(1, HelpMenu::geometry(120, 80)[3], '120x80 holds the grid on one page');
        $surface = Surface::new(120, 80);
        HelpMenu::new()->paint($surface, self::ctx(120, 80));
        $columns = self::strippedColumns($surface->plainLines(), 120, 80);

        $this->assertTrue(self::anyColumnCarries($columns, Lang::t('help.footer')), 'the single page carries the footer');
        $this->assertTrue(self::anyColumnCarries($columns, Lang::t('help.url')), 'the single page carries the URL');
        $this->assertTrue(self::anyColumnCarries($columns, Lang::t('help.section.info')), 'the single page carries the Info heading');
    }

    /**
     * Each grid column window as a whitespace-stripped vertical strip:
     * concatenating a window top-to-bottom reconstitutes a description
     * that word-wrapped over several rows. The framed layout adds border
     * rows and side glyphs inside every window, so the strip drops both
     * whitespace and box-drawing glyphs (U+2500-U+257F). Column offsets
     * come from HelpMenu's public geometry, never hard-coded.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private static function strippedColumns(array $lines, int $cols, int $rows): array
    {
        $width = HelpMenu::widthFor($cols);
        $columnCount = HelpMenu::columnsFor($width);
        $columnWidth = HelpMenu::columnWidth($width, $columnCount);
        $x = HelpMenu::geometry($cols, $rows)[0];

        $strips = [];
        for ($j = 0; $j < $columnCount; ++$j) {
            $start = $x + $j * ($columnWidth + HelpMenu::GUTTER);
            $text = '';
            foreach ($lines as $line) {
                $text .= mb_substr($line, $start, $columnWidth);
            }
            $strips[] = preg_replace('/[\s\x{2500}-\x{257F}]+/u', '', $text);
        }

        return $strips;
    }

    /** @param list<string> $columns */
    private static function anyColumnCarries(array $columns, string $needle): bool
    {
        $flat = preg_replace('/\s+/u', '', $needle);
        foreach ($columns as $column) {
            if (str_contains($column, $flat)) {
                return true;
            }
        }

        return false;
    }
}
