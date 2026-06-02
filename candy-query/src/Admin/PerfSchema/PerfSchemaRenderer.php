<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\PerfSchema;

use SugarCraft\Query\Admin\Format;

/**
 * Renders Performance Schema configuration UI components.
 *
 * Provides rendering utilities for the PS tabbed interface including
 * tree views, tri-state indicators, and formatted lists.
 *
 * @see Mirrors mysql-workbench wb_admin_performance_schema_ui
 */
final class PerfSchemaRenderer
{
    /**
     * Render a tri-state indicator for enabled/disabled/mixed states.
     *
     * @param int $state -1 (disabled), 0 (mixed), 1 (enabled)
     */
    public static function tristate(int $state): string
    {
        return match ($state) {
            1 => "\x1b[32m[x]\x1b[0m",
            0 => "\x1b[33m[~]\x1b[0m",
            -1 => "\x1b[90m[ ]\x1b[0m",
            default => "\x1b[90m[?]\x1b[0m",
        };
    }

    /**
     * Render a boolean as enabled/disabled indicator.
     *
     * @param bool $enabled True for enabled, false for disabled
     */
    public static function enabled(bool $enabled): string
    {
        return $enabled ? "\x1b[32m[x]\x1b[0m" : "\x1b[90m[ ]\x1b[0m";
    }

    /**
     * Render a setup state label with appropriate color.
     *
     * @param string $state One of: 'fully', 'default', 'custom', 'disabled'
     */
    public static function stateLabel(string $state): string
    {
        return match ($state) {
            'fully' => "\x1b[32mFULLY\x1b[0m",
            'default' => "\x1b[33mDEFAULT\x1b[0m",
            'custom' => "\x1b[36mCUSTOM\x1b[0m",
            'disabled' => "\x1b[31mDISABLED\x1b[0m",
            default => "\x1b[90mUNKNOWN\x1b[0m",
        };
    }

    /**
     * Render a separator line.
     */
    public static function separator(): string
    {
        return "\x1b[36m──\x1b[0m" . str_repeat('─', 20);
    }

    /**
     * Render a section header with color.
     *
     * @param string $title The header title
     */
    public static function header(string $title): string
    {
        return "\x1b[1;35m{$title}\x1b[0m";
    }

    /**
     * Render a row with selection highlight.
     *
     * @param bool $isSelected Whether this row is selected
     * @param string $content The row content
     */
    public static function selectedRow(bool $isSelected, string $content): string
    {
        if ($isSelected) {
            return "\x1b[7m> \x1b[0m" . $content;
        }

        return '  ' . $content;
    }

    /**
     * Render an instrument name with path shortening for display.
     *
     * @param string $name Full instrument name
     * @param int $maxLength Maximum display length
     */
    public static function instrumentName(string $name, int $maxLength = 50): string
    {
        if (strlen($name) <= $maxLength) {
            return $name;
        }

        // Shorten by keeping the beginning and end
        $keepLength = $maxLength - 3;
        $startLength = (int) ($keepLength * 0.7);
        $endLength = $keepLength - $startLength;

        return substr($name, 0, $startLength) . '...'
            . ($endLength > 0 ? substr($name, -$endLength) : '');
    }

    /**
     * Render an actor display string.
     *
     * @param string $host Host pattern
     * @param string $user User pattern
     * @param string $role Role pattern
     */
    public static function actorDisplay(string $host, string $user, string $role): string
    {
        return sprintf('%s/%s/%s', $host, $user, $role);
    }

    /**
     * Render an object display string.
     *
     * @param string $objectType Object type
     * @param string $objectSchema Object schema
     * @param string $objectName Object name
     */
    public static function objectDisplay(string $objectType, string $objectSchema, string $objectName): string
    {
        return sprintf('%s:%s.%s', $objectType, $objectSchema, $objectName);
    }

    /**
     * Render a thread row.
     *
     * @param int $threadId Thread ID
     * @param string $name Thread name
     * @param string $type Thread type
     * @param string|null $user Processlist user
     * @param bool $isSelected Whether this row is selected
     */
    public static function threadRow(
        int $threadId,
        string $name,
        string $type,
        ?string $user,
        bool $isSelected = false,
    ): string {
        $prefix = $isSelected ? "\x1b[7m>\x1b[0m" : ' ';
        $nameDisplay = self::instrumentName($name, 28);

        return sprintf(
            '%s %-8d %-30s %-12s %-10s',
            $prefix,
            $threadId,
            $nameDisplay,
            $type,
            $user ?? '-'
        );
    }

    /**
     * Render a timer row.
     *
     * @param string $name Timer name
     * @param string $timerName Implementation name
     * @param float $scaleFactor Scale factor
     */
    public static function timerRow(
        string $name,
        string $timerName,
        float $scaleFactor,
    ): string {
        return sprintf('  %-12s %-20s %-15.2f', $name, $timerName, $scaleFactor);
    }

    /**
     * Render the tab bar.
     *
     * @param string $activeTab The currently active tab
     * @param array<string, string> $tabs Map of tab ID to label
     */
    public static function tabBar(string $activeTab, array $tabs): string
    {
        $parts = [];

        foreach ($tabs as $tabId => $label) {
            $isActive = $tabId === $activeTab;

            $tabStr = $isActive
                ? "\x1b[1;33m[{$label}]\x1b[0m"
                : "\x1b[90m[{$label}]\x1b[0m";

            $parts[] = $tabStr;
        }

        return '  ' . implode(' ', $parts);
    }

    /**
     * Render the footer with navigation hints and status.
     *
     * @param bool $isDirty Whether there are pending changes
     * @param int $dirtyCount Number of pending changes
     * @param bool $readOnly Whether in read-only mode
     */
    public static function footer(bool $isDirty, int $dirtyCount, bool $readOnly): string
    {
        $dirtyIndicator = $dirtyCount > 0
            ? sprintf(' \x1b[33mPending: %d change%s\x1b[0m', $dirtyCount, $dirtyCount === 1 ? '' : 's')
            : '';

        $readOnlyIndicator = $readOnly ? ' \x1b[31m[READ ONLY]\x1b[0m' : '';

        $navHint = '[j/k] nav  [Space] toggle  [Tab] tabs';
        $actionHint = $isDirty ? '  [c] commit  [r] revert' : '';
        $quitHint = '  [q] quit';

        return "\x1b[90m{$navHint}{$actionHint}{$quitHint}{$dirtyIndicator}{$readOnlyIndicator}\x1b[0m";
    }

    /**
     * Render the Easy Setup panel.
     *
     * @param string $state Current setup state
     * @param bool $readOnly Whether in read-only mode
     */
    public static function easySetupPanel(string $state, bool $readOnly): string
    {
        $lines = [];
        $lines[] = self::header('Easy Setup');
        $lines[] = self::separator();

        $stateLabel = self::stateLabel($state);
        $lines[] = sprintf('  Current State: %s', $stateLabel);
        $lines[] = '';

        if ($readOnly) {
            $lines[] = "\x1b[90m  (Read-only mode - no privileges to modify)\x1b[0m";
        } else {
            $lines[] = '  [1] Enable Full PS';
            $lines[] = '  [2] Disable PS';
            $lines[] = '  [3] Reset to Defaults';
        }

        $lines[] = '';
        $lines[] = "\x1b[90m  Default instruments: stage/%, statement/%, wait/%\x1b[0m";
        $lines[] = "\x1b[90m  Default consumers: events_statements_history, events_waits_history, etc.\x1b[0m";

        return implode("\n", $lines);
    }

    /**
     * Render a count summary line.
     *
     * @param string $itemType Type of items (e.g., "instruments", "consumers")
     * @param int $count Total count
     * @param int|null $dirtyCount Number of dirty items (optional)
     */
    public static function countSummary(string $itemType, int $count, ?int $dirtyCount = null): string
    {
        $base = sprintf("\x1b[90m  Total: %d %s\x1b[0m", $count, $itemType);

        if ($dirtyCount !== null && $dirtyCount > 0) {
            return $base . sprintf(' (\x1b[33m%d dirty\x1b[0m)', $dirtyCount);
        }

        return $base;
    }

    /**
     * Format a duration in human-readable form.
     *
     * Delegates to the shared {@see Format::duration()} helper.
     *
     * @param int $seconds Duration in seconds
     */
    public static function formatDuration(int $seconds): string
    {
        return Format::duration($seconds);
    }

    /**
     * Format a percentage with color.
     *
     * @param int $percentage Percentage value (0-100)
     */
    public static function formatPercentage(int $percentage): string
    {
        $color = match (true) {
            $percentage >= 80 => '32', // Green
            $percentage >= 50 => '33', // Yellow
            default => '31', // Red
        };

        return sprintf("\x1b[%sm%d%%\x1b[0m", $color, $percentage);
    }
}
