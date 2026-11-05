<?php

declare(strict_types=1);

namespace SugarCraft\Cli;

use SugarCraft\Core\Util\Color;
use SugarCraft\Style\Style;

/**
 * Render text-art logos with optional color theming.
 *
 * The built-in {@see sugarcraft()} preset is Unicode box-drawing art, not
 * 7-bit ASCII; {@see fromAscii()} accepts whatever art a host app supplies
 * (and is spelled for legacy callers of that name).
 *
 * First-party widget — there is no upstream Logo counterpart. The inspired-by
 * library (charmbracelet/fang, see MATCHUPS.md) renders its branded splash
 * as a half-block *image* logo and delegates command parsing to cobra;
 * figlet-style text-art presets like {@see sugarcraft()} exist only here.
 *
 * Art is plain text by contract: terminal escapes and control bytes in a
 * caller-supplied page are stripped at ingress (line feeds survive — they
 * ARE the layout). Color is applied once, at {@see render()} time, so
 * styling can never be forged through the art and repeated
 * {@see withColor()} calls replace rather than stack.
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
        private readonly string $art,
        private readonly ?Color $color = null,
    ) {}

    /**
     * Build a Logo from a raw text-art page.
     *
     * The art keeps its line structure and printable characters; every
     * escape sequence and control byte except LF is stripped at ingress
     * (see the class docblock contract).
     */
    public static function fromAscii(string $ascii): self
    {
        return new self(Internal\SafeText::page($ascii));
    }

    /**
     * Built-in SugarCraft logo in box-drawing characters.
     *
     * Every line shares one display width (a 2-cell left margin, the side
     * rails, and a uniform inner column) — the invariant LogoTest pins so a
     * re-flowed figlet row can never again overrun the right rail. That
     * width is 66 cells: the preset is NOT clamped to the terminal, so on a
     * narrower one it wraps — supply narrower art via {@see fromAscii()} if
     * the launch target may be under 66 columns.
     *
     * NOTE: the logo has two visual layers — the double-line box border
     * (top/bottom rules and the side rails on every line) and the figlet
     * text inside it. Both layers take the same foreground colour via
     * {@see withColor()}. If you need separate border and text colours, use
     * {@see fromAscii()} to supply your own art and apply colours in two
     * passes, or treat the border as a fixed decorative element separate
     * from the text layer.
     */
    public static function sugarcraft(): self
    {
        $art = <<<'ART'
  ╔══════════════════════════════════════════════════════════════╗
  ║   ____       _          _   _                 _ _            ║
  ║  | __ ) _   _| |_ ___   | | | |__   __ _ _ __ (_) |_ _   _   ║
  ║  |  _ \ | | | | __/ __|  | |_| '_ \ / _` | '_ \| | __| | | | ║
  ║  | |_) | |_| | |_\__ \  |  _  | | | (_| | |_) | | |_| |_| |  ║
  ║  |____/ \__,_|\__|___/  |_| |_|_| |_\__,_| .__/|_|\__|\__, | ║
  ║                                          |_|          |___/  ║
  ╚══════════════════════════════════════════════════════════════╝
ART;
        return new self($art);
    }

    /**
     * Render the logo with a foreground color.
     *
     * Sets the color applied by {@see render()}; a later call REPLACES the
     * earlier one (styling is not composed, and the art string is never
     * mutated — that is what makes repeated calls idempotent per-color).
     *
     * @param string|Color $color CSS hex string or Color instance
     */
    public function withColor(string|Color $color): self
    {
        return new self($this->art, $color instanceof Color ? $color : Color::hex($color));
    }

    /**
     * Render the logo as a string, applying the chosen color (if any) once.
     */
    public function render(): string
    {
        if ($this->color === null) {
            return $this->art;
        }

        return Style::new()->foreground($this->color)->render($this->art);
    }
}
