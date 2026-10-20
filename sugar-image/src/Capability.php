<?php

declare(strict_types=1);

namespace SugarCraft\Image;

/**
 * Snapshot of the detected terminal's image-rendering capabilities.
 * Immutable value object returned by {@see Detect::probe()}.
 */
final class Capability
{
    private function __construct(
        public readonly bool $sixel,
        public readonly bool $kitty,
        public readonly bool $iterm2,
        public readonly bool $halfblock,
        public readonly bool $chafa,
        /** Cell pixel dimensions if probed, null otherwise. */
        public readonly ?CellSize $cellSize,
        /** True when TMUX env var is set (requires allow-passthrough on). */
        public readonly bool $inTmux,
        /**
         * F-08: terminal default foreground from the OSC 10 query, lowercase
         * 6-hex-digit 'rrggbb' — the same packed 0xRRGGBB fact chafa stores
         * from its reply parse (chafa-term.c:403-409 probe_color_to_packed_
         * rgb); where chafa keeps -1 for "unknown" the null of this ?string
         * carries it. Reply components are scaled by the XTerm/Xcms rule —
         * digits replicated to 16 bits, high byte taken, identical to
         * chafa's /256 on the 16-bit replies chafa models. Null until
         * probed AND answered — see {@see Detect::probeDefaultColors()}.
         */
        public readonly ?string $defaultFg = null,
        /** F-08: OSC 11 default background, same representation and sentinel. */
        public readonly ?string $defaultBg = null,
    ) {}

    /**
     * Build a full-capability instance (all protocols available).
     * Used for {@see Mosaic::halfBlock()} which is always available.
     */
    public static function universal(?CellSize $cellSize = null, bool $inTmux = false): self
    {
        return new self(true, true, true, true, true, $cellSize, $inTmux);
    }

    /** Kitty-only capability (other protocols unknown until DA1 probing). */
    public static function kitty(?CellSize $cellSize = null, bool $inTmux = false): self
    {
        return new self(false, true, false, true, false, $cellSize, $inTmux);
    }

    /** iTerm2-only capability. */
    public static function iterm2(?CellSize $cellSize = null, bool $inTmux = false): self
    {
        return new self(false, false, true, true, false, $cellSize, $inTmux);
    }

    /** Sixel-only capability. */
    public static function sixel(?CellSize $cellSize = null, bool $inTmux = false): self
    {
        return new self(true, false, false, true, false, $cellSize, $inTmux);
    }

    /**
     * Empty capability set — used when no probe data is available.
     */
    public static function unknown(?CellSize $cellSize = null, bool $inTmux = false): self
    {
        return new self(false, false, false, true, false, $cellSize, $inTmux);
    }

    /**
     * Return a copy of this capability with the given cell size attached.
     * Used by {@see Detect::probeFontSize()} to attach font-size data
     * to whatever protocol was detected via env vars or DA1.
     */
    public function withCellSize(?CellSize $cellSize): self
    {
        return new self(
            $this->sixel,
            $this->kitty,
            $this->iterm2,
            $this->halfblock,
            $this->chafa,
            $cellSize,
            $this->inTmux,
            // F-08: colors survive a cell-size change — the two carriers are
            // orthogonal facets of the same probe, neither may blank the other.
            $this->defaultFg,
            $this->defaultBg,
        );
    }

    /**
     * F-08: Return a copy of this capability with the terminal's reported
     * default colours attached (lowercase 'rrggbb', null keeps unknown),
     * shaped exactly like {@see withCellSize()} — the OSC 10/11 answers
     * ride onto whichever protocol rung the ladder answered on.
     */
    public function withDefaultColors(?string $defaultFg, ?string $defaultBg): self
    {
        return new self(
            $this->sixel,
            $this->kitty,
            $this->iterm2,
            $this->halfblock,
            $this->chafa,
            $this->cellSize,
            $this->inTmux,
            $defaultFg,
            $defaultBg,
        );
    }

    /**
     * Return a human-readable summary of which protocols were detected.
     * Mirrors Charmbracelet's image.(Kitty|Sixel|Iterm2|HalfBlock)Protocol.Name().
     *
     * @return string e.g. "Kitty", "iTerm2", "Sixel", "HalfBlock", "Unknown"
     */
    public function detectSummary(): string
    {
        if ($this->kitty) {
            return 'Kitty';
        }
        if ($this->iterm2) {
            return 'iTerm2';
        }
        if ($this->sixel) {
            return 'Sixel';
        }
        if ($this->chafa) {
            return 'Chafa';
        }
        if ($this->halfblock) {
            return 'HalfBlock';
        }

        return 'Unknown';
    }
}
