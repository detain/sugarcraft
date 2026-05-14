<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Nav;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A simple pagination control component.
 *
 * Displays basic page navigation with minimal controls:
 * - Previous/Next buttons only
 * - Current page and total pages display
 * - Simple, compact design
 *
 * Mirrors pagination concept adapted to PHP with wither-style immutable setters.
 * Simpler alternative to the full-featured Pagination component.
 */
final class PaginationSimple implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly int $currentPage,
        private readonly int $totalPages,
        private readonly ?Color $activeColor = null,
        private readonly ?Color $inactiveColor = null,
        private readonly string $prevLabel = '‹',
        private readonly string $nextLabel = '›',
    ) {}

    /**
     * Create a new simple pagination with default styling.
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

        $result = '';

        // Previous button
        if ($currentPage > 1) {
            $result .= $this->renderButton($this->prevLabel, false);
        } else {
            $result .= $this->renderButton($this->prevLabel, true);
        }

        // Page indicator: "3 / 10"
        $pageIndicator = sprintf('%d / %d', $currentPage, $this->totalPages);
        if ($this->activeColor !== null) {
            $result .= ' ';
            $result .= $this->activeColor->toFg(ColorProfile::TrueColor);
            $result .= $pageIndicator;
            $result .= Ansi::reset();
        } else {
            $result .= ' ' . $pageIndicator;
        }

        // Next button
        if ($currentPage < $this->totalPages) {
            $result .= ' ' . $this->renderButton($this->nextLabel, false);
        } else {
            $result .= ' ' . $this->renderButton($this->nextLabel, true);
        }

        // Pad to allocated width if needed
        $useWidth = $this->width ?? 0;
        if ($useWidth > 0) {
            $resultWidth = Width::string($result);
            if ($resultWidth < $useWidth) {
                $result = str_pad($result, $useWidth, ' ', STR_PAD_RIGHT);
            }
        }

        return $result;
    }

    /**
     * Render a single button.
     */
    private function renderButton(string $label, bool $disabled): string
    {
        if ($disabled) {
            $color = $this->inactiveColor;
        } else {
            $color = $this->activeColor;
        }

        $result = '';

        if ($color !== null) {
            $result .= $color->toFg(ColorProfile::TrueColor);
        }

        $result .= '[' . $label . ']';

        if ($color !== null) {
            $result .= Ansi::reset();
        }

        return $result;
    }

    /**
     * Calculate the natural dimensions of this pagination.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        // Estimate: [‹] 1 / 10 [›] = about 12 chars
        $totalDigits = strlen((string) $this->totalPages);
        $width = 3 + $totalDigits * 2 + 4 + 3 + $totalDigits + 3; // prev + page + next + spaces

        $width = $this->width !== null ? max($this->width, $width) : $width;

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
            prevLabel: $this->prevLabel,
            nextLabel: $label,
        );
    }
}