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

    /**
     * The three help trailer rows (the blank line, help.footer, help.url)
     * land at the tail of the Info section — on the last grid page — so no
     * first-page golden has ever shown them. Drive the real key handler to
     * the last page at 120x40 and require the footer, the URL, and section
     * headings to appear together in one frame. Descriptions word-wrap, and
     * the columns run side by side, so a flat string match cannot see them;
     * {@see self::strippedColumns()} reads each column as a vertical strip.
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
        $this->assertTrue(self::anyColumnCarries($columns, Lang::t('help.section.net')), 'an earlier section heading is still on the same frame');

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
     * Each grid column as a whitespace-stripped vertical strip: concatenating
     * a column top-to-bottom reconstitutes a description that word-wrapped
     * over several rows. Column offsets come from HelpMenu's public geometry,
     * never hard-coded.
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
            $strips[] = preg_replace('/\s+/u', '', $text);
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
