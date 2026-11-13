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
 *  - Inline ANSI SGR colour parsing — each styled run becomes its own
 *    `<text>` element carrying `fill` (plus `font-weight` / `font-style` /
 *    `text-decoration` when set), and background colours are painted as
 *    per-segment `<rect>` bands behind the text. Bold, italic and
 *    underline attributes are honoured in the SVG output.
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

    /**
     * Hard ceiling on output pixels (~40M), mirroring
     * PngRenderer::MAX_PNG_PIXELS. The engines' asymmetry WAS the -011
     * attack surface: the 5 MB single-line repro happily emitted a
     * `width="42000048"` non-renderable document while PNG refused.
     */
    public const MAX_SVG_PIXELS = 40_000_000;

    /**
     * "Not passed" marker for copy()'s `mixed $fontPath` leg. A path string
     * containing a NUL byte can never be a real filename, and null is a VALUE
     * on this leg — `withFont(null)` means "clear" — so the unset case needs
     * its own sentinel (cures the can't-clear bug, lineage #58 origin).
     */
    private const FONT_UNSET = "\0font-path-unset";

    /**
     * The bytes {@see xmlEscape()} provably leaves untouched: TAB/LF/CR,
     * printable ASCII except the five characters htmlspecialchars()
     * rewrites under ENT_XML1|ENT_QUOTES (" & < > '), and nothing above
     * 0x7E. A string wholly built from these takes the -046 fast path —
     * the sweep below is what would run on it, and every stage of that
     * sweep is identity on these bytes (DEL and the C1 pair need >= 0x7F,
     * the /u class needs >= 0x80, the noncharacter band lives above
     * 0xFDD0). Everything else — invalid UTF-8 included — still walks
     * the full fail-closed pipeline unchanged.
     */
    private const XML_ESCAPE_SAFE = "\t\n\r !#$%()*+,-./0123456789:;=@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~";

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
     * @param ?string $color hex colour of the family the library paints
     *                       (#rgb / #rrggbb / #rrggbbaa); null (default)
     *                       resolves to the theme's
     *                       {@see Theme::$highlight} band, falling back to
     *                       #fffbe6 on themes that carry no band colour.
     *                       CSS names were the old documented promise and the
     *                       silent-failure door (candy-codeshot-067): any
     *                       non-hex string escaped into the fill and painted
     *                       nothing. The contract is hex-only now.
     *
     * @throws \InvalidArgumentException on an impossible range or a colour
     *                                  outside the hex family.
     */
    public function withHighlight(int $start, int $end, ?string $color = null): self
    {
        if ($start < 1) {
            throw new \InvalidArgumentException("Highlight start must be >= 1, got {$start}");
        }
        if ($end < $start) {
            throw new \InvalidArgumentException("Highlight end must be >= start, got {$end} < {$start}");
        }
        // Trust boundary, not decoration: the bin refuses junk at parse time
        // (candy-codeshot-067), but the renderer is public API — a library
        // caller may bypass it, and an escaped no-colour stripe is exactly
        // the silent failure the finding named.
        if ($color !== null && preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $color) !== 1) {
            throw new \InvalidArgumentException("Highlight colour must be a #rgb, #rrggbb or #rrggbbaa hex value, got {$color}");
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

        // cs-08 (-011): refuse oversized geometry BEFORE the per-line loop
        // spends CPU on it — the 5 MB single-line repro used to emit a
        // `width="42000048"` document that no downstream renderer can paint
        // (bin turns this into exit 2 via its render catch). The ceiling is
        // the PNG engine's number: one advertised pipeline, one posture.
        if ((int) $totalW * (int) $totalH > self::MAX_SVG_PIXELS) {
            throw new Support\OutputTooLarge('SVG', (int) $totalW, (int) $totalH, self::MAX_SVG_PIXELS);
        }

        $svgWidth  = (int) ($totalW - $shadowMargin * 2);
        $svgHeight = (int) ($totalH - $shadowMargin * 2);

        $svg  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        // No aria-labelledby and no ids on <title>/<desc>: the child <title>
        // names an img-role root by itself, while fixed anchor ids collide
        // the moment two renders are embedded inline in one document — every
        // SVG after the first would inherit the FIRST render's accessible
        // name. Bare elements keep each render self-describing.
        $svg .= '<svg xmlns="http://www.w3.org/2000/svg" role="img" '
              . 'width="' . $totalW . '" height="' . $totalH . '" '
              . 'viewBox="0 0 ' . $totalW . ' ' . $totalH . '">' . "\n"
              . '  <title>'
              . self::xmlEscape($this->title ?? 'candy-codeshot screenshot')
              . '</title>' . "\n"
              . '  <desc>Code screenshot rendered by candy-codeshot.</desc>' . "\n";

        // Defs (drop shadow filter + embedded font).
        $defs = '';
        if ($this->shadow) {
            $defs .= '  <filter id="shadow" x="-20%" y="-20%" width="140%" height="140%">' . "\n"
                 . '    <feDropShadow dx="0" dy="4" stdDeviation="6" flood-color="' . self::xmlEscape($this->theme->shadow) . '" />' . "\n"
                 . '  </filter>' . "\n";
        }
        if ($this->fontPath !== null) {
            // candy-codeshot-048: the stat ceiling alone is a race and a lie.
            // A path that statted small can be swapped for a bigger file before
            // the read, and under --unsafe-paths a device reports a usable stat
            // while streaming forever (/dev/zero stats 0 bytes and would make an
            // unbounded file_get_contents consume all RAM). Two doors: regular
            // file first, then a read that is itself capped — at most the embed
            // limit plus one byte is ever taken in, and the byte count is
            // reconciled against the stat, so a lying or changed source is
            // refused with a bounded spike instead of embedded.
            if (!is_file($this->fontPath)) {
                throw new \RuntimeException("Font path is not a regular file: {$this->fontPath}");
            }
            $fontSize = @filesize($this->fontPath);
            if ($fontSize === false) {
                throw new \RuntimeException("Cannot stat font file: {$this->fontPath}");
            }
            if ($fontSize > self::MAX_EMBEDDED_FONT_BYTES) {
                throw new Support\FontTooLarge($this->fontPath, $fontSize, self::MAX_EMBEDDED_FONT_BYTES);
            }
            $handle = @fopen($this->fontPath, 'rb');
            if ($handle === false) {
                throw new \RuntimeException("Cannot read font file: {$this->fontPath}");
            }
            $fontData = stream_get_contents($handle, self::MAX_EMBEDDED_FONT_BYTES + 1);
            fclose($handle);
            if ($fontData === false) {
                throw new \RuntimeException("Cannot read font file: {$this->fontPath}");
            }
            if (strlen($fontData) !== $fontSize) {
                throw new \RuntimeException(sprintf(
                    'Font file changed while being embedded: stat reported %d bytes, the bounded read returned %d (%s)',
                    $fontSize,
                    strlen($fontData),
                    $this->fontPath,
                ));
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

        // cs-25 (-045): loop-invariants hoisted. The highlight band range,
        // its resolved colour and the escape of it, the gutter pad width
        // (which carries the strlen((string)count($lines)) walk), and the
        // escaped line-number fill are fixed for the whole document — the
        // old code recomputed every one of them for every line.
        $highlightRange = null;
        $highlightFill  = null;
        if ($this->highlight !== null) {
            $highlightRange = $this->highlight['range'];
            $highlightFill  = self::xmlEscape($this->highlight['color'] ?? $this->theme->highlight ?? '#fffbe6');
        }
        $lineNumberFill = $this->lineNumbers ? self::xmlEscape($this->theme->lineNumber) : '';
        $numberPad      = $this->lineNumbers ? max(2, strlen((string) count($lines))) : 0;

        $sgrCarry = null;
        foreach ($lines as $i => $line) {
            $lineNumber = $i + 1;
            $y = $textY0 + $i * $cellH;

            // Line-highlight background (--highlight range).
            if ($highlightRange !== null) {
                [$start, $end] = $highlightRange;
                if ($lineNumber >= $start && $lineNumber <= $end) {
                    $svg .= sprintf(
                        '<rect x="%d" y="%.2f" width="%d" height="%.2f" fill="%s" />' . "\n",
                        $shadowMargin + $this->padding,
                        $y - $this->theme->fontSize,
                        $contentWidth,
                        $cellH,
                        $highlightFill,
                    );
                }
            }

            if ($this->lineNumbers) {
                $svg .= sprintf(
                    '<text x="%.2f" y="%.2f" fill="%s">%s</text>' . "\n",
                    $textX0,
                    $y,
                    $lineNumberFill,
                    self::xmlEscape(str_pad((string) ($i + 1), $numberPad, ' ', STR_PAD_LEFT)),
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
        // -046 fast path: wholly-safe bytes are identity under the sweep
        // below (see XML_ESCAPE_SAFE), so skip five engine calls for the
        // overwhelmingly common case — theme hex colours, plain ASCII code.
        if (strspn($s, self::XML_ESCAPE_SAFE) === strlen($s)) {
            return $s;
        }

        // Byte-level sweep — NO /u (byte semantics: the subject may be
        // invalid UTF-8 and must still be swept). DEL and the C1 controls in
        // their two-byte UTF-8 form (U+0080-U+009F) are dropped, not emitted,
        // not numericised: this is the contract sugar-core's
        // Sanitize::untrusted() specifies, and a numeric reference would be
        // a character XML still cannot encode (-006).
        $s = preg_replace('/[\x7f]|\xC2[\x80-\x9F]/', '', $s) ?? '';

        // htmlspecialchars() silently returns '' for non-empty invalid UTF-8
        // — the whole string misrepresented as empty (-050). Convert
        // lossily-but-honestly first (the M12 rule the segment channel in
        // AnsiParser already applies), which also makes the /u pass below
        // safe: preg with /u fails outright on malformed subjects.
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }

        // XML 1.0 forbids the C0 scalars outright — a lone \x00 makes the
        // whole document unparseable — and libxml additionally rejects the
        // U+FDD0-U+FDEF noncharacters and U+FFFE/FFFF even when the UTF-8 is
        // well-formed (-015 killed one-shot renders). Tab, LF and CR stay
        // legal and pass through untouched. Policy: drop (no valid XML
        // encoding exists for these).
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{80}-\x{9F}\x{FDD0}-\x{FDEF}\x{FFFE}\x{FFFF}]/u', '', $s) ?? '';

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
        mixed $fontPath = self::FONT_UNSET,
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
            // Dedicated sentinel (not null): null is a VALUE here — withFont(null)
            // must clear — so "not passed" needs its own marker.
            fontPath:     $fontPath === self::FONT_UNSET ? $this->fontPath : $fontPath,
            // The null-conflation is safe on this leg and kept as-is: copy() is
            // private and withHighlight() always passes a non-null range array
            // (the inner 'color' may be null), and there is no highlight-removal
            // API — an explicit-null highlight is unreachable, so no can't-clear
            // bug exists here to cure.
            highlight:    is_null($highlight) ? $this->highlight : $highlight,
            title:        $title        ?? $this->title,
        );
    }
}
