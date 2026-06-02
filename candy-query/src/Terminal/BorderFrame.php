<?php

declare(strict_types=1);

namespace SugarCraft\Query\Terminal;

use SugarCraft\Query\App;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Pane;

/**
 * Wraps TUI content in a full terminal frame with title bar and status bar.
 *
 * Produces a double-line box frame (╔═╗║╚═╝) containing:
 *   - Title bar: app name, table count, connection info, server version
 *   - Content area: the actual TUI panes (tables, rows, query, admin)
 *   - Status bar: context-sensitive keyboard shortcuts
 *
 * @internal Renderer consumer only — not part of the public sugar-query API.
 */
final class BorderFrame
{
    /** ANSI color codes for frame elements. */
    private const C = [
        'border' => "\x1b[38;2;100;116;139m",
        'title' => "\x1b[1;36m",
        'sep' => "\x1b[38;2;100;116;139m",
        'info' => "\x1b[38;2;203;213;227m",
        'reset' => "\x1b[0m",
    ];

    /**
     * Wrap content in a full terminal frame.
     *
     * Frame layout (top to bottom):
     *   - Top border (╔ + ═ + ╗) with title bar centered on first line
     *   - Divider (╠ + ═ + ╣)
     *   - Content lines (║ + padded content + ║)
     *   - Divider (╠ + ═ + ╣)
     *   - Bottom border (╚ + ║ + ╝) with status bar on last line
     *
     * Uses \x1b[2J to clear the screen and \x1b[H to home the cursor,
     * ensuring no leftover content from previous renders.
     */
    public static function wrap(App $a, string $content): string
    {
        // Use Renderer::getTerminalSize() once to ensure frame dimensions match
        // the dimensions used to render the content.
        try {
            $size = \SugarCraft\Query\Renderer::getTerminalSize();
            $width = $size['cols'];
            $height = $size['rows'];
        } catch (\Throwable) {
            // Fallback: match Renderer hard-coded default (200 cols × 60 rows for modern terminals)
            $width = 200;
            $height = 60;
        }
        $titleBar = self::buildTitleBar($a, $width);
        $statusBar = self::buildStatusBar($a, $width);

        // Clear screen and home cursor to prevent old content bleeding through
        $clear = "\x1b[2J\x1b[H";

        // Frame overhead: 1 top + 1 title + 1 divider + content + 1 divider + 1 status + 1 bottom = 7 lines
        $frameOverhead = 7;
        $availableContentHeight = $height - $frameOverhead;

        // Split content into lines and measure
        $contentLines = explode("\n", $content);
        $contentLineCount = count($contentLines);

        // Pad content to fill available height
        $paddedLines = $contentLines;
        if ($contentLineCount < $availableContentHeight) {
            // Add blank lines to fill
            $paddingCount = $availableContentHeight - $contentLineCount;
            for ($i = 0; $i < $paddingCount; $i++) {
                $paddedLines[] = '';
            }
        }

        // Build the frame
        $lines = [];
        $lines[] = self::topBorder($width);
        $lines[] = '║' . self::padCenter($titleBar, $width - 2) . '║';
        $lines[] = self::divider($width);

        foreach ($paddedLines as $line) {
            $lines[] = '║' . self::padRight($line, $width - 2) . '║';
        }

        $lines[] = self::divider($width);
        $lines[] = '║' . self::padRight($statusBar, $width - 2) . '║';
        $lines[] = self::bottomBorder($width);

        return $clear . implode("\n", $lines);
    }

    /**
     * Build the title bar content.
     *
     * Shows: SugarSQL │ Tables: N │ dsn │ version
     * Only non-empty values are included.
     */
    private static function buildTitleBar(App $a, int $width): string
    {
        $parts = [];

        // App name in bold cyan
        $parts[] = self::C['title'] . 'SugarSQL' . self::C['reset'];

        // Table count
        $parts[] = 'Tables: ' . count($a->tables);

        // Connection info (dsn) — only if non-empty
        $dsn = $a->db->dsn();
        if ($dsn !== '') {
            $parts[] = self::C['info'] . $dsn . self::C['reset'];
        }

        // Server version from serverContext, or flavor fallback
        $version = self::serverVersion($a);
        if ($version !== '') {
            $parts[] = self::C['info'] . $version . self::C['reset'];
        }

        return implode(self::C['sep'] . ' │ ' . self::C['reset'], $parts);
    }

    /**
     * Build the status bar content with context-sensitive keyboard shortcuts.
     */
    private static function buildStatusBar(App $a, int $width): string
    {
        $segments = ['Tab:cycle', '↑↓:navigate'];

        if ($a->pane === Pane::Tables || $a->pane === Pane::Rows) {
            $segments[] = 'Enter:load';
        }
        if ($a->pane === Pane::Query) {
            $segments[] = 'Ctrl+R:run';
        }
        if ($a->pane === Pane::Admin) {
            $segments[] = '1-6:select';
            $segments[] = 'j/k:nav';
            $segments[] = '[admin:' . $a->adminPane->value . ']';
        }
        $segments[] = 'q:quit';

        $status = implode('  ', $segments);

        // Show PAUSED indicator when admin dashboard is paused
        if ($a->pane === Pane::Admin && $a->paused) {
            $status .= '  ' . self::C['title'] . '[PAUSED]' . self::C['reset'];
        }

        return $status;
    }

    /**
     * Get server version string from App.
     */
    private static function serverVersion(App $a): string
    {
        if ($a->serverContext !== null) {
            return $a->serverContext->versionString();
        }

        // Fallback: show flavor for non-SQLite databases
        if ($a->flavor !== Flavor::Sqlite) {
            return $a->flavor->value;
        }

        return '';
    }

    private static function topBorder(int $width): string
    {
        return '╔' . str_repeat('═', $width - 2) . '╗';
    }

    private static function bottomBorder(int $width): string
    {
        return '╚' . str_repeat('═', $width - 2) . '╝';
    }

    private static function divider(int $width): string
    {
        return '╠' . str_repeat('═', $width - 2) . '╣';
    }

    /**
     * Get terminal width, using Renderer::getTerminalSize() for consistency.
     */
    private static function terminalWidth(): int
    {
        // Try to use Renderer's detection for consistency
        try {
            $size = \SugarCraft\Query\Renderer::getTerminalSize();
            if ($size['cols'] > 0) {
                return $size['cols'];
            }
        } catch (\Throwable) {
        }

        // Fallback: env vars
        $cols = (int) (getenv('COLUMNS') ?: 0);
        if ($cols > 0) {
            return $cols;
        }

        // Fallback: stty
        $stty = trim((string) shell_exec('stty size 2>/dev/null'));
        if ($stty !== '' && str_contains($stty, ' ')) {
            [$rows, $cols] = explode(' ', $stty, 2);
            if ((int) $cols > 0) {
                return (int) $cols;
            }
        }

        // Default: match Renderer fallback of 80 columns
        return 80;
    }

    /**
     * Get terminal height, using Renderer::getTerminalSize() for consistency.
     */
    private static function terminalHeight(): int
    {
        // Try to use Renderer's detection for consistency
        try {
            $size = \SugarCraft\Query\Renderer::getTerminalSize();
            if ($size['rows'] > 0) {
                return $size['rows'];
            }
        } catch (\Throwable) {
        }

        // Fallback: env vars
        $rows = (int) (getenv('LINES') ?: 0);
        if ($rows > 0) {
            return $rows;
        }

        // Fallback: stty
        $stty = trim((string) shell_exec('stty size 2>/dev/null'));
        if ($stty !== '' && str_contains($stty, ' ')) {
            [$rows, $cols] = explode(' ', $stty, 2);
            if ((int) $rows > 0) {
                return (int) $rows;
            }
        }

        // Default: match Renderer fallback of 48 rows for modern terminals
        return 48;
    }

    /**
     * Pad string to width on the right with spaces.
     *
     * Handles ANSI escape sequences by measuring stripped length
     * but truncating the visible content to fit within $width.
     * Truncation appends "…" (ellipsis) to indicate overflow.
     */
    private static function padRight(string $s, int $width): string
    {
        // Strip ANSI codes for length calculation
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $s);
        $len = mb_strlen($stripped);

        if ($len > $width) {
            // Truncate to fit, appending ellipsis to indicate overflow
            $visibleWidth = $width - 1; // Leave room for ellipsis
            $truncated = mb_substr($stripped, 0, $visibleWidth);
            return $truncated . '…';
        }

        if ($len < $width) {
            return $s . str_repeat(' ', $width - $len);
        }

        return $s;
    }

    /**
     * Center string within width, padding equally on both sides.
     *
     * When content exceeds width, truncates to fit with ellipsis.
     */
    private static function padCenter(string $s, int $width): string
    {
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $s);
        $len = mb_strlen($stripped);

        if ($len > $width) {
            // Truncate with ellipsis
            $visibleWidth = $width - 1;
            return mb_substr($stripped, 0, $visibleWidth) . '…';
        }

        $pad = $width - $len;
        $left = (int) floor($pad / 2);
        $right = $pad - $left;

        return str_repeat(' ', $left) . $s . str_repeat(' ', $right);
    }
}
