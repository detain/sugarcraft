<?php

declare(strict_types=1);

namespace SugarCraft\Query;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Tty\PosixBackend;
use SugarCraft\Query\Admin\AdminPane;
use SugarCraft\Query\Admin\AdminSection;
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
     * Get the terminal size, trying multiple backends in order:
     *   1. Environment variables (COLUMNS/LINES, set by some terminal emulators)
     *   2. FFI ioctl(TIOCGWINSZ) via PosixBackend
     *   3. Shell-out to `stty size`
     *   4. Hard default of 24 rows × 80 cols
     *
     * @return array{rows:int, cols:int}
     */
    private static function getTerminalSize(): array
    {
        if (self::$terminalSize !== null) {
            return self::$terminalSize;
        }

        // 1. Environment variables (set by terminal emulators / resize commands)
        $cols = (int) (getenv('COLUMNS') ?: 0);
        $rows = (int) (getenv('LINES') ?: 0);
        if ($cols > 0 && $rows > 0) {
            self::$terminalSize = ['rows' => $rows, 'cols' => $cols];
            return self::$terminalSize;
        }

        // 2. FFI ioctl via PosixBackend (candy-core/candy-pty)
        try {
            $backend = new PosixBackend(STDOUT);
            $size = $backend->size();
            if ($size['cols'] > 0 && $size['rows'] > 0) {
                self::$terminalSize = $size;
                return self::$terminalSize;
            }
        } catch (\Throwable) {
            // FFI not available or ioctl failed — fall through
        }

        // 3. Shell fallback: `stty size` ( POSIX-compatible )
        $stty = trim((string) shell_exec('stty size 2>/dev/null'));
        if ($stty !== '' && str_contains($stty, ' ')) {
            [$r, $c] = explode(' ', $stty, 2);
            if ((int) $r > 0 && (int) $c > 0) {
                self::$terminalSize = ['rows' => (int) $r, 'cols' => (int) $c];
                return self::$terminalSize;
            }
        }

        // 4. Hard default
        self::$terminalSize = ['rows' => 24, 'cols' => 80];
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
                ->render('tab  switch pane  ·  1-6  select admin page  ·  p  pause  ·  r  reset  ·  q  quit');
            $status = '';
            if ($a->error !== null) {
                $status = "\n " . Style::new()->foreground(Color::hex('#ff5f87'))->bold()
                    ->render('error: ' . $a->error);
            } elseif ($a->status !== null) {
                $status = "\n " . Style::new()->foreground(Color::hex('#6ee7b7'))
                    ->render($a->status);
            }
            $title = Style::new()->bold()->foreground(Color::hex('#7dd3fc'))
                ->render(' CandyQuery ');
            return $title . "\n" . $admin . "\n" . $query . "\n " . $help . $status . "\n";
        }

        // Standard 3-pane layout: tables + rows + query
        $available = max(3, $size['rows'] - 18);
        $tables = self::tablesPane($a, $size['rows'], $cols);
        $rows   = self::rowsPane($a, $available, $cols);
        $top    = Layout::joinHorizontal(Position::TOP, $tables, '  ', $rows);

        $query  = self::queryPane($a, $cols);

        $help   = Style::new()->foreground(Color::hex('#7d6e98'))
            ->render('tab  switch pane  ·  enter  load table  ·  ctrl+r  run query  ·  q  quit');

        $status = '';
        if ($a->error !== null) {
            $status = "\n " . Style::new()->foreground(Color::hex('#ff5f87'))->bold()
                ->render('error: ' . $a->error);
        } elseif ($a->status !== null) {
            $status = "\n " . Style::new()->foreground(Color::hex('#6ee7b7'))
                ->render($a->status);
        }

        $title = Style::new()->bold()->foreground(Color::hex('#7dd3fc'))
            ->render(' CandyQuery ');

        return $title . "\n" . $top . "\n" . $query . "\n " . $help . $status . "\n";
    }

    private static function tablesPane(App $a, int $terminalRows, int $terminalCols): string
    {
        $body = [];

        // Calculate width: expand to use up to half the terminal (minus 3-char gap).
        // Use max(24, ...) to keep at least 24 chars for readability.
        // Formula: width = max(24, min(max_table_name_length, floor(terminalCols/2) - 3))
        $maxTableLen = $a->tables !== [] ? max(array_map('strlen', $a->tables)) : 0;
        $width = max(24, min($maxTableLen, (int) floor($terminalCols / 2) - 3));

        if ($a->tables === []) {
            $body[] = Style::new()->foreground(Color::hex('#7d6e98'))
                ->render('(no tables)');
            $bodyText = implode("\n", $body);
        return self::frame($a, Pane::Tables, ' tables ', $bodyText, $width);
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

        $count = count($a->tables);

        if ($count <= $available) {
            // Everything fits — render the full list without scroll indicators.
            // Pass available=count so array_slice only processes O(available) items,
            // and use count as frame height so the frame exactly fits the content.
            return self::frame($a, Pane::Tables, ' tables ', self::renderTableList($a, 0, $count, $count, 0, $count), max($count, $width));
        }

        // Need to scroll: determine the visible window around the cursor
        $cursor = $a->tableCursor;

        // Center the cursor in the visible window when possible
        $halfWindow = (int) floor(($available - 1) / 2);
        $start = $cursor - $halfWindow;

        // Clamp to keep the window within bounds
        $start = max(0, min($start, $count - $available));

        $visibleTables = array_slice($a->tables, $start, $available);
        $bodyText = self::renderTableList($a, $start, $count, $count, $start, $available);

        return self::frame($a, Pane::Tables, ' tables ', $bodyText, $width);
    }

    /**
     * Render the table list items for a window.
     *
     * @param App $a
     * @param int $start Start index in the full tables array
     * @param int $count Total table count
     * @param int $total Total table count (alias for readability)
     * @param int $visibleStart Start index of the visible window (for scroll indicator logic)
     * @param int $available Height of the visible window
     * @return string
     */
    private static function renderTableList(
        App $a,
        int $start,
        int $count,
        int $total,
        ?int $visibleStart = null,
        ?int $available = null,
    ): string {
        $lines = [];
        $visibleStart ??= 0;
        $available ??= $count;

        // Top scroll indicator if not showing from the beginning
        if ($visibleStart > 0) {
            $lines[] = Style::new()->foreground(Color::hex('#6ee7b7'))->bold()
                ->render('↑ ' . ($visibleStart + 1) . '–' . min($visibleStart + $available - 1, $count - 1) . ' of ' . $count . ' ↑');
        }

        // Only iterate the visible slice — O(visible) not O(total)
        $visibleItems = array_slice($a->tables, $start, $available);
        foreach ($visibleItems as $i => $name) {
            $idx = $start + $i; // absolute index in the full tables array

            $st = Style::new()->foreground(Color::hex('#c5b6dd'));
            if ($name === $a->selectedTable) {
                $st = $st->foreground(Color::hex('#fde68a'))->bold();
            }
            if ($a->pane === Pane::Tables && $idx === $a->tableCursor) {
                $st = $st->reverse();
            }
            $lines[] = $st->render($name);
        }

        // Bottom scroll indicator if not showing through the end
        $endIndex = min($visibleStart + $available - 1, $count - 1);
        if ($endIndex < $count - 1) {
            $lines[] = Style::new()->foreground(Color::hex('#6ee7b7'))->bold()
                ->render('↓ ' . ($endIndex + 1) . '–' . $count . ' of ' . $count . ' ↓');
        }

        return implode("\n", $lines);
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

        // Calculate available content width using the same formula as before.
        // Use floor(terminalCols/2) - 3 to leave room for tables pane + gap.
        $width = min($numFields * 14, (int) floor($terminalCols / 2) - 3);
        $width = max(60, $width);

        if ($a->rows === []) {
            return self::frame(
                $a, Pane::Rows, $title,
                Style::new()->foreground(Color::hex('#7d6e98'))->render('(empty)'),
                $width,
            );
        }

        // Phase 1: Measure actual content width per field (header + all row values).
        $colWidths = array_fill(0, $numFields, 0);
        foreach ($cols as $j => $col) {
            $headerLen = mb_strlen($col);
            $colWidths[$j] = $headerLen;
            foreach ($a->rows as $row) {
                $val = $row[$col] ?? '';
                if (is_scalar($val)) {
                    $val = (string) $val;
                } else {
                    $val = json_encode($val) ?: '';
                }
                // Replace newlines with visual marker so they don't break pane layout
                $val = str_replace(["\r\n", "\r", "\n"], '↵', $val);
                $valLen = mb_strlen($val);
                if ($valLen > $colWidths[$j]) {
                    $colWidths[$j] = $valLen;
                }
            }
            // Ensure minimum of 1 for legibility even if content is empty.
            if ($colWidths[$j] === 0) {
                $colWidths[$j] = 1;
            }
        }

        // Phase 2: Proportional expansion to fill available width.
        // Constraint: sum(fieldWidths) + (numFields - 1) * 2 <= availableWidth
        // Each field takes fieldWidth + 2 for separator (2 spaces), except last.
        $totalActual = array_sum($colWidths);
        $totalWithSeparators = $totalActual + ($numFields - 1) * 2;
        if ($totalWithSeparators < $width) {
            // Content fits — expand proportionally to fill available space.
            $excess = $width - $totalWithSeparators;
            $expansionRatio = ($totalActual + $excess) / $totalActual;
            $expansionRatio = max(1.0, $expansionRatio);
            foreach ($colWidths as $j => $w) {
                $colWidths[$j] = max($w, (int) floor($w * $expansionRatio));
            }
        } else {
            // Content exceeds available width — use measured widths but ensure minimum 12.
            foreach ($colWidths as $j => $w) {
                $colWidths[$j] = max($w, 12);
            }
        }

        // Phase 3: Build output with computed widths.
        $headerCells = [];
        foreach ($cols as $j => $col) {
            $headerCells[] = str_pad($col, $colWidths[$j]);
        }
        $headerLine = Style::new()->bold()->foreground(Color::hex('#fde68a'))
            ->render(implode('  ', $headerCells));
        $bodyLines = [$headerLine];

        // Limit visible data rows to min(11, available - 2) to keep panes balanced.
        // available - 2 accounts for rowsPane header + bottom border overhead.
        $maxRows = max(0, min(11, $available - 2));
        foreach ($a->rows as $i => $row) {
            $cells = [];
            foreach ($cols as $j => $c) {
                $val = $row[$c] ?? '';
                if (is_scalar($val)) {
                    $val = (string) $val;
                } else {
                    $val = json_encode($val) ?: '';
                }
                // Replace newlines with visual marker so they don't break pane layout
                $val = str_replace(["\r\n", "\r", "\n"], '↵', $val);
                $cellWidth = $colWidths[$j];
                if (mb_strlen($val) > $cellWidth) {
                    // Truncate: keep $cellWidth - 1 chars + ellipsis.
                    $val = mb_substr($val, 0, $cellWidth - 1) . '…';
                }
                $cells[] = str_pad($val, $cellWidth);
            }
            $line = implode('  ', $cells);
            if ($a->pane === Pane::Rows && $i === $a->rowCursor) {
                $line = Style::new()->reverse()->render($line);
            }
            $bodyLines[] = $line;
            if ($i >= $maxRows) break;
        }
        return self::frame($a, Pane::Rows, $title, implode("\n", $bodyLines), $width);
    }

    private static function queryPane(App $a, int $terminalCols): string
    {
        $cursorMark = $a->pane === Pane::Query ? '▮' : ' ';
        $body = ($a->queryBuf === '' ? '-- type SQL, ctrl+r to run --' : $a->queryBuf) . $cursorMark;
        // Use full terminal width minus 4 for frame border/padding.
        $width = max(80, $terminalCols - 4);
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

        // Calculate widths
        $sidebarWidth = (int) floor($terminalCols / 4);
        $sidebarWidth = max(20, $sidebarWidth);  // minimum 20 chars
        $contentWidth = $terminalCols - $sidebarWidth - 3;  // 3 for gap

        // Join sidebar and content horizontally — use raw strings, NOT pre-styled frames
        // Layout::joinHorizontal takes strings and pads shorter one with blank lines at the bottom
        $combined = Layout::joinHorizontal(Position::TOP, $sidebarText, '  ', $pageContent);

        // Wrap the combined output in a single frame
        $title = ' admin ';
        $st = Style::new()->border(Border::rounded())->padding(0, 1)->width($terminalCols - 2);
        $st = $a->pane === Pane::Admin
            ? $st->borderForeground(Color::hex('#00ffaa'))
            : $st->borderForeground(Color::hex('#ff66aa'));

        return $st->render(Style::new()->bold()->render($title) . "\n" . $combined);
    }

    private static function frame(App $a, Pane $p, string $title, string $body, int $width): string
    {
        $border = Border::rounded();
        $st = Style::new()->border($border)->padding(0, 1)->width($width);
        $st = $a->pane === $p
            ? $st->borderForeground(Color::hex('#00ffaa'))
            : $st->borderForeground(Color::hex('#ff66aa'));
        return $st->render(Style::new()->bold()->render($title) . "\n" . $body);
    }
}
