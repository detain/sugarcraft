<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;

/**
 * Keyboard-navigable session picker overlay for the SugarCrush TUI.
 *
 * Supports:
 * - Arrow keys (up/k, down/j) to browse sessions
 * - Enter to resume a session
 * - Space to preview a session without committing
 * - Escape to close the picker
 * - Filter that narrows the list to sessions tied to the current git branch
 *
 * Mirrors charmbracelet/crush session picker behavior.
 */
final class SessionPicker
{
    /** @var list<array{sessionId: string, sessionName: string, summary: string, gitBranch: string|null, lastActivity: string}> */
    private readonly array $sessions;

    /** Index of the currently selected session in the filtered list. */
    private readonly int $selectedIndex;

    /** Current branch filter - only show sessions tied to this branch, or null for no filter. */
    private readonly string|null $branchFilter;

    private const MAX_VISIBLE_SESSIONS = 15;

    /**
     * @param list<array{sessionId: string, sessionName: string, summary: string, gitBranch: string|null, lastActivity: string}> $sessions
     */
    private function __construct(array $sessions, int $selectedIndex = 0, string|null $branchFilter = null)
    {
        $this->sessions = $sessions;
        $this->selectedIndex = $selectedIndex;
        $this->branchFilter = $branchFilter;
    }

    /**
     * Create a new SessionPicker with sessions loaded.
     *
     * @param list<array{sessionId: string, sessionName: string, summary: string, gitBranch: string|null, lastActivity: string}> $sessions
     */
    public static function new(array $sessions): self
    {
        return new self($sessions, 0, null);
    }

    /**
     * Return the filtered sessions based on branch filter.
     *
     * @return list<array{sessionId: string, sessionName: string, summary: string, gitBranch: string|null, lastActivity: string}>
     */
    public function filteredSessions(): array
    {
        if ($this->branchFilter === null) {
            return $this->sessions;
        }

        return array_values(array_filter(
            $this->sessions,
            fn(array $s): bool => ($s['gitBranch'] ?? '') !== ''
                && $s['gitBranch'] === $this->branchFilter,
        ));
    }

    /**
     * Return the currently selected session data, or null if none selected.
     *
     * @return array{sessionId: string, sessionName: string, summary: string, gitBranch: string|null, lastActivity: string}|null
     */
    public function selectedSession(): array|null
    {
        $filtered = $this->filteredSessions();

        if ($this->selectedIndex < 0 || $this->selectedIndex >= count($filtered)) {
            return null;
        }

        return $filtered[$this->selectedIndex];
    }

    /**
     * Return the selected index.
     */
    public function selectedIndex(): int
    {
        return $this->selectedIndex;
    }

    /**
     * Return the current branch filter.
     */
    public function branchFilter(): string|null
    {
        return $this->branchFilter;
    }

    /**
     * Create a new SessionPicker with an updated selection index.
     */
    public function withSelectedIndex(int $index): self
    {
        $filtered = $this->filteredSessions();
        $maxIndex = max(0, count($filtered) - 1);

        // Clamp index to valid range
        if ($index < 0) {
            $newIndex = 0;
        } elseif ($index > $maxIndex) {
            $newIndex = $maxIndex;
        } else {
            $newIndex = $index;
        }

        return new self($this->sessions, $newIndex, $this->branchFilter);
    }

    /**
     * Create a new SessionPicker with an updated branch filter.
     */
    public function withBranchFilter(string|null $branch): self
    {
        // When filter changes, reset selection to first item
        return new self($this->sessions, 0, $branch);
    }

    /**
     * Create a new SessionPicker with sessions replaced (e.g., reloaded from store).
     *
     * @param list<array{sessionId: string, sessionName: string, summary: string, gitBranch: string|null, lastActivity: string}> $sessions
     */
    public function withSessions(array $sessions): self
    {
        return new self($sessions, 0, $this->branchFilter);
    }

    /**
     * Render the session picker overlay.
     */
    public function render(int $width, int $height): string
    {
        $filtered = $this->filteredSessions();
        $selectedSession = $this->selectedSession();

        // Build header
        $header = $this->renderHeader($width);
        $headerHeight = substr_count($header, "\n") + 1;

        // Calculate visible range for scrolling
        $availableHeight = $height - $headerHeight - 4; // 4 for padding/borders
        $startIndex = $this->calculateScrollOffset(count($filtered), $availableHeight);
        $visibleSessions = array_slice($filtered, $startIndex, $availableHeight);

        // Build session list
        $lines = [];
        foreach ($visibleSessions as $i => $session) {
            $actualIndex = $startIndex + $i;
            $isSelected = $actualIndex === $this->selectedIndex;
            $lines[] = $this->renderSessionLine($session, $isSelected, $width);
        }

        if ($lines === []) {
            $lines[] = Style::new()
                ->foreground(Color::hex('#7d6e98'))
                ->render('  (no sessions)');
        }

        $body = implode("\n", $lines);

        // Wrap in border
        $st = Style::new()
            ->border(Border::rounded()->withTitle(' sessions '))
            ->borderForeground(Color::hex('#00ffaa'))
            ->padding(0, 1)
            ->width($width);

        // If no sessions match filter, show different styling
        if ($filtered === [] && $this->branchFilter !== null) {
            $st = $st->borderForeground(Color::hex('#fde68a'));
        }

        return $header . "\n" . $st->render($body) . $this->renderFooter($width, $selectedSession);
    }

    /**
     * Render the header with title and branch filter indicator.
     */
    private function renderHeader(int $width): string
    {
        $title = Style::new()
            ->foreground(Color::hex('#00ffaa'))
            ->bold()
            ->render(' session picker ');

        $filterText = '';
        if ($this->branchFilter !== null) {
            $filterText = '  ' . Style::new()
                ->foreground(Color::hex('#fde68a'))
                ->render('branch:') . ' ' . Style::new()
                ->foreground(Color::hex('#00ffaa'))
                ->render($this->branchFilter);
        }

        $controls = Style::new()
            ->foreground(Color::hex('#7d6e98'))
            ->render(' ↑↓ browse  ');
        $controls .= Style::new()
            ->foreground(Color::hex('#7d6e98'))
            ->render('↵ resume  ');
        $controls .= Style::new()
            ->foreground(Color::hex('#7d6e98'))
            ->render('space preview  ');
        $controls .= Style::new()
            ->foreground(Color::hex('#7d6e98'))
            ->render('esc close');

        $separator = str_repeat('─', max(0, $width - 2));

        return $title . $filterText . "\n" . $separator . "\n" . $controls;
    }

    /**
     * Render a single session line.
     *
     * @param array{sessionId: string, sessionName: string, summary: string, gitBranch: string|null, lastActivity: string} $session
     */
    private function renderSessionLine(array $session, bool $isSelected, int $width): string
    {
        $indicator = $isSelected ? '▶' : ' ';
        $nameStyle = $isSelected ? Color::hex('#00ffaa') : Color::hex('#c5b6dd');
        $metaStyle = Color::hex('#7d6e98');

        // Truncate name if needed
        $maxNameLen = 20;
        $name = $session['sessionName'];
        if (strlen($name) > $maxNameLen) {
            $name = substr($name, 0, $maxNameLen - 1) . '…';
        }

        // Show summary preview (first line, truncated)
        $summary = $session['summary'] ?? '';
        $maxSummaryLen = $width - 40; // Account for indicator, name, branch, padding
        if (strlen($summary) > $maxSummaryLen && $maxSummaryLen > 0) {
            $summary = substr($summary, 0, $maxSummaryLen - 2) . '…';
        }

        $branch = $session['gitBranch'] ?? null;
        $branchStr = $branch !== null ? ' @' . $branch : '';

        $displayLine = sprintf(
            '%s %s%s %s',
            $indicator,
            Style::new()->foreground($nameStyle)->render($name),
            $branchStr,
            Style::new()->foreground($metaStyle)->render($summary ? '· ' . $summary : ''),
        );

        return $displayLine;
    }

    /**
     * Render the footer with selected session details.
     */
    private function renderFooter(int $width, array|null $session): string
    {
        if ($session === null) {
            return '';
        }

        $summary = $session['summary'] ?? '(no summary)';
        $maxWidth = max(1, $width - 10);

        if (strlen($summary) > $maxWidth) {
            $summary = substr($summary, 0, $maxWidth - 3) . '…';
        }

        $footer = "\n" . Style::new()
            ->foreground(Color::hex('#7d6e98'))
            ->render('─'.str_repeat('─', $width - 2));
        $footer .= "\n" . Style::new()
            ->foreground(Color::hex('#c5b6dd'))
            ->render('  ' . $summary);

        return $footer;
    }

    /**
     * Calculate scroll offset to keep selected item visible.
     */
    private function calculateScrollOffset(int $totalItems, int $visibleHeight): int
    {
        if ($totalItems <= $visibleHeight) {
            return 0;
        }

        $maxOffset = $totalItems - $visibleHeight;

        // If selected is not within visible range, scroll to center it
        if ($this->selectedIndex < 0 || $this->selectedIndex >= $visibleHeight) {
            $idealOffset = $this->selectedIndex - (int) floor($visibleHeight / 2);
            return max(0, min($maxOffset, $idealOffset));
        }

        return 0;
    }

    /**
     * Handle a keypress and return the action taken.
     *
     * @return array{0: self, 1: string|null} [newPicker, action]
     *   action is 'browse' (arrow moved selection), 'resume' (enter pressed), 'preview' (space pressed), 'close' (escape pressed), or null
     */
    public function handleKey(string $key): array
    {
        return match ($key) {
            'up', 'k' => [$this->moveSelection(-1), 'browse'],
            'down', 'j' => [$this->moveSelection(1), 'browse'],
            'enter' => [$this, $this->selectedSession() !== null ? 'resume' : null],
            ' ' => [$this, $this->selectedSession() !== null ? 'preview' : null],
            'escape' => [$this, 'close'],
            'ctrl+b' => [$this->withBranchFilter($this->branchFilter === null ? $this->getCurrentGitBranch() : null), 'browse'],
            default => [$this, null],
        };
    }

    /**
     * Move selection up or down by delta positions.
     */
    private function moveSelection(int $delta): self
    {
        $newIndex = $this->selectedIndex + $delta;
        $filtered = $this->filteredSessions();
        $maxIndex = max(0, count($filtered) - 1);

        // Wrap around at boundaries
        if ($newIndex < 0) {
            $newIndex = $maxIndex;
        } elseif ($newIndex > $maxIndex) {
            $newIndex = 0;
        }

        return $this->withSelectedIndex($newIndex);
    }

    /**
     * Get the current git branch, or null if not in a git repo.
     */
    private function getCurrentGitBranch(): string|null
    {
        // Guard: must be in a git repo
        $revParse = @exec('git rev-parse --is-inside-work-tree 2>/dev/null');
        if ($revParse !== 'true') {
            return null;
        }

        $branch = @exec('git branch --show-current 2>/dev/null');
        if ($branch === false || $branch === '') {
            return null;
        }

        return trim($branch);
    }

    /**
     * Check if the session picker has any sessions to show.
     */
    public function isEmpty(): bool
    {
        return $this->filteredSessions() === [];
    }

    /**
     * Return the count of filtered sessions.
     */
    public function count(): int
    {
        return count($this->filteredSessions());
    }
}
