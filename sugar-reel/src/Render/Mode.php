<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Render;

/**
 * Rendering mode for terminal video output.
 *
 * No single upstream — drawn from maxcurzi/tplay, seatedro/glyph, joelibaceta/video-to-ascii.
 */
enum Mode: string
{
    /** Plain ASCII characters only — no color. */
    case Ascii = 'ascii';

    /** 256-color ANSI with grayscale characters. */
    case Ansi256 = 'ansi256';

    /** 24-bit TrueColor with grayscale characters. */
    case TrueColor = 'truecolor';

    /** Unicode half-block (▀) rendering two rows per cell. */
    case HalfBlock = 'halfblock';

    /** Unicode quarter-block rendering a 2×2 pixel group per cell. */
    case QuarterBlock = 'quarterblock';

    /** Sixel raster graphics protocol. */
    case Sixel = 'sixel';

    /** Kitty inline image protocol. */
    case Kitty = 'kitty';

    /** iTerm2 inline image protocol. */
    case Iterm2 = 'iterm2';

    /** Source pixel-rows consumed per terminal cell. Half/quarter-block pack 2; graphics modes resolve in the renderer (treated as 1 here). */
    public function rowsPerCell(): int
    {
        return $this === self::HalfBlock || $this === self::QuarterBlock ? 2 : 1;
    }

    /** Source pixel-cols per terminal cell. Quarter-block packs 2 across. */
    public function colsPerCell(): int
    {
        return $this === self::QuarterBlock ? 2 : 1;
    }

    /**
     * Whether this is a pixel-graphics protocol (Sixel/Kitty/iTerm2) rather than
     * a text/cell mode. Graphics modes decode at the terminal's full pixel
     * resolution (cells × cell-pixel-size) and emit a real image, so the decoder
     * sizes their frames from the cell pixel geometry — not the 1-/2-rows-per-cell
     * packing the block modes use.
     */
    public function isGraphics(): bool
    {
        return $this === self::Sixel || $this === self::Kitty || $this === self::Iterm2;
    }

    /**
     * Human-readable description of the rendering mode.
     */
    public function label(): string
    {
        return match ($this) {
            self::Ascii        => 'Plain ASCII (no color)',
            self::Ansi256      => 'ANSI 256-color',
            self::TrueColor    => 'TrueColor 24-bit',
            self::HalfBlock    => 'Unicode half-block (▀)',
            self::QuarterBlock => 'Unicode quarter-block (▚)',
            self::Sixel        => 'Sixel raster graphics',
            self::Kitty        => 'Kitty inline images',
            self::Iterm2       => 'iTerm2 inline images',
        };
    }
}
