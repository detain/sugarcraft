<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Dash\Foundation\Drawable;
use SugarCraft\Dash\Foundation\Theme;

/**
 * A container that splits the available space between multiple components.
 *
 * Features:
 * - Horizontal (side-by-side) or vertical (stacked) splits
 * - Configurable ratios for dividing space
 * - Optional divider line between panes
 * - Support for 2 or more panes
 *
 * Mirrors split-pane/container patterns adapted to PHP with wither-style
 * immutable setters.
 */
final class Split implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        /**
         * @param list<Sizer> $panes The components to display in each pane
         */
        private readonly array $panes,
        private readonly SplitDirection $direction = SplitDirection::Horizontal,
        /**
         * @param list<float> $ratios The ratio of space for each pane (sum should equal 1.0)
         */
        private readonly array $ratios = [],
        private readonly ?Color $dividerColor = null,
        private readonly bool $showDividers = true,
        private readonly int $dividerSize = 1,
    ) {}

    /**
     * Create a new horizontal split (side-by-side panes).
     *
     * @param list<Sizer> $panes
     */
    public static function horizontal(array $panes, array $ratios = []): self
    {
        return new self(
            panes: $panes,
            direction: SplitDirection::Horizontal,
            ratios: $ratios,
            dividerColor: Color::hex('#6C7086'),
            showDividers: true,
            dividerSize: 1,
        );
    }

    /**
     * Create a new vertical split (stacked panes).
     *
     * @param list<Sizer> $panes
     */
    public static function vertical(array $panes, array $ratios = []): self
    {
        return new self(
            panes: $panes,
            direction: SplitDirection::Vertical,
            ratios: $ratios,
            dividerColor: Color::hex('#6C7086'),
            showDividers: true,
            dividerSize: 1,
        );
    }

    /**
     * Set the allocated dimensions for this split.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Calculate the natural dimensions of this split.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        if ($this->panes === []) {
            return [0, 0];
        }

        $useWidth = $this->width ?? 80;
        $useHeight = $this->height ?? 24;

        // Propagate size to panes
        $paneSizes = $this->computePaneSizes($useWidth, $useHeight);

        $maxWidth = 0;
        $maxHeight = 0;

        foreach ($this->panes as $index => $pane) {
            [$pW, $pH] = $paneSizes[$index] ?? [$useWidth, $useHeight];
            $pane = $pane->setSize($pW, $pH);
            [$pW2, $pH2] = $pane->getInnerSize();
            $maxWidth = max($maxWidth, $pW2);
            $maxHeight = max($maxHeight, $pH2);
        }

        return [$maxWidth, $maxHeight];
    }

    /**
     * Render the split container.
     */
    public function render(): string
    {
        if ($this->panes === []) {
            return '';
        }

        $useWidth = $this->width ?? 80;
        $useHeight = $this->height ?? 24;

        $paneSizes = $this->computePaneSizes($useWidth, $useHeight);

        // Render each pane
        $renderedPanes = [];
        foreach ($this->panes as $index => $pane) {
            [$pW, $pH] = $paneSizes[$index] ?? [$useWidth, $useHeight];
            $renderedPanes[] = $pane->setSize($pW, $pH)->render();
        }

        // Combine panes based on direction
        if ($this->direction === SplitDirection::Horizontal) {
            return $this->joinHorizontal($renderedPanes, $paneSizes);
        }

        return $this->joinVertical($renderedPanes, $paneSizes);
    }

    /**
     * Compute the size for each pane based on ratios.
     *
     * @return array<int, array{0:int, 1:int}>
     */
    private function computePaneSizes(int $totalWidth, int $totalHeight): array
    {
        $count = count($this->panes);
        if ($count === 0) {
            return [];
        }

        // Compute equal ratios if not provided
        $ratios = $this->ratios;
        if ($count === 1) {
            $ratios = [1.0];
        } elseif (empty($ratios)) {
            $ratios = array_fill(0, $count, 1.0 / $count);
        } else {
            // Normalize ratios to sum to 1
            $totalRatio = array_sum($ratios);
            if (abs($totalRatio - 1.0) > 0.001) {
                $ratios = array_map(fn($r) => $r / $totalRatio, $ratios);
            }
        }

        $sizes = [];
        if ($this->direction === SplitDirection::Horizontal) {
            $dividerSpace = $this->showDividers ? ($count - 1) * $this->dividerSize : 0;
            $availableWidth = $totalWidth - $dividerSpace;

            $offset = 0;
            for ($i = 0; $i < $count; $i++) {
                $paneWidth = (int) floor($availableWidth * $ratios[$i]);
                $sizes[$i] = [$paneWidth, $totalHeight];
                $offset += $paneWidth;
            }
        } else {
            $dividerSpace = $this->showDividers ? ($count - 1) * $this->dividerSize : 0;
            $availableHeight = $totalHeight - $dividerSpace;

            $offset = 0;
            for ($i = 0; $i < $count; $i++) {
                $paneHeight = (int) floor($availableHeight * $ratios[$i]);
                $sizes[$i] = [$totalWidth, $paneHeight];
                $offset += $paneHeight;
            }
        }

        return $sizes;
    }

    /**
     * Join panes horizontally with dividers.
     *
     * @param list<string> $panes
     * @param array<int, array{0:int, 1:int}> $paneSizes
     */
    private function joinHorizontal(array $panes, array $paneSizes): string
    {
        if (empty($panes)) {
            return '';
        }

        // Split each pane into lines
        $paneLines = [];
        $maxHeight = 0;
        foreach ($panes as $index => $pane) {
            $lines = explode("\n", $pane);
            $paneLines[] = $lines;
            $maxHeight = max($maxHeight, count($lines));
        }

        // Pad each pane to same height
        foreach ($paneLines as $index => $lines) {
            [$width] = $paneSizes[$index] ?? [80];
            while (count($lines) < $maxHeight) {
                $lines[] = str_repeat(' ', $width);
            }
            $paneLines[$index] = $lines;
        }

        // Build result row by row
        $result = [];
        for ($row = 0; $row < $maxHeight; $row++) {
            $rowParts = [];
            for ($col = 0; $col < count($paneLines); $col++) {
                $line = $paneLines[$col][$row] ?? '';
                [$width] = $paneSizes[$col] ?? [80];
                // Truncate or pad line to pane width
                if (mb_strlen($line, 'UTF-8') > $width) {
                    $line = mb_substr($line, 0, $width, 'UTF-8');
                } elseif (mb_strlen($line, 'UTF-8') < $width) {
                    $line = str_pad($line, $width, ' ');
                }
                $rowParts[] = $line;

                // Add divider
                if ($col < count($paneLines) - 1 && $this->showDividers) {
                    $divider = $this->renderVerticalDivider($width, $row, $maxHeight);
                    $rowParts[] = $divider;
                }
            }
            $result[] = implode('', $rowParts);
        }

        return implode("\n", $result);
    }

    /**
     * Join panes vertically with dividers.
     *
     * @param list<string> $panes
     * @param array<int, array{0:int, 1:int}> $paneSizes
     */
    private function joinVertical(array $panes, array $paneSizes): string
    {
        if (empty($panes)) {
            return '';
        }

        $result = [];
        $totalWidth = $this->width ?? 80;

        for ($i = 0; $i < count($panes); $i++) {
            $paneLines = explode("\n", $panes[$i]);

            // Pad/truncate each line to total width
            foreach ($paneLines as $line) {
                if (mb_strlen($line, 'UTF-8') > $totalWidth) {
                    $line = mb_substr($line, 0, $totalWidth, 'UTF-8');
                } elseif (mb_strlen($line, 'UTF-8') < $totalWidth) {
                    $line = str_pad($line, $totalWidth, ' ');
                }
                $result[] = $line;
            }

            // Add horizontal divider (except after last pane)
            if ($i < count($panes) - 1 && $this->showDividers) {
                $divider = $this->renderHorizontalDivider($totalWidth);
                $result[] = $divider;
            }
        }

        return implode("\n", $result);
    }

    /**
     * Render a vertical divider between horizontal panes.
     */
    private function renderVerticalDivider(int $paneWidth, int $row, int $maxHeight): string
    {
        $dividerChar = '│';
        return str_repeat($dividerChar, $this->dividerSize);
    }

    /**
     * Render a horizontal divider between vertical panes.
     */
    private function renderHorizontalDivider(int $width): string
    {
        $dividerChar = '─';
        return str_repeat($dividerChar, $width);
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the split direction.
     */
    public function withDirection(SplitDirection $direction): self
    {
        return new self(
            panes: $this->panes,
            direction: $direction,
            ratios: $this->ratios,
            dividerColor: $this->dividerColor,
            showDividers: $this->showDividers,
            dividerSize: $this->dividerSize,
        );
    }

    /**
     * Set the pane ratios.
     *
     * @param list<float> $ratios
     */
    public function withRatios(array $ratios): self
    {
        return new self(
            panes: $this->panes,
            direction: $this->direction,
            ratios: $ratios,
            dividerColor: $this->dividerColor,
            showDividers: $this->showDividers,
            dividerSize: $this->dividerSize,
        );
    }

    /**
     * Show or hide dividers between panes.
     */
    public function withShowDividers(bool $show): self
    {
        return new self(
            panes: $this->panes,
            direction: $this->direction,
            ratios: $this->ratios,
            dividerColor: $this->dividerColor,
            showDividers: $show,
            dividerSize: $this->dividerSize,
        );
    }

    /**
     * Set the divider color.
     */
    public function withDividerColor(?Color $color): self
    {
        return new self(
            panes: $this->panes,
            direction: $this->direction,
            ratios: $this->ratios,
            dividerColor: $color,
            showDividers: $this->showDividers,
            dividerSize: $this->dividerSize,
        );
    }

    /**
     * Apply a theme, fanning it down to any theme-aware children.
     */
    public function withTheme(Theme $theme): self
    {
        $themedPanes = [];
        foreach ($this->panes as $pane) {
            if ($pane instanceof Drawable) {
                $themedPanes[] = $pane->withTheme($theme);
            } else {
                $themedPanes[] = $pane;
            }
        }

        return new self(
            panes: $themedPanes,
            direction: $this->direction,
            ratios: $this->ratios,
            dividerColor: $this->dividerColor,
            showDividers: $this->showDividers,
            dividerSize: $this->dividerSize,
        );
    }
}
