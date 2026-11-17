<?php

declare(strict_types=1);

namespace SugarCraft\Style;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * Chain form of {@see Style}'s scalar setters: edit one draft, build one Style.
 *
 * Each {@see Style} setter returns a new instance, so a chain of N setters
 * allocates N intermediate styles, each with the full constructor argument
 * list. A builder keeps the draft in one array and constructs the final
 * {@see Style} once, in {@see build()}. The results are identical: every
 * setter here applies the same value, validation and propsSet bookkeeping as
 * the {@see Style} setter of the same name, and the equivalence is pinned by
 * tests/StyleBuilderTest.php.
 *
 * Covers the scalar and colour setters. The structural ones (border(),
 * adaptive and complete colours, hyperlink, transform) stay on {@see Style}:
 * a chain that needs them calls build() and continues on the Style.
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
    public function foreground(?Color $c): self { return $this->put('fg', $c); }

    /** Set the background colour. Pass null to clear. Mirrors {@see Style::background()}. */
    public function background(?Color $c): self { return $this->put('bg', $c); }

    /** Hex-string form of {@see foreground()}. Mirrors {@see Style::fg()}. */
    public function fg(Color|string|null $c): self { return $this->foreground(self::asColor($c)); }

    /** Hex-string form of {@see background()}. Mirrors {@see Style::bg()}. */
    public function bg(Color|string|null $c): self { return $this->background(self::asColor($c)); }

    public function bold(bool $on = true): self          { return $this->flag('bold', $on); }
    public function italic(bool $on = true): self        { return $this->flag('italic', $on); }
    public function underline(bool $on = true): self     { return $this->flag('underline', $on); }
    public function strikethrough(bool $on = true): self { return $this->flag('strike', $on); }
    public function faint(bool $on = true): self         { return $this->flag('faint', $on); }
    public function blink(bool $on = true): self         { return $this->flag('blink', $on); }
    public function reverse(bool $on = true): self       { return $this->flag('reverse', $on); }
    public function inline(bool $on = true): self        { return $this->flag('inline', $on); }
    public function wordWrap(bool $on = true): self      { return $this->flag('wordWrap', $on); }

    public function width(?int $w): self     { return $this->size('width', $w, 'style.width_nonneg'); }
    public function height(?int $h): self    { return $this->size('height', $h, 'style.height_nonneg'); }
    public function maxWidth(?int $w): self  { return $this->size('maxWidth', $w, 'style.maxwidth_nonneg'); }
    public function maxHeight(?int $h): self { return $this->size('maxHeight', $h, 'style.maxheight_nonneg'); }

    public function align(Align $h): self { return $this->put('alignH', $h); }
    public function verticalAlign(VAlign $v): self         { return $this->put('alignV', $v); }

    public function colorProfile(ColorProfile $p): self { return $this->put('profile', $p); }

    /** Bind the default content, as {@see Style::setString()} does. */
    public function setString(string $content): self { return $this->put('boundString', $content); }

    public function padding(int ...$sides): self { return $this->put('padding', ($this->sides)($sides, 'padding')); }
    public function margin(int ...$sides): self  { return $this->put('margin', ($this->sides)($sides, 'margin')); }

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
