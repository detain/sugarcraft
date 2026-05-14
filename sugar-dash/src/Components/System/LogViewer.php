<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\System;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A scrollable log viewer component with severity-based colors.
 *
 * Features:
 * - Collection of log entries with severity levels
 * - Severity-based color coding (debug, info, warning, error)
 * - Optional timestamp display
 * - Scroll handling when entries exceed height
 * - Configurable visible line count
 * - Custom prefix per severity level
 *
 * Mirrors log viewer concepts adapted to PHP with wither-style immutable setters.
 */
final class LogViewer implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public const Debug = 'debug';
    public const Info = 'info';
    public const Warning = 'warning';
    public const Error = 'error';

    /**
     * @param list<array{message: string, severity: string, timestamp?: string|null}> $entries
     */
    public function __construct(
        private readonly array $entries,
        private readonly bool $showTimestamps = false,
        private readonly bool $showSeverityPrefix = true,
        private readonly bool $scrollToBottom = true,
        private readonly int $visibleLines = 10,
    ) {}

    /**
     * Create a new log viewer with the given entries.
     *
     * @param list<array{message: string, severity?: string, timestamp?: string|null}> $entries
     */
    public static function new(array $entries): self
    {
        return new self(
            entries: $entries,
            showTimestamps: false,
            showSeverityPrefix: true,
            scrollToBottom: true,
            visibleLines: 10,
        );
    }

    /**
     * Create a log viewer from a simple list of messages (all info level).
     *
     * @param list<string> $messages
     */
    public static function fromMessages(array $messages): self
    {
        $entries = array_map(fn(string $msg): array => [
            'message' => $msg,
            'severity' => self::Info,
            'timestamp' => null,
        ], $messages);

        return new self(
            entries: $entries,
            showTimestamps: false,
            showSeverityPrefix: true,
            scrollToBottom: true,
            visibleLines: 10,
        );
    }

    /**
     * Set the allocated dimensions for this log viewer.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the log viewer.
     */
    public function render(): string
    {
        $entries = $this->entries;

        if ($entries === []) {
            $useWidth = $this->width ?? 40;
            $useHeight = $this->height ?? $this->visibleLines;
            return str_repeat("\n", max(0, $useHeight - 1)) . str_repeat(' ', $useWidth);
        }

        $useHeight = $this->height ?? min(count($entries), $this->visibleLines);

        // Calculate scroll offset
        $totalEntries = count($entries);
        $scrollOffset = $this->scrollToBottom
            ? max(0, $totalEntries - $useHeight)
            : 0;

        $visibleEntries = array_slice($entries, $scrollOffset, $useHeight);
        $useWidth = $this->width ?? $this->calculateMaxWidth($entries);

        $result = [];
        foreach ($visibleEntries as $entry) {
            $result[] = $this->renderEntry($entry, $useWidth);
        }

        // Pad with empty lines if needed
        while (count($result) < $useHeight) {
            $result[] = str_repeat(' ', $useWidth);
        }

        return implode("\n", $result);
    }

    /**
     * Render a single log entry.
     */
    private function renderEntry(array $entry, int $width): string
    {
        $message = $entry['message'];
        $severity = $entry['severity'] ?? self::Info;
        $timestamp = $entry['timestamp'] ?? null;

        $color = $this->getSeverityColor($severity);
        $prefix = $this->showSeverityPrefix ? $this->getSeverityPrefix($severity) : '';

        $line = '';

        if ($this->showTimestamps && $timestamp !== null) {
            $line .= '[' . $timestamp . '] ';
        }

        if ($prefix !== '') {
            $line .= $prefix . ' ';
        }

        $line .= $message;

        // Truncate if needed
        $lineWidth = Width::string($line);
        if ($lineWidth > $width) {
            $line = mb_substr($line, 0, $width - 1, 'UTF-8') . '…';
        } elseif ($lineWidth < $width) {
            $line .= str_repeat(' ', $width - $lineWidth);
        }

        // Apply color
        if ($color !== null) {
            return $color->toFg(ColorProfile::TrueColor) . $line . Ansi::reset();
        }

        return $line;
    }

    /**
     * Get the color for a severity level.
     */
    private function getSeverityColor(string $severity): ?Color
    {
        return match ($severity) {
            self::Debug => Color::hex('#6B7280'),   // Gray
            self::Info => Color::hex('#3B82F6'),    // Blue
            self::Warning => Color::hex('#F59E0B'), // Yellow/Orange
            self::Error => Color::hex('#EF4444'),   // Red
            default => null,
        };
    }

    /**
     * Get the prefix for a severity level.
     */
    private function getSeverityPrefix(string $severity): string
    {
        return match ($severity) {
            self::Debug => '[DBG]',
            self::Info => '[INF]',
            self::Warning => '[WRN]',
            self::Error => '[ERR]',
            default => '[???]',
        };
    }

    /**
     * Calculate the maximum width among all entries.
     */
    private function calculateMaxWidth(array $entries): int
    {
        $maxWidth = 0;

        foreach ($entries as $entry) {
            $message = $entry['message'];
            $severity = $entry['severity'] ?? self::Info;
            $timestamp = $entry['timestamp'] ?? null;

            $width = 0;

            if ($this->showTimestamps && $timestamp !== null) {
                $width += Width::string('[' . $timestamp . '] ');
            }

            if ($this->showSeverityPrefix) {
                $width += Width::string($this->getSeverityPrefix($severity) . ' ');
            }

            $width += Width::string($message);

            if ($width > $maxWidth) {
                $maxWidth = $width;
            }
        }

        return $maxWidth;
    }

    /**
     * Calculate the natural dimensions of this log viewer.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $w = $this->width ?? $this->calculateMaxWidth($this->entries);
        $h = count($this->entries);

        if ($this->height !== null && $this->height > 0) {
            $h = $this->height;
        } elseif ($this->visibleLines > 0) {
            $h = min($h, $this->visibleLines);
        }

        return [$w, $h];
    }

    /**
     * Get the number of entries.
     */
    public function getEntryCount(): int
    {
        return count($this->entries);
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the log entries.
     *
     * @param list<array{message: string, severity?: string, timestamp?: string|null}> $entries
     */
    public function withEntries(array $entries): self
    {
        return new self(
            entries: $entries,
            showTimestamps: $this->showTimestamps,
            showSeverityPrefix: $this->showSeverityPrefix,
            scrollToBottom: $this->scrollToBottom,
            visibleLines: $this->visibleLines,
        );
    }

    /**
     * Add a new entry to the log.
     */
    public function withEntry(array $entry): self
    {
        return new self(
            entries: array_merge($this->entries, [$entry]),
            showTimestamps: $this->showTimestamps,
            showSeverityPrefix: $this->showSeverityPrefix,
            scrollToBottom: $this->scrollToBottom,
            visibleLines: $this->visibleLines,
        );
    }

    /**
     * Show or hide timestamps.
     */
    public function withShowTimestamps(bool $show): self
    {
        return new self(
            entries: $this->entries,
            showTimestamps: $show,
            showSeverityPrefix: $this->showSeverityPrefix,
            scrollToBottom: $this->scrollToBottom,
            visibleLines: $this->visibleLines,
        );
    }

    /**
     * Show or hide severity prefix.
     */
    public function withShowSeverityPrefix(bool $show): self
    {
        return new self(
            entries: $this->entries,
            showTimestamps: $this->showTimestamps,
            showSeverityPrefix: $show,
            scrollToBottom: $this->scrollToBottom,
            visibleLines: $this->visibleLines,
        );
    }

    /**
     * Set scroll behavior (true = bottom, false = top).
     */
    public function withScrollToBottom(bool $bottom): self
    {
        return new self(
            entries: $this->entries,
            showTimestamps: $this->showTimestamps,
            showSeverityPrefix: $this->showSeverityPrefix,
            scrollToBottom: $bottom,
            visibleLines: $this->visibleLines,
        );
    }

    /**
     * Set the number of visible lines.
     */
    public function withVisibleLines(int $lines): self
    {
        return new self(
            entries: $this->entries,
            showTimestamps: $this->showTimestamps,
            showSeverityPrefix: $this->showSeverityPrefix,
            scrollToBottom: $this->scrollToBottom,
            visibleLines: max(1, $lines),
        );
    }
}