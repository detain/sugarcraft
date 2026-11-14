<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\Modal;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Foundation\ColorMode;

/**
 * A slide-out drawer panel component.
 *
 * Features:
 * - Four positions: left, right, top, bottom
 * - Optional title header with border
 * - Main content area
 * - Optional footer section
 * - Customizable border style (single, double, rounded, bold)
 * - Customizable border color
 * - Configurable width (for left/right) or height (for top/bottom)
 *
 * Mirrors drawer/sidebar UI concepts adapted to PHP with wither-style immutable setters.
 */
final class Drawer implements \SugarCraft\Dashboard\Foundation\SizedItem
{
    private ?int $width = null;
    private ?int $height = null;

    public const POSITION_LEFT = 'left';
    public const POSITION_RIGHT = 'right';
    public const POSITION_TOP = 'top';
    public const POSITION_BOTTOM = 'bottom';

    public function __construct(
        private readonly \SugarCraft\Dashboard\Foundation\Item|string $content,
        private readonly string $position = self::POSITION_LEFT,
        private readonly ?int $size = null,
        private readonly ?string $title = null,
        private readonly ?Color $borderColor = null,
        private readonly string $style = 'single',
        private readonly \SugarCraft\Dashboard\Foundation\Item|string|null $footer = null,
    ) {}

    /**
     * Create a new drawer with default styling.
     *
     * Default: left position, single border style, purple border color.
     */
    public static function new(\SugarCraft\Dashboard\Foundation\Item|string $content, string $position = self::POSITION_LEFT): self
    {
        return new self(
            content: $content,
            position: $position,
            size: null,
            title: null,
            borderColor: Color::hex('#874BFD'),
            style: 'single',
            footer: null,
        );
    }

    /**
     * Create a left-positioned drawer.
     */
    public static function left(\SugarCraft\Dashboard\Foundation\Item|string $content): self
    {
        return self::new($content, self::POSITION_LEFT);
    }

    /**
     * Create a right-positioned drawer.
     */
    public static function right(\SugarCraft\Dashboard\Foundation\Item|string $content): self
    {
        return self::new($content, self::POSITION_RIGHT);
    }

    /**
     * Create a top-positioned drawer.
     */
    public static function top(\SugarCraft\Dashboard\Foundation\Item|string $content): self
    {
        return self::new($content, self::POSITION_TOP);
    }

    /**
     * Create a bottom-positioned drawer.
     */
    public static function bottom(\SugarCraft\Dashboard\Foundation\Item|string $content): self
    {
        return self::new($content, self::POSITION_BOTTOM);
    }

    /**
     * Set the allocated dimensions for this drawer.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dashboard\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Get the style characters for the drawer border.
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
     * Render the drawer as a string.
     */
    public function render(): string
    {
        $allocW = $this->width ?? 80;
        $allocH = $this->height ?? 24;

        // Calculate drawer dimensions based on position
        $drawerW = $this->position === self::POSITION_LEFT || $this->position === self::POSITION_RIGHT
            ? ($this->size ?? (int) floor($allocW * 0.3))
            : $allocW;
        $drawerH = $this->position === self::POSITION_TOP || $this->position === self::POSITION_BOTTOM
            ? ($this->size ?? (int) floor($allocH * 0.4))
            : $allocH;

        $drawerW = max(3, min($drawerW, $allocW));
        $drawerH = max(3, min($drawerH, $allocH));

        // Finding sugar-dashboard-062 (D-71): the border color was PREPENDED
        // to the result one breath before the twin branch RE-ASSIGNED $result
        // from scratch - the documented knob styled nothing at all. The color
        // now opens and resets INSIDE every painted row (the D-67 Modal
        // frame law). An uncolored drawer collapses both parts to '' and
        // keeps its historic bytes, single trailing reset included.
        $borderSgr = $this->borderColor !== null ? $this->borderColor->toFg(ColorMode::profile()) : '';
        $rowEnd = $this->borderColor !== null ? Ansi::reset() : '';

        $result = $this->renderBox(
            $drawerW,
            $drawerH,
            $this->renderSurfaceLines($this->content, $drawerW - 2),
            $borderSgr,
            $rowEnd,
        );

        // Reset ANSI
        $result .= Ansi::reset();

        return $result;
    }

    /**
     * Paint the drawer box from pre-rendered surfaces: top frame, title
     * header, content rows padded to the box budget, footer section, bottom
     * frame - each row colored inside its own span.
     *
     * Finding sugar-dashboard-062 (D-71): this replaces two byte-identical
     * twins (renderVerticalDrawer/renderHorizontalDrawer, one carrying a
     * dead foreach key). The positions differ only in how render() sizes
     * the box, never in how the box is painted, so all four orientations
     * now render from one code path.
     *
     * @param list<string> $contentLines
     */
    private function renderBox(int $drawerW, int $drawerH, array $contentLines, string $borderSgr, string $rowEnd): string
    {
        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();
        $contentWidth = $drawerW - 2;

        $rows = [$tl . str_repeat($h, $contentWidth) . $tr];

        $titleRows = 0;
        if ($this->title !== null) {
            // Finding sugar-dashboard-062 (D-71): the title was a
            // documented, withered, constructor-held surface that render()
            // never read. It now paints as the header row below the top
            // border, cell-truncated to the interior budget.
            $title = Width::truncateAnsi($this->title, $contentWidth);
            $rows[] = $v . Width::padRight($title, $contentWidth) . $v;
            $titleRows = 1;
        }

        $footerLines = $this->footer !== null ? $this->renderSurfaceLines($this->footer, $contentWidth) : [];

        // The header and the footer reserve rows inside the box: the
        // content shares what the frame leaves (finding 062 again).
        $budget = max(0, $drawerH - 2 - $titleRows - count($footerLines));

        foreach ($contentLines as $line) {
            $rows[] = $v . $line . $v;
        }
        for ($pad = $budget - count($contentLines); $pad > 0; $pad--) {
            $rows[] = $v . str_repeat(' ', $contentWidth) . $v;
        }
        foreach ($footerLines as $line) {
            $rows[] = $v . $line . $v;
        }

        $rows[] = $bl . str_repeat($h, $contentWidth) . $br;

        $out = '';
        foreach ($rows as $row) {
            $out .= $borderSgr . $row . $rowEnd . "\n";
        }

        return rtrim($out, "\n");
    }

    /**
     * Render a surface (content or footer) and return cell-padded lines.
     *
     * @return list<string>
     */
    private function renderSurfaceLines(\SugarCraft\Dashboard\Foundation\Item|string $surface, int $contentWidth): array
    {
        if ($surface instanceof \SugarCraft\Dashboard\Foundation\Item) {
            $contentToRender = $surface;
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

        // String surface - simple word wrap
        if ($surface === '') {
            return [str_repeat(' ', $contentWidth)];
        }

        $wrapped = $this->wrapText($surface, $contentWidth);

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
     * Calculate the natural dimensions of this drawer.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function innerSize(): array
    {
        $allocW = $this->width ?? 80;
        $allocH = $this->height ?? 24;

        $drawerW = $this->position === self::POSITION_LEFT || $this->position === self::POSITION_RIGHT
            ? ($this->size ?? (int) floor($allocW * 0.3))
            : $allocW;
        $drawerH = $this->position === self::POSITION_TOP || $this->position === self::POSITION_BOTTOM
            ? ($this->size ?? (int) floor($allocH * 0.4))
            : $allocH;

        return [max(3, min($drawerW, $allocW)), max(3, min($drawerH, $allocH))];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the drawer content.
     */
    public function withContent(\SugarCraft\Dashboard\Foundation\Item|string $content): self
    {
        $next = new self(
            content: $content,
            position: $this->position,
            size: $this->size,
            title: $this->title,
            borderColor: $this->borderColor,
            style: $this->style,
            footer: $this->footer,
        );
        // Finding sugar-dashboard-062 (D-71): every wither rebuilt through
        // the constructor and dropped the setSize allocation (the D-56/D-77
        // Modal class) - a configured drawer fell back to the default box
        // after any fluent change. All seven now carry it.
        $next->width = $this->width;
        $next->height = $this->height;
        return $next;
    }

    /**
     * Set the drawer position.
     */
    public function withPosition(string $position): self
    {
        $next = new self(
            content: $this->content,
            position: $position,
            size: $this->size,
            title: $this->title,
            borderColor: $this->borderColor,
            style: $this->style,
            footer: $this->footer,
        );
        $next->width = $this->width;
        $next->height = $this->height;
        return $next;
    }

    /**
     * Set the drawer size (width for left/right, height for top/bottom).
     */
    public function withSize(?int $size): self
    {
        $next = new self(
            content: $this->content,
            position: $this->position,
            size: $size,
            title: $this->title,
            borderColor: $this->borderColor,
            style: $this->style,
            footer: $this->footer,
        );
        $next->width = $this->width;
        $next->height = $this->height;
        return $next;
    }

    /**
     * Set the drawer title.
     */
    public function withTitle(?string $title): self
    {
        $next = new self(
            content: $this->content,
            position: $this->position,
            size: $this->size,
            title: $title,
            borderColor: $this->borderColor,
            style: $this->style,
            footer: $this->footer,
        );
        $next->width = $this->width;
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
            position: $this->position,
            size: $this->size,
            title: $this->title,
            borderColor: $color,
            style: $this->style,
            footer: $this->footer,
        );
        $next->width = $this->width;
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
            position: $this->position,
            size: $this->size,
            title: $this->title,
            borderColor: $this->borderColor,
            style: $style,
            footer: $this->footer,
        );
        $next->width = $this->width;
        $next->height = $this->height;
        return $next;
    }

    /**
     * Set the drawer footer.
     */
    public function withFooter(\SugarCraft\Dashboard\Foundation\Item|string|null $footer): self
    {
        $next = new self(
            content: $this->content,
            position: $this->position,
            size: $this->size,
            title: $this->title,
            borderColor: $this->borderColor,
            style: $this->style,
            footer: $footer,
        );
        $next->width = $this->width;
        $next->height = $this->height;
        return $next;
    }
}
