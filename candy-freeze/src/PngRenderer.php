<?php

declare(strict_types=1);

namespace SugarCraft\Freeze;

use SugarCraft\Core\Util\Ansi;

/**
 * Renders text (with optional ANSI styling) to a PNG image via GD.
 * Requires ext-gd.
 *
 * Mirrors charmbracelet/freeze's PNG output mode. Supports:
 *
 *  - macOS-style window-control "traffic lights" (`withWindow(true)`)
 *  - Padding around the code area
 *  - Optional rounded-corner frame
 *  - Optional drop shadow
 *  - Line numbers in the gutter
 *  - Inline ANSI SGR colour parsing — foreground colours from the
 *    rendered tokens become coloured text segments. Background
 *    colours and attribute flags (bold, italic, underline) are also
 *    honoured.
 *
 * Uses GD's built-in bitmap fonts (imagestring) for portability — no
 * TTF font file required.
 *
 * @warning GD bitmap fonts do not support Unicode; multi-byte characters
 *          (including emoji, CJK, and most non-Latin scripts) will render
 *          as garbage. Use {@see SvgRenderer} for non-ASCII content.
 */
final class PngRenderer
{
    /** Built-in font size mapping: font number => [width, height] */
    private const FONT_SIZE = [
        1 => [5, 8],
        2 => [6, 13],
        3 => [7, 13],
        4 => [8, 16],
        5 => [8, 16],
    ];

    private const DEFAULT_FONT = 5;

    /** @var \WeakMap<\GdImage, array<string, int>> Per-image colour cache */
    private \WeakMap $colorCache;

    public function __construct(
        public readonly Theme $theme       = new Theme(
            background:   '#0d1117',
            foreground:   '#c9d1d9',
            border:       '#30363d',
            shadow:       'rgba(0, 0, 0, 0.5)',
            lineNumber:   '#6e7681',
            windowRed:    '#ff5f56',
            windowYellow: '#ffbd2e',
            windowGreen:  '#27c93f',
        ),
        public readonly int $padding       = 24,
        public readonly bool $window       = true,
        public readonly bool $shadow       = true,
        public readonly bool $border       = true,
        public readonly bool $lineNumbers  = false,
        public readonly int $borderRadius  = 8,
        public readonly WindowStyle $windowStyle = WindowStyle::Macos,
    ) {
        $this->colorCache = new \WeakMap();
    }

    public static function dark():       self { return new self(theme: Theme::dark()); }
    public static function light():      self { return new self(theme: Theme::light()); }
    public static function dracula():    self { return new self(theme: Theme::dracula()); }
    public static function tokyoNight(): self { return new self(theme: Theme::tokyoNight()); }
    public static function nord():       self { return new self(theme: Theme::nord()); }

    public function withTheme(Theme $t):       self { return $this->copy(theme: $t); }
    public function withPadding(int $p):       self { return $this->copy(padding: max(0, $p)); }
    public function withWindow(bool $on):      self { return $this->copy(window: $on); }
    public function withShadow(bool $on):      self { return $this->copy(shadow: $on); }
    public function withBorder(bool $on):      self { return $this->copy(border: $on); }
    public function withLineNumbers(bool $on): self { return $this->copy(lineNumbers: $on); }
    public function withBorderRadius(int $r):  self { return $this->copy(borderRadius: max(0, $r)); }
    public function withWindowStyle(WindowStyle|string $style): self
    {
        $style = $style instanceof WindowStyle ? $style : WindowStyle::from($style);
        return $this->copy(windowStyle: $style);
    }

    /**
     * Render `$text` (which may contain ANSI escape sequences) to a
     * PNG image and return the bytes.
     *
     * @throws \RuntimeException if ext-gd is not loaded
     * @note Unicode content produces incorrect output with GD's built-in
     *       bitmap fonts. For syntax highlighted code with non-ASCII
     *       characters, use {@see SvgRenderer} instead.
     * @note Rendering is synchronous and CPU-bound. For very large
     *       screenshots, a future major version may offer streaming output.
     */
    public function render(string $text): string
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException(
                'ext-gd is required for PNG output. Install it or use SvgRenderer instead.'
            );
        }

        $lines = explode("\n", rtrim($text, "\n"));
        [$cellW, $cellH] = self::FONT_SIZE[self::DEFAULT_FONT];

        [$maxCols, $gutter, $contentWidth, $contentHeight, $headerHeight, $shadowMargin, $totalW, $totalH] =
            LayoutCalculator::calculate(
                lines: $lines,
                lineNumbers: $this->lineNumbers,
                padding: $this->padding,
                window: $this->window,
                shadow: $this->shadow,
                cellW: $cellW,
                cellH: $cellH,
            );

        // Ensure integer dimensions for GD functions.
        $totalW = (int) $totalW;
        $totalH = (int) $totalH;

        $frameWidth  = (int) ($contentWidth + $this->padding * 2);
        $frameHeight = (int) ($contentHeight + $this->padding * 2 + $headerHeight);

        $img = imagecreatetruecolor((int) $totalW, (int) $totalH);
        if ($img === false) {
            throw new \RuntimeException('Failed to create GD image resource.');
        }

        // Fill with transparent first for shadow blending.
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $transparent);

        // Parse colors once.
        $bgColor    = $this->allocateColor($img, $this->theme->background);
        $borderColor = $this->theme->border ? $this->allocateColor($img, $this->theme->border) : null;

        // Draw shadow if enabled.
        if ($this->shadow) {
            $shadowImg = imagecreatetruecolor($totalW, $totalH);
            if ($shadowImg === false) {
                imagedestroy($img);
                throw new \RuntimeException('Failed to create shadow GD image resource.');
            }
            imagealphablending($shadowImg, false);
            imagesavealpha($shadowImg, true);
            imagefill($shadowImg, 0, 0, imagecolorallocatealpha($shadowImg, 0, 0, 0, 127));

            $shadowColor = imagecolorallocatealpha($shadowImg, 0, 0, 0, 60);
            $shadowOffset = 6;
            imagefilledrectangle(
                $shadowImg,
                $shadowMargin + $shadowOffset,
                $shadowMargin + $shadowOffset,
                $shadowMargin + $frameWidth - 1 + $shadowOffset,
                $shadowMargin + $frameHeight - 1 + $shadowOffset,
                $shadowColor,
            );

            imagecopy($img, $shadowImg, 0, 0, 0, 0, (int) $totalW, (int) $totalH);
            imagedestroy($shadowImg);
        }

        // Draw background frame.
        imagefilledrectangle(
            $img,
            $shadowMargin,
            $shadowMargin,
            $shadowMargin + $frameWidth - 1,
            $shadowMargin + $frameHeight - 1,
            $bgColor,
        );

        // Draw border if enabled.
        if ($this->border && $borderColor !== null) {
            imagerectangle(
                $img,
                $shadowMargin,
                $shadowMargin,
                $shadowMargin + $frameWidth - 1,
                $shadowMargin + $frameHeight - 1,
                $borderColor,
            );
        }

        // Draw window controls.
        if ($this->window) {
            $this->buildWindowChrome($img, $shadowMargin, $frameWidth);
        }

        // Draw text.
        $textY0 = $shadowMargin + $this->padding + $headerHeight;
        $textX0 = $shadowMargin + $this->padding;

        $lineNumColor = $this->allocateColor($img, $this->theme->lineNumber);
        $fgColor      = $this->allocateColor($img, $this->theme->foreground);

        foreach ($lines as $i => $line) {
            $y = $textY0 + $i * $cellH;
            if ($this->lineNumbers) {
                $num = str_pad((string) ($i + 1), max(2, strlen((string) count($lines))), ' ', STR_PAD_LEFT);
                imagestring($img, self::DEFAULT_FONT, $textX0, $y, $num, $lineNumColor);
            }
            $segments = AnsiParser::parse($line);
            $x = $textX0 + $gutter * $cellW;
            foreach ($segments as $seg) {
                $color = $seg->fg !== null
                    ? $this->allocateColor($img, $seg->fg)
                    : $fgColor;
                imagestring($img, self::DEFAULT_FONT, $x, $y, $seg->text, $color);
                $x += mb_strlen($seg->text, 'UTF-8') * $cellW;
            }
        }

        ob_start();
        $ok = imagepng($img);
        imagedestroy($img);
        if (!$ok) {
            ob_end_clean();
            throw new \RuntimeException('imagepng() failed to produce output.');
        }
        $png = ob_get_clean();
        return $png;
    }

    /**
     * Allocate a GD colour from a hex string, reusing cached allocations
     * per image to avoid leaks.
     *
     * @param \GdImage $img
     * @param string $hex Hex colour like '#0d1117'
     * @return int GD colour index
     */
    private function allocateColor(\GdImage $img, string $hex): int
    {
        if (!isset($this->colorCache[$img])) {
            $this->colorCache[$img] = [];
        }
        $cache = &$this->colorCache[$img];

        if (isset($cache[$hex])) {
            return $cache[$hex];
        }

        $r = hexdec(substr($hex, 1, 2));
        $g = hexdec(substr($hex, 3, 2));
        $b = hexdec(substr($hex, 5, 2));

        $color = imagecolorallocate($img, $r, $g, $b);
        if ($color === false) {
            $color = imagecolorallocate($img, 255, 255, 255);
        }

        $cache[$hex] = $color;
        return $color;
    }

    private function buildWindowChrome(\GdImage $img, int $shadowMargin, int $frameWidth): void
    {
        match ($this->windowStyle) {
            WindowStyle::Macos => $this->buildMacosWindow($img, $shadowMargin),
            WindowStyle::WindowsTerminal => $this->buildWindowsTerminalWindow($img, $shadowMargin, $frameWidth),
            WindowStyle::ITerm2 => $this->buildITerm2Window($img, $shadowMargin),
            WindowStyle::Hyper => $this->buildHyperWindow($img, $shadowMargin, $frameWidth),
            WindowStyle::None => null,
        };
    }

    private function buildMacosWindow(\GdImage $img, int $shadowMargin): void
    {
        $geo = WindowChromeGeometry::macos($shadowMargin);
        // Note: $geo->base == $geo->cy in macOS geometry (center x == left edge of first circle)
        $cx = $geo->base;

        $red    = $this->allocateColor($img, $this->theme->windowRed);
        $yellow = $this->allocateColor($img, $this->theme->windowYellow);
        $green  = $this->allocateColor($img, $this->theme->windowGreen);

        $colors = [$red, $yellow, $green];
        foreach ($colors as $i => $color) {
            imagefilledellipse($img, $cx + $i * $geo->gap, $geo->cy, $geo->r * 2, $geo->r * 2, $color);
        }
    }

    private function buildWindowsTerminalWindow(\GdImage $img, int $shadowMargin, int $frameWidth): void
    {
        $geo = WindowChromeGeometry::windowsTerminal($shadowMargin, $frameWidth);
        $titleBarY = $shadowMargin;
        $rightEdge = $shadowMargin + $frameWidth - 12;

        // Title bar background (dark)
        $titleBarBg = $this->allocateColor($img, '#1e1e1e');
        imagefilledrectangle($img, $shadowMargin, $titleBarY, $shadowMargin + $geo->frameWidth - 1, $titleBarY + $geo->titleBarHeight - 1, $titleBarBg);

        // Title bar border
        $titleBarBorder = $this->allocateColor($img, '#303030');
        imagerectangle($img, $shadowMargin, $titleBarY, $shadowMargin + $geo->frameWidth - 1, $titleBarY + $geo->titleBarHeight - 1, $titleBarBorder);

        // Windows-style buttons
        $buttonColors = ['#444444', '#444444', '#444444'];
        $buttonY = $titleBarY + ($geo->titleBarHeight - $geo->buttonSize) / 2;

        foreach ([0, 1, 2] as $i) {
            $bx = $rightEdge - ($geo->buttonSize + $geo->buttonGap) * (3 - $i);
            $btnColor = $this->allocateColor($img, $buttonColors[$i]);
            imagefilledrectangle($img, $bx, $buttonY, $bx + $geo->buttonSize - 1, $buttonY + $geo->buttonSize - 1, $btnColor);
        }
    }

    private function buildITerm2Window(\GdImage $img, int $shadowMargin): void
    {
        $geo = WindowChromeGeometry::iterm2($shadowMargin);
        // Note: $geo->base == $geo->cy in iTerm2 geometry
        $cx = $geo->base;

        $red    = $this->allocateColor($img, $this->theme->windowRed);
        $yellow = $this->allocateColor($img, $this->theme->windowYellow);
        $green  = $this->allocateColor($img, $this->theme->windowGreen);

        $colors = [$red, $yellow, $green];
        foreach ($colors as $i => $color) {
            imagefilledellipse($img, $cx + $i * $geo->gap, $geo->cy, $geo->r * 2, $geo->r * 2, $color);
        }
    }

    private function buildHyperWindow(\GdImage $img, int $shadowMargin, int $frameWidth): void
    {
        $geo = WindowChromeGeometry::hyper($shadowMargin, $frameWidth);
        $titleBarY = $shadowMargin;

        // Title bar background
        $titleBarBg = $this->allocateColor($img, $this->theme->border);
        imagefilledrectangle($img, $shadowMargin, $titleBarY, $shadowMargin + $geo->frameWidth - 1, $titleBarY + $geo->titleBarHeight - 1, $titleBarBg);

        // Traffic lights
        $red    = $this->allocateColor($img, $this->theme->windowRed);
        $yellow = $this->allocateColor($img, $this->theme->windowYellow);
        $green  = $this->allocateColor($img, $this->theme->windowGreen);

        $colors = [$red, $yellow, $green];
        foreach ($colors as $i => $color) {
            imagefilledellipse($img, $geo->base + $i * $geo->gap, $geo->cy, $geo->r * 2, $geo->r * 2, $color);
        }
    }

    private function copy(
        ?Theme $theme = null,
        ?int $padding = null,
        ?bool $window = null,
        ?bool $shadow = null,
        ?bool $border = null,
        ?bool $lineNumbers = null,
        ?int $borderRadius = null,
        ?WindowStyle $windowStyle = null,
    ): self {
        return new self(
            theme:        $theme        ?? $this->theme,
            padding:      $padding      ?? $this->padding,
            window:       $window       ?? $this->window,
            shadow:       $shadow       ?? $this->shadow,
            border:       $border       ?? $this->border,
            lineNumbers:  $lineNumbers  ?? $this->lineNumbers,
            borderRadius: $borderRadius ?? $this->borderRadius,
            windowStyle:  $windowStyle  ?? $this->windowStyle,
        );
    }
}
