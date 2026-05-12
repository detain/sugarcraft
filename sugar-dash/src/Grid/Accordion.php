<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A collapsible accordion component with multiple sections.
 *
 * Features:
 * - Multiple collapsible sections
 * - Each section has a title/header and content
 * - Sections can be open or closed by default
 * - Optional section icons (expanded/collapsed)
 * - Customizable colors for headers and content
 * - Keyboard navigation support indicators
 *
 * Mirrors accordion UI concepts adapted to PHP with wither-style immutable setters.
 */
final class Accordion implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<array{title: string, content: Item|string, isOpen: bool}> $sections
     */
    public function __construct(
        private readonly array $sections,
        private readonly ?Color $headerColor = null,
        private readonly ?Color $contentColor = null,
        private readonly string $expandedIcon = '▼',
        private readonly string $collapsedIcon = '▶',
        private readonly bool $showBorder = true,
    ) {}

    /**
     * Create a new accordion with default styling.
     *
     * Default: first section expanded, purple header accent.
     */
    public static function new(array $sections): self
    {
        // Default: first section open, rest closed
        $sections = array_map(function (array $section, int $index): array {
            $section['isOpen'] = $section['isOpen'] ?? ($index === 0);
            return $section;
        }, $sections, array_keys($sections));

        return new self(
            sections: $sections,
            headerColor: Color::hex('#874BFD'),
            contentColor: null,
            expandedIcon: '▼',
            collapsedIcon: '▶',
            showBorder: true,
        );
    }

    /**
     * Create an accordion from simple title-content pairs.
     *
     * @param list<array{title: string, content: string}> $items
     */
    public static function fromPairs(array $items): self
    {
        $sections = array_map(function (array $item, int $index): array {
            return [
                'title' => $item['title'],
                'content' => $item['content'],
                'isOpen' => $index === 0,
            ];
        }, $items, array_keys($items));

        return new self(
            sections: $sections,
            headerColor: Color::hex('#874BFD'),
            contentColor: null,
            expandedIcon: '▼',
            collapsedIcon: '▶',
            showBorder: true,
        );
    }

    /**
     * Set the allocated dimensions for this accordion.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the accordion as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? $this->calculateNaturalWidth();
        $useWidth = max($useWidth, 5);
        $contentWidth = $useWidth - 2;

        $result = '';

        foreach ($this->sections as $index => $section) {
            $isOpen = $section['isOpen'];
            $title = $section['title'];
            $content = $section['content'];

            // Render section header
            $icon = $isOpen ? $this->expandedIcon : $this->collapsedIcon;
            $headerText = $icon . ' ' . $title;

            if ($this->showBorder) {
                if ($this->headerColor !== null) {
                    $result .= $this->headerColor->toFg(ColorProfile::TrueColor);
                }
                $result .= '┌' . $headerText . str_repeat('─', max(0, $contentWidth - Width::string($headerText))) . '┐' . "\n";
                if ($this->headerColor !== null) {
                    $result .= Ansi::reset();
                }
            } else {
                if ($this->headerColor !== null) {
                    $result .= $this->headerColor->toFg(ColorProfile::TrueColor);
                }
                $result .= $headerText . "\n";
                if ($this->headerColor !== null) {
                    $result .= Ansi::reset();
                }
            }

            // Render section content if expanded
            if ($isOpen) {
                if ($content instanceof Item) {
                    $contentToRender = $content;
                    if ($content instanceof Sizer) {
                        $contentToRender = $content->setSize($contentWidth, 0);
                    }
                    $rendered = $contentToRender->render();

                    if ($rendered !== '') {
                        $lines = explode("\n", $rendered);
                        foreach ($lines as $line) {
                            if ($this->showBorder) {
                                $result .= '│' . $line . str_repeat(' ', max(0, $contentWidth - Width::string($line))) . '│' . "\n";
                            } else {
                                $result .= '  ' . $line . "\n";
                            }
                        }
                    } else {
                        if ($this->showBorder) {
                            $result .= '│' . str_repeat(' ', $contentWidth) . '│' . "\n";
                        } else {
                            $result .= "\n";
                        }
                    }
                } else {
                    // String content with word wrap
                    $wrapped = $this->wrapText($content, $contentWidth);
                    foreach ($wrapped as $line) {
                        if ($this->showBorder) {
                            $result .= '│' . $line . str_repeat(' ', max(0, $contentWidth - Width::string($line))) . '│' . "\n";
                        } else {
                            $result .= '  ' . $line . "\n";
                        }
                    }
                }

                // Bottom of section
                if ($this->showBorder) {
                    $result .= '└' . str_repeat('─', $contentWidth) . '┘';
                    if ($index < count($this->sections) - 1) {
                        $result .= "\n";
                    }
                }
            } else {
                // Collapsed section - just show border underneath header
                if ($this->showBorder) {
                    $result .= '└' . str_repeat('─', $contentWidth) . '┘';
                    if ($index < count($this->sections) - 1) {
                        $result .= "\n";
                    }
                }
            }
        }

        // Reset ANSI
        $result .= Ansi::reset();

        return rtrim($result, "\n");
    }

    /**
     * Wrap text to fit within a given width.
     *
     * @return list<string>
     */
    private function wrapText(string $text, int $width): array
    {
        if ($width <= 0) {
            return [$text];
        }

        if ($text === '') {
            return [''];
        }

        $result = [];
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $currentLine = '';
        $currentWidth = 0;

        foreach ($words as $word) {
            $wordWidth = Width::string($word);

            if ($currentWidth > 0 && $currentWidth + 1 + $wordWidth > $width) {
                $result[] = $currentLine;
                $currentLine = $word;
                $currentWidth = $wordWidth;
            } else {
                if ($currentLine !== '') {
                    $currentLine .= ' ';
                    $currentWidth++;
                }
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
     * Calculate the natural width based on section titles and content.
     */
    private function calculateNaturalWidth(): int
    {
        $width = 10; // Minimum width

        foreach ($this->sections as $section) {
            $titleWidth = Width::string($section['title']) + 4; // +4 for icon and padding
            $width = max($width, $titleWidth);

            $content = $section['content'];
            if ($content instanceof Item) {
                if ($content instanceof Sizer) {
                    [$cw, ] = $content->getInnerSize();
                    $width = max($width, $cw + 2);
                }
            } else {
                $width = max($width, Width::string($content) + 2);
            }
        }

        return $width;
    }

    /**
     * Calculate the natural dimensions of this accordion.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $useWidth = $this->width ?? $this->calculateNaturalWidth();
        $useWidth = max($useWidth, 5);
        $contentWidth = $useWidth - 2;

        $rows = 0;

        foreach ($this->sections as $section) {
            $isOpen = $section['isOpen'];
            $content = $section['content'];

            // Header line
            $rows++;

            if ($isOpen) {
                // Content lines
                if ($content instanceof Item) {
                    if ($content instanceof Sizer) {
                        [, $h] = $content->getInnerSize();
                        $rows += max(1, $h);
                    } else {
                        $rows++;
                    }
                } else {
                    $wrapped = $this->wrapText($content, $contentWidth);
                    $rows += max(1, count($wrapped));
                }
            }

            if ($this->showBorder) {
                $rows++; // Section bottom border
            }
        }

        return [$useWidth, $rows];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the sections.
     *
     * @param list<array{title: string, content: Item|string, isOpen: bool}> $sections
     */
    public function withSections(array $sections): self
    {
        return new self(
            sections: $sections,
            headerColor: $this->headerColor,
            contentColor: $this->contentColor,
            expandedIcon: $this->expandedIcon,
            collapsedIcon: $this->collapsedIcon,
            showBorder: $this->showBorder,
        );
    }

    /**
     * Set which section is open by index.
     */
    public function withOpenSection(int $index): self
    {
        $sections = array_map(function (array $section, int $i) use ($index): array {
            $section['isOpen'] = $i === $index;
            return $section;
        }, $this->sections, array_keys($this->sections));

        return new self(
            sections: $sections,
            headerColor: $this->headerColor,
            contentColor: $this->contentColor,
            expandedIcon: $this->expandedIcon,
            collapsedIcon: $this->collapsedIcon,
            showBorder: $this->showBorder,
        );
    }

    /**
     * Set the header color.
     */
    public function withHeaderColor(?Color $color): self
    {
        return new self(
            sections: $this->sections,
            headerColor: $color,
            contentColor: $this->contentColor,
            expandedIcon: $this->expandedIcon,
            collapsedIcon: $this->collapsedIcon,
            showBorder: $this->showBorder,
        );
    }

    /**
     * Set the content color.
     */
    public function withContentColor(?Color $color): self
    {
        return new self(
            sections: $this->sections,
            headerColor: $this->headerColor,
            contentColor: $color,
            expandedIcon: $this->expandedIcon,
            collapsedIcon: $this->collapsedIcon,
            showBorder: $this->showBorder,
        );
    }

    /**
     * Set the expanded/collapsed icons.
     */
    public function withIcons(string $expanded, string $collapsed): self
    {
        return new self(
            sections: $this->sections,
            headerColor: $this->headerColor,
            contentColor: $this->contentColor,
            expandedIcon: $expanded,
            collapsedIcon: $collapsed,
            showBorder: $this->showBorder,
        );
    }

    /**
     * Show or hide the border.
     */
    public function withShowBorder(bool $show): self
    {
        return new self(
            sections: $this->sections,
            headerColor: $this->headerColor,
            contentColor: $this->contentColor,
            expandedIcon: $this->expandedIcon,
            collapsedIcon: $this->collapsedIcon,
            showBorder: $show,
        );
    }
}
