<?php

declare(strict_types=1);

namespace SugarCraft\Cli;

use SugarCraft\Cli\Internal\SafeText;
use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
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
 * figlet-style text-art presets like {@see sugarcraft()} and its
 * under-40-cell sibling {@see sugarcraftNarrow()} exist only here.
 *
 * Art is plain text by contract: terminal escapes and control bytes in a
 * caller-supplied page are stripped at ingress (line feeds survive — they
 * ARE the layout; tabs fold to a single space so word boundaries survive). Color is applied once, at {@see render()} time, so
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
    use Mutable;

    private function __construct(
        private readonly string $art,
        private readonly ?Color $color = null,
    ) {}

    /**
     * Build a Logo from a raw text-art page.
     *
     * The art keeps its line structure and printable characters; every
     * escape sequence and control byte except LF is stripped at ingress
     * (tabs fold to a single space; see the class docblock contract).
     */
    public static function fromAscii(string $ascii): self
    {
        return new self(SafeText::page($ascii));
    }

    /**
     * Built-in SugarCraft logo in box-drawing characters.
     *
     * Every line shares one display width (a 2-cell left margin, the side
     * rails, and a uniform inner column) — the invariant LogoTest pins so a
     * re-flowed figlet row can never again overrun the right rail. That
     * width is 66 cells: the preset is NOT clamped to the terminal, so on a
     * narrower one it wraps — reach for {@see sugarcraftNarrow()} (or the
     * {@see fits()} predicate) first, and only then supply your own art via
     * {@see fromAscii()}.
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
     * Built-in SugarCraft logo in narrow figlet art, for tight terminals.
     *
     * The same wordmark as {@see sugarcraft()} without the box border, cut
     * from a small figlet face: no line exceeds 40 display cells (widest is
     * 39), pinned line-by-line in LogoTest via {@see Width::string()} — the
     * budget is a measured contract, not a visual guess. On a terminal under
     * 66 columns prefer this preset over letting {@see sugarcraft()} wrap;
     * below 40 columns use {@see fits()} to decide, then {@see fromAscii()}
     * for art that suits the target.
     */
    public static function sugarcraftNarrow(): self
    {
        $art = <<<'ART'
                    _             _   
 ___ _   _ _ __ __ _| |__   __ _  | |_ 
/ __| | | | '__/ _` | '_ \ / _` | | __|
\__ \ |_| | | | (_| | | | | (_| | | |_ 
|___/\__,_|_|  \__,_|_| |_|\__,_|  \__|
ART;
        return new self($art);
    }

    /**
     * True when every art line fits within `$cols` display cells.
     *
     * Measures with {@see Width::string()} — CJK and emoji art count in
     * cells, not codepoints. Blank art fits any width (there is no row to
     * overrun). Use this to route a launch target between {@see sugarcraft()},
     * {@see sugarcraftNarrow()} and custom {@see fromAscii()} art.
     */
    public function fits(int $cols): bool
    {
        foreach (explode("\n", $this->art) as $line) {
            if (Width::string($line) > $cols) {
                return false;
            }
        }

        return true;
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
        return $this->mutate(['color' => $color instanceof Color ? $color : Color::hex($color)]);
    }

    /**
     * Render the logo as a string, applying the chosen color (if any) once.
     *
     * Pass a {@see ColorProfile} (e.g. `ColorProfile::detect()`) to re-quantise
     * the tint to what the destination supports: `NoTty`/`Ascii` downgrade the
     * colour like the themed presenters do, richer profiles quantise the tint.
     * Omitting it keeps the tint as given — the branded-splash default.
     */
    public function render(?ColorProfile $profile = null): string
    {
        if ($this->color === null) {
            return $this->art;
        }

        $style = Style::new()->foreground($this->color);

        return ($profile === null ? $style : $style->colorProfile($profile))->render($this->art);
    }
}
