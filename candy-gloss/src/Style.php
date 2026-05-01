<?php

declare(strict_types=1);

namespace CandyCore\Gloss;

use CandyCore\Core\Util\Ansi;
use CandyCore\Core\Util\Color;
use CandyCore\Core\Util\ColorProfile;
use CandyCore\Core\Util\Width;

/**
 * Immutable styled-text builder. Each setter returns a new Style.
 *
 * Render pipeline (innermost to outermost):
 *   content → fixed width + horizontal alignment → padding (styled)
 *           → fixed height (vertical fill) → border (styled separately)
 *           → margin (unstyled)
 */
final class Style
{
    /**
     * @param array{int,int,int,int}      $padding      top, right, bottom, left
     * @param array{int,int,int,int}      $margin       top, right, bottom, left
     * @param array{bool,bool,bool,bool}  $borderSides  top, right, bottom, left
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
        private readonly ?Border $border = null,
        private readonly array $borderSides = [true, true, true, true],
        private readonly ?Color $borderFg = null,
        private readonly ?Color $borderBg = null,
        private readonly ColorProfile $profile = ColorProfile::TrueColor,
    ) {}

    public static function new(): self
    {
        return new self();
    }

    public function foreground(?Color $c): self          { return $this->with(fg: $c, fgSet: true); }
    public function background(?Color $c): self          { return $this->with(bg: $c, bgSet: true); }
    public function bold(bool $on = true): self          { return $this->with(bold: $on); }
    public function italic(bool $on = true): self        { return $this->with(italic: $on); }
    public function underline(bool $on = true): self     { return $this->with(underline: $on); }
    public function strikethrough(bool $on = true): self { return $this->with(strike: $on); }
    public function faint(bool $on = true): self         { return $this->with(faint: $on); }
    public function blink(bool $on = true): self         { return $this->with(blink: $on); }
    public function reverse(bool $on = true): self       { return $this->with(reverse: $on); }

    /** padding($all) | padding($v, $h) | padding($t, $r, $b, $l) */
    public function padding(int ...$sides): self
    {
        return $this->with(padding: self::expandSides($sides, 'padding'));
    }
    public function paddingTop(int $n): self    { return $this->with(padding: self::setSide($this->padding, 0, $n, 'padding')); }
    public function paddingRight(int $n): self  { return $this->with(padding: self::setSide($this->padding, 1, $n, 'padding')); }
    public function paddingBottom(int $n): self { return $this->with(padding: self::setSide($this->padding, 2, $n, 'padding')); }
    public function paddingLeft(int $n): self   { return $this->with(padding: self::setSide($this->padding, 3, $n, 'padding')); }

    /** margin($all) | margin($v, $h) | margin($t, $r, $b, $l) */
    public function margin(int ...$sides): self
    {
        return $this->with(margin: self::expandSides($sides, 'margin'));
    }
    public function marginTop(int $n): self    { return $this->with(margin: self::setSide($this->margin, 0, $n, 'margin')); }
    public function marginRight(int $n): self  { return $this->with(margin: self::setSide($this->margin, 1, $n, 'margin')); }
    public function marginBottom(int $n): self { return $this->with(margin: self::setSide($this->margin, 2, $n, 'margin')); }
    public function marginLeft(int $n): self   { return $this->with(margin: self::setSide($this->margin, 3, $n, 'margin')); }

    public function width(?int $w): self
    {
        if ($w !== null && $w < 0) {
            throw new \InvalidArgumentException('width must be >= 0');
        }
        return $this->with(width: $w, widthSet: true);
    }

    public function height(?int $h): self
    {
        if ($h !== null && $h < 0) {
            throw new \InvalidArgumentException('height must be >= 0');
        }
        return $this->with(height: $h, heightSet: true);
    }

    public function align(Align $h): self
    {
        return $this->with(alignH: $h);
    }

    /**
     * Apply a border. Sides (variadic) follow CSS shorthand:
     *   border($b)                   → all four sides
     *   border($b, $v, $h)           → top/bottom = $v, left/right = $h
     *   border($b, $t, $r, $b, $l)   → per side
     *
     * `null` removes the border.
     */
    public function border(?Border $b, bool ...$sides): self
    {
        if ($b === null) {
            return $this->with(border: null, borderSet: true);
        }
        $expanded = match (count($sides)) {
            0 => [true, true, true, true],
            2 => [$sides[0], $sides[1], $sides[0], $sides[1]],
            4 => [$sides[0], $sides[1], $sides[2], $sides[3]],
            default => throw new \InvalidArgumentException(
                'border() side toggles take 0, 2, or 4 bools; got ' . count($sides)
            ),
        };
        return $this->with(border: $b, borderSet: true, borderSides: $expanded);
    }

    public function borderTop(bool $on = true): self    { $s = $this->borderSides; $s[0] = $on; return $this->with(borderSides: $s); }
    public function borderRight(bool $on = true): self  { $s = $this->borderSides; $s[1] = $on; return $this->with(borderSides: $s); }
    public function borderBottom(bool $on = true): self { $s = $this->borderSides; $s[2] = $on; return $this->with(borderSides: $s); }
    public function borderLeft(bool $on = true): self   { $s = $this->borderSides; $s[3] = $on; return $this->with(borderSides: $s); }

    public function borderForeground(?Color $c): self { return $this->with(borderFg: $c, borderFgSet: true); }
    public function borderBackground(?Color $c): self { return $this->with(borderBg: $c, borderBgSet: true); }

    public function colorProfile(ColorProfile $p): self { return $this->with(profile: $p); }

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

        // 5. Fixed height: pad with styled blank rows.
        if ($this->height !== null) {
            while (count($body) < $this->height) {
                $body[] = $padBlank;
            }
            if (count($body) > $this->height) {
                $body = array_slice($body, 0, $this->height);
            }
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
        ?Border $border = null, bool $borderSet = false,
        ?array $borderSides = null,
        ?Color $borderFg = null, bool $borderFgSet = false,
        ?Color $borderBg = null, bool $borderBgSet = false,
        ?ColorProfile $profile = null,
    ): self {
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
            border:      $borderSet     ? $border      : $this->border,
            borderSides: $borderSides   ?? $this->borderSides,
            borderFg:    $borderFgSet   ? $borderFg    : $this->borderFg,
            borderBg:    $borderBgSet   ? $borderBg    : $this->borderBg,
            profile:     $profile       ?? $this->profile,
        );
    }
}
