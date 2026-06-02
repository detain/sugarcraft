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
     */
    public static function wrap(App $a, string $content): string
    {
        $width = self::terminalWidth();
        $height = self::terminalHeight();

        $titleBar = self::buildTitleBar($a, $width);
        $statusBar = self::buildStatusBar($a, $width);

        // Count actual content lines
        $contentLines = explode("\n", $content);
        $contentLineCount = count($contentLines);

        // Calculate padding to fill available terminal height
        // Frame overhead: 1 top + 1 title + 1 divider + content + 1 divider + 1 bottom = 5 lines + content
        $frameOverhead = 5;
        $minHeight = 8;
        $availableContent = max($minHeight, $height - $frameOverhead);

        // Pad content if it doesn't fill the terminal
        $paddedContent = $content;
        if ($contentLineCount < $availableContent) {
            $paddingNeeded = $availableContent - $contentLineCount;
            $padding = str_repeat("\n" . str_repeat(' ', $width - 4), $paddingNeeded);
            $paddedContent .= $padding;
        }

        // Build the frame, inserting content lines with side borders
        $lines = [];
        $lines[] = self::topBorder($width);
        $lines[] = '║' . self::padCenter($titleBar, $width - 2) . '║';
        $lines[] = self::divider($width);

        foreach (explode("\n", $paddedContent) as $line) {
            $lines[] = '║' . self::padRight($line, $width - 2) . '║';
        }

        $lines[] = self::divider($width);
        $lines[] = '║' . self::padRight($statusBar, $width - 2) . '║';
        $lines[] = self::bottomBorder($width);

        return implode("\n", $lines);
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
     * Get terminal width, trying multiple fallbacks.
     */
    private static function terminalWidth(): int
    {
        // 1. Environment variable (set by some terminal emulators / resize)
        $cols = (int) (getenv('COLUMNS') ?: 0);
        if ($cols > 0) {
            return $cols;
        }

        // 2. FFI ioctl via PosixBackend
        try {
            $backend = new \SugarCraft\Core\Util\Tty\PosixBackend(STDOUT);
            $size = $backend->size();
            if ($size['cols'] > 0) {
                return $size['cols'];
            }
        } catch (\Throwable) {
        }

        // 3. Shell fallback: stty size
        $stty = trim((string) shell_exec('stty size 2>/dev/null'));
        if ($stty !== '' && str_contains($stty, ' ')) {
            [$rows, $cols] = explode(' ', $stty, 2);
            if ((int) $cols > 0) {
                return (int) $cols;
            }
        }

        return 80;
    }

    /**
     * Get terminal height, trying multiple fallbacks.
     */
    private static function terminalHeight(): int
    {
        // 1. Environment variable
        $rows = (int) (getenv('LINES') ?: 0);
        if ($rows > 0) {
            return $rows;
        }

        // 2. FFI ioctl via PosixBackend
        try {
            $backend = new \SugarCraft\Core\Util\Tty\PosixBackend(STDOUT);
            $size = $backend->size();
            if ($size['rows'] > 0) {
                return $size['rows'];
            }
        } catch (\Throwable) {
        }

        // 3. Shell fallback
        $stty = trim((string) shell_exec('stty size 2>/dev/null'));
        if ($stty !== '' && str_contains($stty, ' ')) {
            [$rows, $cols] = explode(' ', $stty, 2);
            if ((int) $rows > 0) {
                return (int) $rows;
            }
        }

        return 24;
    }

    /**
     * Pad string to width on the right with spaces.
     *
     * Handles ANSI escape sequences by measuring stripped length
     * but preserving escapes in output. When content exceeds width,
     * returns the original string without truncation to avoid
     * corrupting ANSI escape sequences.
     */
    private static function padRight(string $s, int $width): string
    {
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $s);
        $len = mb_strlen($stripped);
        if ($len >= $width) {
            // Content too wide — return as-is to avoid corrupting ANSI sequences
            // by mid-sequence truncation. Panes should already be sized correctly.
            return $s;
        }

        return $s . str_repeat(' ', $width - $len);
    }

    /**
     * Center string within width, padding equally on both sides.
     *
     * When content exceeds width, returns the original string without
     * truncation to avoid corrupting ANSI escape sequences.
     */
    private static function padCenter(string $s, int $width): string
    {
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $s);
        $len = mb_strlen($stripped);
        if ($len >= $width) {
            // Content too wide — return as-is to avoid corrupting ANSI sequences
            return $s;
        }

        $pad = $width - $len;
        $left = (int) floor($pad / 2);
        $right = $pad - $left;

        return str_repeat(' ', $left) . $s . str_repeat(' ', $right);
    }
}
