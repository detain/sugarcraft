<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Nav;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A pagination control component.
 *
 * Displays page navigation controls:
 * - Previous/Next buttons
 * - Page number display
 * - First/Last page buttons (optional)
 * - Ellipsis for large page ranges
 *
 * Mirrors the pagination concept from typical UI toolkits but adapted
 * to PHP with wither-style immutable setters.
 */
final class Pagination implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly int $currentPage,
        private readonly int $totalPages,
        private readonly ?Color $activeColor = null,
        private readonly ?Color $inactiveColor = null,
        private readonly bool $showFirstLast = true,
        private readonly bool $showPrevNext = true,
        private readonly string $prevLabel = '‹',
        private readonly string $nextLabel = '›',
    ) {}

    /**
     * Create a new pagination with default styling.
     *
     * Default: purple active page, gray inactive.
     */
    public static function new(int $currentPage, int $totalPages): self
    {
        return new self(
            currentPage: max(1, $currentPage),
            totalPages: max(1, $totalPages),
            activeColor: Color::hex('#874BFD'),
            inactiveColor: Color::ansi(8),
            showFirstLast: true,
            showPrevNext: true,
            prevLabel: '‹',
            nextLabel: '›',
        );
    }

    /**
     * Set the allocated dimensions for this pagination.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the pagination as a string.
     */
    public function render(): string
    {
        $currentPage = max(1, min($this->currentPage, $this->totalPages));

        $pages = $this->buildPageNumbers($currentPage);

        $result = '';
        $useWidth = $this->width ?? 0;

        // Previous button
        if ($this->showPrevNext) {
            if ($currentPage > 1) {
                $result .= $this->renderButton($this->prevLabel, false);
            } else {
                $result .= $this->renderButton($this->prevLabel, true);
            }
            $result .= ' ';
        }

        // First page button
        if ($this->showFirstLast) {
            if ($currentPage > 2) {
                $result .= $this->renderButton('1', false);
                if ($currentPage > 3) {
                    $result .= $this->renderEllipsis();
                }
            }
        }

        // Page numbers
        foreach ($pages as $pageNum) {
            $isActive = ($pageNum === $currentPage);
            $result .= $this->renderButton((string) $pageNum, false, $isActive);
            $result .= ' ';
        }

        // Last page button
        if ($this->showFirstLast) {
            if ($currentPage < $this->totalPages - 1) {
                if ($currentPage < $this->totalPages - 2) {
                    $result .= $this->renderEllipsis();
                }
                $result .= $this->renderButton((string) $this->totalPages, false);
                $result .= ' ';
            }
        }

        // Next button
        if ($this->showPrevNext) {
            if ($currentPage < $this->totalPages) {
                $result .= $this->renderButton($this->nextLabel, false);
            } else {
                $result .= $this->renderButton($this->nextLabel, true);
            }
        }

        // Trim trailing space and ensure ANSI reset
        $result = rtrim($result);

        // Pad to allocated width if needed
        if ($useWidth > 0) {
            $resultWidth = Width::string($result);
            if ($resultWidth < $useWidth) {
                $result .= str_repeat(' ', $useWidth - $resultWidth);
            }
        }

        if ($this->activeColor !== null || $this->inactiveColor !== null) {
            $result .= Ansi::reset();
        }

        return $result;
    }

    /**
     * Render a single page button.
     */
    private function renderButton(string $label, bool $disabled, bool $isActive = false): string
    {
        $color = $isActive
            ? $this->activeColor
            : ($disabled ? $this->inactiveColor : $this->activeColor);

        $result = '';

        if ($color !== null) {
            $result .= $color->toFg(ColorProfile::TrueColor);
        }

        if ($isActive) {
            $result .= '[' . $label . ']';
        } elseif ($disabled) {
            $result .= ' ' . $label . ' ';
        } else {
            $result .= $label;
        }

        if ($color !== null) {
            $result .= Ansi::reset();
        }

        return $result;
    }

    /**
     * Render an ellipsis.
     */
    private function renderEllipsis(): string
    {
        $color = $this->inactiveColor;
        $result = '';

        if ($color !== null) {
            $result .= $color->toFg(ColorProfile::TrueColor);
        }

        $result .= '...';

        if ($color !== null) {
            $result .= Ansi::reset();
        }

        return $result . ' ';
    }

    /**
     * Build the list of page numbers to display.
     *
     * @return list<int>
     */
    private function buildPageNumbers(int $currentPage): array
    {
        $total = $this->totalPages;

        if ($total <= 7) {
            // Show all pages
            return range(1, $total);
        }

        // Always include first and last
        // Show current page and adjacent pages
        $pages = [];

        if ($currentPage <= 3) {
            // Near the start
            $pages = [1, 2, 3, 4, 5];
        } elseif ($currentPage >= $total - 2) {
            // Near the end
            $pages = [$total - 4, $total - 3, $total - 2, $total - 1, $total];
        } else {
            // Middle
            $pages = [$currentPage - 1, $currentPage, $currentPage + 1];
        }

        return $pages;
    }

    /**
     * Calculate the natural dimensions of this pagination.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        // Estimate width based on total pages display
        $pageWidth = 0;

        if ($this->showPrevNext) {
            $pageWidth += Width::string($this->prevLabel . ' ' . $this->nextLabel);
        }

        if ($this->showFirstLast) {
            $pageWidth += Width::string('1 ... ' . $this->totalPages);
        } else {
            // Just page numbers
            $pageWidth += Width::string('[' . $this->totalPages . ']');
        }

        $width = $this->width !== null ? max($this->width, $pageWidth) : $pageWidth;

        return [$width, 1];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the current page.
     */
    public function withCurrentPage(int $page): self
    {
        return new self(
            currentPage: $page,
            totalPages: $this->totalPages,
            activeColor: $this->activeColor,
            inactiveColor: $this->inactiveColor,
            showFirstLast: $this->showFirstLast,
            showPrevNext: $this->showPrevNext,
            prevLabel: $this->prevLabel,
            nextLabel: $this->nextLabel,
        );
    }

    /**
     * Set the total pages.
     */
    public function withTotalPages(int $total): self
    {
        return new self(
            currentPage: $this->currentPage,
            totalPages: max(1, $total),
            activeColor: $this->activeColor,
            inactiveColor: $this->inactiveColor,
            showFirstLast: $this->showFirstLast,
            showPrevNext: $this->showPrevNext,
            prevLabel: $this->prevLabel,
            nextLabel: $this->nextLabel,
        );
    }

    /**
     * Set the active page color.
     */
    public function withActiveColor(?Color $color): self
    {
        return new self(
            currentPage: $this->currentPage,
            totalPages: $this->totalPages,
            activeColor: $color,
            inactiveColor: $this->inactiveColor,
            showFirstLast: $this->showFirstLast,
            showPrevNext: $this->showPrevNext,
            prevLabel: $this->prevLabel,
            nextLabel: $this->nextLabel,
        );
    }

    /**
     * Set the inactive page color.
     */
    public function withInactiveColor(?Color $color): self
    {
        return new self(
            currentPage: $this->currentPage,
            totalPages: $this->totalPages,
            activeColor: $this->activeColor,
            inactiveColor: $color,
            showFirstLast: $this->showFirstLast,
            showPrevNext: $this->showPrevNext,
            prevLabel: $this->prevLabel,
            nextLabel: $this->nextLabel,
        );
    }

    /**
     * Show or hide first/last buttons.
     */
    public function withShowFirstLast(bool $show): self
    {
        return new self(
            currentPage: $this->currentPage,
            totalPages: $this->totalPages,
            activeColor: $this->activeColor,
            inactiveColor: $this->inactiveColor,
            showFirstLast: $show,
            showPrevNext: $this->showPrevNext,
            prevLabel: $this->prevLabel,
            nextLabel: $this->nextLabel,
        );
    }

    /**
     * Show or hide prev/next buttons.
     */
    public function withShowPrevNext(bool $show): self
    {
        return new self(
            currentPage: $this->currentPage,
            totalPages: $this->totalPages,
            activeColor: $this->activeColor,
            inactiveColor: $this->inactiveColor,
            showFirstLast: $this->showFirstLast,
            showPrevNext: $show,
            prevLabel: $this->prevLabel,
            nextLabel: $this->nextLabel,
        );
    }

    /**
     * Set the previous button label.
     */
    public function withPrevLabel(string $label): self
    {
        return new self(
            currentPage: $this->currentPage,
            totalPages: $this->totalPages,
            activeColor: $this->activeColor,
            inactiveColor: $this->inactiveColor,
            showFirstLast: $this->showFirstLast,
            showPrevNext: $this->showPrevNext,
            prevLabel: $label,
            nextLabel: $this->nextLabel,
        );
    }

    /**
     * Set the next button label.
     */
    public function withNextLabel(string $label): self
    {
        return new self(
            currentPage: $this->currentPage,
            totalPages: $this->totalPages,
            activeColor: $this->activeColor,
            inactiveColor: $this->inactiveColor,
            showFirstLast: $this->showFirstLast,
            showPrevNext: $this->showPrevNext,
            prevLabel: $this->prevLabel,
            nextLabel: $label,
        );
    }
}
