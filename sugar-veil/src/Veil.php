<?php

declare(strict_types=1);

namespace SugarCraft\Veil;

use SugarCraft\Buffer\Buffer;
use SugarCraft\Buffer\Cell;
use SugarCraft\Core\Util\Ansi;
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
use SugarCraft\Zone\Manager;

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

    /** @var Manager|null Stored manager for back-compat only (deprecated) */
    private readonly ?Manager $manager;

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

    /** @var bool Whether per-veil position was explicitly set */
    private readonly bool $positionSet;

    /**
     * @param int             $backdropOpacity 0–100 backdrop dimming
     * @param AnimationKind|null $animationKind Animation kind for transitions
     * @param int $zIndex Stacking order
     * @param bool $clickOutsideDismiss Dismiss on outside click
     * @param bool $autoSize Compute size from content
     * @param Border|null $border Border chrome
     * @param Scanner|null $scanner Self-contained mouse hit-testing scanner
     * @param string|null $lastRendered Last rendered output for scanner
     * @param Manager|null $manager Stored manager for back-compat (deprecated)
     * @param RenderSession|null $session Mutable diff/session state
     * @param Position|null $vPosition Vertical position anchor
     * @param Position|null $hPosition Horizontal position anchor
     * @param int $posX X offset in columns
     * @param int $posY Y offset in rows
     * @param bool $positionSet Whether position was explicitly set
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
        ?Manager $manager = null,
        ?RenderSession $session = null,
        ?Position $vPosition = null,
        ?Position $hPosition = null,
        int $posX = 0,
        int $posY = 0,
        bool $positionSet = false,
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
        $this->manager = $manager;
        $this->session = $session ?? new RenderSession();
        $this->vPosition = $vPosition;
        $this->hPosition = $hPosition;
        $this->posX = $posX;
        $this->posY = $posY;
        $this->positionSet = $positionSet;
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
     */
    public function withAnimation(AnimationKind $kind): self
    {
        return $this->mutate(animationKind: $kind);
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
     * Uses candy-zone Manager for hit testing.
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
     * Set the border chrome for wrapping veil content.
     *
     * Uses candy-sprinkles Border + Style to wrap the content with
     * a terminal border. When combined with autoSize, dimensions
     * are computed from the bordered content.
     */
    public function withBorder(Border $border): self
    {
        return $this->mutate(border: $border);
    }

    /**
     * Set the vertical and horizontal position anchors for this veil.
     *
     * When set on a Veil in a VeilStack, compositeAll() will use these
     * positions instead of the hardcoded TOP/LEFT defaults.
     *
     * @param Position $vertical Vertical anchor (TOP, CENTER, BOTTOM)
     * @param Position $horizontal Horizontal anchor (LEFT, CENTER, RIGHT)
     * @param int $x Additional columns rightward (+) / leftward (-)
     * @param int $y Additional lines downward (+) / upward (-)
     */
    public function withPosition(Position $vertical, Position $horizontal, int $x = 0, int $y = 0): self
    {
        return $this->mutate(
            vPosition: $vertical,
            hPosition: $horizontal,
            posX: $x,
            posY: $y,
            positionSet: true,
        );
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
     * Set the zone manager for click-outside hit testing.
     *
     * @deprecated Self-contained candy-mouse Scanner replaces external Manager.
     *   A self-contained Scanner is always used for hit-testing. The Manager
     *   parameter is stored for back-compat only. Prefer scan()/hit() instead.
     */
    public function withManager(Manager $manager): self
    {
        return $this->mutate(manager: $manager);
    }

    /**
     * Zone manager for click-outside hit testing.
     *
     * @deprecated Self-contained candy-mouse Scanner replaces external Manager.
     *   Use the self-contained scanner via scan()/hit() instead.
     */
    public function manager(): ?Manager
    {
        return $this->manager;
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
        if (!$this->clickOutsideDismiss) {
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
     */
    public function scan(string $rendered): self
    {
        $this->scanner->scan($rendered);
        return $this->mutate(scanner: $this->scanner, lastRendered: $rendered);
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
        if ($this->autoSize) {
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

            // Clip the foreground line to the room left on this row (keeping its
            // escapes) and measure its visible footprint.
            $fgLine = Width::truncateAnsi($fgLines[$fy], $bgWidth - $x);
            $fgVis  = Width::string($fgLine);

            // Compute prefix/suffix from the background, handling off-screen left.
            $prefixWidth = \max(0, $x);
            $prefix = Width::padRight(Width::truncateAnsi($bgLine, $prefixWidth), $prefixWidth);
            $suffix = Width::dropAnsi($bgLine, $x + $fgVis);

            $output[$row] = $this->dimLine($prefix) . $fgLine . $this->dimLine($suffix);
        }

        $fullOutput = \implode("\n", $output);

        if ($this->session->shouldEmitFull($bgWidth, $bgHeight)) {
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
     * with an ANSI sequence (e.g. the foreground overlay row with its own
     * bold/color codes), the line is returned unchanged to preserve original
     * styling (matching the old FAINT behavior where styled text was not dimmed).
     *
     * Lines that start with the old FAINT code (\e[2m) are replaced with the
     * truecolor blend so the gradient is continuous rather than 2-state.
     */
    private function dimLine(string $line): string
    {
        $opacity = $this->backdropOpacity;
        if ($opacity === 0 || $line === '') {
            return $line;
        }

        // If line starts with an ANSI sequence, preserve it unchanged.
        // This matches the old FAINT behavior: styled text (starting with bold,
        // color, etc.) was not dimmed. Lines that start with \e[2m (old FAINT
        // dim codes) are replaced with truecolor for the gradient effect.
        if ($line[0] === "\e" && $line[1] === '[') {
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
     */
    private function mutate(
        ?int $backdropOpacity = null,
        ?AnimationKind $animationKind = null,
        ?int $zIndex = null,
        ?bool $clickOutsideDismiss = null,
        ?bool $autoSize = null,
        ?Border $border = null,
        ?Scanner $scanner = null,
        ?string $lastRendered = null,
        ?Manager $manager = null,
        ?Position $vPosition = null,
        ?Position $hPosition = null,
        ?int $posX = null,
        ?int $posY = null,
        ?bool $positionSet = null,
    ): self {
        return new self(
            backdropOpacity: $backdropOpacity ?? $this->backdropOpacity,
            animationKind: $animationKind ?? $this->animationKind,
            zIndex: $zIndex ?? $this->zIndex,
            clickOutsideDismiss: $clickOutsideDismiss ?? $this->clickOutsideDismiss,
            autoSize: $autoSize ?? $this->autoSize,
            border: $border ?? $this->border,
            scanner: $scanner,
            lastRendered: $lastRendered ?? $this->lastRendered,
            manager: $manager ?? $this->manager,
            session: $this->session,
            vPosition: $vPosition ?? $this->vPosition,
            hPosition: $hPosition ?? $this->hPosition,
            posX: $posX ?? $this->posX,
            posY: $posY ?? $this->posY,
            positionSet: $positionSet ?? $this->positionSet,
        );
    }

    /**
     * Build a Buffer from a multi-line string output.
     *
     * Uses ANSI-aware iteration to map VISIBLE characters (not raw bytes)
     * to grid columns, so SGR escape sequences do not shift visible content.
     * All cells are created with null style — the diff algorithm will
     * still work correctly for detecting changed character positions.
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
            $line = $lines[$row] ?? '';
            $col = 0;
            $lineLen = \strlen($line);
            $pos = 0;

            while ($pos < $lineLen) {
                // Skip ANSI escape sequences (CSI format: ESC [ ... letter)
                if ($line[$pos] === "\e" && $pos + 1 < $lineLen && $line[$pos + 1] === '[') {
                    $pos += 2; // skip ESC [
                    while ($pos < $lineLen && !\ctype_alpha($line[$pos])) {
                        $pos++;
                    }
                    if ($pos < $lineLen) {
                        $pos++; // skip the terminating letter
                    }
                    continue;
                }

                if ($col >= $width) {
                    break;
                }

                // Place this visible character at the current column
                $char = \mb_substr($line, $pos, 1);
                $grid[$row * $width + $col] = Cell::new($char, null, null, 1);
                $col++;
                $pos++;
            }

            // Pad remaining columns with spaces
            while ($col < $width) {
                $grid[$row * $width + $col] = Cell::new(' ', null, null, 1);
                $col++;
            }
        }

        return Buffer::fromGrid($width, $height, $grid);
    }

    /**
     * Reset the previous-frame buffer, forcing the next composite to emit
     * a full frame (used on window resize or cursor-position-lost events).
     *
     * NOTE: This does NOT reset the scanner state. The scanner accumulates
     * zone data across frames intentionally — zones from previous renders
     * persist for hit-testing. If you need a fresh scanner, create a new
     * Veil instance instead.
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
            manager: $this->manager,
            session: new RenderSession(),
            vPosition: $this->vPosition,
            hPosition: $this->hPosition,
            posX: $this->posX,
            posY: $this->posY,
            positionSet: $this->positionSet,
        );
    }
}
