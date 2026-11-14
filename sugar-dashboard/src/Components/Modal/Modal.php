<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\Modal;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Foundation\ColorMode;

/**
 * A modal dialog overlay component.
 *
 * Features:
 * - Centered overlay dialog with backdrop
 * - Optional title header with border
 * - Main content area
 * - Optional close button (X)
 * - Customizable border style (single, double, rounded, bold)
 * - Customizable border and background colors
 * - Centered positioning within allocated dimensions
 *
 * Mirrors modal dialog UI concepts adapted to PHP with wither-style immutable setters.
 */
final class Modal implements \SugarCraft\Dashboard\Foundation\SizedItem
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly \SugarCraft\Dashboard\Foundation\Item|string $content,
        private readonly ?string $title = null,
        private readonly ?Color $borderColor = null,
        private readonly ?Color $bgColor = null,
        private readonly string $style = 'single',
        private readonly bool $showClose = true,
        private readonly string $closeLabel = '×',
    ) {}

    /**
     * Create a new modal with default styling.
     *
     * Default: single border style, purple border color, close button visible.
     */
    public static function new(\SugarCraft\Dashboard\Foundation\Item|string $content): self
    {
        return new self(
            content: $content,
            title: null,
            borderColor: Color::hex('#874BFD'),
            bgColor: Color::hex('#1E1E2E'),
            style: 'single',
            showClose: true,
            closeLabel: '×',
        );
    }

    /**
     * Create a modal with a title.
     */
    public static function titled(\SugarCraft\Dashboard\Foundation\Item|string $content, string $title): self
    {
        return new self(
            content: $content,
            title: $title,
            borderColor: Color::hex('#874BFD'),
            bgColor: Color::hex('#1E1E2E'),
            style: 'single',
            showClose: true,
            closeLabel: '×',
        );
    }

    /**
     * Set the allocated dimensions for this modal.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dashboard\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Get the style characters for the modal border.
     *
     * @return array{0:string, 1:string, 2:string, 3:string, 4:string, 5:string} top-left, top-right, bottom-left, bottom-right, horizontal, vertical
     */
    private function getStyleChars(): array
    {
        return match ($this->style) {
            'double' => ['╔', '╗', '╚', '╝', '═', '║'],
            'rounded' => ['╭', '╮', '╰', '╯', '─', '│'],
            'single' => ['┌', '┐', '└', '┘', '─', '│'],
            'bold' => ['┏', '┓', '┗', '┛', '━', '┃'],
            'empty' => [' ', ' ', ' ', ' ', ' ', ' '],
            default => ['┌', '┐', '└', '┘', '─', '│'],
        };
    }

    /**
     * Render the modal as a string.
     */
    public function render(): string
    {
        $allocW = $this->width ?? 40;
        $allocH = $this->height ?? 10;

        // Modal takes 80% of available space, minimum 20 chars wide
        $modalW = max(20, (int) floor($allocW * 0.8));
        $modalH = max(5, (int) floor($allocH * 0.8));

        // Center the modal
        $offsetX = (int) floor(($allocW - $modalW) / 2);
        $offsetY = (int) floor(($allocH - $modalH) / 2);

        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();

        $result = '';

        // Finding sugar-dashboard-061 (D-67): the border color opened ONCE
        // for the whole box, so every pad row and frame row shared a single
        // SGR span - a consumer slicing rows (overflow, scrollback capture)
        // lost or smeared the color mid-box. The color now opens and resets
        // INSIDE every painted frame row. An uncolored modal emits exactly
        // the same bytes as before (both parts collapse to '').
        $borderSgr = $this->borderColor !== null ? $this->borderColor->toFg(ColorMode::profile()) : '';
        $rowEnd = $this->borderColor !== null ? Ansi::reset() : '';

        // Finding sugar-dashboard-063 (D-68): bgColor was a public knob with
        // zero effect - stored, defaulted by new()/titled(), propagated by
        // every wither, never consulted. It now paints the INTERIOR content
        // rows cell-accurately: opened after the left wall, reset before the
        // right wall, so the bg spans exactly the padded cells of the line.
        // Frame rows stay the border color's scope. A null bgColor collapses
        // both parts to '' and the interior bytes are identical to before.
        $bgSgr = $this->bgColor !== null ? $this->bgColor->toBg(ColorMode::profile()) : '';
        $bgEnd = $bgSgr !== '' ? Ansi::reset() : '';

        // Top border with title or centered
        $result .= $borderSgr . $tl;
        if ($this->title !== null) {
            // Finding sugar-dashboard-060 (D-66): the dash padding around an
            // embedded title was unclamped arithmetic - a long title (or a
            // close label) at a floor width made the counts negative and
            // str_repeat threw ValueError. Truncate the title to the
            // interior budget first, then clamp every pad non-negative. At
            // healthy widths both legs are byte no-ops.
            $closeWidth = $this->showClose ? Width::string($this->closeLabel) : 0;
            $titleBudget = max(0, $modalW - 2 - $closeWidth - 3);
            $title = $this->title;
            $titleWidth = Width::string($title);
            if ($titleWidth > $titleBudget) {
                $title = Width::truncateAnsi($title, $titleBudget);
                $titleWidth = Width::string($title);
            }
            $paddingWidth = max(0, $modalW - 2 - $titleWidth - ($this->showClose ? $closeWidth + 1 : 0));
            $leftPad = (int) floor($paddingWidth / 2);
            $rightPad = $paddingWidth - $leftPad;

            $result .= str_repeat($h, $leftPad);
            $result .= ' ' . $title . ' ';
            if ($this->showClose) {
                $result .= str_repeat($h, max(0, $rightPad - $closeWidth - 1));
                $result .= $this->closeLabel;
            } else {
                $result .= str_repeat($h, $rightPad);
            }
        } else {
            if ($this->showClose) {
                // Finding sugar-dashboard-166 (D-67): the dash run reserved a
                // one-cell label no matter the real closeLabel, so any wider
                // label pushed the frame row past the modal budget (and the
                // build-loop truncation then ate the label's tail). Reserve
                // the label's CELLS so the assembled row is exactly modalW.
                $closeCells = Width::string($this->closeLabel);
                $result .= str_repeat($h, max(0, $modalW - 4 - $closeCells));
                $result .= ' ' . $this->closeLabel . ' ';
            } else {
                $result .= str_repeat($h, max(0, $modalW - 2));
            }
        }
        $result .= $tr . $rowEnd . "\n";

        // Content lines
        $contentLines = $this->renderContentLines($modalW - 2);
        foreach ($contentLines as $line) {
            $paddedLine = $borderSgr . $v . $bgSgr . $line . $bgEnd . $borderSgr . $v . $rowEnd;
            $result .= $paddedLine . "\n";
        }

        // Bottom border
        $result .= $borderSgr . $bl . str_repeat($h, $modalW - 2) . $br . $rowEnd;

        // Reset ANSI
        $result .= Ansi::reset();

        // Pad to allocated height with empty lines
        $renderedLines = explode("\n", rtrim($result, "\n"));
        $renderedCount = count($renderedLines);
        while ($renderedCount < $modalH) {
            array_unshift($renderedLines, '');
            $renderedCount++;
        }
        if ($renderedCount > $modalH) {
            // Finding sugar-dashboard-061 (D-67): the old array_shift loop
            // ate the TOP BORDER - and with it the title - whenever content
            // outgrew the modal box. Overflow now drops INTERIOR content
            // rows (from the tail) and keeps both frame rows: the box stays
            // a box. modalH has a floor of 5, so the modalH-2 interior
            // budget is always >= 3 and count > modalH >= 5 guarantees the
            // top/middle/bottom split exists.
            $interior = array_slice($renderedLines, 1, -1);
            $interior = array_slice($interior, 0, max(0, $modalH - 2));
            $renderedLines = array_merge(
                [$renderedLines[0]],
                $interior,
                [$renderedLines[$renderedCount - 1]]
            );
            $renderedCount = count($renderedLines);
        }

        // Build the full output with centering
        $output = [];
        for ($i = 0; $i < $offsetY; $i++) {
            $output[] = str_repeat(' ', $allocW);
        }

        foreach ($renderedLines as $line) {
            $lineWidth = Width::string($line);
            // Finding sugar-dashboard-061 (D-67): this cell width was read
            // and then DISCARDED - byte str_pad let a CJK row overrun the
            // modal budget by one cell per wide glyph, desyncing every
            // following column. Truncate over-wide rows to the cell budget
            // first, then pad by cells (Width::padRight is ANSI-aware);
            // ASCII rows stay byte-identical to the old str_pad.
            if ($lineWidth > $modalW) {
                $line = Width::truncateAnsi($line, $modalW);
                $lineWidth = $modalW;
            }
            // Finding sugar-dashboard-166 (D-66): with modalW at its 20-cell
            // floor inside an allocation narrower than 25, the centering
            // left pad and the residual gap both went negative - another
            // ValueError from str_repeat. Both clamped; healthy widths are
            // byte-identical. (Row-overflow TRUTH is D-67's cell budget leg.)
            $leftPad = max(0, (int) floor(($allocW - $modalW) / 2));
            $padding = str_repeat(' ', $leftPad);
            $output[] = $padding . Width::padRight($line, $modalW) . str_repeat(' ', max(0, $allocW - $leftPad - $modalW));
        }

        $outputCount = count($output);
        while ($outputCount < $allocH) {
            $output[] = str_repeat(' ', $allocW);
            $outputCount++;
        }

        return implode("\n", array_slice($output, 0, $allocH));
    }

    /**
     * Render content and return lines.
     *
     * @return list<string>
     */
    private function renderContentLines(int $contentWidth): array
    {
        if ($this->content instanceof \SugarCraft\Dashboard\Foundation\Item) {
            $contentToRender = $this->content;
            if ($contentToRender instanceof \SugarCraft\Dashboard\Foundation\Sizer) {
                $contentToRender = $contentToRender->setSize($contentWidth, 0);
            }
            $rendered = $contentToRender->render();

            if ($rendered === '') {
                return [str_repeat(' ', $contentWidth)];
            }

            $lines = explode("\n", $rendered);
            return array_map(function ($line) use ($contentWidth) {
                $lineWidth = Width::string($line);
                if ($lineWidth < $contentWidth) {
                    $line .= str_repeat(' ', $contentWidth - $lineWidth);
                }
                return $line;
            }, $lines);
        }

        // String content - simple word wrap
        if ($this->content === '') {
            return [str_repeat(' ', $contentWidth)];
        }

        $wrapped = $this->wrapText($this->content, $contentWidth);

        return array_map(function ($line) use ($contentWidth) {
            $lineWidth = Width::string($line);
            if ($lineWidth < $contentWidth) {
                $line .= str_repeat(' ', $contentWidth - $lineWidth);
            }
            return $line;
        }, $wrapped);
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
     * Calculate the natural dimensions of this modal.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function innerSize(): array
    {
        $w = $this->width ?? 40;
        $h = $this->height ?? 10;

        return [max(20, (int) floor($w * 0.8)), max(5, (int) floor($h * 0.8))];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the modal content.
     */
    public function withContent(\SugarCraft\Dashboard\Foundation\Item|string $content): self
    {
        $next = new self(
            content: $content,
            title: $this->title,
            borderColor: $this->borderColor,
            bgColor: $this->bgColor,
            style: $this->style,
            showClose: $this->showClose,
            closeLabel: $this->closeLabel,
        );
        // Finding sugar-dashboard-166 (D-67): withers rebuilt through the
        // constructor dropped the setSize allocation - the D-56/D-77
        // allocation-drop class, screened here per the D-96/D-98 doctrine.
        $next->width = $this->width;
        $next->height = $this->height;
        return $next;
    }

    /**
     * Set the modal title.
     */
    public function withTitle(?string $title): self
    {
        $next = new self(
            content: $this->content,
            title: $title,
            borderColor: $this->borderColor,
            bgColor: $this->bgColor,
            style: $this->style,
            showClose: $this->showClose,
            closeLabel: $this->closeLabel,
        );
        $next->width = $this->width; // D-67 allocation carry (finding 166)
        $next->height = $this->height;
        return $next;
    }

    /**
     * Set the border color.
     */
    public function withBorderColor(?Color $color): self
    {
        $next = new self(
            content: $this->content,
            title: $this->title,
            borderColor: $color,
            bgColor: $this->bgColor,
            style: $this->style,
            showClose: $this->showClose,
            closeLabel: $this->closeLabel,
        );
        $next->width = $this->width; // D-67 allocation carry (finding 166)
        $next->height = $this->height;
        return $next;
    }

    /**
     * Set the background color.
     */
    public function withBgColor(?Color $color): self
    {
        $next = new self(
            content: $this->content,
            title: $this->title,
            borderColor: $this->borderColor,
            bgColor: $color,
            style: $this->style,
            showClose: $this->showClose,
            closeLabel: $this->closeLabel,
        );
        $next->width = $this->width; // D-67 allocation carry (finding 166)
        $next->height = $this->height;
        return $next;
    }

    /**
     * Set the border style.
     */
    public function withStyle(string $style): self
    {
        $next = new self(
            content: $this->content,
            title: $this->title,
            borderColor: $this->borderColor,
            bgColor: $this->bgColor,
            style: $style,
            showClose: $this->showClose,
            closeLabel: $this->closeLabel,
        );
        $next->width = $this->width; // D-67 allocation carry (finding 166)
        $next->height = $this->height;
        return $next;
    }

    /**
     * Show or hide the close button.
     */
    public function withShowClose(bool $show): self
    {
        $next = new self(
            content: $this->content,
            title: $this->title,
            borderColor: $this->borderColor,
            bgColor: $this->bgColor,
            style: $this->style,
            showClose: $show,
            closeLabel: $this->closeLabel,
        );
        $next->width = $this->width; // D-67 allocation carry (finding 166)
        $next->height = $this->height;
        return $next;
    }

    /**
     * Set the close button label.
     */
    public function withCloseLabel(string $label): self
    {
        $next = new self(
            content: $this->content,
            title: $this->title,
            borderColor: $this->borderColor,
            bgColor: $this->bgColor,
            style: $this->style,
            showClose: $this->showClose,
            closeLabel: $label,
        );
        $next->width = $this->width; // D-67 allocation carry (finding 166)
        $next->height = $this->height;
        return $next;
    }
}
