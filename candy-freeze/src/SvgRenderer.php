<?php

declare(strict_types=1);

namespace SugarCraft\Freeze;

use SugarCraft\Core\Util\Ansi;

/**
 * Renders text (with optional ANSI styling) to an SVG document. The
 * output is plain text — no `ext-gd` / Imagick required — making it
 * safe to drop into pipelines and CI artifacts.
 *
 * Mirrors charmbracelet/freeze's SVG output mode. Supports:
 *
 *  - macOS-style window-control "traffic lights" (`withWindow(true)`)
 *  - Padding around the code area
 *  - Optional rounded-corner frame
 *  - Optional drop shadow
 *  - Line numbers in the gutter
 *  - Inline ANSI SGR colour parsing — foreground colours from the
 *    rendered tokens become `<tspan fill="…">` segments. Background
 *    colours and attribute flags (bold, italic, underline) are also
 *    honoured.
 *
 * Width and height are computed from the rendered text using a
 * monospace cell estimate (font size × 0.6 wide, font size × line
 * height tall) so the surrounding frame fits the content.
 */
final class SvgRenderer
{
    /** @param array{range: array{0:int,1:int}, color:string}|null $highlight */
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
        public readonly bool $ligatures    = false,
        public readonly ?string $fontPath  = null,
        public readonly ?array $highlight  = null,
    ) {}

    public static function dark():       self { return new self(theme: Theme::dark()); }
    public static function light():      self { return new self(theme: Theme::light()); }
    public static function dracula():    self { return new self(theme: Theme::dracula()); }
    public static function tokyoNight(): self { return new self(theme: Theme::tokyoNight()); }
    public static function nord():       self { return new self(theme: Theme::nord()); }

    public function withTheme(Theme $t):     self { return $this->copy(theme: $t); }
    public function withPadding(int $p):     self { return $this->copy(padding: max(0, $p)); }
    public function withWindow(bool $on):    self { return $this->copy(window: $on); }
    public function withShadow(bool $on):    self { return $this->copy(shadow: $on); }
    public function withBorder(bool $on):    self { return $this->copy(border: $on); }
    public function withLineNumbers(bool $on): self { return $this->copy(lineNumbers: $on); }
    public function withBorderRadius(int $r): self { return $this->copy(borderRadius: max(0, $r)); }
    public function withLigatures(bool $on): self { return $this->copy(ligatures: $on); }
    public function withWindowStyle(WindowStyle|string $style): self
    {
        $style = $style instanceof WindowStyle ? $style : WindowStyle::from($style);
        return $this->copy(windowStyle: $style);
    }

    /**
     * Embed a TTF font file (loaded from `$path`) as base64 into the SVG
     * so the output is fully self-contained.
     *
     * Pass `null` to disable font embedding.
     */
    public function withFont(?string $path): self
    {
        if ($path !== null && !file_exists($path)) {
            throw new \InvalidArgumentException("Font file not found: {$path}");
        }
        return $this->copy(fontPath: $path);
    }

    /**
     * Highlight a range of lines with a background colour.
     *
     * @param int $start 1-based start line (inclusive)
     * @param int $end   1-based end line (inclusive)
     * @param string $color CSS colour string (default: #fffbe6, a soft yellow)
     */
    public function withHighlight(int $start, int $end, string $color = '#fffbe6'): self
    {
        if ($start < 1) {
            throw new \InvalidArgumentException("Highlight start must be >= 1, got {$start}");
        }
        if ($end < $start) {
            throw new \InvalidArgumentException("Highlight end must be >= start, got {$end} < {$start}");
        }
        return $this->copy(highlight: ['range' => [$start, $end], 'color' => $color]);
    }

    /**
     * Render `$text` (which may contain ANSI escape sequences) to an
     * SVG document and return the bytes.
     */
    public function render(string $text): string
    {
        $lines = explode("\n", rtrim($text, "\n"));
        $cellW = $this->theme->fontSize * 0.6;
        $cellH = $this->theme->fontSize * $this->theme->lineHeight;

        [$maxCols, $gutter, $contentWidth, , $headerHeight, $shadowMargin, $totalW, $totalH] =
            LayoutCalculator::calculate(
                lines: $lines,
                lineNumbers: $this->lineNumbers,
                padding: $this->padding,
                window: $this->window,
                shadow: $this->shadow,
                cellW: $cellW,
                cellH: $cellH,
            );

        $svgWidth  = (int) ($totalW - $shadowMargin * 2);
        $svgHeight = (int) ($totalH - $shadowMargin * 2);

        $svg  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $svg .= '<svg xmlns="http://www.w3.org/2000/svg" '
              . 'width="' . $totalW . '" height="' . $totalH . '" '
              . 'viewBox="0 0 ' . $totalW . ' ' . $totalH . '">' . "\n";

        // Defs (drop shadow filter + embedded font).
        $defs = '';
        if ($this->shadow) {
            $defs .= '  <filter id="shadow" x="-20%" y="-20%" width="140%" height="140%">' . "\n"
                 . '    <feDropShadow dx="0" dy="4" stdDeviation="6" flood-color="' . self::xmlEscape($this->theme->shadow) . '" />' . "\n"
                 . '  </filter>' . "\n";
        }
        if ($this->fontPath !== null) {
            $fontData = file_get_contents($this->fontPath);
            if ($fontData !== false) {
                $base64 = base64_encode($fontData);
                $mime = 'font/ttf';
                $defs .= '  <style type="text/css"><![CDATA[' . "\n"
                     . '    @font-face { font-family: "embedded"; src: url(data:' . $mime . ';base64,' . $base64 . '); }' . "\n"
                     . '  ]]></style>' . "\n";
            }
        }
        if ($defs !== '') {
            $svg .= '<defs>' . "\n" . $defs . '</defs>' . "\n";
        }

        // Background frame.
        $frameAttrs = sprintf(
            'x="%d" y="%d" width="%d" height="%d" rx="%d" fill="%s"',
            $shadowMargin, $shadowMargin, $svgWidth, $svgHeight, $this->borderRadius,
            self::xmlEscape($this->theme->background),
        );
        if ($this->border) {
            $frameAttrs .= ' stroke="' . self::xmlEscape($this->theme->border) . '" stroke-width="1"';
        }
        if ($this->shadow) {
            $frameAttrs .= ' filter="url(#shadow)"';
        }
        $svg .= '<rect ' . $frameAttrs . ' />' . "\n";

        if ($this->window) {
            $svg .= $this->buildWindowChrome($shadowMargin, $svgWidth);
        }

        $textY0 = $shadowMargin + $this->padding + $headerHeight + $this->theme->fontSize;
        $textX0 = $shadowMargin + $this->padding;

        $fontFamily = $this->fontPath !== null ? 'embedded' : $this->theme->fontFamily;
        $ligatureAttr = $this->ligatures ? ' font-variant-ligatures="normal"' : '';
        $svg .= '<g font-family="' . self::xmlEscape($fontFamily) . '" '
              . 'font-size="' . $this->theme->fontSize . '" '
              . 'fill="' . self::xmlEscape($this->theme->foreground) . '"'
              . $ligatureAttr . ' xml:space="preserve">' . "\n";

        foreach ($lines as $i => $line) {
            $lineNumber = $i + 1;
            $y = $textY0 + $i * $cellH;

            // Line-highlight background (--highlight range).
            if ($this->highlight !== null) {
                [$start, $end] = $this->highlight['range'];
                $hlColor = $this->highlight['color'];
                if ($lineNumber >= $start && $lineNumber <= $end) {
                    $svg .= sprintf(
                        '<rect x="%d" y="%.2f" width="%d" height="%.2f" fill="%s" />' . "\n",
                        $shadowMargin + $this->padding,
                        $y - $this->theme->fontSize,
                        $contentWidth,
                        $cellH,
                        self::xmlEscape($hlColor),
                    );
                }
            }

            if ($this->lineNumbers) {
                $svg .= sprintf(
                    '<text x="%.2f" y="%.2f" fill="%s">%s</text>' . "\n",
                    $textX0,
                    $y,
                    self::xmlEscape($this->theme->lineNumber),
                    self::xmlEscape(str_pad((string) ($i + 1), max(2, strlen((string) count($lines))), ' ', STR_PAD_LEFT)),
                );
            }
            $segments = AnsiParser::parse($line);
            $x = $textX0 + $gutter * $cellW;
            foreach ($segments as $seg) {
                $textLen = mb_strlen($seg->text, 'UTF-8');
                $segW = $textLen * $cellW;
                if ($seg->bg !== null) {
                    $svg .= sprintf(
                        '<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" fill="%s" />' . "\n",
                        $x, $y - $this->theme->fontSize, $segW, $cellH,
                        self::xmlEscape($seg->bg),
                    );
                }
                $attrs = '';
                if ($seg->fg !== null) {
                    $attrs .= ' fill="' . self::xmlEscape($seg->fg) . '"';
                }
                if ($seg->bold)      { $attrs .= ' font-weight="bold"';   }
                if ($seg->italic)    { $attrs .= ' font-style="italic"';  }
                if ($seg->underline) { $attrs .= ' text-decoration="underline"'; }
                $svg .= sprintf(
                    '<text x="%.2f" y="%.2f"%s>%s</text>' . "\n",
                    $x, $y, $attrs, self::xmlEscape($seg->text),
                );
                $x += $segW;
            }
        }
        $svg .= '</g>' . "\n";
        $svg .= '</svg>' . "\n";
        return $svg;
    }

    private function buildWindowChrome(int $shadowMargin, int $contentWidth): string
    {
        return match ($this->windowStyle) {
            WindowStyle::Macos => $this->buildMacosWindow($shadowMargin),
            WindowStyle::WindowsTerminal => $this->buildWindowsTerminalWindow($shadowMargin, $contentWidth),
            WindowStyle::ITerm2 => $this->buildITerm2Window($shadowMargin),
            WindowStyle::Hyper => $this->buildHyperWindow($shadowMargin, $contentWidth),
            WindowStyle::None => '',
        };
    }

    private function buildMacosWindow(int $shadowMargin): string
    {
        $cy = $shadowMargin + 18;
        $base = $shadowMargin + 18;
        $r = 6;
        $gap = 18;
        $colors = [$this->theme->windowRed, $this->theme->windowYellow, $this->theme->windowGreen];
        $svg = '';
        foreach ($colors as $i => $c) {
            $svg .= sprintf(
                '<circle cx="%d" cy="%d" r="%d" fill="%s" />' . "\n",
                $base + $i * $gap, $cy, $r, self::xmlEscape($c),
            );
        }
        return $svg;
    }

    private function buildWindowsTerminalWindow(int $shadowMargin, int $contentWidth): string
    {
        $titleBarHeight = 28;
        $buttonSize = 14;
        $buttonGap = 8;
        $rightEdge = $shadowMargin + $contentWidth - 12;
        $titleBarY = $shadowMargin;

        $svg = '';

        // Title bar background (dark)
        $svg .= sprintf(
            '<rect x="%d" y="%d" width="%d" height="%d" fill="%s" />' . "\n",
            $shadowMargin,
            $titleBarY,
            $contentWidth,
            $titleBarHeight,
            self::xmlEscape('#1e1e1e'),
        );

        // Title bar border
        $svg .= sprintf(
            '<rect x="%d" y="%d" width="%d" height="%d" fill="none" stroke="%s" stroke-width="1" />' . "\n",
            $shadowMargin,
            $titleBarY,
            $contentWidth,
            $titleBarHeight,
            self::xmlEscape('#303030'),
        );

        // Windows-style buttons (minimize, maximize, close)
        $buttonColors = ['#444444', '#444444', '#444444'];
        $buttonY = $titleBarY + ($titleBarHeight - $buttonSize) / 2;

        foreach ([0, 1, 2] as $i) {
            $bx = $rightEdge - ($buttonSize + $buttonGap) * (3 - $i);
            $svg .= sprintf(
                '<rect x="%d" y="%d" width="%d" height="%d" rx="1" fill="%s" />' . "\n",
                $bx, $buttonY, $buttonSize, $buttonSize, $buttonColors[$i],
            );
        }

        return $svg;
    }

    private function buildITerm2Window(int $shadowMargin): string
    {
        // iTerm2 style: smaller traffic lights, no outer border effect visible
        $cy = $shadowMargin + 14;
        $base = $shadowMargin + 14;
        $r = 4; // smaller radius
        $gap = 14; // tighter spacing
        $colors = [$this->theme->windowRed, $this->theme->windowYellow, $this->theme->windowGreen];
        $svg = '';
        foreach ($colors as $i => $c) {
            $svg .= sprintf(
                '<circle cx="%d" cy="%d" r="%d" fill="%s" />' . "\n",
                $base + $i * $gap, $cy, $r, self::xmlEscape($c),
            );
        }
        return $svg;
    }

    private function buildHyperWindow(int $shadowMargin, int $contentWidth): string
    {
        $titleBarHeight = 24;
        $titleBarY = $shadowMargin;
        $r = 5;
        $gap = 16;
        $colors = [$this->theme->windowRed, $this->theme->windowYellow, $this->theme->windowGreen];

        $svg = '';

        // Thin title bar background
        $svg .= sprintf(
            '<rect x="%d" y="%d" width="%d" height="%d" fill="%s" />' . "\n",
            $shadowMargin,
            $titleBarY,
            $contentWidth,
            $titleBarHeight,
            self::xmlEscape($this->theme->border),
        );

        // Traffic lights in title bar
        $cy = $titleBarY + ($titleBarHeight - $r * 2) / 2;
        $base = $shadowMargin + 12;
        foreach ($colors as $i => $c) {
            $svg .= sprintf(
                '<circle cx="%d" cy="%d" r="%d" fill="%s" />' . "\n",
                $base + $i * $gap, $cy, $r, self::xmlEscape($c),
            );
        }

        return $svg;
    }

    private static function xmlEscape(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
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
        ?bool $ligatures = null,
        mixed $fontPath = null,
        mixed $highlight = null,
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
            ligatures:    $ligatures    ?? $this->ligatures,
            fontPath:     is_null($fontPath) ? $this->fontPath : $fontPath,
            highlight:    is_null($highlight) ? $this->highlight : $highlight,
        );
    }
}
