<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\System;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A styled console output component.
 *
 * Features:
 * - Multiple stream types (stdout, stderr, info, success, warning, error, debug)
 * - Timestamp display (optional)
 * - Stream filtering
 * - Word-wrap support
 * - ANSI color codes
 *
 * Mirrors console output patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class Console implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /** @var list<ConsoleEntry> */
    private array $entries;

    /** @var list<ConsoleEntry> Original unfiltered entries */
    private array $originalEntries;

    public function __construct(
        array $entries = [],
        private readonly ?int $maxEntries = null,
        private readonly bool $showTimestamps = false,
        private readonly bool $wordWrap = true,
        private readonly ?ConsoleStream $minStream = null,
        private readonly bool $showPrefix = true,
        private readonly ?int $timestampWidth = null,
        private readonly ?Color $timestampColor = null,
        private readonly ?Color $prefixColor = null,
    ) {
        $this->originalEntries = $entries;
        $this->entries = $this->filterEntries($entries);
    }

    /**
     * Create a new console with default styling.
     */
    public static function new(): self
    {
        return new self(
            entries: [],
            maxEntries: null,
            showTimestamps: false,
            wordWrap: true,
            minStream: null,
            showPrefix: true,
            timestampWidth: null,
            timestampColor: Color::hex('#6C7086'),
            prefixColor: null,
        );
    }

    /**
     * Set the allocated dimensions for this console.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Filter entries by minimum stream level.
     *
     * @param list<ConsoleEntry> $entries
     * @return list<ConsoleEntry>
     */
    private function filterEntries(array $entries): array
    {
        if ($this->minStream === null) {
            return $entries;
        }

        $minOrder = $this->minStream->sortOrder();
        return array_values(array_filter(
            $entries,
            fn(ConsoleEntry $e) => $e->stream->sortOrder() >= $minOrder
        ));
    }

    /**
     * Render the console as a string.
     */
    public function render(): string
    {
        $contentWidth = $this->getContentWidth();

        if ($contentWidth <= 0 || $this->height !== null && $this->height <= 0) {
            return '';
        }

        $output = '';
        $maxLines = $this->height ?? count($this->entries);
        $timestampWidth = $this->calculateTimestampWidth();
        $prefixWidth = $this->showPrefix ? 8 : 0; // "[ERROR]" is 7 chars
        $availableWidth = $contentWidth - $timestampWidth - $prefixWidth - 2;

        $lineCount = 0;
        foreach ($this->entries as $entry) {
            if ($lineCount >= $maxLines) {
                break;
            }

            $lines = $this->renderEntry($entry, $timestampWidth, $prefixWidth, $availableWidth);
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
     * Render a single console entry.
     *
     * @return list<string>
     */
    private function renderEntry(
        ConsoleEntry $entry,
        int $timestampWidth,
        int $prefixWidth,
        int $messageWidth
    ): array {
        $color = $entry->color ?? $entry->stream->defaultColor();
        $prefix = $entry->stream->prefix();

        $timestampStr = '';
        if ($this->showTimestamps) {
            $timestampStr = str_pad('', $timestampWidth);
        }

        $prefixStr = '';
        if ($this->showPrefix && $prefix !== '') {
            $prefixStr = $this->showTimestamps ? str_pad($prefix, $prefixWidth) : $prefix;
            if ($this->prefixColor !== null) {
                $prefixStr = $this->prefixColor->toFg(ColorProfile::TrueColor) . $prefixStr . Ansi::reset();
            } else {
                $prefixStr = $color->toFg(ColorProfile::TrueColor) . $prefixStr . Ansi::reset();
            }
        }

        $colorStr = $color->toFg(ColorProfile::TrueColor);

        if ($this->wordWrap && $messageWidth > 0) {
            $messageLines = $this->wrapMessage($entry->message, $messageWidth);
            $result = [];

            foreach ($messageLines as $i => $messageLine) {
                $line = '';
                if ($timestampStr !== '') {
                    $line .= $timestampStr . ' ';
                }
                $line .= $prefixStr;
                if ($line !== '' && !str_ends_with($line, ' ')) {
                    $line .= ' ';
                }
                $line .= $colorStr . $messageLine . Ansi::reset();

                $result[] = $line;
            }

            return $result;
        }

        $message = mb_substr($entry->message, 0, $messageWidth, 'UTF-8');
        $line = $timestampStr;
        if ($line !== '' && !str_ends_with($line, ' ')) {
            $line .= ' ';
        }
        $line .= $prefixStr;
        if ($line !== '' && !str_ends_with($line, ' ')) {
            $line .= ' ';
        }
        $line .= $colorStr . $message . Ansi::reset();

        return [$line];
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
        if (!$this->showTimestamps) {
            return 0;
        }
        return $this->timestampWidth ?? 0;
    }

    /**
     * Get the content width for rendering.
     */
    private function getContentWidth(): int
    {
        return $this->width ?? 80;
    }

    /**
     * Calculate the natural dimensions of this console.
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
     * Add console entries.
     *
     * @param list<ConsoleEntry> $entries
     */
    public function withEntries(array $entries): self
    {
        $clone = clone $this;
        $clone->originalEntries = $entries;
        $clone->entries = $this->filterEntries($entries);
        return $clone;
    }

    /**
     * Add a single console entry.
     */
    public function withEntry(ConsoleEntry $entry): self
    {
        $clone = clone $this;
        $clone->originalEntries = array_merge($clone->originalEntries, [$entry]);
        $clone->entries = $this->filterEntries($clone->originalEntries);
        return $clone;
    }

    /**
     * Set the maximum number of entries to display.
     */
    public function withMaxEntries(?int $max): self
    {
        return new self(
            entries: $this->originalEntries,
            maxEntries: $max,
            showTimestamps: $this->showTimestamps,
            wordWrap: $this->wordWrap,
            minStream: $this->minStream,
            showPrefix: $this->showPrefix,
            timestampWidth: $this->timestampWidth,
            timestampColor: $this->timestampColor,
            prefixColor: $this->prefixColor,
        );
    }

    /**
     * Show or hide timestamps.
     */
    public function withTimestamps(bool $show): self
    {
        return new self(
            entries: $this->originalEntries,
            maxEntries: $this->maxEntries,
            showTimestamps: $show,
            wordWrap: $this->wordWrap,
            minStream: $this->minStream,
            showPrefix: $this->showPrefix,
            timestampWidth: $this->timestampWidth,
            timestampColor: $this->timestampColor,
            prefixColor: $this->prefixColor,
        );
    }

    /**
     * Enable or disable word-wrapping.
     */
    public function withWordWrap(bool $wordWrap): self
    {
        return new self(
            entries: $this->originalEntries,
            maxEntries: $this->maxEntries,
            showTimestamps: $this->showTimestamps,
            wordWrap: $wordWrap,
            minStream: $this->minStream,
            showPrefix: $this->showPrefix,
            timestampWidth: $this->timestampWidth,
            timestampColor: $this->timestampColor,
            prefixColor: $this->prefixColor,
        );
    }

    /**
     * Set the minimum stream level to display.
     */
    public function withMinStream(?ConsoleStream $stream): self
    {
        return new self(
            entries: $this->originalEntries,
            maxEntries: $this->maxEntries,
            showTimestamps: $this->showTimestamps,
            wordWrap: $this->wordWrap,
            minStream: $stream,
            showPrefix: $this->showPrefix,
            timestampWidth: $this->timestampWidth,
            timestampColor: $this->timestampColor,
            prefixColor: $this->prefixColor,
        );
    }

    /**
     * Show or hide stream prefixes.
     */
    public function withShowPrefix(bool $show): self
    {
        return new self(
            entries: $this->originalEntries,
            maxEntries: $this->maxEntries,
            showTimestamps: $this->showTimestamps,
            wordWrap: $this->wordWrap,
            minStream: $this->minStream,
            showPrefix: $show,
            timestampWidth: $this->timestampWidth,
            timestampColor: $this->timestampColor,
            prefixColor: $this->prefixColor,
        );
    }

    /**
     * Set the timestamp column width.
     */
    public function withTimestampWidth(?int $width): self
    {
        return new self(
            entries: $this->originalEntries,
            maxEntries: $this->maxEntries,
            showTimestamps: $this->showTimestamps,
            wordWrap: $this->wordWrap,
            minStream: $this->minStream,
            showPrefix: $this->showPrefix,
            timestampWidth: $width,
            timestampColor: $this->timestampColor,
            prefixColor: $this->prefixColor,
        );
    }

    /**
     * Set the timestamp color.
     */
    public function withTimestampColor(?Color $color): self
    {
        return new self(
            entries: $this->originalEntries,
            maxEntries: $this->maxEntries,
            showTimestamps: $this->showTimestamps,
            wordWrap: $this->wordWrap,
            minStream: $this->minStream,
            showPrefix: $this->showPrefix,
            timestampWidth: $this->timestampWidth,
            timestampColor: $color,
            prefixColor: $this->prefixColor,
        );
    }

    /**
     * Set the prefix color.
     */
    public function withPrefixColor(?Color $color): self
    {
        return new self(
            entries: $this->originalEntries,
            maxEntries: $this->maxEntries,
            showTimestamps: $this->showTimestamps,
            wordWrap: $this->wordWrap,
            minStream: $this->minStream,
            showPrefix: $this->showPrefix,
            timestampWidth: $this->timestampWidth,
            timestampColor: $this->timestampColor,
            prefixColor: $color,
        );
    }
}