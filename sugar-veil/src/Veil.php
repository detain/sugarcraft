<?php

declare(strict_types=1);

namespace SugarCraft\Veil;

use SugarCraft\Buffer\Buffer;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Hyperlink;
use SugarCraft\Buffer\Style as BufferStyle;
use SugarCraft\Core\Util\Width;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Mouse\Zone;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Veil\Animation\AnimationKind;
use SugarCraft\Veil\Animation\Fade;
use SugarCraft\Veil\Animation\Scale;
use SugarCraft\Veil\Animation\Slide;

/**
 * Terminal overlay compositor.
 *
 * Composites a foreground string over a background string at a given
 * position with optional pixel offsets. Supports backdrop dimming
 * and animated transitions via honey-bounce CubicBezier easing.
 *
 * Port of rmhubbert/bubbletea-overlay.
 *
 * @see https://github.com/rmhubbert/bubbletea-overlay
 */
final class Veil
{
    /** @var int Backdrop opacity 0–100 (0 = no dimming, 100 = fully dimmed) */
    private readonly int $backdropOpacity;

    /** @var AnimationKind|null Animation to apply during transitions */
    private readonly ?AnimationKind $animationKind;

    /** @var int Stacking order — higher renders on top of lower */
    private readonly int $zIndex;

    /** @var bool Dismiss veil when mouse click lands outside its zone */
    private readonly bool $clickOutsideDismiss;

    /** @var bool Compute veil dimensions from content rather than fixed width/height */
    private readonly bool $autoSize;

    /** @var Border|null Border chrome to wrap content with */
    private readonly ?Border $border;

    /** @var Scanner Self-contained mouse hit-testing scanner (always present) */
    private readonly Scanner $scanner;

    /** @var string|null Last rendered output — feed to scanner via scan() before hit-testing */
    private readonly ?string $lastRendered;

    /** @var Mark Zone marker helper */
    private readonly Mark $marker;

    /** @var RenderSession Mutable diff/session state shared across with*() clones */
    private readonly RenderSession $session;

    /** @var Position|null Vertical position anchor for per-veil positioning */
    private readonly ?Position $vPosition;

    /** @var Position|null Horizontal position anchor for per-veil positioning */
    private readonly ?Position $hPosition;

    /** @var int X offset in columns */
    private readonly int $posX;

    /** @var int Y offset in rows */
    private readonly int $posY;

    /** @var string Overlay content this veil paints when driven by a VeilStack (empty = backdrop-only layer) */
    private readonly string $content;

    /**
     * @param int             $backdropOpacity 0–100 backdrop dimming
     * @param AnimationKind|null $animationKind Animation kind for transitions
     * @param int $zIndex Stacking order
     * @param bool $clickOutsideDismiss Dismiss on outside click
     * @param bool $autoSize Compute size from content
     * @param Border|null $border Border chrome
     * @param Scanner|null $scanner Self-contained mouse hit-testing scanner
     * @param string|null $lastRendered Last rendered output for scanner
     * @param RenderSession|null $session Mutable diff/session state
     * @param Position|null $vPosition Vertical position anchor
     * @param Position|null $hPosition Horizontal position anchor
     * @param int $posX X offset in columns
     * @param int $posY Y offset in rows
     * @param string $content Overlay content for stack-driven compositing
     */
    private function __construct(
        int $backdropOpacity = 0,
        ?AnimationKind $animationKind = null,
        int $zIndex = 0,
        bool $clickOutsideDismiss = false,
        bool $autoSize = false,
        ?Border $border = null,
        ?Scanner $scanner = null,
        ?string $lastRendered = null,
        ?RenderSession $session = null,
        ?Position $vPosition = null,
        ?Position $hPosition = null,
        int $posX = 0,
        int $posY = 0,
        string $content = '',
    ) {
        $this->backdropOpacity = \max(0, \min(100, $backdropOpacity));
        $this->animationKind = $animationKind;
        $this->zIndex = $zIndex;
        $this->clickOutsideDismiss = $clickOutsideDismiss;
        $this->autoSize = $autoSize;
        $this->border = $border;
        $this->scanner = $scanner ?? Scanner::new();
        $this->lastRendered = $lastRendered;
        $this->marker = new Mark();
        $this->session = $session ?? new RenderSession();
        $this->vPosition = $vPosition;
        $this->hPosition = $hPosition;
        $this->posX = $posX;
        $this->posY = $posY;
        $this->content = $content;
    }

    /**
     * Create a new Veil instance.
     */
    public static function new(): self
    {
        return new self();
    }

    /**
     * Set the backdrop opacity for dimming the background.
     *
     * @param int $opacity 0–100 (0 = no dimming, 100 = fully dimmed)
     */
    public function withBackdrop(int $opacity): self
    {
        return $this->mutate(backdropOpacity: $opacity);
    }

    /**
     * Set the animation kind for overlay transitions.
     *
     * Passing null clears the animation (composite runs un-animated).
     */
    public function withAnimation(?AnimationKind $kind): self
    {
        return $this->mutate(animationKind: $kind, animationKindSet: true);
    }

    /** Read-only accessor for z-index. */
    public function zIndex(): int
    {
        return $this->zIndex;
    }

    /** Read-only accessor for click-outside-dismiss flag. */
    public function clickOutsideDismiss(): bool
    {
        return $this->clickOutsideDismiss;
    }

    /** Read-only accessor for auto-size flag. */
    public function autoSize(): bool
    {
        return $this->autoSize;
    }

    /** Read-only accessor for border chrome. */
    public function border(): ?Border
    {
        return $this->border;
    }

    /**
     * Set the z-index for stacking order.
     *
     * Veils with higher z-index render on top of those with lower z-index.
     * When rendering a stack, sort by z-index ascending and composite in order.
     */
    public function withZIndex(int $zIndex): self
    {
        return $this->mutate(zIndex: $zIndex);
    }

    /**
     * Set the click-outside-dismiss flag.
     *
     * When true, clicking outside the veil's zone will dismiss it.
     * Uses the self-contained candy-mouse Scanner for hit testing.
     */
    public function withClickOutsideDismiss(bool $enabled = true): self
    {
        return $this->mutate(clickOutsideDismiss: $enabled);
    }

    /**
     * Set the auto-size flag.
     *
     * When true, veil dimensions are computed from content rather than
     * fixed width/height. The border chrome (if present) is applied
     * to the content and the resulting sized output is used.
     */
    public function withAutoSize(bool $enabled = true): self
    {
        return $this->mutate(autoSize: $enabled);
    }

    /**
     * Set the border chrome for wrapping veil content, or clear it with null.
     *
     * Uses candy-sprinkles Border + Style to wrap the content with
     * a terminal border. When combined with autoSize, dimensions
     * are computed from the bordered content.
     */
    public function withBorder(?Border $border): self
    {
        return $this->mutate(border: $border, borderSet: true);
    }

    /**
     * Set the vertical and horizontal position anchors for this veil.
     *
     * When set on a Veil in a VeilStack, compositeAll() will use these
     * positions instead of defaulting to CENTER/CENTER.
     *
     * Passing null for an axis CLEARS that anchor (the veil falls back to
     * the stack default on that axis); offsets always reset to the given
     * values, so withPosition(null, null) restores full defaults.
     *
     * @param Position|null $vertical Vertical anchor (TOP, CENTER, BOTTOM) or null to clear
     * @param Position|null $horizontal Horizontal anchor (LEFT, CENTER, RIGHT) or null to clear
     * @param int $x Additional columns rightward (+) / leftward (-)
     * @param int $y Additional lines downward (+) / upward (-)
     */
    public function withPosition(?Position $vertical, ?Position $horizontal, int $x = 0, int $y = 0): self
    {
        return $this->mutate(
            vPosition: $vertical,
            vPositionSet: true,
            hPosition: $horizontal,
            hPositionSet: true,
            posX: $x,
            posY: $y,
        );
    }

    /**
     * Attach the overlay content this veil paints when a VeilStack drives
     * it. A veil WITHOUT content acts as a backdrop-only layer: the stack
     * pass dims (or passes through) the canvas beneath it and writes no
     * foreground of its own.
     */
    public function withContent(string $content): self
    {
        return $this->mutate(content: $content);
    }

    /** Read-only accessor for the stack-driven overlay content ('' = none). */
    public function content(): string
    {
        return $this->content;
    }

    /** Read-only accessor for vertical position anchor. */
    public function vPosition(): ?Position
    {
        return $this->vPosition;
    }

    /** Read-only accessor for horizontal position anchor. */
    public function hPosition(): ?Position
    {
        return $this->hPosition;
    }

    /** Read-only accessor for X position offset in columns. */
    public function positionX(): int
    {
        return $this->posX;
    }

    /** Read-only accessor for Y position offset in rows. */
    public function positionY(): int
    {
        return $this->posY;
    }

    /**
     * Wrap content with border chrome using Sprinkles Style.
     *
     * @param string $content The content to wrap
     * @return string Content wrapped in border (or unchanged if no border set)
     */
    public function applyBorderChrome(string $content): string
    {
        if ($this->border === null) {
            return $content;
        }
        return Style::new()
            ->border($this->border)
            ->render($content);
    }

    /**
     * Check if a mouse message is outside the veil zone.
     *
     * Requires scan($renderedOutput) to be called first with the
     * rendered veil output so the scanner knows the zone bounds.
     *
     * @throws \RuntimeException if called without prior scan()
     *
     * @see scan()
     * @see hit()
     */
    public function isClickOutside(\SugarCraft\Core\Msg\MouseMsg $mouse): bool
    {
        if ($this->clickOutsideDismiss === FALSE) {
            return false;
        }
        if ($this->lastRendered === null) {
            throw new \RuntimeException(
                'isClickOutside() requires scan() to be called first with the rendered veil output.'
            );
        }
        return $this->hit($mouse->x, $mouse->y) === null;
    }

    /**
     * Feed a rendered output string to the internal scanner so that
     * subsequent hit-testing can determine which zone contains
     * a given coordinate pair.
     *
     * Call this after animate() or composite() return, before calling
     * hit() or isClickOutside().
     *
     * Uses candy-mouse Scanner internally — no external Manager needed.
     *
     * The clone carries a FRESH scanner holding only this render's zones:
     * Scanner::scan() replaces zones wholesale, so reusing the shared
     * instance would only smuggle hidden state across with*() clones
     * (scanning a clone would retro-change the original's hit-testing).
     */
    public function scan(string $rendered): self
    {
        $scanner = Scanner::new();
        $scanner->scan($rendered);
        return $this->mutate(scanner: $scanner, lastRendered: $rendered, lastRenderedSet: true);
    }

    /**
     * Return the zone at the given terminal coordinate, or null if
     * no zone contains that cell.
     *
     * Requires scan() to have been called after the last render.
     */
    public function hit(int $col, int $row): ?Zone
    {
        return $this->scanner->hit($col, $row);
    }

    /**
     * Wrap $content with an invisible zone marker so the scanner
     * can later extract bounding boxes.
     *
     * Uses candy-mouse Mark internally — no external Manager needed.
     */
    public function mark(string $id, string $content): string
    {
        return $this->marker->wrap($id, $content);
    }

    /**
     * Apply animation and composite the overlay onto the background.
     *
     * @param string    $foreground  The overlay content (e.g. a modal)
     * @param string    $background    The base content
     * @param Position  $vertical     Vertical position anchor
     * @param Position  $horizontal   Horizontal position anchor
     * @param float     $progress    Animation progress 0.0–1.0 (0=start, 1=end)
     * @param int       $xOffset      Additional columns rightward (+) / leftward (-)
     * @param int       $yOffset     Additional lines downward (+) / upward (-)
     * @return string                 The composited output
     */
    public function animate(
        string $foreground,
        string $background,
        Position $vertical,
        Position $horizontal,
        float $progress,
        int $xOffset = 0,
        int $yOffset = 0,
    ): string {
        $animFg = $foreground;
        $animXOffset = $xOffset;
        $animYOffset = $yOffset;

        if ($this->animationKind !== null && $progress < 1.0) {
            $result = $this->applyAnimation($foreground, $progress, $vertical, $horizontal);
            $animFg = $result['foreground'];
            $animXOffset = $xOffset + $result['horizontalOffset'];
            $animYOffset = $yOffset + $result['verticalOffset'];
        }

        return $this->composite($animFg, $background, $vertical, $horizontal, $animXOffset, $animYOffset);
    }

    /**
     * Apply the configured animation to the foreground at the given progress.
     *
     * @return array{foreground: string, verticalOffset: int, horizontalOffset: int}
     */
    private function applyAnimation(
        string $foreground,
        float $progress,
        Position $vertical,
        Position $horizontal,
    ): array {
        return match ($this->animationKind) {
            AnimationKind::SLIDE => (new Slide())->apply($foreground, $progress, $vertical, $horizontal),
            AnimationKind::FADE => [
                'foreground' => (new Fade())->apply($foreground, $progress),
                'verticalOffset' => 0,
                'horizontalOffset' => 0,
            ],
            AnimationKind::SCALE => [
                'foreground' => (new Scale())->apply($foreground, $progress),
                'verticalOffset' => 0,
                'horizontalOffset' => 0,
            ],
        };
    }

    /**
     * Composite a foreground string over a background string.
     *
     * On the first composite (or after a resize), emits the full output.
     * On subsequent composites with the same dimensions, emits only the
     * delta via Buffer::diff() + DiffEncoder for reduced SSH bandwidth.
     *
     * @param string    $foreground  The overlay content (e.g. a modal)
     * @param string    $background   The base content
     * @param Position $vertical     Vertical position anchor
     * @param Position $horizontal    Horizontal position anchor
     * @param int       $xOffset      Additional columns rightward (+) / leftward (-)
     * @param int       $yOffset      Additional lines downward (+) / upward (-)
     * @return string                 The composited output
     */
    public function composite(
        string $foreground,
        string $background,
        Position $vertical,
        Position $horizontal,
        int $xOffset = 0,
        int $yOffset = 0,
    ): string {
        $bgLines  = $this->splitLines($background);
        $bgHeight = \count($bgLines);
        $bgWidth  = $this->maxLineWidth($bgLines);

        // When autoSize is enabled, apply border chrome first and compute dimensions from bordered content
        if ($this->autoSize === TRUE) {
            $foreground = $this->applyBorderChrome($foreground);
        }

        $fgLines  = $this->splitLines($foreground);
        $fgHeight = \count($fgLines);
        $fgWidth  = $this->maxLineWidth($fgLines);

        if ($bgHeight === 0 || $bgWidth === 0) {
            return $background;
        }

        // Resolve base position
        $baseX = $horizontal->xOffset($fgWidth, $bgWidth);
        $baseY = $vertical->yOffset($fgHeight, $bgHeight);

        // Apply additional offsets
        $x = $baseX + $xOffset;
        $y = $baseY + $yOffset;

        // Build the output a row at a time. Each foreground line is overlaid as a
        // single styled SEGMENT at [x, x + visibleWidth) — cell-aware (via Width)
        // so its ANSI escape sequences are never split — and the backdrop is dimmed
        // only OUTSIDE the foreground footprint, so the overlay stays bright over a
        // dimmed background instead of inheriting the dim.
        //
        // Unlike the old clamp, we allow $x/$y to fall entirely outside the
        // background bounds so that animations (e.g. SLIDE at progress 0) can
        // position the overlay fully off-screen without showing a sliver.
        $output = [];
        for ($row = 0; $row < $bgHeight; $row++) {
            $bgLine = $bgLines[$row];
            $fy = $row - $y;

            if ($fy < 0 || $fy >= $fgHeight) {
                // Not covered by the foreground — dim the whole background line.
                $output[$row] = $this->dimLine($bgLine);
                continue;
            }

            // If the overlay starts to the right of the background, skip this row.
            if ($x >= $bgWidth) {
                $output[$row] = $this->dimLine($bgLine);
                continue;
            }

            // Mirror guard for the left edge: an overlay whose whole footprint
            // ends at or left of column 0 (negative xOffset, or an anchor
            // centre where fg is wider than bg) shows nothing — including a
            // zero-width line, whose drop below lands at x=0.
            if ($x + Width::string($fgLines[$fy]) <= 0) {
                $output[$row] = $this->dimLine($bgLine);
                continue;
            }

            // Clip the foreground line to the row, keeping its escapes cell-
            // aware (via Width): drop the head when x < 0 so off-screen-left
            // columns never leak onto col 0, then truncate to the room left.
            $fgLine = $x < 0
                ? Width::truncateAnsi(Width::dropAnsi($fgLines[$fy], -$x), $bgWidth)
                : Width::truncateAnsi($fgLines[$fy], $bgWidth - $x);
            $fgVis  = Width::string($fgLine);

            // Compute prefix/suffix from the background; with the head clipped
            // the overlay always starts at column max(0, x).
            $prefixWidth = \max(0, $x);
            $prefix = Width::padRight(Width::truncateAnsi($bgLine, $prefixWidth), $prefixWidth);
            $suffix = Width::dropAnsi($bgLine, $prefixWidth + $fgVis);

            $output[$row] = $this->dimLine($prefix) . $fgLine . $this->dimLine($suffix);
        }

        $fullOutput = \implode("\n", $output);

        if ($this->session->shouldEmitFull($bgWidth, $bgHeight) === TRUE) {
            $this->session->rememberFull($fullOutput, $bgWidth, $bgHeight);
            return $fullOutput;
        }

        return $this->session->diff(
            $fullOutput,
            $bgWidth,
            $bgHeight,
            fn(string $out, int $w, int $h): Buffer => $this->bufferFromOutput($out, $w, $h),
        );
    }

    /**
     * Dim a single background line using truecolor opacity blend toward black.
     *
     * Uses a truecolor foreground color that is the default terminal foreground
     * (white-ish) blended toward black proportional to the backdrop opacity.
     * This replaces the old nested FAINT approach which only produced ~2 visual
     * states; the truecolor blend gives a smooth gradient across 0–100%.
     *
     * For backdrop lines (lines without embedded ANSI styling that starts the
     * line), the gray blend achieves visible dimming. For lines that START
     * with an escape sequence (CSI styling like bold/color codes, or OSC
     * payloads such as hyperlinks), the line is returned unchanged to
     * preserve original styling (matching the old FAINT behavior where
     * styled text was not dimmed) — wrapping an escape-led line in color SGR
     * would corrupt the payload it carries.
     */
    private function dimLine(string $line): string
    {
        $opacity = $this->backdropOpacity;
        if ($opacity === 0 || $line === '') {
            return $line;
        }

        // If line starts with ANY escape introducer (CSI 'ESC [', OSC 'ESC ]',
        // charset selects, …), preserve it unchanged. A lone trailing ESC is
        // length-guarded so no offset read walks past the string end.
        if ($line[0] === "\e" && $line !== "\e") {
            return $line;
        }

        // Default terminal foreground (approx white) blended toward black
        // by the opacity percentage. Formula: new = original * (1 - opacity/100)
        $factor = 1.0 - ($opacity / 100.0);
        $r = (int) \round(255 * $factor);
        $g = (int) \round(255 * $factor);
        $b = (int) \round(255 * $factor);

        return "\e[38;2;{$r};{$g};{$b}m{$line}\e[39m";
    }

    /**
     * Split a multi-line string into an array of lines.
     *
     * @return list<string>
     */
    public function splitLines(string $text): array
    {
        $lines = \explode("\n", $text);
        // Remove trailing empty line from final \n
        if (\end($lines) === '') {
            \array_pop($lines);
        }
        return $lines;
    }

    /**
     * Get the maximum line width (in characters) of an array of lines.
     *
     * @param list<string> $lines
     */
    public function maxLineWidth(array $lines): int
    {
        $max = 0;
        foreach ($lines as $line) {
            $w = $this->lineWidth($line);
            if ($w > $max) $max = $w;
        }
        return $max;
    }

    /**
     * Get the display width of a single line (stripping ANSI escape codes).
     */
    public function lineWidth(string $line): int
    {
        return Width::string($line);
    }

    /**
     * Create a new instance with updated properties.
     *
     * Nullable fields use paired `…Set` sentinels so an explicit null
     * CLEARS the value (house immutable-fluent law): without a sentinel,
     * `?? $this->x` cannot distinguish "set to null" from "leave alone".
     */
    private function mutate(
        ?int $backdropOpacity = null,
        ?AnimationKind $animationKind = null,
        bool $animationKindSet = false,
        ?int $zIndex = null,
        ?bool $clickOutsideDismiss = null,
        ?bool $autoSize = null,
        ?Border $border = null,
        bool $borderSet = false,
        ?Scanner $scanner = null,
        ?string $lastRendered = null,
        bool $lastRenderedSet = false,
        ?Position $vPosition = null,
        bool $vPositionSet = false,
        ?Position $hPosition = null,
        bool $hPositionSet = false,
        ?int $posX = null,
        ?int $posY = null,
        ?string $content = null,
    ): self {
        return new self(
            backdropOpacity: $backdropOpacity ?? $this->backdropOpacity,
            animationKind: $animationKindSet ? $animationKind : $this->animationKind,
            zIndex: $zIndex ?? $this->zIndex,
            clickOutsideDismiss: $clickOutsideDismiss ?? $this->clickOutsideDismiss,
            autoSize: $autoSize ?? $this->autoSize,
            border: $borderSet ? $border : $this->border,
            scanner: $scanner,
            lastRendered: $lastRenderedSet ? $lastRendered : $this->lastRendered,
            session: $this->session,
            vPosition: $vPositionSet ? $vPosition : $this->vPosition,
            hPosition: $hPositionSet ? $hPosition : $this->hPosition,
            posX: $posX ?? $this->posX,
            posY: $posY ?? $this->posY,
            content: $content ?? $this->content,
        );
    }

    /**
     * Build a Buffer from a multi-line string output.
     *
     * Walks each row with a terminal-faithful tokenizer (mirroring the
     * candy-vt emulator's cell representation, ESC-2/ESC-3 in commit
     * 9a8c8879c) so the diff compares what a terminal would actually
     * SHOW, not raw bytes:
     *
     *  - grapheme clusters get their DISPLAY width from Width::of();
     *    a width-2 head is followed by a Cell::continuation() tail, so
     *    CJK text lands on the columns the terminal paints it on.
     *  - CSI SGR sequences mutate a running pen; every cell stamped
     *    after them carries that Style, so a style-only frame change
     *    (e.g. backdrop opacity) is visible to Cell::equals() and
     *    produces a delta instead of being diffed away.
     *  - OSC 8 hyperlinks open/close a running link pen (an empty URI
     *    closes; SGR reset does NOT, per xterm) and other OSC sequences
     *    are consumed through BEL/ST — never emitted as visible cells.
     *  - candy-mouse zone sentinels (U+E000 id U+E001 triples) are
     *    invisible to terminals, so they consume ZERO columns here.
     *
     * @param string $output Multi-line string from composite()
     * @param int    $width  Buffer width in cells
     * @param int    $height Buffer height in rows
     */
    private function bufferFromOutput(string $output, int $width, int $height): Buffer
    {
        $lines = \explode("\n", $output);
        /** @var list<Cell> $grid */
        $grid = [];
        for ($row = 0; $row < $height; $row++) {
            foreach ($this->paintRow($lines[$row] ?? '', $width) as $cell) {
                $grid[] = $cell;
            }
        }

        return Buffer::fromGrid($width, $height, $grid);
    }

    /**
     * Tokenize one rendered row into exactly $width grid cells.
     *
     * @return list<Cell>
     */
    private function paintRow(string $line, int $width): array
    {
        // Blank row first; visible placement overwrites from the left. This
        // keeps fromGrid()'s exact width*height contract regardless of where
        // the walk stops (row overrun, unplaceable wide glyph).
        /** @var list<Cell> $cells */
        $cells = [];
        for ($i = 0; $i < $width; $i++) {
            $cells[] = Cell::new(' ', null, null, 1);
        }

        $pen  = null;   // running SGR style
        $link = null;   // running OSC 8 link (survives SGR reset, per xterm)

        $len = \strlen($line);
        $pos = 0;
        $col = 0;

        while ($pos < $len) {
            $byte = $line[$pos];

            // ESC-introducers: CSI and OSC only — both consume zero columns.
            if ($byte === "\e" && $pos + 1 < $len) {
                $intro = $line[$pos + 1];

                if ($intro === '[') {
                    // CSI: parameters run until the first final byte (0x40–0x7E).
                    // Only SGR ('m') feeds the pen; cursor moves and the rest are
                    // skipped whole (the old ctype_alpha scan mis-terminated on
                    // parameter bytes like 'p' or '>').
                    $p = $pos + 2;
                    while ($p < $len && \ord($line[$p]) < 0x40) {
                        $p++;
                    }
                    if ($p < $len && $line[$p] === 'm') {
                        $pen = self::penFromSgr(\substr($line, $pos + 2, $p - $pos - 2), $pen);
                    }
                    $pos = $p < $len ? $p + 1 : $len;
                    continue;
                }

                if ($intro === ']') {
                    // OSC: terminated by BEL or ST (ESC \). Unterminated OSC
                    // swallows the rest of the row, matching the emulator.
                    $tail = $pos + 2 + \strcspn($line, "\x07\x1b", $pos + 2);
                    $body = \substr($line, $pos + 2, $tail - $pos - 2);
                    if ($tail < $len && $line[$tail] === "\x1b") {
                        $pos = $tail + 2; // swallow ESC + the ST backslash
                    } else {
                        $pos = $tail < $len ? $tail + 1 : $len; // BEL consumed
                    }
                    $link = self::linkFromOsc($body, $link);
                    continue;
                }

                // Any other two-byte ESC sequence (ESC ( B charset selects, a
                // stray ST, …): consume both bytes without touching the pens.
                $pos += 2;
                continue;
            }

            // candy-mouse zone sentinels: U+E000 … U+E001 triples (the id
            // rides BETWEEN them in plain ASCII) plus stray closes are
            // zero-column to a terminal. An unmatched open swallows the tail.
            if ($byte === "\xEE" && $pos + 2 < $len && $line[$pos + 1] === "\x80") {
                if ($line[$pos + 2] === "\x80") { // U+E000 OPEN — skip id to CLOSE
                    $close = \strpos($line, "\xEE\x80\x81", $pos + 3);
                    $pos = $close === FALSE ? $len : $close + 3;
                    continue;
                }
                if ($line[$pos + 2] === "\x81") { // U+E001 CLOSE without an OPEN
                    $pos += 3;
                    continue;
                }
            }

            $cluster = self::nextCluster($line, $pos);
            $gw      = Width::of($cluster);

            if ($gw === 0) {
                // Combining marks / ZWJ joiners render inside the preceding
                // cell; giving them their own column would shift the row
                // against the terminal's own layout. Drop them (the base
                // grapheme already placed at this column carries the glyph).
                $pos += \strlen($cluster);
                continue;
            }

            if ($col + $gw > $width) {
                break; // no room left on this row (wide glyph at last column)
            }

            $cells[$col] = Cell::new($cluster, $pen, $link, $gw);
            if ($gw === 2) {
                $cells[$col + 1] = Cell::continuation();
            }
            $col += $gw;
            $pos += \strlen($cluster);
        }

        return $cells;
    }

    /**
     * Next user-perceived grapheme cluster starting at byte $pos.
     *
     * grapheme_str_split() is PHP 8.4+; on 8.3 fall back to the UTF-8
     * lead-byte walk (same cascade sugar-charts' BufferHelper uses) so
     * clustering is stable across PHP versions. Combining marks ride with
     * their base character here rather than becoming stray cells.
     */
    private static function nextCluster(string $s, int $pos): string
    {
        $b = \ord($s[$pos]);
        $len = $b < 0x80 ? 1 : (($b & 0xE0) === 0xC0 ? 2 : (($b & 0xF0) === 0xE0 ? 3 : (($b & 0xF8) === 0xF0 ? 4 : 1)));
        $cluster = \substr($s, $pos, $len);

        // Attach following combining marks (U+0300–U+036F) and variation
        // selectors (U+FE00–U+FE0F) so one cell = one rendered glyph.
        $i = $pos + $len;
        $sLen = \strlen($s);
        while ($i < $sLen) {
            $nb = \ord($s[$i]);
            if ($nb === 0xCC || $nb === 0xCD) { // U+0300–U+036F (2-byte 0xCC 0x80–0xBF, 0xCD 0x80–0xAF)
                $cp = (($nb & 0x1F) << 6) | (\ord($s[$i + 1] ?? "\x80") & 0x3F);
                if ($cp >= 0x0300 && $cp <= 0x036F) {
                    $cluster .= \substr($s, $i, 2);
                    $i += 2;
                    continue;
                }
                break;
            }
            if ($nb === 0xEF) { // 3-byte U+FE00–U+FE0F: EF B8 80–BF / EF B8 90–8F…
                $cpSub = \substr($s, $i, 3);
                if (\preg_match('/\A\xEF\xB8[\x80-\xAF]\z/', $cpSub) === 1) {
                    $cluster .= $cpSub;
                    $i += 3;
                    continue;
                }
                break;
            }
            break;
        }

        return $cluster;
    }

    /**
     * Merge one CSI SGR parameter string into the live pen.
     *
     * Mirrors Buffer::styleFromSgr() (private in candy-buffer) — reset,
     * 16-colour + bright ranges, 38;5/38;2 and 48;5/48;2 colour keys,
     * 39/49 default colours, and the attribute on-codes — so veil's diff
     * pen and candy-buffer's own parse agree on what a style means.
     * SGR reset deliberately does NOT clear the link pen (xterm scopes
     * links to the OSC 8 protocol).
     */
    private static function penFromSgr(string $params, ?BufferStyle $carry): ?BufferStyle
    {
        $codes = \array_map('intval', \explode(';', $params));

        $fg    = $carry?->fg();
        $bg    = $carry?->bg();
        $attrs = $carry?->attrs() ?? 0;

        $count = \count($codes);
        for ($i = 0; $i < $count; $i++) {
            $p = $codes[$i];
            if ($p === 0) {
                $fg = null;
                $bg = null;
                $attrs = 0;
            } elseif ($p >= 30 && $p <= 37) {
                $fg = self::ansiColorToHex($p - 30);
            } elseif ($p >= 40 && $p <= 47) {
                $bg = self::ansiColorToHex($p - 40);
            } elseif ($p >= 90 && $p <= 97) {
                $fg = self::ansiColorToHex($p - 90, bright: true);
            } elseif ($p >= 100 && $p <= 107) {
                $bg = self::ansiColorToHex($p - 100, bright: true);
            } elseif ($p === 39) {
                $fg = null;
            } elseif ($p === 49) {
                $bg = null;
            } elseif (($p === 38 || $p === 48) && $i + 1 < $count) {
                if ($codes[$i + 1] === 2 && $i + 4 < $count) {
                    $rgb = (($codes[$i + 2] & 0xFF) << 16)
                        | (($codes[$i + 3] & 0xFF) << 8)
                        | ($codes[$i + 4] & 0xFF);
                    if ($p === 38) {
                        $fg = $rgb;
                    } else {
                        $bg = $rgb;
                    }
                    $i += 4; // consumed 2;r;g;b
                } elseif ($codes[$i + 1] === 5 && $i + 2 < $count) {
                    $rgb = self::xterm256ToHex($codes[$i + 2]);
                    if ($p === 38) {
                        $fg = $rgb;
                    } else {
                        $bg = $rgb;
                    }
                    $i += 2; // consumed 5;n
                } else {
                    $i = $count; // malformed extended colour: stop, keep prior pen
                }
            } elseif ($p === 1) {
                $attrs |= BufferStyle::ATTR_BOLD;
            } elseif ($p === 2) {
                $attrs |= BufferStyle::ATTR_FAINT;
            } elseif ($p === 3) {
                $attrs |= BufferStyle::ATTR_ITALIC;
            } elseif ($p === 4) {
                $attrs |= BufferStyle::ATTR_UNDERLINE;
            } elseif ($p === 5) {
                $attrs |= BufferStyle::ATTR_BLINK;
            } elseif ($p === 7) {
                $attrs |= BufferStyle::ATTR_REVERSE;
            } elseif ($p === 8) {
                $attrs |= BufferStyle::ATTR_INVISIBLE;
            } elseif ($p === 9) {
                $attrs |= BufferStyle::ATTR_STRIKE;
            }
        }

        return $fg !== null || $bg !== null || $attrs !== 0
            ? new BufferStyle($fg, $bg, $attrs)
            : null;
    }

    /** Base-16 palette hexes, matching Buffer::ansiColorToHex(). */
    private static function ansiColorToHex(int $idx, bool $bright = false): int
    {
        $palette = [
            0x000000, 0xff0000, 0x00ff00, 0xffff00,
            0x0000ff, 0xff00ff, 0x00ffff, 0xffffff,
        ];
        $base = $palette[$idx] ?? 0xffffff;
        if ($bright === false) {
            return $base;
        }
        $lift = static fn(int $c): int => min(255, (int) ($c + ($c * 0.4)));
        return ($lift(($base >> 16) & 0xFF) << 16) | ($lift(($base >> 8) & 0xFF) << 8) | $lift($base & 0xFF);
    }

    /** Standard xterm 256-colour cube → 0xRRGGBB. */
    private static function xterm256ToHex(int $n): int
    {
        if ($n < 8) {
            return self::ansiColorToHex($n);
        }
        if ($n < 16) {
            return self::ansiColorToHex($n - 8, bright: true);
        }
        if ($n >= 232) {
            $gray = 8 + ($n - 232) * 10;
            return ($gray << 16) | ($gray << 8) | $gray;
        }
        $cube = $n - 16;
        $step = static fn(int $v): int => $v === 0 ? 0 : 55 + $v * 40;
        return ($step(intdiv($cube, 36)) << 16)
            | ($step(intdiv($cube % 36, 6)) << 8)
            | $step($cube % 6);
    }

    /**
     * Apply one OSC body to the link pen: OSC 8 opens (or, with an empty
     * URI, closes) a hyperlink; every other OSC code is consumed without
     * touching the pen.
     */
    private static function linkFromOsc(string $body, ?Hyperlink $carry): ?Hyperlink
    {
        if (\str_starts_with($body, '8;') === false) {
            return $carry;
        }
        $rest = \substr($body, 2);
        $sep  = \strpos($rest, ';');
        if ($sep === FALSE) {
            return $carry; // malformed OSC 8 (no URI field) — leave pen unchanged
        }
        $params = \substr($rest, 0, $sep);
        $url    = \substr($rest, $sep + 1);

        if ($url === '') {
            return null; // empty URI closes the link (OSC 8 spec)
        }

        // BEL/ST terminated the body already, so the URL carries no C0
        // controls by construction; Hyperlink's own guard stays load-bearing
        // against anything that skips past this parse.
        \preg_match('/(?:\A|:)id=([^:]*)/', $params, $m);
        return new Hyperlink($url, $m[1] ?? '');
    }

    /**
     * Reset the previous-frame buffer, forcing the next composite to emit
     * a full frame (used on window resize or cursor-position-lost events).
     *
     * NOTE: This does NOT reset the scanner state. Hit-testing reads the
     * zones from the most recent scan() output — Scanner::scan() replaces
     * zones wholesale, so a re-scan after the next render refreshes them;
     * zones are not accumulated across frames. If you need an unscanned
     * instance, create a new Veil instead.
     *
     * @see scan()
     * @see withoutSession()
     */
    public function resetPreviousFrame(): void
    {
        $this->session->reset();
    }

    /**
     * Return a copy of this veil with a fresh RenderSession.
     *
     * This ensures that when a veil is reused in a stacking context,
     * inner compositing operations always emit FULL frames rather than
     * deltas that would otherwise corrupt the chaining computation.
     */
    public function withoutSession(): self
    {
        return new self(
            backdropOpacity: $this->backdropOpacity,
            animationKind: $this->animationKind,
            zIndex: $this->zIndex,
            clickOutsideDismiss: $this->clickOutsideDismiss,
            autoSize: $this->autoSize,
            border: $this->border,
            scanner: $this->scanner,
            lastRendered: $this->lastRendered,
            session: new RenderSession(),
            vPosition: $this->vPosition,
            hPosition: $this->hPosition,
            posX: $this->posX,
            posY: $this->posY,
            content: $this->content,
        );
    }
}
