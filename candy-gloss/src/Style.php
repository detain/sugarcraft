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
 * Mirrors the lipgloss `Style` API at the level needed for Phase 1: text
 * attributes (bold/italic/underline/strikethrough/reverse/faint/blink),
 * foreground/background color, and per-side padding. Borders, margins,
 * alignment, fixed width/height, and inheritance arrive in subsequent
 * Phase-1 increments.
 */
final class Style
{
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
        /** @var array{int,int,int,int} top, right, bottom, left */
        private readonly array $padding = [0, 0, 0, 0],
        private readonly ColorProfile $profile = ColorProfile::TrueColor,
    ) {}

    public static function new(): self
    {
        return new self();
    }

    public function foreground(?Color $c): self      { return $this->with(fg: $c); }
    public function background(?Color $c): self      { return $this->with(bg: $c); }
    public function bold(bool $on = true): self      { return $this->with(bold: $on); }
    public function italic(bool $on = true): self    { return $this->with(italic: $on); }
    public function underline(bool $on = true): self { return $this->with(underline: $on); }
    public function strikethrough(bool $on = true): self { return $this->with(strike: $on); }
    public function faint(bool $on = true): self     { return $this->with(faint: $on); }
    public function blink(bool $on = true): self     { return $this->with(blink: $on); }
    public function reverse(bool $on = true): self   { return $this->with(reverse: $on); }

    /**
     * Padding mirrors CSS shorthand:
     *   padding($all)
     *   padding($vertical, $horizontal)
     *   padding($top, $right, $bottom, $left)
     */
    public function padding(int ...$sides): self
    {
        $p = match (count($sides)) {
            1 => [$sides[0], $sides[0], $sides[0], $sides[0]],
            2 => [$sides[0], $sides[1], $sides[0], $sides[1]],
            4 => [$sides[0], $sides[1], $sides[2], $sides[3]],
            default => throw new \InvalidArgumentException(
                'padding() takes 1, 2, or 4 ints; got ' . count($sides)
            ),
        };
        foreach ($p as $v) {
            if ($v < 0) {
                throw new \InvalidArgumentException('padding values must be >= 0');
            }
        }
        return $this->with(padding: $p);
    }

    public function paddingTop(int $n): self    { return $this->withPadding(0, $n); }
    public function paddingRight(int $n): self  { return $this->withPadding(1, $n); }
    public function paddingBottom(int $n): self { return $this->withPadding(2, $n); }
    public function paddingLeft(int $n): self   { return $this->withPadding(3, $n); }

    public function colorProfile(ColorProfile $p): self
    {
        return $this->with(profile: $p);
    }

    /**
     * Render the supplied content, applying SGR codes and padding. Multi-line
     * strings have padding and styling applied per line. Returns the styled
     * string with a trailing reset whenever any styling was emitted.
     */
    public function render(string $content): string
    {
        $sgr = $this->buildSgr();
        $reset = $sgr === '' ? '' : Ansi::reset();

        [$top, $right, $bottom, $left] = $this->padding;

        $lines = $content === '' ? [''] : explode("\n", $content);
        $maxWidth = 0;
        foreach ($lines as $line) {
            $maxWidth = max($maxWidth, Width::string($line));
        }

        $padLeftStr  = str_repeat(' ', $left);
        $padRightFor = static fn(string $line) =>
            str_repeat(' ', $right + max(0, $maxWidth - Width::string($line)));

        $rendered = [];
        $blank = $sgr . str_repeat(' ', $left + $maxWidth + $right) . $reset;

        for ($i = 0; $i < $top; $i++) {
            $rendered[] = $blank;
        }
        foreach ($lines as $line) {
            $rendered[] = $sgr . $padLeftStr . $line . $padRightFor($line) . $reset;
        }
        for ($i = 0; $i < $bottom; $i++) {
            $rendered[] = $blank;
        }

        return implode("\n", $rendered);
    }

    public function __invoke(string $content): string
    {
        return $this->render($content);
    }

    private function buildSgr(): string
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

    private function withPadding(int $idx, int $value): self
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('padding values must be >= 0');
        }
        $p = $this->padding;
        $p[$idx] = $value;
        return $this->with(padding: $p);
    }

    /**
     * @param array{int,int,int,int}|null $padding
     */
    private function with(
        ?Color $fg = null,
        ?Color $bg = null,
        ?bool $bold = null,
        ?bool $italic = null,
        ?bool $underline = null,
        ?bool $strike = null,
        ?bool $faint = null,
        ?bool $blink = null,
        ?bool $reverse = null,
        ?array $padding = null,
        ?ColorProfile $profile = null,
        bool $clearFg = false,
        bool $clearBg = false,
    ): self {
        return new self(
            fg:        $clearFg ? null : ($fg ?? $this->fg),
            bg:        $clearBg ? null : ($bg ?? $this->bg),
            bold:      $bold      ?? $this->bold,
            italic:    $italic    ?? $this->italic,
            underline: $underline ?? $this->underline,
            strike:    $strike    ?? $this->strike,
            faint:     $faint     ?? $this->faint,
            blink:     $blink     ?? $this->blink,
            reverse:   $reverse   ?? $this->reverse,
            padding:   $padding   ?? $this->padding,
            profile:   $profile   ?? $this->profile,
        );
    }
}
