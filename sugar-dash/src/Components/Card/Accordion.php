<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Card;

use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Dash\Layout\HAlign;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * An accordion component that displays collapsible sections.
 *
 * Each section has a title and content. Sections can be open or closed.
 * Closed sections show only the title with a collapse icon; open sections
 * show both title and content with an expand icon.
 *
 * Mirrors the accordion pattern from common TUI libraries.
 */
final class Accordion implements Sizer, Item
{
    /** @var list<array{title: string, content: string, isOpen: bool}> */
    private array $sections;

    private int $width = 80;
    private int $height = 24;

    private ?Color $headerColor = null;
    private string $collapsedIcon = '▶';
    private string $expandedIcon = '▼';
    private bool $showBorder = true;

    /**
     * @param list<array{title: string, content: string, isOpen?: bool}> $sections
     */
    public function __construct(array $sections)
    {
        $this->sections = array_map(function (array $section) use (&$first): array {
            $isFirst = !isset($first);
            $first = true;
            return [
                'title' => $section['title'],
                'content' => $section['content'],
                'isOpen' => $section['isOpen'] ?? $isFirst,
            ];
        }, $sections);
    }

    /**
     * Create a new accordion with the given sections.
     *
     * @param list<array{title: string, content: string, isOpen?: bool}> $sections
     */
    public static function new(array $sections): self
    {
        return new self($sections);
    }

    /**
     * Create an accordion from key-value pairs.
     *
     * @param list<array{title: string, content: string}> $pairs
     */
    public static function fromPairs(array $pairs): self
    {
        return new self(array_map(function (array $pair) use (&$first): array {
            $isFirst = !isset($first);
            $first = true;
            return [
                'title' => $pair['title'],
                'content' => $pair['content'],
                'isOpen' => $isFirst,
            ];
        }, $pairs));
    }

    /**
     * Set the size of this accordion.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Get the inner dimensions of this accordion.
     *
     * @return array{0: int, 1: int}
     */
    public function getInnerSize(): array
    {
        $innerWidth = $this->width - ($this->showBorder ? 2 : 0);
        $totalHeight = 0;

        foreach ($this->sections as $section) {
            // Header line
            $totalHeight += 1;

            // Content lines (only if open)
            if ($section['isOpen']) {
                $contentWidth = $innerWidth - ($this->showBorder ? 4 : 2);
                $contentLines = $this->wrapText($section['content'], $contentWidth);
                $totalHeight += count($contentLines);
            }
        }

        // Border top and bottom rows
        if ($this->showBorder) {
            $totalHeight += 2;
        }

        return [$innerWidth, $totalHeight];
    }

    /**
     * Set the header color.
     */
    public function withHeaderColor(Color $color): self
    {
        $clone = clone $this;
        $clone->headerColor = $color;
        return $clone;
    }

    /**
     * Set custom icons for collapsed/expanded states.
     *
     * @param string $collapsed Icon shown when section is collapsed (default: ▶)
     * @param string $expanded Icon shown when section is expanded (default: ▼)
     */
    public function withIcons(string $collapsed, string $expanded): self
    {
        $clone = clone $this;
        $clone->collapsedIcon = $collapsed;
        $clone->expandedIcon = $expanded;
        return $clone;
    }

    /**
     * Toggle border visibility.
     */
    public function withShowBorder(bool $show): self
    {
        $clone = clone $this;
        $clone->showBorder = $show;
        return $clone;
    }

    /**
     * Update sections.
     *
     * @param list<array{title: string, content: string, isOpen?: bool}> $sections
     */
    public function withSections(array $sections): self
    {
        $clone = clone $this;
        $clone->sections = array_map(function (array $section): array {
            return [
                'title' => $section['title'],
                'content' => $section['content'],
                'isOpen' => $section['isOpen'] ?? false,
            ];
        }, $sections);
        return $clone;
    }

    /**
     * Open a specific section by index.
     *
     * @param int $index Section index to open (0-based)
     */
    public function withOpenSection(int $index): self
    {
        $clone = clone $this;
        foreach ($clone->sections as $i => &$section) {
            $section['isOpen'] = ($i === $index);
        }
        return $clone;
    }

    /**
     * Render the accordion.
     */
    public function render(): string
    {
        $lines = [];
        $innerWidth = $this->width - ($this->showBorder ? 2 : 0);

        foreach ($this->sections as $section) {
            $isOpen = $section['isOpen'];
            $icon = $isOpen ? $this->expandedIcon : $this->collapsedIcon;

            // Header line
            $headerContent = $icon . ' ' . $section['title'];
            if ($this->showBorder) {
                $headerContent = '│ ' . $headerContent;
            }

            if ($this->headerColor !== null) {
                $headerContent = $this->headerColor->toFg(ColorProfile::TrueColor) . $headerContent . "\x1b[0m";
            }

            $lines[] = $headerContent;

            // Content lines (only if open)
            if ($isOpen) {
                $contentLines = $this->wrapText($section['content'], $innerWidth - ($this->showBorder ? 4 : 2));
                foreach ($contentLines as $contentLine) {
                    if ($this->showBorder) {
                        $contentLine = '│ ' . $contentLine;
                    }
                    $lines[] = $contentLine;
                }
            }
        }

        // Build output with optional border
        if ($this->showBorder) {
            $topBorder = '┌' . str_repeat('─', $this->width - 2) . '┐';
            $bottomBorder = '└' . str_repeat('─', $this->width - 2) . '┘';

            array_unshift($lines, $topBorder);
            $lines[] = $bottomBorder;
        }

        $output = implode("\n", $lines);

        // Ensure color reset at the very end of output
        if ($this->headerColor !== null) {
            $output .= "\x1b[0m";
        }

        return $output;
    }

    /**
     * Wrap text to fit within a given width.
     *
     * @return list<string>
     */
    private function wrapText(string $text, int $maxWidth): array
    {
        if ($maxWidth <= 0) {
            return [''];
        }

        $words = preg_split('/\s+/', $text);
        $lines = [];
        $currentLine = '';

        foreach ($words as $word) {
            // If word itself exceeds maxWidth, break it into chunks
            if (mb_strlen($word) > $maxWidth) {
                if ($currentLine !== '') {
                    $lines[] = $currentLine;
                    $currentLine = '';
                }
                // Break long word into pieces
                while (mb_strlen($word) > $maxWidth) {
                    $lines[] = mb_substr($word, 0, $maxWidth);
                    $word = mb_substr($word, $maxWidth);
                }
                $currentLine = $word;
                continue;
            }

            $testLine = $currentLine === '' ? $word : $currentLine . ' ' . $word;

            if (mb_strlen($testLine) <= $maxWidth) {
                $currentLine = $testLine;
            } else {
                if ($currentLine !== '') {
                    $lines[] = $currentLine;
                }
                $currentLine = $word;
            }
        }

        if ($currentLine !== '') {
            $lines[] = $currentLine;
        }

        return $lines ?: [''];
    }
}
