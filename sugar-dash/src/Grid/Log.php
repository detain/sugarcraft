<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * Log entry severity levels.
 */
enum LogLevel: string
{
    case Debug = 'DEBUG';
    case Info = 'INFO';
    case Warn = 'WARN';
    case Error = 'ERROR';
    case Fatal = 'FATAL';

    /**
     * Get the default color for this severity level.
     */
    public function defaultColor(): Color
    {
        return match ($this) {
            self::Debug => Color::hex('#6C7086'),
            self::Info => Color::hex('#89B4FA'),
            self::Warn => Color::hex('#F9E2AF'),
            self::Error => Color::hex('#F38BA8'),
            self::Fatal => Color::hex('#EBA0AC'),
        };
    }

    /**
     * Get the sort order for this level (lower = more severe).
     */
    public function sortOrder(): int
    {
        return match ($this) {
            self::Debug => 0,
            self::Info => 1,
            self::Warn => 2,
            self::Error => 3,
            self::Fatal => 4,
        };
    }
}

/**
 * A log entry with timestamp, level, and message.
 */
final readonly class LogEntry
{
    public function __construct(
        public string $timestamp,
        public LogLevel $level,
        public string $message,
    ) {}

    /**
     * Create a new log entry.
     */
    public static function create(
        string $message,
        LogLevel $level = LogLevel::Info,
        ?string $timestamp = null,
    ): self {
        return new self(
            timestamp: $timestamp ?? date('Y-m-d H:i:s'),
            level: $level,
            message: $message,
        );
    }
}

/**
 * A log viewer component with severity level filtering and display.
 *
 * Displays log entries with timestamps, severity colors, and message
 * content. Supports filtering by minimum level, max entries limit,
 * and word-wrapping of long messages.
 *
 * Mirrors log viewer patterns from termui/logview but adapted to PHP
 * with wither-style immutable setters.
 */
final class Log implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /** @var list<LogEntry> */
    private array $entries;

    public function __construct(
        array $entries = [],
        private readonly ?int $maxEntries = null,
        private readonly ?LogLevel $minLevel = null,
        private readonly bool $showTimestamps = true,
        private readonly bool $wordWrap = true,
        private readonly ?int $timestampWidth = null,
        private readonly ?Color $timestampColor = null,
    ) {
        $this->entries = $this->filterAndSortEntries($entries);
    }

    /**
     * Create a new log viewer with default styling.
     */
    public static function new(): self
    {
        return new self(
            entries: [],
            maxEntries: null,
            minLevel: null,
            showTimestamps: true,
            wordWrap: true,
            timestampWidth: null,
            timestampColor: Color::hex('#6C7086'),
        );
    }

    /**
     * Set the allocated dimensions for this log viewer.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Filter entries by level and sort by timestamp.
     *
     * @param list<LogEntry> $entries
     * @return list<LogEntry>
     */
    private function filterAndSortEntries(array $entries): array
    {
        $filtered = $entries;

        // Filter by minimum level
        if ($this->minLevel !== null) {
            $minOrder = $this->minLevel->sortOrder();
            $filtered = array_values(array_filter(
                $filtered,
                fn(LogEntry $e) => $e->level->sortOrder() >= $minOrder
            ));
        }

        // Sort by timestamp descending (newest first)
        usort($filtered, fn(LogEntry $a, LogEntry $b) => $b->timestamp <=> $a->timestamp);

        // Limit entries
        if ($this->maxEntries !== null && count($filtered) > $this->maxEntries) {
            $filtered = array_slice($filtered, 0, $this->maxEntries);
        }

        return $filtered;
    }

    /**
     * Render the log viewer as a string.
     */
    public function render(): string
    {
        $contentWidth = $this->getContentWidth();

        if ($contentWidth <= 0 || $this->height !== null && $this->height <= 0) {
            return '';
        }

        $output = '';
        $maxLines = $this->height !== null ? $this->height : count($this->entries);
        $timestampWidth = $this->calculateTimestampWidth();
        $levelWidth = 5; // "FATAL" is longest
        $availableWidth = $contentWidth - $timestampWidth - $levelWidth - 3; // 3 for separators and spaces

        $lineCount = 0;
        foreach ($this->entries as $entry) {
            if ($lineCount >= $maxLines) {
                break;
            }

            $lines = $this->renderEntry($entry, $timestampWidth, $levelWidth, $availableWidth);
            foreach ($lines as $line) {
                if ($lineCount >= $maxLines) {
                    break;
                }
                if ($output !== '') {
                    $output .= "\n";
                }
                $output .= $line;
                $lineCount++;
            }
        }

        return $output;
    }

    /**
     * Render a single log entry.
     *
     * @return list<string>
     */
    private function renderEntry(
        LogEntry $entry,
        int $timestampWidth,
        int $levelWidth,
        int $messageWidth
    ): array {
        $levelColor = $entry->level->defaultColor();
        $levelStr = str_pad($entry->level->value, $levelWidth);

        $timestampStr = '';
        if ($this->showTimestamps) {
            $timestampStr = str_pad($entry->timestamp, $timestampWidth);
            if ($this->timestampColor !== null) {
                $timestampStr = $this->timestampColor->toFg(ColorProfile::TrueColor) . $timestampStr . Ansi::reset();
            }
        }

        $levelStr = $levelColor->toFg(ColorProfile::TrueColor) . $levelStr . Ansi::reset();

        if ($this->wordWrap && $messageWidth > 0) {
            $messageLines = $this->wrapMessage($entry->message, $messageWidth);
            $result = [];

            foreach ($messageLines as $i => $messageLine) {
                if ($i === 0) {
                    $result[] = $timestampStr . ' ' . $levelStr . ' ' . $messageLine;
                } else {
                    // Continuation lines have indentation
                    $indent = str_repeat(' ', $timestampWidth + $levelWidth + 3);
                    $result[] = $indent . $messageLine;
                }
            }

            return $result;
        }

        $message = mb_substr($entry->message, 0, $messageWidth, 'UTF-8');
        return [$timestampStr . ' ' . $levelStr . ' ' . $message];
    }

    /**
     * Wrap a message to fit within the given width.
     *
     * @return list<string>
     */
    private function wrapMessage(string $message, int $width): array
    {
        if ($width <= 0 || Width::string($message) <= $width) {
            return [$message];
        }

        $words = preg_split('/(\s+)/', $message, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($words === false) {
            return [$message];
        }

        $result = [];
        $currentLine = '';
        $currentWidth = 0;

        foreach ($words as $word) {
            $wordWidth = Width::string($word);

            if ($currentWidth > 0 && $currentWidth + $wordWidth > $width) {
                $result[] = $currentLine;
                $currentLine = $word;
                $currentWidth = $wordWidth;
            } else {
                $currentLine .= $word;
                $currentWidth += $wordWidth;
            }
        }

        if ($currentLine !== '') {
            $result[] = $currentLine;
        }

        return $result === [] ? [''] : $result;
    }

    /**
     * Calculate the timestamp column width.
     */
    private function calculateTimestampWidth(): int
    {
        if ($this->timestampWidth !== null) {
            return $this->timestampWidth;
        }

        if (!$this->showTimestamps) {
            return 0;
        }

        // Default timestamp format is "YYYY-MM-DD HH:MM:SS" = 19 chars
        return 19;
    }

    /**
     * Get the content width for rendering.
     */
    private function getContentWidth(): int
    {
        return $this->width ?? 0;
    }

    /**
     * Calculate the natural dimensions of this log viewer.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->width ?? 80;
        $height = count($this->entries);

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Add log entries to the viewer.
     *
     * @param list<LogEntry> $entries
     */
    public function withEntries(array $entries): self
    {
        $clone = clone $this;
        $clone->entries = $this->filterAndSortEntries($entries);
        return $clone;
    }

    /**
     * Add a single log entry.
     */
    public function withEntry(LogEntry $entry): self
    {
        $clone = clone $this;
        $newEntries = array_merge($clone->entries, [$entry]);
        $clone->entries = $this->filterAndSortEntries($newEntries);
        return $clone;
    }

    /**
     * Set the maximum number of entries to display.
     */
    public function withMaxEntries(?int $max): self
    {
        $clone = clone $this;
        $clone->entries = $this->filterAndSortEntries($clone->entries);
        // Re-apply max entries limit
        if ($max !== null && count($clone->entries) > $max) {
            $clone->entries = array_slice($clone->entries, 0, $max);
        }
        return new self(
            entries: $clone->entries,
            maxEntries: $max,
            minLevel: $this->minLevel,
            showTimestamps: $this->showTimestamps,
            wordWrap: $this->wordWrap,
            timestampWidth: $this->timestampWidth,
            timestampColor: $this->timestampColor,
        );
    }

    /**
     * Set the minimum log level to display.
     */
    public function withMinLevel(?LogLevel $level): self
    {
        return new self(
            entries: $this->entries,
            maxEntries: $this->maxEntries,
            minLevel: $level,
            showTimestamps: $this->showTimestamps,
            wordWrap: $this->wordWrap,
            timestampWidth: $this->timestampWidth,
            timestampColor: $this->timestampColor,
        );
    }

    /**
     * Show or hide timestamps.
     */
    public function withTimestamps(bool $show): self
    {
        return new self(
            entries: $this->entries,
            maxEntries: $this->maxEntries,
            minLevel: $this->minLevel,
            showTimestamps: $show,
            wordWrap: $this->wordWrap,
            timestampWidth: $this->timestampWidth,
            timestampColor: $this->timestampColor,
        );
    }

    /**
     * Enable or disable word-wrapping.
     */
    public function withWordWrap(bool $wordWrap): self
    {
        return new self(
            entries: $this->entries,
            maxEntries: $this->maxEntries,
            minLevel: $this->minLevel,
            showTimestamps: $this->showTimestamps,
            wordWrap: $wordWrap,
            timestampWidth: $this->timestampWidth,
            timestampColor: $this->timestampColor,
        );
    }

    /**
     * Set the timestamp column width.
     */
    public function withTimestampWidth(?int $width): self
    {
        return new self(
            entries: $this->entries,
            maxEntries: $this->maxEntries,
            minLevel: $this->minLevel,
            showTimestamps: $this->showTimestamps,
            wordWrap: $this->wordWrap,
            timestampWidth: $width,
            timestampColor: $this->timestampColor,
        );
    }

    /**
     * Set the timestamp color.
     */
    public function withTimestampColor(?Color $color): self
    {
        return new self(
            entries: $this->entries,
            maxEntries: $this->maxEntries,
            minLevel: $this->minLevel,
            showTimestamps: $this->showTimestamps,
            wordWrap: $this->wordWrap,
            timestampWidth: $this->timestampWidth,
            timestampColor: $color,
        );
    }
}
