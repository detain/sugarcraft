<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot;

use SugarCraft\Core\Util\Width;

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
    public readonly int $padding;

    public readonly int $borderRadius;

    /** Upper bound accepted for padding (cells); the ctor doors the value. */
    public const MAX_PADDING = 4096;

    /** Upper bound for corner rounding (pixels); the ctor doors the value. */
    public const MAX_BORDER_RADIUS = 256;

    /** Font blobs larger than this (32 MiB) are refused by render(). */
    public const MAX_EMBEDDED_FONT_BYTES = 33_554_432;

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
        public readonly bool $window       = true,
        public readonly bool $shadow       = true,
        public readonly bool $border       = true,
        public readonly bool $lineNumbers  = false,
        public readonly WindowStyle $windowStyle = WindowStyle::Macos,
        public readonly bool $ligatures    = false,
        public readonly ?string $fontPath  = null,
        public readonly ?array $highlight  = null,
        int $padding = 24,
        int $borderRadius = 8,
        public readonly ?string $title = null,
    ) {
        // A direct `new SvgRenderer(padding: -5)` must not be able to forge
        // negative geometry the withers refuse: the constructor clamps
        // exactly like withPadding()/withBorderRadius().
        $this->padding      = max(0, min(self::MAX_PADDING, $padding));
        $this->borderRadius = max(0, min(self::MAX_BORDER_RADIUS, $borderRadius));
    }

    public static function dark():       self { return new self(theme: Theme::dark()); }
    public static function light():      self { return new self(theme: Theme::light()); }
    public static function dracula():    self { return new self(theme: Theme::dracula()); }
    public static function tokyoNight(): self { return new self(theme: Theme::tokyoNight()); }
    public static function nord():       self { return new self(theme: Theme::nord()); }

    public function withTheme(Theme $t):     self { return $this->copy(theme: $t); }
    public function withPadding(int $p):     self { return $this->copy(padding: max(0, min(self::MAX_PADDING, $p))); }
    public function withWindow(bool $on):    self { return $this->copy(window: $on); }
    public function withShadow(bool $on):    self { return $this->copy(shadow: $on); }
    public function withBorder(bool $on):    self { return $this->copy(border: $on); }
    public function withLineNumbers(bool $on): self { return $this->copy(lineNumbers: $on); }
    public function withBorderRadius(int $r): self { return $this->copy(borderRadius: max(0, min(self::MAX_BORDER_RADIUS, $r))); }
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
     * Map a font blob's leading magic bytes to its `@font-face` src MIME type,
     * or null when the bytes match no known font signature. Prevents the
     * embedded `src` from advertising the wrong type (previously hardcoded to
     * `font/ttf` regardless of the actual format).
     *
     *  - `\x00\x01\x00\x00` / `true` / `ttcf` → TrueType (`font/ttf`)
     *  - `OTTO`                                → OpenType/CFF (`font/otf`)
     *  - `wOFF`                                → WOFF   (`font/woff`)
     *  - `wOF2`                                → WOFF2  (`font/woff2`)
     */
    public static function sniffFontMime(string $data): ?string
    {
        return match (substr($data, 0, 4)) {
            "\x00\x01\x00\x00", 'true', 'ttcf' => 'font/ttf',
            'OTTO'                             => 'font/otf',
            'wOFF'                             => 'font/woff',
            'wOF2'                             => 'font/woff2',
            default                            => null,
        };
    }

    /**
     * Highlight a range of lines with a background colour.
     *
     * @param int $start 1-based start line (inclusive)
     * @param int $end   1-based end line (inclusive)
     * @param ?string $color CSS colour string; null (default) resolves to the
     *                       theme's {@see Theme::$highlight} band, falling back
     *                       to #fffbe6 on themes that carry no band colour.
     */
    public function withHighlight(int $start, int $end, ?string $color = null): self
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
     * Name the document for assistive technology (the SVG <title>/<desc> pair
     * plus role="img"). PNG bitmaps carry no text metadata; SVG-only setter.
     */
    public function withTitle(string $title): self
    {
        return $this->copy(title: $title);
    }

    /**
     * Render `$text` (which may contain ANSI escape sequences) to an
     * SVG document and return the bytes.
     *
     * Rendering is synchronous and returns the complete document; it does
     * not stream. The {@see OutputWriter} trio is a standalone chunking
     * helper for callers that assemble output themselves — no renderer in
     * this library calls into it.
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
                // WindowStyle::None paints no title bar (see buildWindowChrome),
                // so the 36px band must collapse with it — otherwise a
                // `--window-style none` frame carries an empty header.
                chrome: $this->windowStyle !== WindowStyle::None,
            );

        $svgWidth  = (int) ($totalW - $shadowMargin * 2);
        $svgHeight = (int) ($totalH - $shadowMargin * 2);

        $svg  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $svg .= '<svg xmlns="http://www.w3.org/2000/svg" role="img" '
              . 'aria-labelledby="candy-codeshot-title candy-codeshot-desc" '
              . 'width="' . $totalW . '" height="' . $totalH . '" '
              . 'viewBox="0 0 ' . $totalW . ' ' . $totalH . '">' . "\n"
              . '  <title id="candy-codeshot-title">'
              . self::xmlEscape($this->title ?? 'candy-codeshot screenshot')
              . '</title>' . "\n"
              . '  <desc id="candy-codeshot-desc">Code screenshot rendered by candy-codeshot.</desc>' . "\n";

        // Defs (drop shadow filter + embedded font).
        $defs = '';
        if ($this->shadow) {
            $defs .= '  <filter id="shadow" x="-20%" y="-20%" width="140%" height="140%">' . "\n"
                 . '    <feDropShadow dx="0" dy="4" stdDeviation="6" flood-color="' . self::xmlEscape($this->theme->shadow) . '" />' . "\n"
                 . '  </filter>' . "\n";
        }
        if ($this->fontPath !== null) {
            $fontSize = @filesize($this->fontPath);
            if ($fontSize === false) {
                throw new \RuntimeException("Cannot stat font file: {$this->fontPath}");
            }
            if ($fontSize > self::MAX_EMBEDDED_FONT_BYTES) {
                throw new \RuntimeException(
                    "Font file too large to embed: {$fontSize} bytes exceed the 32 MiB limit ({$this->fontPath})"
                );
            }
            $fontData = file_get_contents($this->fontPath);
            if ($fontData === false) {
                throw new \RuntimeException("Cannot read font file: {$this->fontPath}");
            }
            {
                $base64 = base64_encode($fontData);
                $mime = self::sniffFontMime($fontData) ?? 'font/ttf';
                $defs .= '  <style type="text/css"><![CDATA[' . "\n"
                     . '    @font-face { font-family: "embedded"; src: url(data:' . $mime . ';base64,' . $base64 . '); }' . "\n"
                     . '  ]]></style>' . "\n";
            }
        }
        if ($this->window && ($this->windowStyle === WindowStyle::WindowsTerminal
            || $this->windowStyle === WindowStyle::Hyper)) {
            // The two square title-bar chrome styles paint full-width rects;
            // clipping them to the rounded frame stops corner bleed when
            // --border-radius is non-zero.
            $defs .= '  <clipPath id="frame">'
                 . sprintf('<rect x="%d" y="%d" width="%d" height="%d" rx="%d" />',
                     $shadowMargin, $shadowMargin, $svgWidth, $svgHeight, $this->borderRadius)
                 . '</clipPath>' . "\n";
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

        $sgrCarry = null;
        foreach ($lines as $i => $line) {
            $lineNumber = $i + 1;
            $y = $textY0 + $i * $cellH;

            // Line-highlight background (--highlight range).
            if ($this->highlight !== null) {
                [$start, $end] = $this->highlight['range'];
                $hlColor = $this->highlight['color'] ?? $this->theme->highlight ?? '#fffbe6';
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
            $segments = AnsiParser::parse($line, $sgrCarry);
            $x = $textX0 + $gutter * $cellW;
            foreach ($segments as $seg) {
                $textLen = Width::of($seg->text);
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
        $geo = WindowChromeGeometry::macos($shadowMargin);
        $colors = [$this->theme->windowRed, $this->theme->windowYellow, $this->theme->windowGreen];
        $svg = '';
        foreach ($colors as $i => $c) {
            $svg .= sprintf(
                '<circle cx="%d" cy="%d" r="%d" fill="%s" />' . "\n",
                $geo->base + $i * $geo->gap, $geo->cy, $geo->r, self::xmlEscape($c),
            );
        }
        return $svg;
    }

    private function buildWindowsTerminalWindow(int $shadowMargin, int $contentWidth): string
    {
        // Deliberately theme-blind: Windows Terminal's own chrome is dark
        // regardless of the code theme, so the title bar and buttons keep
        // these fixed literals. A themeable variant is a future API seam.
        $geo = WindowChromeGeometry::windowsTerminal($shadowMargin, $contentWidth);
        $titleBarY = $shadowMargin;
        $rightEdge = $shadowMargin + $contentWidth - 12;

        $svg = '';

        // Title bar background (dark)
        $svg .= sprintf(
            '<rect x="%d" y="%d" width="%d" height="%d" clip-path="url(#frame)" fill="%s" />' . "\n",
            $shadowMargin,
            $titleBarY,
            $geo->frameWidth,
            $geo->titleBarHeight,
            self::xmlEscape('#1e1e1e'),
        );

        // Title bar border
        $svg .= sprintf(
            '<rect x="%d" y="%d" width="%d" height="%d" clip-path="url(#frame)" fill="none" stroke="%s" stroke-width="1" />' . "\n",
            $shadowMargin,
            $titleBarY,
            $geo->frameWidth,
            $geo->titleBarHeight,
            self::xmlEscape('#303030'),
        );

        // Windows-style buttons (minimize, maximize, close)
        $buttonColors = ['#444444', '#444444', '#444444'];
        $buttonY = $titleBarY + ($geo->titleBarHeight - $geo->buttonSize) / 2;

        foreach ([0, 1, 2] as $i) {
            $bx = $rightEdge - ($geo->buttonSize + $geo->buttonGap) * (3 - $i);
            $svg .= sprintf(
                '<rect x="%d" y="%d" width="%d" height="%d" rx="1" fill="%s" />' . "\n",
                $bx, $buttonY, $geo->buttonSize, $geo->buttonSize, $buttonColors[$i],
            );
        }

        return $svg;
    }

    private function buildITerm2Window(int $shadowMargin): string
    {
        $geo = WindowChromeGeometry::iterm2($shadowMargin);
        $colors = [$this->theme->windowRed, $this->theme->windowYellow, $this->theme->windowGreen];
        $svg = '';
        foreach ($colors as $i => $c) {
            $svg .= sprintf(
                '<circle cx="%d" cy="%d" r="%d" fill="%s" />' . "\n",
                $geo->base + $i * $geo->gap, $geo->cy, $geo->r, self::xmlEscape($c),
            );
        }
        return $svg;
    }

    private function buildHyperWindow(int $shadowMargin, int $contentWidth): string
    {
        $geo = WindowChromeGeometry::hyper($shadowMargin, $contentWidth);
        $titleBarY = $shadowMargin;
        $colors = [$this->theme->windowRed, $this->theme->windowYellow, $this->theme->windowGreen];

        $svg = '';

        // Thin title bar background
        $svg .= sprintf(
            '<rect x="%d" y="%d" width="%d" height="%d" clip-path="url(#frame)" fill="%s" />' . "\n",
            $shadowMargin,
            $titleBarY,
            $geo->frameWidth,
            $geo->titleBarHeight,
            self::xmlEscape($this->theme->border),
        );

        // Traffic lights in title bar
        foreach ($colors as $i => $c) {
            $svg .= sprintf(
                '<circle cx="%d" cy="%d" r="%d" fill="%s" />' . "\n",
                $geo->base + $i * $geo->gap, $geo->cy, $geo->r, self::xmlEscape($c),
            );
        }

        return $svg;
    }

    private static function xmlEscape(string $s): string
    {
        // XML 1.0 forbids C0 scalars outright - a lone \x00 makes the whole
        // document unparseable - so drop them before escaping. Tab, LF and
        // CR stay legal and pass through untouched.
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '';

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
        ?string $title = null,
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
            title:        $title        ?? $this->title,
        );
    }
}
