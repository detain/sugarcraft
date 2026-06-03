<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Debug;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Color;
use SugarCraft\Query\Admin\PageBase;
use SugarCraft\Query\Admin\QueryLogger;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Sprinkles\Style;

/**
 * Debug pane displaying the query/action log from QueryLogger.
 *
 * Shows timestamp, query type, SQL (truncated if long), row count,
 * and error message if any. The log is displayed in reverse chronological
 * order (newest first) with a distinct monospace style to make it clear
 * this is debug output.
 *
 * Keyboard shortcuts:
 *   [j/k]     - navigate entries down/up
 *   [c]       - clear the log
 *   [q]       - quit to previous view
 *
 * @see QueryLogger
 */
final class DebugPage extends PageBase
{
    /** Maximum SQL length to display before truncation. */
    private const MAX_SQL_DISPLAY = 120;

    /** Number of log entries to show per page. */
    private const PAGE_SIZE = 50;

    private int $cursor = 0;
    private int $scrollOffset = 0;

    public function __construct(
        ServerContextInterface $context,
    ) {
        parent::__construct($context);
    }

    /**
     * Create a new DebugPage from the server context.
     */
    public static function new(ServerContextInterface $context): self
    {
        return new self($context);
    }

    /**
     * Validate that we can access the log.
     */
    protected function validate(): bool
    {
        return true;
    }

    /**
     * Build the debug page output.
     */
    protected function build(): string
    {
        $entries = QueryLogger::getEntries();

        $lines = [];
        $lines[] = $this->renderHeader();
        $lines[] = '';
        $lines[] = $this->renderLogEntries($entries);
        $lines[] = '';
        $lines[] = $this->renderFooter();

        return \implode("\n", $lines);
    }

    /**
     * Handle keyboard shortcuts for navigation and clearing.
     *
     * @return array{0: self, 1: ?\SugarCraft\Core\Cmd}
     */
    public function update(Msg $msg): array
    {
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }

        $ch = $msg->rune ?? '';
        $type = $msg->type;

        // j/k or arrows navigate entries
        if ($ch === 'j' || $type === KeyType::Down) {
            return [$this->withNavigateDown(), null];
        }

        if ($ch === 'k' || $type === KeyType::Up) {
            return [$this->withNavigateUp(), null];
        }

        // c clears the log
        if ($ch === 'c') {
            QueryLogger::clear();
            return [$this->withResetCursor(), null];
        }

        // q quits (handled by parent controller)
        if ($ch === 'q') {
            return [$this->withQuit(), null];
        }

        return [$this, null];
    }

    // ─── Wither Methods ───────────────────────────────────────────────────────

    /**
     * Return a new instance with cursor moved down.
     */
    private function withNavigateDown(): self
    {
        $entries = QueryLogger::getEntries();
        $maxIndex = \count($entries) - 1;

        $clone = clone $this;
        if ($clone->cursor < $maxIndex) {
            $clone->cursor++;
            // Auto-scroll when cursor goes past visible window
            if ($clone->cursor > $clone->scrollOffset + self::PAGE_SIZE - 1) {
                $clone->scrollOffset = $clone->cursor - self::PAGE_SIZE + 1;
            }
        }
        return $clone;
    }

    /**
     * Return a new instance with cursor moved up.
     */
    private function withNavigateUp(): self
    {
        $clone = clone $this;
        if ($clone->cursor > 0) {
            $clone->cursor--;
            // Auto-scroll up when cursor goes before visible window
            if ($clone->cursor < $clone->scrollOffset) {
                $clone->scrollOffset = \max(0, $clone->cursor);
            }
        }
        return $clone;
    }

    /**
     * Return a new instance with cursor reset to top.
     */
    private function withResetCursor(): self
    {
        $clone = clone $this;
        $clone->cursor = 0;
        $clone->scrollOffset = 0;
        return $clone;
    }

    /**
     * Return a clone (quit is handled by parent controller).
     */
    public function withQuit(): self
    {
        return clone $this;
    }

    // ─── Rendering ───────────────────────────────────────────────────────────

    private function renderHeader(): string
    {
        $entryCount = \count(QueryLogger::getEntries());
        $title = Style::new()->bold()->foreground(Color::hex('#f59e0b'))->render('Debug Log');

        $counter = Style::new()->foreground(Color::hex('#6b7280'))->render(" ({$entryCount} entries)");

        return "{$title}{$counter}";
    }

    /**
     * Render the log entries as a scrollable list.
     *
     * @param list<array{timestamp: float, type: string, sql: string, rows: int, error: string|null}> $entries
     */
    private function renderLogEntries(array $entries): string
    {
        if ($entries === []) {
            return Style::new()->foreground(Color::ansi(8))->render('  (no log entries)');
        }

        // Reverse to show newest first
        $reversed = \array_reverse($entries);
        $visibleEntries = \array_slice($reversed, $this->scrollOffset, self::PAGE_SIZE);

        $lines = [];
        foreach ($visibleEntries as $index => $entry) {
            $globalIndex = $this->scrollOffset + $index;
            $isSelected = $globalIndex === $this->cursor;
            $lines[] = $this->renderLogEntry($entry, $isSelected);
        }

        // Show scroll indicator if there are more entries
        $totalEntries = \count($entries);
        $lastVisibleIndex = $this->scrollOffset + self::PAGE_SIZE - 1;

        if ($lastVisibleIndex < $totalEntries - 1) {
            $remaining = $totalEntries - $lastVisibleIndex - 1;
            $lines[] = '';
            $lines[] = Style::new()->foreground(Color::ansi(8))->render("  ↑ {$remaining} more entries");
        }

        return \implode("\n", $lines);
    }

    /**
     * Render a single log entry.
     *
     * @param array{timestamp: float, type: string, sql: string, rows: int, error: string|null} $entry
     */
    private function renderLogEntry(array $entry, bool $isSelected): string
    {
        $prefix = $isSelected ? '▶ ' : '  ';

        // Format timestamp
        $ts = date('H:i:s', (int) $entry['timestamp']);
        $ms = (int)(($entry['timestamp'] - floor($entry['timestamp'])) * 1000);
        $time = sprintf('%s.%03d', $ts, $ms);
        $timeStyled = Style::new()->foreground(Color::hex('#6b7280'))->render($time);

        // Format type (padded)
        $type = \str_pad($entry['type'], 12);

        // Color code by type
        $typeColor = match ($entry['type']) {
            'error' => Color::hex('#f38ba8'),
            'status', 'server' => Color::hex('#89b4fa'),
            default => Color::hex('#a6e3a1'),
        };
        $typeStyled = Style::new()->foreground($typeColor)->render($type);

        // Truncate SQL if needed
        $sql = $entry['sql'];
        if (\strlen($sql) > self::MAX_SQL_DISPLAY) {
            $sql = \substr($sql, 0, self::MAX_SQL_DISPLAY - 3) . '...';
        }
        // Use a distinct monospace style for SQL
        $sqlStyled = Style::new()->foreground(Color::hex('#cdd6f4'))->render($sql);

        // Row count
        $rowsStr = $entry['rows'] > 0 ? Style::new()->foreground(Color::hex('#6b7280'))->render(" [{$entry['rows']} rows]") : '';

        // Error indicator
        $errorStr = '';
        if ($entry['error'] !== null) {
            $errorStr = ' ' . Style::new()->foreground(Color::hex('#f38ba8'))->render("⚠ {$entry['error']}");
        }

        // Build the line - selected entry gets a subtle highlight
        if ($isSelected) {
            $line = "{$prefix}{$timeStyled} {$typeStyled} {$sqlStyled}{$rowsStr}{$errorStr}";
            return Style::new()->render($line);
        }

        return "{$prefix}{$timeStyled} {$typeStyled} {$sqlStyled}{$rowsStr}{$errorStr}";
    }

    private function renderFooter(): string
    {
        return Style::new()->foreground(Color::hex('#6b7280'))
            ->render('[j/k] nav  [c] clear  [q] quit');
    }

    // ─── Accessors ───────────────────────────────────────────────────────────

    public function cursor(): int
    {
        return $this->cursor;
    }
}
