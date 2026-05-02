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
    private function __construct(
        private readonly ?Color $fg = null,
        private readonly ?Color $bg = null,
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
        private readonly Align $alignH = Align::Left,
        private readonly VAlign $alignV = VAlign::Top,
        private readonly ?Border $border = null,
        private readonly array $borderSides = [true, true, true, true],
        private readonly ?Color $borderFg = null,
        private readonly ?Color $borderBg = null,
        private readonly ColorProfile $profile = ColorProfile::TrueColor,
        private readonly array $propsSet = [],
    ) {}

    public static function new(): self
    {
        return new self();
    }

    public function foreground(?Color $c): self          { return $this->with(fg: $c, fgSet: true, propsAdded: ['fg']); }
    public function background(?Color $c): self          { return $this->with(bg: $c, bgSet: true, propsAdded: ['bg']); }
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

    public function colorProfile(ColorProfile $p): self { return $this->with(profile: $p, propsAdded: ['profile']); }

    /**
     * Merge {@see $parent} into this style. Any property the child
     * explicitly set wins; everything else is taken from the parent.
     */
    public function inherit(self $parent): self
    {
        $result = $parent;
        foreach (array_keys($this->propsSet) as $prop) {
            $result = match ($prop) {
                'fg'          => $result->with(fg: $this->fg, fgSet: true, propsAdded: ['fg']),
                'bg'          => $result->with(bg: $this->bg, bgSet: true, propsAdded: ['bg']),
                'bold'        => $result->with(bold: $this->bold, propsAdded: ['bold']),
                'italic'      => $result->with(italic: $this->italic, propsAdded: ['italic']),
                'underline'   => $result->with(underline: $this->underline, propsAdded: ['underline']),
                'strike'      => $result->with(strike: $this->strike, propsAdded: ['strike']),
                'faint'       => $result->with(faint: $this->faint, propsAdded: ['faint']),
                'blink'       => $result->with(blink: $this->blink, propsAdded: ['blink']),
                'reverse'     => $result->with(reverse: $this->reverse, propsAdded: ['reverse']),
                'padding'     => $result->with(padding: $this->padding, propsAdded: ['padding']),
                'margin'      => $result->with(margin: $this->margin, propsAdded: ['margin']),
                'width'       => $result->with(width: $this->width, widthSet: true, propsAdded: ['width']),
                'height'      => $result->with(height: $this->height, heightSet: true, propsAdded: ['height']),
                'alignH'      => $result->with(alignH: $this->alignH, propsAdded: ['alignH']),
                'alignV'      => $result->with(alignV: $this->alignV, propsAdded: ['alignV']),
                'border'      => $result->with(border: $this->border, borderSet: true, propsAdded: ['border']),
                'borderSides' => $result->with(borderSides: $this->borderSides, propsAdded: ['borderSides']),
                'borderFg'    => $result->with(borderFg: $this->borderFg, borderFgSet: true, propsAdded: ['borderFg']),
                'borderBg'    => $result->with(borderBg: $this->borderBg, borderBgSet: true, propsAdded: ['borderBg']),
                'profile'     => $result->with(profile: $this->profile, propsAdded: ['profile']),
                default       => $result,
            };
        }
        return $result;
    }

    public function render(string $content): string
    {
        [$pT, $pR, $pB, $pL] = $this->padding;
        [$mT, $mR, $mB, $mL] = $this->margin;
        $bSides = $this->border !== null ? $this->borderSides : [false, false, false, false];

        $sgr = $this->buildContentSgr();
        $reset = $sgr === '' ? '' : Ansi::reset();

        // 1. Lines from content.
        $lines = $content === '' ? [''] : explode("\n", $content);

        // 2. Inner width: explicit, else max line width.
        if ($this->width !== null) {
            $innerWidth = $this->width;
            $lines = array_map(static fn(string $l) => Width::truncate($l, $innerWidth), $lines);
        } else {
            $innerWidth = 0;
            foreach ($lines as $l) {
                $innerWidth = max($innerWidth, Width::string($l));
            }
        }

        // 3. Horizontal alignment within innerWidth.
        $lines = array_map(fn(string $l) => $this->halign($l, $innerWidth), $lines);

        // 4. Padding (styled).
        $contentWidth = $pL + $innerWidth + $pR;
        $padBlank = $sgr . str_repeat(' ', $contentWidth) . $reset;
        $padLeftStr  = str_repeat(' ', $pL);
        $padRightStr = str_repeat(' ', $pR);

        $body = [];
        for ($i = 0; $i < $pT; $i++) {
            $body[] = $padBlank;
        }
        foreach ($lines as $l) {
            $body[] = $sgr . $padLeftStr . $l . $padRightStr . $reset;
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

        // 6. Border.
        $rows = $this->applyBorder($body, $contentWidth, $bSides);
        $borderedWidth = $contentWidth + ($bSides[1] ? 1 : 0) + ($bSides[3] ? 1 : 0);

        // 7. Margin (unstyled).
        $marginBlank = str_repeat(' ', $mL + $borderedWidth + $mR);
        $mLstr = str_repeat(' ', $mL);
        $mRstr = str_repeat(' ', $mR);

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

        $bSgr = $this->buildBorderSgr();
        $bReset = $bSgr === '' ? '' : Ansi::reset();

        $out = [];
        if ($top) {
            $line = ($left ? $b->topLeft : '')
                  . str_repeat($b->top, $contentWidth)
                  . ($right ? $b->topRight : '');
            $out[] = $bSgr . $line . $bReset;
        }
        $leftRune  = $left  ? ($bSgr . $b->left  . $bReset) : '';
        $rightRune = $right ? ($bSgr . $b->right . $bReset) : '';
        foreach ($body as $row) {
            $out[] = $leftRune . $row . $rightRune;
        }
        if ($bottom) {
            $line = ($left ? $b->bottomLeft : '')
                  . str_repeat($b->bottom, $contentWidth)
                  . ($right ? $b->bottomRight : '');
            $out[] = $bSgr . $line . $bReset;
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
        ?Align $alignH = null,
        ?VAlign $alignV = null,
        ?Border $border = null, bool $borderSet = false,
        ?array $borderSides = null,
        ?Color $borderFg = null, bool $borderFgSet = false,
        ?Color $borderBg = null, bool $borderBgSet = false,
        ?ColorProfile $profile = null,
        array $propsAdded = [],
    ): self {
        $newProps = $this->propsSet;
        foreach ($propsAdded as $p) {
            $newProps[$p] = true;
        }
        return new self(
            fg:          $fgSet         ? $fg          : $this->fg,
            bg:          $bgSet         ? $bg          : $this->bg,
            bold:        $bold          ?? $this->bold,
            italic:      $italic        ?? $this->italic,
            underline:   $underline     ?? $this->underline,
            strike:      $strike        ?? $this->strike,
            faint:       $faint         ?? $this->faint,
            blink:       $blink         ?? $this->blink,
            reverse:     $reverse       ?? $this->reverse,
            padding:     $padding       ?? $this->padding,
            margin:      $margin        ?? $this->margin,
            width:       $widthSet      ? $width       : $this->width,
            height:      $heightSet     ? $height      : $this->height,
            alignH:      $alignH        ?? $this->alignH,
            alignV:      $alignV        ?? $this->alignV,
            border:      $borderSet     ? $border      : $this->border,
            borderSides: $borderSides   ?? $this->borderSides,
            borderFg:    $borderFgSet   ? $borderFg    : $this->borderFg,
            borderBg:    $borderBgSet   ? $borderBg    : $this->borderBg,
            profile:     $profile       ?? $this->profile,
            propsSet:    $newProps,
        );
    }
}
