<?php

declare(strict_types=1);

namespace CandyCore\Sprinkles;

use CandyCore\Core\Util\Ansi;
use CandyCore\Core\Util\Color;
use CandyCore\Core\Util\ColorProfile;
use CandyCore\Core\Util\Width;

/**
 * Immutable styled-text builder. Each setter returns a new Style.
 *
 * Render pipeline (innermost to outermost):
 *   content → fixed width + horizontal alignment → padding (styled)
 *           → fixed height (vertical fill, anchored by alignV)
 *           → border (styled separately) → margin (unstyled)
 *
 * Each public setter records the prop it touched in {@see $propsSet}, which
 * lets {@see inherit()} merge a parent style into a child while preserving
 * any value the child explicitly set.
 */
final class Style
{
    /**
     * @param array{int,int,int,int}     $padding     top, right, bottom, left
     * @param array{int,int,int,int}     $margin      top, right, bottom, left
     * @param array{bool,bool,bool,bool} $borderSides top, right, bottom, left
     * @param array<string,bool>         $propsSet
     */
    /**
     * @param array{int,int,int,int}     $padding     top, right, bottom, left
     * @param array{int,int,int,int}     $margin      top, right, bottom, left
     * @param array{bool,bool,bool,bool} $borderSides top, right, bottom, left
     * @param array{?Color,?Color,?Color,?Color} $borderSideFg per-side fg overrides
     * @param array{?Color,?Color,?Color,?Color} $borderSideBg per-side bg overrides
     * @param array<string,bool>         $propsSet
     * @param ?\Closure(string):string   $transform
     */
    private function __construct(
        private readonly ?Color $fg = null,
        private readonly ?Color $bg = null,
        private readonly ?AdaptiveColor $fgAdaptive = null,
        private readonly ?AdaptiveColor $bgAdaptive = null,
        private readonly ?CompleteColor $fgComplete = null,
        private readonly ?CompleteColor $bgComplete = null,
        private readonly bool $bold = false,
        private readonly bool $italic = false,
        private readonly bool $underline = false,
        private readonly bool $strike = false,
        private readonly bool $faint = false,
        private readonly bool $blink = false,
        private readonly bool $reverse = false,
        private readonly array $padding = [0, 0, 0, 0],
        private readonly array $margin  = [0, 0, 0, 0],
        private readonly ?int $width = null,
        private readonly ?int $height = null,
        private readonly ?int $maxWidth = null,
        private readonly ?int $maxHeight = null,
        private readonly Align $alignH = Align::Left,
        private readonly VAlign $alignV = VAlign::Top,
        private readonly ?Border $border = null,
        private readonly array $borderSides = [true, true, true, true],
        private readonly ?Color $borderFg = null,
        private readonly ?Color $borderBg = null,
        private readonly array $borderSideFg = [null, null, null, null],
        private readonly array $borderSideBg = [null, null, null, null],
        private readonly ColorProfile $profile = ColorProfile::TrueColor,
        private readonly array $propsSet = [],
        private readonly bool $inline = false,
        private readonly ?Color $marginBg = null,
        private readonly bool $colorWhitespace = true,
        private readonly int $tabWidth = 4,
        private readonly ?\Closure $transform = null,
    ) {}

    public static function new(): self
    {
        return new self();
    }

    public function foreground(?Color $c): self          { return $this->with(fg: $c, fgSet: true, propsAdded: ['fg']); }
    public function background(?Color $c): self          { return $this->with(bg: $c, bgSet: true, propsAdded: ['bg']); }

    /**
     * Set a foreground that picks between $light (for terminals with a
     * light background) and $dark (for terminals with a dark
     * background) at render time. Resolve via {@see resolveAdaptive()}
     * — until then the concrete `foreground` slot wins.
     *
     * Mirrors lipgloss v2's `AdaptiveColor` field.
     */
    public function foregroundAdaptive(Color $light, Color $dark): self
    {
        return $this->with(
            fgAdaptive: new AdaptiveColor($light, $dark),
            fgAdaptiveSet: true,
            propsAdded: ['fgAdaptive'],
        );
    }

    public function backgroundAdaptive(Color $light, Color $dark): self
    {
        return $this->with(
            bgAdaptive: new AdaptiveColor($light, $dark),
            bgAdaptiveSet: true,
            propsAdded: ['bgAdaptive'],
        );
    }

    /**
     * Set a foreground that picks per-tier from the supplied triple
     * based on the live {@see ColorProfile}. Use this when you want
     * to override the automatic downsampling with designer-chosen
     * values for each terminal capability tier. Mirrors lipgloss v2's
     * `CompleteColor`.
     */
    public function foregroundComplete(Color $trueColor, Color $ansi256, Color $ansi): self
    {
        return $this->with(
            fgComplete: new CompleteColor($trueColor, $ansi256, $ansi),
            fgCompleteSet: true,
            propsAdded: ['fgComplete'],
        );
    }

    public function backgroundComplete(Color $trueColor, Color $ansi256, Color $ansi): self
    {
        return $this->with(
            bgComplete: new CompleteColor($trueColor, $ansi256, $ansi),
            bgCompleteSet: true,
            propsAdded: ['bgComplete'],
        );
    }

    /**
     * Collapse any adaptive fg/bg slots into concrete `foreground` /
     * `background` based on the supplied dark-background flag (typically
     * the value of {@see \CandyCore\Core\Msg\BackgroundColorMsg::isDark()}).
     *
     * Adaptive slots only resolve when the matching concrete slot
     * isn't explicitly set, so an explicit `foreground()` always wins
     * over `foregroundAdaptive()` — same precedence as lipgloss.
     */
    public function resolveAdaptive(bool $isDark): self
    {
        $next = $this;
        $hasFg = isset($this->propsSet['fg']);
        $hasBg = isset($this->propsSet['bg']);
        if ($this->fgAdaptive !== null && !$hasFg) {
            $next = $next->foreground($this->fgAdaptive->pick($isDark));
        }
        if ($this->bgAdaptive !== null && !$hasBg) {
            $next = $next->background($this->bgAdaptive->pick($isDark));
        }
        return $next;
    }

    /**
     * Collapse any complete-colour fg/bg slots into concrete
     * `foreground` / `background` based on the style's current colour
     * profile. Explicit concrete colours win, matching lipgloss
     * precedence. Call this after any `colorProfile()` change but
     * before `render()` if you mixed `foregroundComplete()` calls
     * into the style.
     */
    public function resolveProfile(): self
    {
        $next = $this;
        $hasFg = isset($this->propsSet['fg']);
        $hasBg = isset($this->propsSet['bg']);
        if ($this->fgComplete !== null && !$hasFg) {
            $next = $next->foreground($this->fgComplete->pick($this->profile));
        }
        if ($this->bgComplete !== null && !$hasBg) {
            $next = $next->background($this->bgComplete->pick($this->profile));
        }
        return $next;
    }
    public function bold(bool $on = true): self          { return $this->with(bold: $on, propsAdded: ['bold']); }
    public function italic(bool $on = true): self        { return $this->with(italic: $on, propsAdded: ['italic']); }
    public function underline(bool $on = true): self     { return $this->with(underline: $on, propsAdded: ['underline']); }
    public function strikethrough(bool $on = true): self { return $this->with(strike: $on, propsAdded: ['strike']); }
    public function faint(bool $on = true): self         { return $this->with(faint: $on, propsAdded: ['faint']); }
    public function blink(bool $on = true): self         { return $this->with(blink: $on, propsAdded: ['blink']); }
    public function reverse(bool $on = true): self       { return $this->with(reverse: $on, propsAdded: ['reverse']); }

    /** padding($all) | padding($v, $h) | padding($t, $r, $b, $l) */
    public function padding(int ...$sides): self
    {
        return $this->with(padding: self::expandSides($sides, 'padding'), propsAdded: ['padding']);
    }
    public function paddingTop(int $n): self    { return $this->with(padding: self::setSide($this->padding, 0, $n, 'padding'), propsAdded: ['padding']); }
    public function paddingRight(int $n): self  { return $this->with(padding: self::setSide($this->padding, 1, $n, 'padding'), propsAdded: ['padding']); }
    public function paddingBottom(int $n): self { return $this->with(padding: self::setSide($this->padding, 2, $n, 'padding'), propsAdded: ['padding']); }
    public function paddingLeft(int $n): self   { return $this->with(padding: self::setSide($this->padding, 3, $n, 'padding'), propsAdded: ['padding']); }

    /** margin($all) | margin($v, $h) | margin($t, $r, $b, $l) */
    public function margin(int ...$sides): self
    {
        return $this->with(margin: self::expandSides($sides, 'margin'), propsAdded: ['margin']);
    }
    public function marginTop(int $n): self    { return $this->with(margin: self::setSide($this->margin, 0, $n, 'margin'), propsAdded: ['margin']); }
    public function marginRight(int $n): self  { return $this->with(margin: self::setSide($this->margin, 1, $n, 'margin'), propsAdded: ['margin']); }
    public function marginBottom(int $n): self { return $this->with(margin: self::setSide($this->margin, 2, $n, 'margin'), propsAdded: ['margin']); }
    public function marginLeft(int $n): self   { return $this->with(margin: self::setSide($this->margin, 3, $n, 'margin'), propsAdded: ['margin']); }

    public function width(?int $w): self
    {
        if ($w !== null && $w < 0) {
            throw new \InvalidArgumentException('width must be >= 0');
        }
        return $this->with(width: $w, widthSet: true, propsAdded: ['width']);
    }

    public function height(?int $h): self
    {
        if ($h !== null && $h < 0) {
            throw new \InvalidArgumentException('height must be >= 0');
        }
        return $this->with(height: $h, heightSet: true, propsAdded: ['height']);
    }

    /**
     * Cap rendered width to `$w` cells (truncate longer lines). Unlike
     * {@see width()}, doesn't pad shorter content. Mirrors lipgloss's
     * `MaxWidth`. Pass `null` to remove the cap.
     */
    public function maxWidth(?int $w): self
    {
        if ($w !== null && $w < 0) {
            throw new \InvalidArgumentException('maxWidth must be >= 0');
        }
        return $this->with(maxWidth: $w, maxWidthSet: true, propsAdded: ['maxWidth']);
    }

    /**
     * Cap rendered height to `$h` rows (truncate excess). Unlike
     * {@see height()}, doesn't pad shorter content. Mirrors `MaxHeight`.
     */
    public function maxHeight(?int $h): self
    {
        if ($h !== null && $h < 0) {
            throw new \InvalidArgumentException('maxHeight must be >= 0');
        }
        return $this->with(maxHeight: $h, maxHeightSet: true, propsAdded: ['maxHeight']);
    }

    /**
     * Force the rendered output onto a single line. Newlines in
     * content are replaced with spaces; padding-top/bottom and
     * margin-top/bottom collapse to zero. Mirrors lipgloss's
     * `Inline()`.
     */
    public function inline(bool $on = true): self
    {
        return $this->with(inline: $on, propsAdded: ['inline']);
    }

    /**
     * Set the colour painted into the margin area (outside the border).
     * Defaults to no colour; useful for full-width status bars.
     */
    public function marginBackground(?Color $c): self
    {
        return $this->with(marginBg: $c, marginBgSet: true, propsAdded: ['marginBg']);
    }

    /**
     * Toggle whether the background colour fills padding/whitespace
     * cells. Mirrors lipgloss's `ColorWhitespace`. Defaults to true.
     */
    public function colorWhitespace(bool $on = true): self
    {
        return $this->with(colorWhitespace: $on, propsAdded: ['colorWhitespace']);
    }

    /**
     * Width of a `\t` character when expanded inside content. Defaults
     * to 4. Pass 0 to keep tabs as literal `\t` (no expansion).
     */
    public function tabWidth(int $w): self
    {
        if ($w < 0) {
            throw new \InvalidArgumentException('tabWidth must be >= 0');
        }
        return $this->with(tabWidth: $w, propsAdded: ['tabWidth']);
    }

    /**
     * Apply `$fn` to the rendered string just before its border /
     * margin layer. Useful for last-mile rewrites (e.g. capitalise,
     * mask sensitive values). Mirrors lipgloss's `Transform`.
     *
     * @param ?\Closure(string):string $fn pass null to clear.
     */
    public function transform(?\Closure $fn): self
    {
        return $this->with(transform: $fn, transformSet: true, propsAdded: ['transform']);
    }

    public function align(Align $h): self          { return $this->with(alignH: $h, propsAdded: ['alignH']); }
    public function verticalAlign(VAlign $v): self { return $this->with(alignV: $v, propsAdded: ['alignV']); }

    /**
     * Apply a border. Sides (variadic) follow CSS shorthand:
     *   border($b)                  → all four sides
     *   border($b, $v, $h)          → top/bottom = $v, left/right = $h
     *   border($b, $t, $r, $b, $l)  → per side
     *
     * `null` removes the border.
     */
    public function border(?Border $b, bool ...$sides): self
    {
        if ($b === null) {
            return $this->with(border: null, borderSet: true, propsAdded: ['border']);
        }
        $expanded = match (count($sides)) {
            0 => [true, true, true, true],
            2 => [$sides[0], $sides[1], $sides[0], $sides[1]],
            4 => [$sides[0], $sides[1], $sides[2], $sides[3]],
            default => throw new \InvalidArgumentException(
                'border() side toggles take 0, 2, or 4 bools; got ' . count($sides)
            ),
        };
        return $this->with(border: $b, borderSet: true, borderSides: $expanded, propsAdded: ['border', 'borderSides']);
    }

    public function borderTop(bool $on = true): self    { $s = $this->borderSides; $s[0] = $on; return $this->with(borderSides: $s, propsAdded: ['borderSides']); }
    public function borderRight(bool $on = true): self  { $s = $this->borderSides; $s[1] = $on; return $this->with(borderSides: $s, propsAdded: ['borderSides']); }
    public function borderBottom(bool $on = true): self { $s = $this->borderSides; $s[2] = $on; return $this->with(borderSides: $s, propsAdded: ['borderSides']); }
    public function borderLeft(bool $on = true): self   { $s = $this->borderSides; $s[3] = $on; return $this->with(borderSides: $s, propsAdded: ['borderSides']); }

    public function borderForeground(?Color $c): self { return $this->with(borderFg: $c, borderFgSet: true, propsAdded: ['borderFg']); }
    public function borderBackground(?Color $c): self { return $this->with(borderBg: $c, borderBgSet: true, propsAdded: ['borderBg']); }

    /**
     * Per-side border foreground colours. Each setter overrides the
     * default `borderForeground()` for that one side; pass `null` to
     * clear the override and fall back to the default.
     */
    public function borderTopForeground(?Color $c): self
    {
        $sides = $this->borderSideFg; $sides[0] = $c;
        return $this->with(borderSideFg: $sides, propsAdded: ['borderSideFg']);
    }

    public function borderRightForeground(?Color $c): self
    {
        $sides = $this->borderSideFg; $sides[1] = $c;
        return $this->with(borderSideFg: $sides, propsAdded: ['borderSideFg']);
    }

    public function borderBottomForeground(?Color $c): self
    {
        $sides = $this->borderSideFg; $sides[2] = $c;
        return $this->with(borderSideFg: $sides, propsAdded: ['borderSideFg']);
    }

    public function borderLeftForeground(?Color $c): self
    {
        $sides = $this->borderSideFg; $sides[3] = $c;
        return $this->with(borderSideFg: $sides, propsAdded: ['borderSideFg']);
    }

    public function borderTopBackground(?Color $c): self
    {
        $sides = $this->borderSideBg; $sides[0] = $c;
        return $this->with(borderSideBg: $sides, propsAdded: ['borderSideBg']);
    }

    public function borderRightBackground(?Color $c): self
    {
        $sides = $this->borderSideBg; $sides[1] = $c;
        return $this->with(borderSideBg: $sides, propsAdded: ['borderSideBg']);
    }

    public function borderBottomBackground(?Color $c): self
    {
        $sides = $this->borderSideBg; $sides[2] = $c;
        return $this->with(borderSideBg: $sides, propsAdded: ['borderSideBg']);
    }

    public function borderLeftBackground(?Color $c): self
    {
        $sides = $this->borderSideBg; $sides[3] = $c;
        return $this->with(borderSideBg: $sides, propsAdded: ['borderSideBg']);
    }

    public function colorProfile(ColorProfile $p): self { return $this->with(profile: $p, propsAdded: ['profile']); }

    // ─── Getters ───────────────────────────────────────────────────────

    public function getForeground(): ?Color   { return $this->fg; }
    public function getBackground(): ?Color   { return $this->bg; }
    public function isBold(): bool             { return $this->bold; }
    public function isItalic(): bool           { return $this->italic; }
    public function isUnderline(): bool        { return $this->underline; }
    public function isStrikethrough(): bool    { return $this->strike; }
    public function isFaint(): bool            { return $this->faint; }
    public function isBlink(): bool            { return $this->blink; }
    public function isReverse(): bool          { return $this->reverse; }
    public function getWidth(): ?int           { return $this->width; }
    public function getHeight(): ?int          { return $this->height; }
    public function getMaxWidth(): ?int        { return $this->maxWidth; }
    public function getMaxHeight(): ?int       { return $this->maxHeight; }
    public function getAlign(): Align          { return $this->alignH; }
    public function getVerticalAlign(): VAlign { return $this->alignV; }
    public function getBorder(): ?Border       { return $this->border; }
    public function getBorderForeground(): ?Color { return $this->borderFg; }
    public function getBorderBackground(): ?Color { return $this->borderBg; }
    public function getColorProfile(): ColorProfile { return $this->profile; }
    public function isInline(): bool           { return $this->inline; }
    public function getTabWidth(): int         { return $this->tabWidth; }
    public function getMarginBackground(): ?Color { return $this->marginBg; }

    /** @return array{int,int,int,int} */
    public function getPadding(): array { return $this->padding; }
    /** @return array{int,int,int,int} */
    public function getMargin(): array { return $this->margin; }
    /** @return array{bool,bool,bool,bool} */
    public function getBorderSides(): array { return $this->borderSides; }

    public function isSet(string $prop): bool { return isset($this->propsSet[$prop]); }

    // ─── Unset* resetters ──────────────────────────────────────────────

    public function unsetForeground(): self    { return $this->withUnset('fg', fg: null); }
    public function unsetBackground(): self    { return $this->withUnset('bg', bg: null); }
    public function unsetBold(): self          { return $this->withUnset('bold', bold: false); }
    public function unsetItalic(): self        { return $this->withUnset('italic', italic: false); }
    public function unsetUnderline(): self     { return $this->withUnset('underline', underline: false); }
    public function unsetStrikethrough(): self { return $this->withUnset('strike', strike: false); }
    public function unsetFaint(): self         { return $this->withUnset('faint', faint: false); }
    public function unsetBlink(): self         { return $this->withUnset('blink', blink: false); }
    public function unsetReverse(): self       { return $this->withUnset('reverse', reverse: false); }
    public function unsetWidth(): self         { return $this->withUnset('width', width: null); }
    public function unsetHeight(): self        { return $this->withUnset('height', height: null); }
    public function unsetMaxWidth(): self      { return $this->withUnset('maxWidth', maxWidth: null); }
    public function unsetMaxHeight(): self     { return $this->withUnset('maxHeight', maxHeight: null); }
    public function unsetBorder(): self        { return $this->withUnset('border', border: null); }
    public function unsetTransform(): self     { return $this->withUnset('transform', transform: null); }

    /**
     * Duplicate this style. Returns an identical instance (the explicit
     * propsSet is preserved). Mirrors lipgloss's deprecated `Copy()` —
     * since Style is immutable, this is mostly a clarity helper.
     */
    public function copy(): self
    {
        return clone $this;
    }

    /**
     * Merge {@see $parent} into this style. Any property the child
     * explicitly set wins; everything else is taken from the parent.
     *
     * The returned style's propsSet contains only the child's *original*
     * explicit set — parent props are pulled in for their values but are
     * not recorded as set on the merged style. That keeps chained
     * inheritance correct: in `Style::new()->inherit($a)->inherit($b)`,
     * the second inherit() can supply $b's defaults for fields the
     * intermediate didn't explicitly set on its own.
     */
    public function inherit(self $parent): self
    {
        $has = fn(string $p): bool => isset($this->propsSet[$p]);
        return new self(
            fg:               $has('fg')               ? $this->fg               : $parent->fg,
            bg:               $has('bg')               ? $this->bg               : $parent->bg,
            fgAdaptive:       $has('fgAdaptive')       ? $this->fgAdaptive       : $parent->fgAdaptive,
            bgAdaptive:       $has('bgAdaptive')       ? $this->bgAdaptive       : $parent->bgAdaptive,
            fgComplete:       $has('fgComplete')       ? $this->fgComplete       : $parent->fgComplete,
            bgComplete:       $has('bgComplete')       ? $this->bgComplete       : $parent->bgComplete,
            bold:             $has('bold')             ? $this->bold             : $parent->bold,
            italic:           $has('italic')           ? $this->italic           : $parent->italic,
            underline:        $has('underline')        ? $this->underline        : $parent->underline,
            strike:           $has('strike')           ? $this->strike           : $parent->strike,
            faint:            $has('faint')            ? $this->faint            : $parent->faint,
            blink:            $has('blink')            ? $this->blink            : $parent->blink,
            reverse:          $has('reverse')          ? $this->reverse          : $parent->reverse,
            padding:          $has('padding')          ? $this->padding          : $parent->padding,
            margin:           $has('margin')           ? $this->margin           : $parent->margin,
            width:            $has('width')            ? $this->width            : $parent->width,
            height:           $has('height')           ? $this->height           : $parent->height,
            maxWidth:         $has('maxWidth')         ? $this->maxWidth         : $parent->maxWidth,
            maxHeight:        $has('maxHeight')        ? $this->maxHeight        : $parent->maxHeight,
            alignH:           $has('alignH')           ? $this->alignH           : $parent->alignH,
            alignV:           $has('alignV')           ? $this->alignV           : $parent->alignV,
            border:           $has('border')           ? $this->border           : $parent->border,
            borderSides:      $has('borderSides')      ? $this->borderSides      : $parent->borderSides,
            borderFg:         $has('borderFg')         ? $this->borderFg         : $parent->borderFg,
            borderBg:         $has('borderBg')         ? $this->borderBg         : $parent->borderBg,
            borderSideFg:     $has('borderSideFg')     ? $this->borderSideFg     : $parent->borderSideFg,
            borderSideBg:     $has('borderSideBg')     ? $this->borderSideBg     : $parent->borderSideBg,
            profile:          $has('profile')          ? $this->profile          : $parent->profile,
            propsSet:         $this->propsSet,
            inline:           $has('inline')           ? $this->inline           : $parent->inline,
            marginBg:         $has('marginBg')         ? $this->marginBg         : $parent->marginBg,
            colorWhitespace:  $has('colorWhitespace')  ? $this->colorWhitespace  : $parent->colorWhitespace,
            tabWidth:         $has('tabWidth')         ? $this->tabWidth         : $parent->tabWidth,
            transform:        $has('transform')        ? $this->transform        : $parent->transform,
        );
    }

    public function render(string $content): string
    {
        // Tab expansion (before any width measurements).
        if ($this->tabWidth > 0 && str_contains($content, "\t")) {
            $content = str_replace("\t", str_repeat(' ', $this->tabWidth), $content);
        }
        // Inline mode collapses newlines into spaces and zeroes vertical
        // padding/margin so the result is one line.
        if ($this->inline) {
            $content = str_replace("\n", ' ', $content);
        }

        [$pT, $pR, $pB, $pL] = $this->padding;
        [$mT, $mR, $mB, $mL] = $this->margin;
        if ($this->inline) {
            $pT = $pB = 0;
            $mT = $mB = 0;
        }
        $bSides = $this->border !== null ? $this->borderSides : [false, false, false, false];

        $sgr = $this->buildContentSgr();
        $reset = $sgr === '' ? '' : Ansi::reset();

        // 1. Lines from content.
        $lines = $content === '' ? [''] : explode("\n", $content);

        // 2. Inner width: explicit, else max line width.
        if ($this->width !== null) {
            $innerWidth = $this->width;
            // Preserve inline ANSI escapes when callers pass pre-styled
            // content (e.g. another Style's render() output).
            $lines = array_map(static fn(string $l) => Width::truncateAnsi($l, $innerWidth), $lines);
        } else {
            $innerWidth = 0;
            foreach ($lines as $l) {
                $innerWidth = max($innerWidth, Width::string($l));
            }
        }
        // MaxWidth caps innerWidth without padding shorter lines.
        if ($this->maxWidth !== null && $innerWidth > $this->maxWidth) {
            $innerWidth = $this->maxWidth;
            $lines = array_map(static fn(string $l) => Width::truncateAnsi($l, $innerWidth), $lines);
        }

        // 3. Horizontal alignment within innerWidth.
        $lines = array_map(fn(string $l) => $this->halign($l, $innerWidth), $lines);

        // 4. Padding (styled — only if colorWhitespace).
        $contentWidth = $pL + $innerWidth + $pR;
        if ($this->colorWhitespace) {
            $padBlank = $sgr . str_repeat(' ', $contentWidth) . $reset;
            $padLeftStr  = $sgr . str_repeat(' ', $pL);
            $padRightStr = str_repeat(' ', $pR) . $reset;
            $linePrefix = $sgr;
            $lineSuffix = $reset;
        } else {
            $padBlank = str_repeat(' ', $contentWidth);
            $padLeftStr  = str_repeat(' ', $pL);
            $padRightStr = str_repeat(' ', $pR);
            $linePrefix = $sgr;
            $lineSuffix = $reset;
        }

        $body = [];
        for ($i = 0; $i < $pT; $i++) {
            $body[] = $padBlank;
        }
        foreach ($lines as $l) {
            if ($this->colorWhitespace) {
                $body[] = $sgr . str_repeat(' ', $pL) . $l . str_repeat(' ', $pR) . $reset;
            } else {
                $body[] = str_repeat(' ', $pL) . $linePrefix . $l . $lineSuffix . str_repeat(' ', $pR);
            }
        }
        for ($i = 0; $i < $pB; $i++) {
            $body[] = $padBlank;
        }

        // 5. Fixed height: anchor body per alignV, padding above/below with
        //    styled blank rows. If body already exceeds the height, truncate
        //    consistently with the anchor.
        if ($this->height !== null) {
            $body = $this->vfit($body, $this->height, $padBlank);
        }
        // MaxHeight caps without padding shorter content.
        if ($this->maxHeight !== null && count($body) > $this->maxHeight) {
            $body = array_slice($body, 0, $this->maxHeight);
        }

        // 6. Border.
        $rows = $this->applyBorder($body, $contentWidth, $bSides);
        $borderedWidth = $contentWidth + ($bSides[1] ? 1 : 0) + ($bSides[3] ? 1 : 0);

        // 7. Transform: callback rewrite of the bordered+padded body.
        if ($this->transform !== null) {
            $rendered = ($this->transform)(implode("\n", $rows));
            $rows = explode("\n", $rendered);
        }

        // 8. Margin (optionally backgrounded).
        $marginSgr = '';
        $marginReset = '';
        if ($this->marginBg !== null) {
            $marginSgr = $this->marginBg->toBg($this->profile);
            $marginReset = $marginSgr === '' ? '' : Ansi::reset();
        }
        $marginBlank = $marginSgr . str_repeat(' ', $mL + $borderedWidth + $mR) . $marginReset;
        $mLstr = $marginSgr . str_repeat(' ', $mL) . $marginReset;
        $mRstr = $marginSgr . str_repeat(' ', $mR) . $marginReset;
        if ($mL === 0) { $mLstr = ''; }
        if ($mR === 0) { $mRstr = ''; }

        $out = [];
        for ($i = 0; $i < $mT; $i++) {
            $out[] = $marginBlank;
        }
        foreach ($rows as $r) {
            $out[] = $mLstr . $r . $mRstr;
        }
        for ($i = 0; $i < $mB; $i++) {
            $out[] = $marginBlank;
        }

        return implode("\n", $out);
    }

    public function __invoke(string $content): string
    {
        return $this->render($content);
    }

    /**
     * Alias for {@see render()} matching lipgloss v2's `Sprint`.
     * Concatenates all arguments with single spaces between them
     * before styling.
     */
    public function sprint(string ...$content): string
    {
        return $this->render(implode(' ', $content));
    }

    /**
     * Render and `printf`-substitute. Mirrors lipgloss v2's `Printf`.
     */
    public function printfSprint(string $format, mixed ...$args): string
    {
        return $this->render(sprintf($format, ...$args));
    }

    /**
     * Render to STDOUT followed by a newline. Mirrors lipgloss v2's
     * `Println` for non-TUI scripts that just want styled console
     * output.
     */
    public function println(string ...$content): void
    {
        fwrite(STDOUT, $this->sprint(...$content) . "\n");
    }

    /**
     * Render to STDOUT (no trailing newline). Mirrors `Print`.
     */
    public function print(string ...$content): void
    {
        fwrite(STDOUT, $this->sprint(...$content));
    }

    /**
     * Render to a caller-supplied stream resource. Mirrors `Fprint`.
     *
     * @param resource $stream
     */
    public function fprint($stream, string ...$content): void
    {
        fwrite($stream, $this->sprint(...$content));
    }

    private function halign(string $line, int $innerWidth): string
    {
        $w = Width::string($line);
        $extra = $innerWidth - $w;
        if ($extra <= 0) {
            return $line;
        }
        return match ($this->alignH) {
            Align::Left   => $line . str_repeat(' ', $extra),
            Align::Right  => str_repeat(' ', $extra) . $line,
            Align::Center => str_repeat(' ', intdiv($extra, 2)) . $line . str_repeat(' ', $extra - intdiv($extra, 2)),
        };
    }

    /**
     * Pad or truncate $body to exactly $height rows, anchored per alignV.
     *
     * @param  list<string> $body
     * @return list<string>
     */
    private function vfit(array $body, int $height, string $blank): array
    {
        $count = count($body);
        if ($count === $height) {
            return $body;
        }
        if ($count < $height) {
            $extra = $height - $count;
            return match ($this->alignV) {
                VAlign::Top    => array_merge($body, array_fill(0, $extra, $blank)),
                VAlign::Bottom => array_merge(array_fill(0, $extra, $blank), $body),
                VAlign::Middle => array_merge(
                    array_fill(0, intdiv($extra, 2), $blank),
                    $body,
                    array_fill(0, $extra - intdiv($extra, 2), $blank),
                ),
            };
        }
        // Truncate. Drop excess from whichever side holds non-anchor content.
        $excess = $count - $height;
        return match ($this->alignV) {
            VAlign::Top    => array_slice($body, 0, $height),
            VAlign::Bottom => array_slice($body, $excess),
            VAlign::Middle => array_slice($body, intdiv($excess, 2), $height),
        };
    }

    /**
     * @param list<string>                $body
     * @param array{bool,bool,bool,bool}  $sides
     * @return list<string>
     */
    private function applyBorder(array $body, int $contentWidth, array $sides): array
    {
        if ($this->border === null || !in_array(true, $sides, true)) {
            return $body;
        }
        $b = $this->border;
        [$top, $right, $bottom, $left] = $sides;

        // Resolve per-side colours, falling back to the default
        // borderForeground / borderBackground.
        $sideSgr = function (int $sideIdx): array {
            $fg = $this->borderSideFg[$sideIdx] ?? $this->borderFg;
            $bg = $this->borderSideBg[$sideIdx] ?? $this->borderBg;
            $sgr = '';
            if ($fg !== null) $sgr .= $fg->toFg($this->profile);
            if ($bg !== null) $sgr .= $bg->toBg($this->profile);
            $reset = $sgr === '' ? '' : Ansi::reset();
            return [$sgr, $reset];
        };

        [$topSgr,    $topReset]    = $sideSgr(0);
        [$rightSgr,  $rightReset]  = $sideSgr(1);
        [$bottomSgr, $bottomReset] = $sideSgr(2);
        [$leftSgr,   $leftReset]   = $sideSgr(3);

        $out = [];
        if ($top) {
            $line = ($left  ? $b->topLeft  : '')
                  . str_repeat($b->top, $contentWidth)
                  . ($right ? $b->topRight : '');
            $out[] = $topSgr . $line . $topReset;
        }
        $leftRune  = $left  ? ($leftSgr  . $b->left  . $leftReset)  : '';
        $rightRune = $right ? ($rightSgr . $b->right . $rightReset) : '';
        foreach ($body as $row) {
            $out[] = $leftRune . $row . $rightRune;
        }
        if ($bottom) {
            $line = ($left  ? $b->bottomLeft  : '')
                  . str_repeat($b->bottom, $contentWidth)
                  . ($right ? $b->bottomRight : '');
            $out[] = $bottomSgr . $line . $bottomReset;
        }
        return $out;
    }

    private function buildContentSgr(): string
    {
        $codes = [];
        if ($this->bold)      $codes[] = Ansi::BOLD;
        if ($this->faint)     $codes[] = Ansi::FAINT;
        if ($this->italic)    $codes[] = Ansi::ITALIC;
        if ($this->underline) $codes[] = Ansi::UNDERLINE;
        if ($this->blink)     $codes[] = Ansi::BLINK;
        if ($this->reverse)   $codes[] = Ansi::REVERSE;
        if ($this->strike)    $codes[] = Ansi::STRIKE;

        $sgr = $codes === [] ? '' : Ansi::sgr(...$codes);
        if ($this->fg !== null) $sgr .= $this->fg->toFg($this->profile);
        if ($this->bg !== null) $sgr .= $this->bg->toBg($this->profile);
        return $sgr;
    }

    private function buildBorderSgr(): string
    {
        $sgr = '';
        if ($this->borderFg !== null) $sgr .= $this->borderFg->toFg($this->profile);
        if ($this->borderBg !== null) $sgr .= $this->borderBg->toBg($this->profile);
        return $sgr;
    }

    /**
     * @param array{int,int,int,int} $cur
     * @return array{int,int,int,int}
     */
    private static function setSide(array $cur, int $idx, int $value, string $label): array
    {
        if ($value < 0) {
            throw new \InvalidArgumentException("$label values must be >= 0");
        }
        $cur[$idx] = $value;
        return $cur;
    }

    /**
     * @param list<int> $sides
     * @return array{int,int,int,int}
     */
    private static function expandSides(array $sides, string $label): array
    {
        $out = match (count($sides)) {
            1 => [$sides[0], $sides[0], $sides[0], $sides[0]],
            2 => [$sides[0], $sides[1], $sides[0], $sides[1]],
            4 => [$sides[0], $sides[1], $sides[2], $sides[3]],
            default => throw new \InvalidArgumentException(
                "$label() takes 1, 2, or 4 ints; got " . count($sides)
            ),
        };
        foreach ($out as $v) {
            if ($v < 0) {
                throw new \InvalidArgumentException("$label values must be >= 0");
            }
        }
        return $out;
    }

    /**
     * @param array{int,int,int,int}|null     $padding
     * @param array{int,int,int,int}|null     $margin
     * @param array{bool,bool,bool,bool}|null $borderSides
     * @param list<string>                    $propsAdded
     */
    private function with(
        ?Color $fg = null, bool $fgSet = false,
        ?Color $bg = null, bool $bgSet = false,
        ?AdaptiveColor $fgAdaptive = null, bool $fgAdaptiveSet = false,
        ?AdaptiveColor $bgAdaptive = null, bool $bgAdaptiveSet = false,
        ?CompleteColor $fgComplete = null, bool $fgCompleteSet = false,
        ?CompleteColor $bgComplete = null, bool $bgCompleteSet = false,
        ?bool $bold = null,
        ?bool $italic = null,
        ?bool $underline = null,
        ?bool $strike = null,
        ?bool $faint = null,
        ?bool $blink = null,
        ?bool $reverse = null,
        ?array $padding = null,
        ?array $margin = null,
        ?int $width = null, bool $widthSet = false,
        ?int $height = null, bool $heightSet = false,
        ?int $maxWidth = null, bool $maxWidthSet = false,
        ?int $maxHeight = null, bool $maxHeightSet = false,
        ?Align $alignH = null,
        ?VAlign $alignV = null,
        ?Border $border = null, bool $borderSet = false,
        ?array $borderSides = null,
        ?Color $borderFg = null, bool $borderFgSet = false,
        ?Color $borderBg = null, bool $borderBgSet = false,
        ?array $borderSideFg = null,
        ?array $borderSideBg = null,
        ?ColorProfile $profile = null,
        ?bool $inline = null,
        ?Color $marginBg = null, bool $marginBgSet = false,
        ?bool $colorWhitespace = null,
        ?int $tabWidth = null,
        ?\Closure $transform = null, bool $transformSet = false,
        array $propsAdded = [],
    ): self {
        $newProps = $this->propsSet;
        foreach ($propsAdded as $p) {
            $newProps[$p] = true;
        }
        return new self(
            fg:               $fgSet         ? $fg              : $this->fg,
            bg:               $bgSet         ? $bg              : $this->bg,
            fgAdaptive:       $fgAdaptiveSet ? $fgAdaptive      : $this->fgAdaptive,
            bgAdaptive:       $bgAdaptiveSet ? $bgAdaptive      : $this->bgAdaptive,
            fgComplete:       $fgCompleteSet ? $fgComplete      : $this->fgComplete,
            bgComplete:       $bgCompleteSet ? $bgComplete      : $this->bgComplete,
            bold:             $bold          ?? $this->bold,
            italic:           $italic        ?? $this->italic,
            underline:        $underline     ?? $this->underline,
            strike:           $strike        ?? $this->strike,
            faint:            $faint         ?? $this->faint,
            blink:            $blink         ?? $this->blink,
            reverse:          $reverse       ?? $this->reverse,
            padding:          $padding       ?? $this->padding,
            margin:           $margin        ?? $this->margin,
            width:            $widthSet      ? $width           : $this->width,
            height:           $heightSet     ? $height          : $this->height,
            maxWidth:         $maxWidthSet   ? $maxWidth        : $this->maxWidth,
            maxHeight:        $maxHeightSet  ? $maxHeight       : $this->maxHeight,
            alignH:           $alignH        ?? $this->alignH,
            alignV:           $alignV        ?? $this->alignV,
            border:           $borderSet     ? $border          : $this->border,
            borderSides:      $borderSides   ?? $this->borderSides,
            borderFg:         $borderFgSet   ? $borderFg        : $this->borderFg,
            borderBg:         $borderBgSet   ? $borderBg        : $this->borderBg,
            borderSideFg:     $borderSideFg  ?? $this->borderSideFg,
            borderSideBg:     $borderSideBg  ?? $this->borderSideBg,
            profile:          $profile       ?? $this->profile,
            propsSet:         $newProps,
            inline:           $inline        ?? $this->inline,
            marginBg:         $marginBgSet   ? $marginBg        : $this->marginBg,
            colorWhitespace:  $colorWhitespace ?? $this->colorWhitespace,
            tabWidth:         $tabWidth      ?? $this->tabWidth,
            transform:        $transformSet  ? $transform       : $this->transform,
        );
    }

    /**
     * Reset a property to its zero value AND scrub it from $propsSet so
     * future inherit() calls can re-apply the parent's value.
     */
    private function withUnset(
        string $prop,
        ?Color $fg = null, bool $fgSet = false,
        ?Color $bg = null, bool $bgSet = false,
        ?bool $bold = null,
        ?bool $italic = null,
        ?bool $underline = null,
        ?bool $strike = null,
        ?bool $faint = null,
        ?bool $blink = null,
        ?bool $reverse = null,
        ?int $width = null, bool $widthSet = false,
        ?int $height = null, bool $heightSet = false,
        ?int $maxWidth = null, bool $maxWidthSet = false,
        ?int $maxHeight = null, bool $maxHeightSet = false,
        ?Border $border = null, bool $borderSet = false,
        ?\Closure $transform = null, bool $transformSet = false,
    ): self {
        $next = $this;
        $newProps = $this->propsSet;
        unset($newProps[$prop]);
        // Apply zero value on the relevant slot.
        return new self(
            fg:               $prop === 'fg'         ? null         : $next->fg,
            bg:               $prop === 'bg'         ? null         : $next->bg,
            fgAdaptive:       $next->fgAdaptive,
            bgAdaptive:       $next->bgAdaptive,
            fgComplete:       $next->fgComplete,
            bgComplete:       $next->bgComplete,
            bold:             $prop === 'bold'       ? false        : $next->bold,
            italic:           $prop === 'italic'     ? false        : $next->italic,
            underline:        $prop === 'underline'  ? false        : $next->underline,
            strike:           $prop === 'strike'     ? false        : $next->strike,
            faint:            $prop === 'faint'      ? false        : $next->faint,
            blink:            $prop === 'blink'      ? false        : $next->blink,
            reverse:          $prop === 'reverse'    ? false        : $next->reverse,
            padding:          $next->padding,
            margin:           $next->margin,
            width:            $prop === 'width'      ? null         : $next->width,
            height:           $prop === 'height'     ? null         : $next->height,
            maxWidth:         $prop === 'maxWidth'   ? null         : $next->maxWidth,
            maxHeight:        $prop === 'maxHeight'  ? null         : $next->maxHeight,
            alignH:           $next->alignH,
            alignV:           $next->alignV,
            border:           $prop === 'border'     ? null         : $next->border,
            borderSides:      $next->borderSides,
            borderFg:         $next->borderFg,
            borderBg:         $next->borderBg,
            borderSideFg:     $next->borderSideFg,
            borderSideBg:     $next->borderSideBg,
            profile:          $next->profile,
            propsSet:         $newProps,
            inline:           $next->inline,
            marginBg:         $next->marginBg,
            colorWhitespace:  $next->colorWhitespace,
            tabWidth:         $next->tabWidth,
            transform:        $prop === 'transform'  ? null         : $next->transform,
        );
    }
}
