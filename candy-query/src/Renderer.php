<?php

declare(strict_types=1);

namespace SugarCraft\Query;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Tty;
use SugarCraft\Core\Util\Width;
use SugarCraft\Forms\ItemList\ItemList;
use SugarCraft\Forms\ItemList\StringItem;
use SugarCraft\Table\{Column, Row, RowData, Table};
use SugarCraft\Query\Admin\AdminPane;
use SugarCraft\Query\Admin\AdminSection;
use SugarCraft\Query\Terminal\BorderFrame;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Layout;
use SugarCraft\Sprinkles\Position;
use SugarCraft\Sprinkles\Style;

/**
 * Stateless renderer for the candy-query TUI shell.
 *
 * `Renderer::render(App)` composes the three panes (tables list, rows
 * preview, query editor) plus a help footer into a single ANSI string.
 * Pure function — given the same {@see App} it always produces the
 * same bytes.
 */
final class Renderer
{
    /** Cached terminal dimensions for the current render pass. */
    private static ?array $terminalSize = null;

    /**
     * Record the authoritative terminal size handed to us by the framework.
     *
     * The {@see \SugarCraft\Core\Program} emits a
     * {@see \SugarCraft\Core\Msg\WindowSizeMsg} at startup and on every
     * `SIGWINCH`; {@see App::update()} forwards it here so the renderer lays
     * out for exactly the space the program's own screen renderer is driving.
     * This is the single source of truth — it overrides any independent
     * detection below (which is only a fallback for contexts where no
     * WindowSizeMsg arrives, e.g. tests).
     */
    public static function setSize(int $cols, int $rows): void
    {
        if ($cols > 0 && $rows > 0) {
            self::$terminalSize = ['rows' => $rows, 'cols' => $cols];
        }
    }

    /**
     * Get the terminal size. Once {@see setSize()} has been called with the
     * framework's WindowSizeMsg, that value is authoritative. Otherwise detection
     * is delegated to candy-core's {@see Tty} façade, which selects the
     * Posix/Windows backend and runs the full size ladder internally (FFI
     * TIOCGWINSZ → COLUMNS/LINES → /dev/tty → stty → default). A hard default
     * guards the unlikely case where the façade itself is unavailable.
     *
     * @return array{rows:int, cols:int}
     */
    public static function getTerminalSize(): array
    {
        if (self::$terminalSize !== null) {
            return self::$terminalSize;
        }

        // Delegate detection to candy-core's Tty façade rather than re-rolling
        // the FFI/env/stty ladder here — it tracks live resizes (kernel
        // TIOCGWINSZ) and falls back through /dev/tty + stty internally, picking
        // the right backend per platform. The WindowSizeMsg cache above stays
        // the single source of truth; this path only runs before the first
        // WindowSizeMsg arrives (e.g. tests, the very first frame).
        try {
            $size = (new Tty(STDOUT))->size();
            if ($size['cols'] > 0 && $size['rows'] > 0) {
                self::$terminalSize = ['rows' => $size['rows'], 'cols' => $size['cols']];
                return self::$terminalSize;
            }
        } catch (\Throwable) {
            // Detection unavailable — fall through to the hard default.
        }

        // Hard default for modern wide/tall terminals (accommodates the table
        // list + rows + query + help + status panes without cutting).
        self::$terminalSize = ['rows' => 60, 'cols' => 200];
        return self::$terminalSize;
    }

    /**
     * Reset the cached terminal size (call when the terminal may have resized).
     */
    public static function resetSizeCache(): void
    {
        self::$terminalSize = null;
    }

    public static function render(App $a): string
    {
        $size = self::getTerminalSize();
        $cols = $size['cols'];

        // Admin pane uses a left sidebar layout instead of the 3-pane browser
        if ($a->pane === Pane::Admin) {
            $admin = self::adminPane($a, $cols);
            $query = self::queryPane($a, $cols);
            $help = Style::new()->foreground(Color::hex('#7d6e98'))
                ->render('1-6  select page  ·  j/k  navigate  ·  p  pause  ·  r  reset  ·  tab  switch pane  ·  q  quit');
            $status = '';
            if ($a->error !== null) {
                $status = "\n " . Style::new()->foreground(Color::hex('#ff5f87'))->bold()
                    ->render('error: ' . $a->error);
            } elseif ($a->status !== null) {
                $status = "\n " . Style::new()->foreground(Color::hex('#6ee7b7'))
                    ->render($a->status);
            }
            $content = $admin . "\n" . $query . "\n " . $help . $status;
            return BorderFrame::wrap($a, $content);
        }

        // Standard 3-pane layout: tables + rows + query
        $available = max(3, $size['rows'] - 18);
        $tables = self::tablesPane($a, $size['rows'], $cols);
        $rows   = self::rowsPane($a, $available, $cols);
        $top    = Layout::joinHorizontal(Position::TOP, $tables, '  ', $rows);

        $query  = self::queryPane($a, $cols);

        // Help text differs per pane
        if ($a->pane === Pane::Query) {
            $help = Style::new()->foreground(Color::hex('#7d6e98'))
                ->render('tab  switch pane  ·  ctrl+r  run  ·  ctrl+e  clear  ·  ctrl+h  history  ·  q  quit');
        } else {
            $help = Style::new()->foreground(Color::hex('#7d6e98'))
                ->render('tab  switch pane  ·  enter  load table  ·  ctrl+r  run query  ·  q  quit');
        }

        $status = '';
        if ($a->error !== null) {
            $status = "\n " . Style::new()->foreground(Color::hex('#ff5f87'))->bold()
                ->render('error: ' . $a->error);
        } elseif ($a->status !== null) {
            $status = "\n " . Style::new()->foreground(Color::hex('#6ee7b7'))
                ->render($a->status);
        }

        $content = $top . "\n" . $query . "\n " . $help . $status;
        return BorderFrame::wrap($a, $content);
    }

    private static function tablesPane(App $a, int $terminalRows, int $terminalCols): string
    {
        // Calculate width: expand to use up to half the terminal (minus 3-char gap).
        // Use max(24, ...) to keep at least 24 chars for readability.
        // Formula: width = max(24, min(max_table_name_length, floor(terminalCols/2) - 3))
        // Cap each pane at floor(cols/2) - 6 so the two panes side-by-side fit
        // the outer frame: each rendered pane is paneWidth + 4 (border+padding),
        // joined with a 2-space gap, all inside the outer frame's (cols-2)
        // content area → 2*(w+4) + 2 ≤ cols-2 ⇒ w ≤ floor(cols/2) - 6.
        $maxTableLen = $a->tables !== [] ? max(array_map('strlen', $a->tables)) : 0;
        $width = max(24, min($maxTableLen, (int) floor($terminalCols / 2) - 6));

        if ($a->tables === []) {
            $body = Style::new()->foreground(Color::hex('#7d6e98'))->render('(no tables)');
            return self::frame($a, Pane::Tables, ' tables ', $body, $width);
        }

        // Layout: title(1) + gap(1) + [tablesPane | rowsPane joined horizontally] +
        // query(3) + help(1) + status(1) + finalNL(1) = 8 lines overhead.
        // tablesPane frame: 1 title + 1 top + content + 1 bottom = content + 3.
        // rowsPane frame: 1 title + 1 top + content + 1 bottom; fixed at ~15 lines
        //   when showing 11 data rows (header + 11 = 12 body + 4 frame = 16 total).
        // Both panes joined at TOP → height = max(tablesPane, rowsPane).
        // Constraint: 1 + 1 + max(available+3, 16) + 3 + 1 + 1 + 1 ≤ terminalRows
        //           → available ≤ terminalRows - 18. Use max(3, terminalRows - 18).
        $available = max(3, $terminalRows - 18);

        // candy-forms ItemList owns the cursor, selection styling and scroll
        // window — no more hand-rolled centering/slice/`↑ N–M of T ↑` indicators.
        // Pre-style each name (gold+bold for the loaded table, muted otherwise);
        // the cursor row is rendered reverse-video by the widget.
        $items = [];
        foreach ($a->tables as $name) {
            $items[] = new StringItem(
                $name === $a->selectedTable
                    ? Style::new()->foreground(Color::hex('#fde68a'))->bold()->render($name)
                    : Style::new()->foreground(Color::hex('#c5b6dd'))->render($name)
            );
        }
        $list = ItemList::new($items, $width, $available)
            ->withTitle('')
            ->withShowStatusBar(false)
            ->withShowHelp(false)
            ->withShowFilter(false)
            ->withCursorPrefix('')
            ->withUnselectedPrefix('')
            ->select($a->tableCursor);

        return self::frame($a, Pane::Tables, ' tables ', $list->view(), $width);
    }

    /**
     * @param int $available The number of lines available for the tables pane content.
     *                       Used to proportionally limit rows pane height.
     * @param int $terminalCols The terminal column count for width calculation.
     */
    private static function rowsPane(App $a, int $available = 12, int $terminalCols = 80): string
    {
        $title = ' rows ' . ($a->selectedTable ? "[{$a->selectedTable}] " : '');

        $cols = array_keys($a->rows[0] ?? []);
        $numFields = count($cols);

        // Cap at floor(cols/2) - 6 (see tablesPane) so tables + rows fit the
        // outer frame side-by-side without overflowing and wrapping.
        $width = min($numFields * 14, (int) floor($terminalCols / 2) - 6);
        $width = max(60, $width);

        if ($a->rows === []) {
            return self::frame(
                $a, Pane::Rows, $title,
                Style::new()->foreground(Color::hex('#7d6e98'))->render('(empty)'),
                $width,
            );
        }

        // Executed-query results render through ResultTable (its horizontal-
        // scroll grid with JSON pretty-printing + a styled NULL token); regular
        // table browsing renders through sugar-table.
        if ($a->resultTable !== null) {
            $body = $a->resultTable->withVisibleWidth($width)->render();
            return self::frame($a, Pane::Rows, $title, $body, $width);
        }

        // Keep panes balanced: show at most ~11 rows from the top (the bottom
        // overhead is header + frame). The active row is highlighted via
        // sugar-table's own selection, driven by our rowCursor.
        $maxRows = max(1, min(11, $available - 2));

        // Size each column to share the pane width (minus the table's own
        // border); sugar-table truncates cell content to the column maxWidth.
        $colBudget = max(6, (int) floor(($width - 1 - ($numFields - 1)) / max(1, $numFields)));
        $columns = [];
        foreach ($cols as $col) {
            $columns[] = Column::new((string) $col, (string) $col, $colBudget)
                ->withAlignLeft()
                ->withMaxWidth($colBudget);
        }

        $tableRows = [];
        foreach ($a->rows as $row) {
            $data = [];
            foreach ($cols as $col) {
                $data[(string) $col] = CellValue::display($row[$col] ?? null);
            }
            $tableRows[] = Row::new(RowData::from($data));
        }

        $table = Table::withColumns($columns)
            ->withRows($tableRows)
            ->withSelectable()
            ->withZebra()
            ->withViewportHeight($maxRows)
            ->withSelectedIndex($a->rowCursor);

        return self::frame($a, Pane::Rows, $title, $table->View(), $width);
    }

    private static function queryPane(App $a, int $terminalCols): string
    {
        // This pane spans the full width. The outer BorderFrame gives each line
        // a content area of (cols - 2); a bordered+padded Style adds 4 (2 border
        // + 2 padding), so the inner CONTENT width must be (cols - 2) - 4 = cols - 6.
        // Using cols - 4 (the old value) overflowed the outer frame by 2 cells,
        // wrapping the query box and dropping its right border.
        $width = max(20, $terminalCols - 6);
        // The candy-forms TextArea owns the cursor + placeholder; it renders the
        // cursor only while focused (Query pane). Size it to the pane at render
        // time without mutating App state.
        $body = $a->editor()->withWidth($width)->view();
        return self::frame($a, Pane::Query, ' query ', $body, $width);
    }

    private static function adminPane(App $a, int $terminalCols): string
    {
        // Build sidebar WITHOUT frame — just styled text lines
        $sidebarLines = [];

        // Group admin panes by section
        $managementItems = [];
        $performanceItems = [];
        foreach (AdminPane::cases() as $pane) {
            if ($pane->section() === AdminSection::Management) {
                $managementItems[] = $pane;
            } else {
                $performanceItems[] = $pane;
            }
        }

        // Add "ADMIN" title
        $sidebarLines[] = Style::new()->bold()->foreground(Color::hex('#fde68a'))->render(' ADMIN ');
        $sidebarLines[] = '';

        // Management section
        $sidebarLines[] = Style::new()->bold()->foreground(Color::hex('#6ee7b7'))->render(' Management ');
        foreach ($managementItems as $pane) {
            $isActive = $a->adminPane === $pane;
            $marker = $isActive ? '▶' : ' ';
            $color = $isActive ? Color::hex('#00ffaa') : Color::hex('#6a5898');
            $sidebarLines[] = Style::new()->foreground($color)->render($marker . ' ' . $pane->label());
        }
        $sidebarLines[] = '';

        // Performance section
        $sidebarLines[] = Style::new()->bold()->foreground(Color::hex('#6ee7b7'))->render(' Performance ');
        foreach ($performanceItems as $pane) {
            $isActive = $a->adminPane === $pane;
            $marker = $isActive ? '▶' : ' ';
            $color = $isActive ? Color::hex('#00ffaa') : Color::hex('#6a5898');
            $sidebarLines[] = Style::new()->foreground($color)->render($marker . ' ' . $pane->label());
        }

        $sidebarText = implode("\n", $sidebarLines);

        // Get admin page content — no frame wrapping from here
        $pageContent = $a->adminPage()->view();

        // This pane spans the full width. The outer BorderFrame content area is
        // (cols - 2); a bordered+padded Style adds 4, so the inner content width
        // must be (cols - 6). Using cols - 2 (the old value) overflowed by 4.
        $innerWidth = max(20, $terminalCols - 6);

        // Calculate widths within the inner content region.
        $sidebarWidth = (int) floor($terminalCols / 4);
        $sidebarWidth = max(20, $sidebarWidth);  // minimum 20 chars
        $contentWidth = max(10, $innerWidth - $sidebarWidth - 2);  // 2 for gap

        // Join sidebar and content horizontally — use raw strings, NOT pre-styled frames
        // Layout::joinHorizontal takes strings and pads shorter one with blank lines at the bottom
        $combined = Layout::joinHorizontal(Position::TOP, $sidebarText, '  ', $pageContent);

        // Wrap the combined output in a single frame; the title rides in the border.
        $st = Style::new()->border(Border::rounded()->withTitle(' admin '))->padding(0, 1)->width($innerWidth);
        $st = $a->pane === Pane::Admin
            ? $st->borderForeground(Color::hex('#00ffaa'))
            : $st->borderForeground(Color::hex('#ff66aa'));

        return $st->render($combined);
    }

    private static function frame(App $a, Pane $p, string $title, string $body, int $width): string
    {
        // The pane title rides in the rounded border itself (a first-class
        // Sprinkles\Border feature) instead of a hand-drawn bold line inside it.
        $st = Style::new()->border(Border::rounded()->withTitle($title))->padding(0, 1)->width($width);
        $st = $a->pane === $p
            ? $st->borderForeground(Color::hex('#00ffaa'))
            : $st->borderForeground(Color::hex('#ff66aa'));
        return $st->render($body);
    }
}
