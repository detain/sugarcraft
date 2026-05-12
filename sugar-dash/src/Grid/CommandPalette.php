<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A command palette component (Cmd+K style).
 *
 * Features:
 * - Modal overlay with search input
 * - Filtered list of commands/actions based on query
 * - Keyboard navigation between commands
 * - Display of keyboard shortcuts
 * - Fuzzy matching support
 *
 * Mirrors command palette UI concepts adapted to PHP with
 * wither-style immutable setters.
 */
final class CommandPalette implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param array<int, array{label: string, icon?: string, shortcut?: string, action?: string}> $commands
     */
    public function __construct(
        private readonly array $commands,
        private readonly string $query = '',
        private readonly int $selectedIndex = 0,
        private readonly ?Color $paletteColor = null,
        private readonly ?Color $selectedColor = null,
        private readonly ?Color $queryColor = null,
        private readonly ?Color $shortcutColor = null,
        private readonly string $placeholder = 'Type a command...',
        private readonly string $borderChar = '─',
        private readonly string $selectedChar = '▶',
    ) {}

    /**
     * Create a new command palette with default styling.
     */
    public static function new(array $commands): self
    {
        return new self(
            commands: $commands,
            query: '',
            selectedIndex: 0,
            paletteColor: Color::hex('#1E1E2E'),
            selectedColor: Color::hex('#3B82F6'),
            queryColor: Color::hex('#FFFFFF'),
            shortcutColor: Color::ansi(8),
            placeholder: 'Type a command...',
            borderChar: '─',
            selectedChar: '▶',
        );
    }

    /**
     * Set the allocated dimensions for this command palette.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Get filtered commands based on the current query.
     *
     * @return array<int, array{label: string, icon?: string, shortcut?: string, action?: string}>
     */
    public function getFilteredCommands(): array
    {
        if ($this->query === '') {
            return $this->commands;
        }

        $query = mb_strtolower($this->query);
        $filtered = [];

        foreach ($this->commands as $command) {
            $label = mb_strtolower($command['label']);
            if (str_contains($label, $query)) {
                $filtered[] = $command;
            }
        }

        return $filtered;
    }

    /**
     * Render the command palette component.
     */
    public function render(): string
    {
        $filtered = $this->getFilteredCommands();

        if (empty($filtered)) {
            return $this->renderEmpty();
        }

        return $this->renderPalette($filtered);
    }

    /**
     * Render the command palette with commands.
     *
     * @param array<int, array{label: string, icon?: string, shortcut?: string, action?: string}> $filtered
     */
    private function renderPalette(array $filtered): string
    {
        $lines = [];

        // Header with border
        $lines[] = $this->renderHeader();

        // Search input
        $lines[] = $this->renderSearchInput();

        // Header bottom border
        $lines[] = $this->renderInputBorder();

        // Commands
        $safeIndex = max(0, min($this->selectedIndex, count($filtered) - 1));
        $maxVisible = $this->height !== null ? max(0, $this->height - 4) : count($filtered);
        $maxVisible = min($maxVisible, count($filtered));

        $startIndex = max(0, $safeIndex - $maxVisible + 1);
        $visibleCommands = array_slice($filtered, $startIndex, $maxVisible);

        foreach ($visibleCommands as $index => $command) {
            $actualIndex = $startIndex + $index;
            $lines[] = $this->renderCommand($command, $actualIndex === $safeIndex);
        }

        // Bottom border
        $lines[] = $this->renderBorder();

        return implode("\n", $lines);
    }

    /**
     * Render the header bar.
     */
    private function renderHeader(): string
    {
        $header = 'Commands';
        if ($this->paletteColor !== null) {
            return $this->paletteColor->toFg(ColorProfile::TrueColor) . $header . Ansi::reset();
        }
        return $header;
    }

    /**
     * Render the search input line.
     */
    private function renderSearchInput(): string
    {
        $queryDisplay = $this->query !== '' ? $this->query : $this->placeholder;
        $prefix = '🔍 ';

        if ($this->query === '' && $this->queryColor !== null) {
            $queryDisplay = $this->placeholder;
        }

        $content = $prefix . $queryDisplay;

        if ($this->queryColor !== null) {
            $content = $this->queryColor->toFg(ColorProfile::TrueColor) . $content . Ansi::reset();
        }

        return $content;
    }

    /**
     * Render the input area bottom border.
     */
    private function renderInputBorder(): string
    {
        $width = $this->width ?? 40;
        $border = $this->renderBorderLine($width);
        return $border;
    }

    /**
     * Render a single command line.
     *
     * @param array{label: string, icon?: string, shortcut?: string, action?: string} $command
     */
    private function renderCommand(array $command, bool $isSelected): string
    {
        $label = $command['label'];
        $icon = $command['icon'] ?? ' ';
        $shortcut = $command['shortcut'] ?? '';

        $prefix = $isSelected ? $this->selectedChar . ' ' : '  ';
        $content = $prefix . $icon . ' ' . $label;

        if ($shortcut !== '') {
            $labelWidth = Width::string($label);
            $padding = max(1, 25 - $labelWidth);
            $content .= str_repeat(' ', $padding);
            $shortcutText = $shortcut;
            if ($this->shortcutColor !== null) {
                $shortcutText = $this->shortcutColor->toFg(ColorProfile::TrueColor) . $shortcut . Ansi::reset();
            }
            $content .= $shortcutText;
        }

        if ($isSelected && $this->selectedColor !== null) {
            $content = $this->selectedColor->toFg(ColorProfile::TrueColor) . $content . Ansi::reset();
        }

        return $content;
    }

    /**
     * Render an empty state when no commands match.
     */
    private function renderEmpty(): string
    {
        $lines = [];
        $lines[] = $this->renderHeader();
        $lines[] = $this->renderSearchInput();
        $lines[] = $this->renderInputBorder();
        $lines[] = '  No commands found';
        $lines[] = $this->renderBorder();

        return implode("\n", $lines);
    }

    /**
     * Render a border line.
     */
    private function renderBorder(): string
    {
        $width = $this->width ?? 40;
        return $this->renderBorderLine($width);
    }

    /**
     * Render a border line of specified width.
     */
    private function renderBorderLine(int $width): string
    {
        $border = str_repeat($this->borderChar, $width);

        if ($this->paletteColor !== null) {
            return $this->paletteColor->toFg(ColorProfile::TrueColor) . $border . Ansi::reset();
        }

        return $border;
    }

    /**
     * Calculate the natural dimensions of this command palette.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $filtered = $this->getFilteredCommands();

        // Calculate max width needed
        $maxWidth = 15; // Minimum width

        foreach ($filtered as $command) {
            $icon = $command['icon'] ?? ' ';
            $shortcut = $command['shortcut'] ?? '';
            // prefix + icon + space + label + padding + shortcut
            $cmdWidth = 2 + Width::string($icon) + 1 + Width::string($command['label']);
            if ($shortcut !== '') {
                $cmdWidth += max(1, 25 - Width::string($command['label'])) + Width::string($shortcut);
            }
            if ($cmdWidth > $maxWidth) {
                $maxWidth = $cmdWidth;
            }
        }

        // Account for header, search input, and borders
        $width = max($maxWidth, 25);
        $height = 4 + count($filtered); // header + search + top border + bottom border + commands

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the search query.
     */
    public function withQuery(string $query): self
    {
        return new self(
            commands: $this->commands,
            query: $query,
            selectedIndex: 0, // Reset selection when query changes
            paletteColor: $this->paletteColor,
            selectedColor: $this->selectedColor,
            queryColor: $this->queryColor,
            shortcutColor: $this->shortcutColor,
            placeholder: $this->placeholder,
            borderChar: $this->borderChar,
            selectedChar: $this->selectedChar,
        );
    }

    /**
     * Set the selected command index.
     */
    public function withSelectedIndex(int $index): self
    {
        $filtered = $this->getFilteredCommands();
        $maxIndex = max(0, count($filtered) - 1);

        return new self(
            commands: $this->commands,
            query: $this->query,
            selectedIndex: max(0, min($index, $maxIndex)),
            paletteColor: $this->paletteColor,
            selectedColor: $this->selectedColor,
            queryColor: $this->queryColor,
            shortcutColor: $this->shortcutColor,
            placeholder: $this->placeholder,
            borderChar: $this->borderChar,
            selectedChar: $this->selectedChar,
        );
    }

    /**
     * Set the commands.
     *
     * @param array<int, array{label: string, icon?: string, shortcut?: string, action?: string}> $commands
     */
    public function withCommands(array $commands): self
    {
        return new self(
            commands: $commands,
            query: $this->query,
            selectedIndex: min($this->selectedIndex, max(0, count($commands) - 1)),
            paletteColor: $this->paletteColor,
            selectedColor: $this->selectedColor,
            queryColor: $this->queryColor,
            shortcutColor: $this->shortcutColor,
            placeholder: $this->placeholder,
            borderChar: $this->borderChar,
            selectedChar: $this->selectedChar,
        );
    }

    /**
     * Set the palette background color.
     */
    public function withPaletteColor(?Color $color): self
    {
        return new self(
            commands: $this->commands,
            query: $this->query,
            selectedIndex: $this->selectedIndex,
            paletteColor: $color,
            selectedColor: $this->selectedColor,
            queryColor: $this->queryColor,
            shortcutColor: $this->shortcutColor,
            placeholder: $this->placeholder,
            borderChar: $this->borderChar,
            selectedChar: $this->selectedChar,
        );
    }

    /**
     * Set the selected item highlight color.
     */
    public function withSelectedColor(?Color $color): self
    {
        return new self(
            commands: $this->commands,
            query: $this->query,
            selectedIndex: $this->selectedIndex,
            paletteColor: $this->paletteColor,
            selectedColor: $color,
            queryColor: $this->queryColor,
            shortcutColor: $this->shortcutColor,
            placeholder: $this->placeholder,
            borderChar: $this->borderChar,
            selectedChar: $this->selectedChar,
        );
    }

    /**
     * Set the query text color.
     */
    public function withQueryColor(?Color $color): self
    {
        return new self(
            commands: $this->commands,
            query: $this->query,
            selectedIndex: $this->selectedIndex,
            paletteColor: $this->paletteColor,
            selectedColor: $this->selectedColor,
            queryColor: $color,
            shortcutColor: $this->shortcutColor,
            placeholder: $this->placeholder,
            borderChar: $this->borderChar,
            selectedChar: $this->selectedChar,
        );
    }

    /**
     * Set the shortcut text color.
     */
    public function withShortcutColor(?Color $color): self
    {
        return new self(
            commands: $this->commands,
            query: $this->query,
            selectedIndex: $this->selectedIndex,
            paletteColor: $this->paletteColor,
            selectedColor: $this->selectedColor,
            queryColor: $this->queryColor,
            shortcutColor: $color,
            placeholder: $this->placeholder,
            borderChar: $this->borderChar,
            selectedChar: $this->selectedChar,
        );
    }

    /**
     * Set the placeholder text.
     */
    public function withPlaceholder(string $placeholder): self
    {
        return new self(
            commands: $this->commands,
            query: $this->query,
            selectedIndex: $this->selectedIndex,
            paletteColor: $this->paletteColor,
            selectedColor: $this->selectedColor,
            queryColor: $this->queryColor,
            shortcutColor: $this->shortcutColor,
            placeholder: $placeholder,
            borderChar: $this->borderChar,
            selectedChar: $this->selectedChar,
        );
    }

    /**
     * Set custom border character.
     */
    public function withBorderChar(string $char): self
    {
        return new self(
            commands: $this->commands,
            query: $this->query,
            selectedIndex: $this->selectedIndex,
            paletteColor: $this->paletteColor,
            selectedColor: $this->selectedColor,
            queryColor: $this->queryColor,
            shortcutColor: $this->shortcutColor,
            placeholder: $this->placeholder,
            borderChar: $char,
            selectedChar: $this->selectedChar,
        );
    }

    /**
     * Set custom selected item character.
     */
    public function withSelectedChar(string $char): self
    {
        return new self(
            commands: $this->commands,
            query: $this->query,
            selectedIndex: $this->selectedIndex,
            paletteColor: $this->paletteColor,
            selectedColor: $this->selectedColor,
            queryColor: $this->queryColor,
            shortcutColor: $this->shortcutColor,
            placeholder: $this->placeholder,
            borderChar: $this->borderChar,
            selectedChar: $char,
        );
    }
}
