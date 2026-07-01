<?php

declare(strict_types=1);

namespace SugarCraft\Kit;

use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Style;

/**
 * Render ASCII-art logos with optional color theming.
 *
 * Mirrors ratatui v0.27+ `Logo` widget.
 *
 * Usage:
 * ```php
 * echo Logo::sugarcraft()->render();
 *
 * echo Logo::fromAscii(<<<'ART'
 *   ╭───────╮
 *   │  APP  │
 *   ╰───────╯
 * ART)->withColor('#ff5fd2')->render();
 * ```
 */
final class Logo
{
    private function __construct(
        private readonly string $ascii,
    ) {}

    /**
     * Build a Logo from a raw ASCII-art string.
     *
     * The string is rendered as-is with no transformation.
     */
    public static function fromAscii(string $ascii): self
    {
        return new self($ascii);
    }

    /**
     * Built-in SugarCraft logo in box-drawing characters.
     *
     * NOTE: the logo contains two visual layers — the double-line box border
     * (lines 1, 2, 7, 8) and the text content (lines 3–6). Both layers use
     * the same foreground colour via {@see withColor()}. If you need separate
     * border and text colours, use {@see fromAscii()} to supply your own art
     * and apply colours in two passes, or treat the border as a fixed
     * decorative element separate from the text layer.
     */
    public static function sugarcraft(): self
    {
        $art = <<<'ART'
  ╔═══════════════════════════════════════════════════════════╗
  ║   ____       _          _   _                 _ _         ║
  ║  | __ ) _   _| |_ ___   | | | |__   __ _ _ __ (_) |_ _   _║
  ║  |  _ \ | | | | __/ __|  | |_| '_ \ / _` | '_ \| | __| | | |║
  ║  | |_) | |_| | |_\__ \  |  _  | | | (_| | |_) | | |_| |_| |║
  ║  |____/ \__,_|\__|___/  |_| |_|_| |_\__,_| .__/|_|\__|\__, |║
  ║                                          |_|          |___/ ║
  ╚═══════════════════════════════════════════════════════════╝
ART;
        return new self($art);
    }

    /**
     * Render the logo with a foreground color.
     *
     * @param string|Color $color CSS hex string or Color instance
     */
    public function withColor(string|\SugarCraft\Core\Util\Color $color): self
    {
        $c = $color instanceof Color ? $color : Color::hex($color);
        $styled = Style::new()->foreground($c)->render($this->ascii);
        return new self($styled);
    }

    /**
     * Render the logo as a string.
     */
    public function render(): string
    {
        return $this->ascii;
    }
}