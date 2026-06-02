<?php

declare(strict_types=1);

namespace SugarCraft\Query;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Tty\PosixBackend;
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
        $available = max(3, $size['rows'] - 26);
        $tables = self::tablesPane($a, $size['rows']);
        $rows   = self::rowsPane($a, $available);
        $top    = Layout::joinHorizontal(Position::TOP, $tables, '  ', $rows);

        $query  = self::queryPane($a);

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

    private static function tablesPane(App $a, int $terminalRows): string
    {
        $body = [];

        if ($a->tables === []) {
            $body[] = Style::new()->foreground(Color::hex('#7d6e98'))
                ->render('(no tables)');
            $bodyText = implode("\n", $body);
        // 24 chars is the standard terminal width for character-wrap; $available
        // is a line count and does not directly translate to character width.
        return self::frame($a, Pane::Tables, ' tables ', $bodyText, 24);
        }

        // Layout: title(1) + gap(1) + [tablesPane | rowsPane joined horizontally] +
        // query(3) + help(1) + status(1) + finalNL(1) = 8 lines overhead.
        // tablesPane frame: 1 title + 1 top + content + 1 bottom = content + 3.
        // rowsPane frame: 1 title + 1 top + content + 1 bottom; fixed at ~15 lines
        //   when showing 11 data rows (header + 11 = 12 body + 4 frame = 16 total).
        // Both panes joined at TOP → height = max(tablesPane, rowsPane).
        // Constraint: 1 + 1 + max(available+3, 16) + 3 + 1 + 1 + 1 ≤ terminalRows
        //           → available ≤ terminalRows - 26. Use max(3, terminalRows - 26).
        $available = max(3, $terminalRows - 26);

        $count = count($a->tables);

        if ($count <= $available) {
            // Everything fits — render the full list without scroll indicators.
            // Pass available=count so array_slice only processes O(available) items,
            // and use count as frame height so the frame exactly fits the content.
            // Use max(count, 24) for width so table names don't wrap heavily.
            return self::frame($a, Pane::Tables, ' tables ', self::renderTableList($a, 0, $count, $count, 0, $count), max($count, 24));
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

        return self::frame($a, Pane::Tables, ' tables ', $bodyText, 24);
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
     */
    private static function rowsPane(App $a, int $available = 12): string
    {
        $title = ' rows ' . ($a->selectedTable ? "[{$a->selectedTable}] " : '');
        if ($a->rows === []) {
            return self::frame(
                $a, Pane::Rows, $title,
                Style::new()->foreground(Color::hex('#7d6e98'))->render('(empty)'),
                60,
            );
        }
        // Header row from the first row's keys.
        $cols = array_keys($a->rows[0]);
        $headerLine = Style::new()->bold()->foreground(Color::hex('#fde68a'))
            ->render(implode('  ', array_map(static fn($c) => str_pad($c, 12), $cols)));
        $bodyLines = [$headerLine];
        // Limit visible data rows to min(11, available - 2) to keep panes balanced.
        // available - 2 accounts for rowsPane header + bottom border overhead.
        $maxRows = max(0, min(11, $available - 2));
        foreach ($a->rows as $i => $row) {
            $cells = [];
            foreach ($cols as $c) {
                $val = $row[$c] ?? '';
                if (is_scalar($val)) {
                    $val = (string) $val;
                } else {
                    $val = json_encode($val) ?: '';
                }
                if (mb_strlen($val) > 12) {
                    $val = mb_substr($val, 0, 11) . '…';
                }
                $cells[] = str_pad($val, 12);
            }
            $line = implode('  ', $cells);
            if ($a->pane === Pane::Rows && $i === $a->rowCursor) {
                $line = Style::new()->reverse()->render($line);
            }
            $bodyLines[] = $line;
            if ($i >= $maxRows) break;
        }
        return self::frame($a, Pane::Rows, $title, implode("\n", $bodyLines), 60);
    }

    private static function queryPane(App $a): string
    {
        $cursorMark = $a->pane === Pane::Query ? '▮' : ' ';
        $body = ($a->queryBuf === '' ? '-- type SQL, ctrl+r to run --' : $a->queryBuf) . $cursorMark;
        return self::frame($a, Pane::Query, ' query ', $body, 88);
    }

    private static function frame(App $a, Pane $p, string $title, string $body, int $width): string
    {
        $border = Border::rounded();
        $st = Style::new()->border($border)->padding(0, 1)->width($width);
        $st = $a->pane === $p
            ? $st->borderForeground(Color::hex('#7dd3fc'))
            : $st->borderForeground(Color::hex('#4a3868'));
        return $st->render(Style::new()->bold()->render($title) . "\n" . $body);
    }
}
