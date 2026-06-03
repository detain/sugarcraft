<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Render;

/**
 * Rendering mode for terminal video output.
 *
 * Mirrors charmbracelet/sugar-reel rendering modes.
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

    /** Sixel raster graphics protocol. */
    case Sixel = 'sixel';

    /** Kitty inline image protocol. */
    case Kitty = 'kitty';

    /** iTerm2 inline image protocol. */
    case Iterm2 = 'iterm2';

    /** Source pixel-rows consumed per terminal cell. HalfBlock packs 2; graphics modes resolve in the renderer (treated as 1 here). */
    public function rowsPerCell(): int
    {
        return $this === self::HalfBlock ? 2 : 1;
    }

    /** Source pixel-cols per terminal cell. */
    public function colsPerCell(): int
    {
        return 1;
    }

    /**
     * Human-readable description of the rendering mode.
     */
    public function label(): string
    {
        return match ($this) {
            self::Ascii     => 'Plain ASCII (no color)',
            self::Ansi256   => 'ANSI 256-color',
            self::TrueColor => 'TrueColor 24-bit',
            self::HalfBlock => 'Unicode half-block (▀)',
            self::Sixel    => 'Sixel raster graphics',
            self::Kitty     => 'Kitty inline images',
            self::Iterm2    => 'iTerm2 inline images',
        };
    }
}
