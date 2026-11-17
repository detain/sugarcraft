<?php

declare(strict_types=1);

namespace SugarCraft\Style;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * Chain form of {@see Style}'s plain-value setters: edit one draft, build one Style.
 *
 * Each {@see Style} setter returns a new instance, so a chain of N setters
 * allocates N intermediate styles, each with the full constructor argument
 * list. A builder keeps the draft in one array and constructs the final
 * {@see Style} once, in {@see build()}. The results are identical: every
 * setter here applies the same value, validation and propsSet bookkeeping as
 * the {@see Style} setter of the same name, and the equivalence is pinned by
 * tests/StyleBuilderTest.php.
 *
 * Covers the setters that take a plain value: colours, the boolean
 * attributes, underline shape and colour, sizes and tab width, alignment,
 * colour profile, padding and margin as whole shorthands, the padding and
 * margin glyphs and margin background, and the bound content. Four groups
 * stay on {@see Style}, and a chain that needs one calls build() and
 * continues there:
 *  - structural setters: border() and its side and colour variants, the
 *    adaptive and complete colours, hyperlink() and transform();
 *  - the per-side padding and margin setters (paddingTop() and so on);
 *  - the short aliases on(), of(), dim(), pad() and mg().
 *
 * The setters are named set*, the non-immutable form the naming rule asks
 * for: a builder mutates its draft rather than returning a new value.
 *
 * MUTABLE BY DESIGN (exemption, dated 2026-11-17): the builder is a one-shot
 * draft owned by the chain that created it. Its setters write into the draft
 * and return `$this`, which is the allocation saving the class exists for.
 * It never escapes as a value: build() returns an immutable Style, and the
 * builder itself is not a value object.
 */
final class StyleBuilder
{
    /** @var array<string,mixed> constructor arguments of the draft, keyed by property name */
    private array $args;

    /**
     * Internal: obtain a builder from {@see Style::builder()}, which supplies
     * the seed's constructor arguments and the two Style-scoped helpers.
     *
     * @param array<string,mixed>                       $seedArgs the seed Style's properties
     * @param \Closure(array<string,mixed>):Style       $factory  builds a Style from constructor arguments
     * @param \Closure(array<int,int>,string):array     $sides    the padding/margin normaliser
     * @internal
     */
    public function __construct(
        array $seedArgs,
        private readonly \Closure $factory,
        private readonly \Closure $sides,
    ) {
        $this->args = $seedArgs;
    }

    /** Set the foreground colour. Pass null to clear. Mirrors {@see Style::foreground()}. */
    public function setForeground(?Color $c): self { return $this->put('fg', $c); }

    /** Set the background colour. Pass null to clear. Mirrors {@see Style::background()}. */
    public function setBackground(?Color $c): self { return $this->put('bg', $c); }

    /** Hex-string form of {@see setForeground()}. Mirrors {@see Style::fg()}. */
    public function setFg(Color|string|null $c): self { return $this->setForeground(self::asColor($c)); }

    /** Hex-string form of {@see setBackground()}. Mirrors {@see Style::bg()}. */
    public function setBg(Color|string|null $c): self { return $this->setBackground(self::asColor($c)); }

    /** Set the underline colour (SGR 58), or clear it with null. Mirrors {@see Style::underlineColor()}. */
    public function setUnderlineColor(Color|string|null $c): self { return $this->put('underlineColor', self::asColor($c)); }

    public function setBold(bool $on = true): self          { return $this->flag('bold', $on); }
    public function setItalic(bool $on = true): self        { return $this->flag('italic', $on); }
    public function setUnderline(bool $on = true): self     { return $this->flag('underline', $on); }
    public function setStrikethrough(bool $on = true): self { return $this->flag('strike', $on); }
    public function setFaint(bool $on = true): self         { return $this->flag('faint', $on); }
    public function setBlink(bool $on = true): self         { return $this->flag('blink', $on); }
    public function setRapidBlink(bool $on = true): self    { return $this->flag('rapidBlink', $on); }
    public function setReverse(bool $on = true): self       { return $this->flag('reverse', $on); }
    public function setOverline(bool $on = true): self      { return $this->flag('overline', $on); }
    public function setInvisible(bool $on = true): self     { return $this->flag('invisible', $on); }
    public function setInline(bool $on = true): self        { return $this->flag('inline', $on); }
    public function setWordWrap(bool $on = true): self      { return $this->flag('wordWrap', $on); }
    public function setColorWhitespace(bool $on = true): self { return $this->flag('colorWhitespace', $on); }

    /**
     * Underline shape. A non-None shape also switches the underline flag on,
     * as {@see Style::underlineStyle()} does; None leaves the flag alone.
     */
    public function setUnderlineStyle(UnderlineStyle $style): self
    {
        if ($style !== UnderlineStyle::None) {
            $this->put('underline', true);
        }
        return $this->put('underlineStyle', $style);
    }

    public function setWidth(?int $w): self     { return $this->size('width', $w, 'style.width_nonneg'); }
    public function setHeight(?int $h): self    { return $this->size('height', $h, 'style.height_nonneg'); }
    public function setMaxWidth(?int $w): self  { return $this->size('maxWidth', $w, 'style.maxwidth_nonneg'); }
    public function setMaxHeight(?int $h): self { return $this->size('maxHeight', $h, 'style.maxheight_nonneg'); }

    /** Mirrors {@see Style::tabWidth()}: a negative width is refused. */
    public function setTabWidth(int $w): self
    {
        if ($w < 0) {
            throw new \InvalidArgumentException(Lang::t('style.tabwidth_nonneg'));
        }
        return $this->put('tabWidth', $w);
    }

    public function setAlign(Align $h): self { return $this->put('alignH', $h); }
    public function setVerticalAlign(VAlign $v): self { return $this->put('alignV', $v); }

    public function setColorProfile(ColorProfile $p): self { return $this->put('profile', $p); }

    /** Bind the default content, as {@see Style::setString()} does. */
    public function setString(string $content): self { return $this->put('boundString', $content); }

    public function setPadding(int ...$sides): self { return $this->put('padding', ($this->sides)($sides, 'padding')); }
    public function setMargin(int ...$sides): self  { return $this->put('margin', ($this->sides)($sides, 'margin')); }

    /** Glyph that fills padding cells. Mirrors {@see Style::paddingChar()}. */
    public function setPaddingChar(string $char): self { return $this->put('paddingChar', $char); }

    /** Glyph that fills margin cells. Mirrors {@see Style::marginChar()}. */
    public function setMarginChar(string $char): self { return $this->put('marginChar', $char); }

    /** Colour painted into the margin, or cleared with null. Mirrors {@see Style::marginBackground()}. */
    public function setMarginBackground(?Color $c): self { return $this->put('marginBg', $c); }

    /** Build the Style. The builder stays usable: the draft is copied, not consumed. */
    public function build(): Style
    {
        return ($this->factory)($this->args);
    }

    /** Write a value and mark its propsSet key, the bookkeeping every Style setter does. */
    private function put(string $prop, mixed $value): self
    {
        $this->args[$prop] = $value;
        $this->args['propsSet'][$prop] = true;
        return $this;
    }

    /** A bool setter: the Style setters apply `?? $this->prop`, so a bool always lands. */
    private function flag(string $prop, bool $on): self
    {
        return $this->put($prop, $on);
    }

    /** A non-negative size setter, validated as {@see Style::width()} and its siblings are. */
    private function size(string $prop, ?int $value, string $message): self
    {
        if ($value !== null && $value < 0) {
            throw new \InvalidArgumentException(Lang::t($message));
        }
        return $this->put($prop, $value);
    }

    /** Coerce a Color | hex string | null, as {@see Style::fg()} does. */
    private static function asColor(Color|string|null $c): ?Color
    {
        if ($c === null || $c instanceof Color) {
            return $c;
        }
        return Color::hex($c);
    }
}
