<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer;

/**
 * Minimal per-cell style record — foreground colour, background colour,
 * palette-slot identity, underline colour, and a raw attribute bitmask.
 *
 * This is intentionally a stripped-down cousin of
 * {@see \SugarCraft\Style\Style}. The rich styling API (border,
 * padding, underline, transform, etc.) lives in sugar-style and
 * builds on top of these primitives. Buffer/Cell carry only what the
 * terminal needs to emit SGR sequences.
 *
 * Foreground, background and underline may each carry EITHER an RGB
 * value (0xRRGGBB) OR a palette-slot identity (an index in 0-255, the
 * xterm colour-slot space the {@see \SugarCraft\Core\Util\Ansi}
 * family spells as 3x/9x/38;5/n). Slot identity lets theme-following
 * colours round-trip through diff frames without baking in a hex
 * value; {@see \SugarCraft\CellBuffer\Support\SgrEmitter} prefers the slot when one is set. The
 * with*() builders keep the two representations mutually exclusive;
 * they may coexist only as the slot plus its canonical hex belt — the
 * constructor refuses any other pairing, and clamps RGB saturating
 * (never masking) exactly like the SGR parser.
 *
 * The attribute bit layout below is this package's own. It
 * deliberately does NOT line up with sugar-vterm's independent
 * attribute constants (there INVERSE=8 / STRIKETHROUGH=16; here
 * STRIKE=8 / FAINT=16 / REVERSE=64); bridging consumers map between
 * the two. A shared cross-package enum was considered and not taken.
 *
 * @readonly
 */
final class Style implements \JsonSerializable
{
    /** Attribute bits: bold=1, italic=2, underline=4, strike=8, … */
    public const ATTR_BOLD      = 1 << 0;
    public const ATTR_ITALIC   = 1 << 1;
    public const ATTR_UNDERLINE = 1 << 2;
    public const ATTR_STRIKE   = 1 << 3;
    public const ATTR_FAINT    = 1 << 4;
    public const ATTR_BLINK    = 1 << 5;
    public const ATTR_REVERSE  = 1 << 6;
    public const ATTR_OVERLINE  = 1 << 7;
    public const ATTR_INVISIBLE= 1 << 8;

    /**
     * @param int|null $fg     Foreground RGB 0xRRGGBB (saturating clamp)
     * @param int|null $bg     Background RGB 0xRRGGBB (saturating clamp)
     * @param int      $attrs  Bitmask of ATTR_* constants
     * @param int|null $fgSlot Foreground palette slot 0-255
     * @param int|null $bgSlot Background palette slot 0-255
     * @param int|null $ul     Underline RGB 0xRRGGBB (saturating clamp)
     * @param int|null $ulSlot Underline palette slot 0-255
     *
     * Channel law: a colour is an RGB value, a palette slot, or the slot
     * PAIRED with its canonical hex belt (what {@see fromSgr()'s} palette
     * arms record). An RGB that is not the slot's canonical value alongside
     * that slot is a contradiction and is refused — emission prefers the
     * slot, so a hand-forged mismatched pair silently loses the hex half.
     *
     * @throws \InvalidArgumentException when a palette slot is outside 0-255
     *                                   or an RGB contradicts its co-set slot.
     */
    /** @deprecated use {@see self::fg()} — accessors are the canonical read surface. */
    public readonly ?int $fg;      // 0xRRGGBB or null = default
    /** @deprecated use {@see self::bg()} — canonical read surface. */
    public readonly ?int $bg;      // 0xRRGGBB or null = default
    /** @deprecated use {@see self::attrs()} — canonical read surface. */
    public readonly int $attrs;    // bitmask of ATTR_* constants
    /** @deprecated use {@see self::fgSlot()} — canonical read surface. */
    public readonly ?int $fgSlot;  // 0-255 palette identity, or null
    /** @deprecated use {@see self::bgSlot()} — canonical read surface. */
    public readonly ?int $bgSlot;  // 0-255 palette identity, or null
    /** @deprecated use {@see self::ul()} — canonical read surface. */
    public readonly ?int $ul;      // underline 0xRRGGBB or null
    /** @deprecated use {@see self::ulSlot()} — canonical read surface. */
    public readonly ?int $ulSlot;  // 0-255 palette identity, or null

    public function __construct(
        ?int $fg = null,
        ?int $bg = null,
        int $attrs = 0,
        ?int $fgSlot = null,
        ?int $bgSlot = null,
        ?int $ul = null,
        ?int $ulSlot = null,
    ) {
        self::checkSlot($fgSlot, 'foreground');
        self::checkSlot($bgSlot, 'background');
        self::checkSlot($ulSlot, 'underline-slot');

        // Clamp, never mask (CALIBER 2026-10-03 law): the parser saturates
        // out-of-range channels through channel(); the type carries the same
        // law so a hand-built Style can never ride bytes the parser would
        // never emit (a `& 0xFFFFFF` wrap would repaint an unrelated colour).
        $this->fg = self::clampRgb($fg);
        $this->bg = self::clampRgb($bg);
        $this->ul = self::clampRgb($ul);
        $this->attrs = $attrs;
        $this->fgSlot = $fgSlot;
        $this->bgSlot = $bgSlot;
        $this->ulSlot = $ulSlot;

        self::checkBelt($this->fg, $this->fgSlot, 'foreground');
        self::checkBelt($this->bg, $this->bgSlot, 'background');
        self::checkBelt($this->ul, $this->ulSlot, 'underline');
    }

    /**
     * Saturate one RGB channel into 0x000000-0xFFFFFF, mirroring the
     * component-wise clamp the SGR parser applies.
     */
    private static function clampRgb(?int $rgb): ?int
    {
        if ($rgb === null) {
            return null;
        }

        return max(0x000000, min(0xFFFFFF, $rgb));
    }

    /**
     * Belt law: when an RGB and a palette slot share a channel, the RGB must
     * be exactly the slot's canonical hex — the fallback belt fromSgr()
     * records — or absent. Any other pairing is a contradiction the emitter
     * would resolve by silently dropping the hex.
     */
    private static function checkBelt(?int $rgb, ?int $slot, string $name): void
    {
        if ($rgb === null || $slot === null || $rgb === self::xterm256ToHex($slot)) {
            return;
        }

        throw new \InvalidArgumentException(sprintf(
            'Style %s pairs palette slot %d with RGB 0x%06X; a co-set channel may carry only the slot\'s canonical belt hex (0x%06X) or null',
            $name,
            $slot,
            $rgb,
            self::xterm256ToHex($slot),
        ));
    }

    /**
     * Fail-fast palette-slot bound: a slot is an xterm colour index.
     */
    private static function checkSlot(?int $slot, string $name): void
    {
        if ($slot !== null && ($slot < 0 || $slot > 255)) {
            throw new \InvalidArgumentException("Style palette slot {$name} must be 0-255 or null, got {$slot}");
        }
    }

    /**
     * Default factory.
     */
    public static function new(?int $fg = null, ?int $bg = null, int $attrs = 0): self
    {
        return new self($fg, $bg, $attrs);
    }

    /** Factory: bold. */
    public static function bold(): self
    {
        return new self(null, null, self::ATTR_BOLD);
    }

    /** Factory: reverse. */
    public static function reverse(): self
    {
        return new self(null, null, self::ATTR_REVERSE);
    }

    public function fg(): ?int      { return $this->fg; }
    public function bg(): ?int      { return $this->bg; }
    public function attrs(): int    { return $this->attrs; }
    public function fgSlot(): ?int  { return $this->fgSlot; }
    public function bgSlot(): ?int  { return $this->bgSlot; }
    public function ul(): ?int      { return $this->ul; }
    public function ulSlot(): ?int  { return $this->ulSlot; }

    public function hasBold(): bool      { return (bool)($this->attrs & self::ATTR_BOLD); }
    public function hasItalic(): bool    { return (bool)($this->attrs & self::ATTR_ITALIC); }
    public function hasUnderline(): bool  { return (bool)($this->attrs & self::ATTR_UNDERLINE); }
    public function hasStrike(): bool     { return (bool)($this->attrs & self::ATTR_STRIKE); }
    public function hasFaint(): bool    { return (bool)($this->attrs & self::ATTR_FAINT); }
    public function hasBlink(): bool    { return (bool)($this->attrs & self::ATTR_BLINK); }
    public function hasReverse(): bool   { return (bool)($this->attrs & self::ATTR_REVERSE); }
    public function hasOverline(): bool { return (bool)($this->attrs & self::ATTR_OVERLINE); }
    public function hasInvisible(): bool { return (bool)($this->attrs & self::ATTR_INVISIBLE); }

    // ─── Fluent builders ────────────────────────────────────────────────
    //
    // Each setter constructs the new pen directly with named arguments:
    // the old mutate(['field' => …]) path allocated a 7-key array literal,
    // array_merged it, and re-spread it per call (~2.1× the cost of plain
    // construction, measured) — this is the per-composition hot path for
    // consumers stamping styles onto cells. Construction-time validation
    // (slots, clamp, belt) stays exactly once, in the constructor.

    /**
     * Sets the RGB foreground and discards palette-slot identity (the two
     * representations are mutually exclusive by this class's with*() law).
     */
    public function withFg(?int $fg): self
    {
        return new self(
            fg: $fg,
            bg: $this->bg,
            attrs: $this->attrs,
            fgSlot: null,
            bgSlot: $this->bgSlot,
            ul: $this->ul,
            ulSlot: $this->ulSlot,
        );
    }

    /** Sets the RGB background and discards its palette-slot identity. */
    public function withBg(?int $bg): self
    {
        return new self(
            fg: $this->fg,
            bg: $bg,
            attrs: $this->attrs,
            fgSlot: $this->fgSlot,
            bgSlot: null,
            ul: $this->ul,
            ulSlot: $this->ulSlot,
        );
    }

    /**
     * Sets the foreground palette slot (0-255, or null to clear it) and
     * discards the RGB foreground so a stale hex can never shadow the slot.
     */
    public function withFgSlot(?int $fgSlot): self
    {
        return new self(
            fg: null,
            bg: $this->bg,
            attrs: $this->attrs,
            fgSlot: $fgSlot,
            bgSlot: $this->bgSlot,
            ul: $this->ul,
            ulSlot: $this->ulSlot,
        );
    }

    /** Sets the background palette slot (0-255 or null) and discards its RGB twin. */
    public function withBgSlot(?int $bgSlot): self
    {
        return new self(
            fg: $this->fg,
            bg: null,
            attrs: $this->attrs,
            fgSlot: $this->fgSlot,
            bgSlot: $bgSlot,
            ul: $this->ul,
            ulSlot: $this->ulSlot,
        );
    }

    /** Sets the underline RGB and discards its palette-slot identity. */
    public function withUl(?int $ul): self
    {
        return new self(
            fg: $this->fg,
            bg: $this->bg,
            attrs: $this->attrs,
            fgSlot: $this->fgSlot,
            bgSlot: $this->bgSlot,
            ul: $ul,
            ulSlot: null,
        );
    }

    /** Sets the underline palette slot (0-255 or null) and discards its RGB twin. */
    public function withUlSlot(?int $ulSlot): self
    {
        return new self(
            fg: $this->fg,
            bg: $this->bg,
            attrs: $this->attrs,
            fgSlot: $this->fgSlot,
            bgSlot: $this->bgSlot,
            ul: null,
            ulSlot: $ulSlot,
        );
    }

    /** Return a new Style with the given attribute bitmask (replaces). */
    public function withAttrs(int $attrs): self
    {
        return new self(
            fg: $this->fg,
            bg: $this->bg,
            attrs: $attrs,
            fgSlot: $this->fgSlot,
            bgSlot: $this->bgSlot,
            ul: $this->ul,
            ulSlot: $this->ulSlot,
        );
    }

    /** Return a new Style with bold toggled on or off. */
    public function withBold(bool $on = true): self
    {
        return $this->withToggledAttr(self::ATTR_BOLD, $on);
    }

    /** Return a new Style with reverse toggled on or off. */
    public function withReverse(bool $on = true): self
    {
        return $this->withToggledAttr(self::ATTR_REVERSE, $on);
    }

    /** Return a new Style with italic toggled on or off. */
    public function withItalic(bool $on = true): self
    {
        return $this->withToggledAttr(self::ATTR_ITALIC, $on);
    }

    /** Return a new Style with underline toggled on or off. */
    public function withUnderline(bool $on = true): self
    {
        return $this->withToggledAttr(self::ATTR_UNDERLINE, $on);
    }

    /** Return a new Style with strike toggled on or off. */
    public function withStrike(bool $on = true): self
    {
        return $this->withToggledAttr(self::ATTR_STRIKE, $on);
    }

    /** Return a new Style with faint toggled on or off. */
    public function withFaint(bool $on = true): self
    {
        return $this->withToggledAttr(self::ATTR_FAINT, $on);
    }

    /** Return a new Style with blink toggled on or off. */
    public function withBlink(bool $on = true): self
    {
        return $this->withToggledAttr(self::ATTR_BLINK, $on);
    }

    /** Return a new Style with overline toggled on or off. */
    public function withOverline(bool $on = true): self
    {
        return $this->withToggledAttr(self::ATTR_OVERLINE, $on);
    }

    /** Return a new Style with invisible (conceal) toggled on or off. */
    public function withInvisible(bool $on = true): self
    {
        return $this->withToggledAttr(self::ATTR_INVISIBLE, $on);
    }

    /** Shared shape for the nine single-bit toggles — one construction, no array. */
    private function withToggledAttr(int $bit, bool $on): self
    {
        return new self(
            fg: $this->fg,
            bg: $this->bg,
            attrs: $on ? ($this->attrs | $bit) : ($this->attrs & ~$bit),
            fgSlot: $this->fgSlot,
            bgSlot: $this->bgSlot,
            ul: $this->ul,
            ulSlot: $this->ulSlot,
        );
    }

    /**
     * Value equality: two styles are equal iff their fg, bg, attrs,
     * palette slots, and underline colour values are all identical.
     */
    public function equals(self $other): bool
    {
        return $this->fg === $other->fg()
            && $this->bg === $other->bg()
            && $this->attrs === $other->attrs()
            && $this->fgSlot === $other->fgSlot()
            && $this->bgSlot === $other->bgSlot()
            && $this->ul === $other->ul()
            && $this->ulSlot === $other->ulSlot();
    }

    /**
     * Null-safe value equality: two optional styles are the same render
     * state iff both are null, or both are non-null and value-equal.
     *
     * Canonicaliser for every style-transition decision in the encode and
     * diff paths, so output bytes never depend on whether two equal
     * styles happen to share a PHP instance (value equality, not identity).
     */
    public static function valuesEqual(?self $a, ?self $b): bool
    {
        return $a === null ? $b === null : ($b !== null && $a->equals($b));
    }

    /**
     * Parse one SGR parameter run (the digits between "\x1b[" and 'm')
     * into a Style, carried on top of $carry.
     *
     * Vocabulary: 0 resets everything; 30-37/90-97 and 40-47/100-107 set
     * the palette ANSI colours WITH slot identity (and an approximate hex
     * belt, see {@see ansiColorToHex()}); 38;2;r;g;b / 48;2 set truecolor
     * and clear the slot, 38;5;n / 48;5;n set the slot with a hex belt;
     * 39/49 clear foreground/background (both spellings); 58;2 / 58;5 /
     * 59 do the same for the underline colour; 1 bold, 2 faint, 3 italic,
     * 4 underline, 5 blink, 7 reverse, 8 invisible, 9 strike, 53 overline
     * are the attribute ON codes; 22 clears bold AND faint, 23 italic,
     * 24 underline, 25 blink, 27 reverse, 28 invisible, 29 strike and 55
     * overline are the OFF codes. Codes outside this vocabulary (6, 21,
     * 26, 51/52/54, the 10-20 block) are deliberately ignored, not errors:
     * 21 would need a double-underline bit the nine-bit attribute model
     * does not carry, and its legacy bold-off reading is already served by
     * 22. An extended-colour arm that is truncated or malformed
     * ends the parse rather than letting its operands be misread as
     * attribute codes.
     *
     * This parser is public so consumers that mirror SGR decoding (the
     * sugar-overlay pen reader being the historical second copy) can route
     * here instead of forking it a third time.
     *
     * @param string $params Semicolon-separated SGR parameter numbers
     *                       (colon sub-parameters are interpreted per
     *                       {@see self::expandSgrParameters()}, not flattened)
     * @param Style|null $carry Style in effect before this sequence
     *
     * @throws \InvalidArgumentException if $params is not a parameter run
     */
    public static function fromSgr(string $params, ?self $carry = null): ?self
    {
        $codes = self::expandSgrParameters($params);

        $fg = $carry?->fg();
        $bg = $carry?->bg();
        $attrs = $carry?->attrs() ?? 0;
        $fgSlot = $carry?->fgSlot();
        $bgSlot = $carry?->bgSlot();
        $ul = $carry?->ul();
        $ulSlot = $carry?->ulSlot();

        $count = count($codes);
        for ($i = 0; $i < $count; $i++) {
            $p = $codes[$i];
            if ($p === 0) {
                $fg = null;
                $bg = null;
                $attrs = 0;
                $fgSlot = null;
                $bgSlot = null;
                $ul = null;
                $ulSlot = null;
            } elseif ($p >= 30 && $p <= 37) {
                $fgSlot = $p - 30;
                $fg = self::ansiColorToHex($p - 30);
            } elseif ($p >= 40 && $p <= 47) {
                $bgSlot = $p - 40;
                $bg = self::ansiColorToHex($p - 40);
            } elseif ($p >= 90 && $p <= 97) {
                $fgSlot = 8 + ($p - 90);
                $fg = self::ansiColorToHex($p - 90, true);
            } elseif ($p >= 100 && $p <= 107) {
                $bgSlot = 8 + ($p - 100);
                $bg = self::ansiColorToHex($p - 100, true);
            } elseif ($p === 39) {
                $fg = null;
                $fgSlot = null;
            } elseif ($p === 49) {
                $bg = null;
                $bgSlot = null;
            } elseif ($p === 59) {
                $ul = null;
                $ulSlot = null;
            } elseif ($p === 38 || $p === 48) {
                if ($i + 4 < $count && $codes[$i + 1] === 2) {
                    $rgb = (self::channel($codes[$i + 2]) << 16)
                        | (self::channel($codes[$i + 3]) << 8)
                        | self::channel($codes[$i + 4]);
                    if ($p === 38) {
                        $fg = $rgb;
                        $fgSlot = null;
                    } else {
                        $bg = $rgb;
                        $bgSlot = null;
                    }
                    $i += 4; // consumed 2;r;g;b
                } elseif ($i + 2 < $count && $codes[$i + 1] === 5) {
                    $slot = self::channel($codes[$i + 2]);
                    if ($p === 38) {
                        $fg = self::xterm256ToHex($slot);
                        $fgSlot = $slot;
                    } else {
                        $bg = self::xterm256ToHex($slot);
                        $bgSlot = $slot;
                    }
                    $i += 2; // consumed 5;n
                } else {
                    // Malformed / truncated extended colour: its trailing
                    // parameters are colour operands, not SGR codes — a bare
                    // `38;5` must not set blink, nor `38;2` faint. Stop and
                    // keep the pen built so far.
                    break;
                }
            } elseif ($p === 58) {
                if ($i + 4 < $count && $codes[$i + 1] === 2) {
                    $ul = (self::channel($codes[$i + 2]) << 16)
                        | (self::channel($codes[$i + 3]) << 8)
                        | self::channel($codes[$i + 4]);
                    $ulSlot = null;
                    $i += 4; // consumed 2;r;g;b
                } elseif ($i + 2 < $count && $codes[$i + 1] === 5) {
                    $slot = self::channel($codes[$i + 2]);
                    $ul = self::xterm256ToHex($slot);
                    $ulSlot = $slot;
                    $i += 2; // consumed 5;n
                } else {
                    break; // same fail-stop law as 38/48
                }
            } elseif ($p === 1) {
                $attrs |= self::ATTR_BOLD;
            } elseif ($p === 2) {
                $attrs |= self::ATTR_FAINT;
            } elseif ($p === 3) {
                $attrs |= self::ATTR_ITALIC;
            } elseif ($p === 4) {
                $attrs |= self::ATTR_UNDERLINE;
            } elseif ($p === 5) {
                $attrs |= self::ATTR_BLINK;
            } elseif ($p === 7) {
                $attrs |= self::ATTR_REVERSE;
            } elseif ($p === 8) {
                $attrs |= self::ATTR_INVISIBLE;
            } elseif ($p === 9) {
                $attrs |= self::ATTR_STRIKE;
            } elseif ($p === 53) {
                $attrs |= self::ATTR_OVERLINE;
            } elseif ($p === 22) {
                $attrs &= ~(self::ATTR_BOLD | self::ATTR_FAINT);
            } elseif ($p === 23) {
                $attrs &= ~self::ATTR_ITALIC;
            } elseif ($p === 24) {
                $attrs &= ~self::ATTR_UNDERLINE;
            } elseif ($p === 25) {
                $attrs &= ~self::ATTR_BLINK;
            } elseif ($p === 27) {
                $attrs &= ~self::ATTR_REVERSE;
            } elseif ($p === 28) {
                $attrs &= ~self::ATTR_INVISIBLE;
            } elseif ($p === 29) {
                $attrs &= ~self::ATTR_STRIKE;
            } elseif ($p === 55) {
                $attrs &= ~self::ATTR_OVERLINE;
            }
        }

        return $fg !== null || $bg !== null || $attrs !== 0
            || $fgSlot !== null || $bgSlot !== null || $ul !== null || $ulSlot !== null
            ? new self($fg, $bg, $attrs, $fgSlot, $bgSlot, $ul, $ulSlot)
            : null;
    }

    /**
     * Expand one SGR parameter run into a flat list of numeric codes.
     *
     * Colon sub-parameter spellings are INTERPRETED, never squashed: the
     * old str_replace(':',';') flattening shifted the operands of the
     * ITU-T T.416 colour-space form (in `38:2:1:200:100:50` the colour
     * space id `1` was consumed as the red channel — a confidently wrong
     * colour, the exact class the clamp-never-mask law legislates) and
     * turned the empty-params variant (`38:2::1:2:3`) into a throw on the
     * numeric check. Per-parameter expansion here:
     *
     *  - `38:2:r:g:b` and `38:2:cs:r:g:b` (colour-space id present or
     *    empty) → introducer, mode, then the TRUE TAIL three operands;
     *  - `38:5:n` → the `38;5;n` equivalent;
     *  - a truncated or out-of-vocabulary extended colour (`38:5:`,
     *    `38:6:…`, `38:2` with too few/too many operands) → the bare
     *    introducer passes through, and the flat parser fail-stops on it
     *    exactly as it does for the semicolon spelling;
     *  - colons on a non-colour code (`4:0` underline styles) are an
     *    unrecognised extension: the parameter is DROPPED whole, never
     *    reinterpreted as separate codes;
     *  - plain numeric parameters pass through; anything else throws.
     *
     * 38, 48 and 58 share one grammar, so the rule reads `38`/`48`/`58`
     * wherever spelled above.
     *
     * @return list<int> flat SGR code list for the merge loop
     *
     * @throws \InvalidArgumentException when a parameter is neither a
     *         numeric code nor a supported colon sub-parameter form.
     */
    private static function expandSgrParameters(string $params): array
    {
        $refuse = static fn(): \InvalidArgumentException => new \InvalidArgumentException(
            "SGR parameters must be semicolon-separated integers, got '{$params}'"
        );

        $codes = [];
        foreach (explode(';', $params) as $param) {
            if (!str_contains($param, ':')) {
                if ($param === '' || preg_match('/^\d+$/', $param) !== 1) {
                    throw $refuse();
                }
                $codes[] = (int) $param;
                continue;
            }

            $sub = explode(':', $param);
            if (preg_match('/^\d+$/', $sub[0]) !== 1) {
                throw $refuse();
            }
            $introducer = (int) $sub[0];
            $mode = $sub[1] ?? '';
            $isExtendedColour = in_array($introducer, [38, 48, 58], true);

            if ($isExtendedColour && ($mode === '2' || $mode === '5')) {
                $rest = array_slice($sub, 2);
                if ($mode === '2' && (count($rest) === 3 || count($rest) === 4)) {
                    $tail = array_slice($rest, -3);
                    foreach ($tail as $component) {
                        if (preg_match('/^\d+$/', $component) !== 1) {
                            throw $refuse();
                        }
                    }
                    $codes[] = $introducer;
                    $codes[] = 2;
                    foreach ($tail as $component) {
                        $codes[] = (int) $component;
                    }
                    continue;
                }
                if ($mode === '5' && count($rest) === 1 && preg_match('/^\d+$/', $rest[0]) === 1) {
                    $codes[] = $introducer;
                    $codes[] = 5;
                    $codes[] = (int) $rest[0];
                    continue;
                }
                // Truncated/out-of-shape extended colour: forward the bare
                // introducer so the merge loop fail-stops exactly like the
                // `38;5` semicolon spelling does.
                $codes[] = $introducer;
                continue;
            }

            if ($isExtendedColour) {
                // Unknown colour mode (CSA `38:6:…` or a missing one): same
                // fail-stop forwarding as the out-of-shape arms above.
                $codes[] = $introducer;
                continue;
            }

            // Unrecognised colon extension on an attribute code — dropped,
            // never flattened into codes it was never meant to spell.
        }

        return $codes;
    }

    /**
     * Clamp one 38;2 / 48;2 colour component into 0-255, the way terminals
     * saturate an out-of-range value — `300` paints as 255, never as the
     * `300 & 0xFF = 44` a bit-mask would produce.
     */
    private static function channel(int $component): int
    {
        return max(0, min(255, $component));
    }

    /**
     * Standard xterm 256-colour palette index → 0xRRGGBB: 0-7 and 8-15 map
     * through ansiColorToHex() (so `38;5;1` and `31` agree), 16-231 the 6×6×6
     * cube, 232-255 the grayscale ramp. The index is clamped into 0-255 first,
     * the same saturation channel() applies to truecolor components, so an
     * out-of-range index can never produce a value outside 0xRRGGBB.
     */
    private static function xterm256ToHex(int $n): int
    {
        $n = self::channel($n);
        if ($n < 8) {
            return self::ansiColorToHex($n);
        }
        if ($n < 16) {
            return self::ansiColorToHex($n - 8, true);
        }
        if ($n >= 232) {
            $gray = 8 + ($n - 232) * 10;
            return ($gray << 16) | ($gray << 8) | $gray;
        }
        $cube = $n - 16;
        return (self::cubeStep(intdiv($cube, 36)) << 16)
            | (self::cubeStep(intdiv($cube % 36, 6)) << 8)
            | self::cubeStep($cube % 6);
    }

    /** One axis of the 6×6×6 cube: 0 stays 0, 1-5 ride the 55+40v ramp. */
    private static function cubeStep(int $v): int
    {
        return $v === 0 ? 0 : 55 + $v * 40;
    }

    /**
     * Standard ANSI 8-colour palette and bright variants as 0xRRGGBB.
     *
     * The bright-1..7 values are an approximation: each channel of the
     * standard colour is lightened by ~40% (clamped), which for these
     * fully-saturated primaries is a no-op — so bright 1-7 equal their
     * standard twins in the table, the historical law flattened into
     * constants by CB-36 (differential-proven byte-identical to the
     * formula over the probed index domain, fallbacks included). bright-black (slot 8) is the one hand-tuned case (0x7F7F7F,
     * xterm colour 8 / sugar-colorprofile's StandardColors::$brightBlack)
     * because lightening #000000 would keep it invisible on dark
     * terminals. This map exists only for fixture parsing and hex belts —
     * real theme-following goes through the palette-slot fields, which
     * never consult this approximation.
     */
    private const PALETTE_16 = [
        // Standard colours
        0x000000, // 0  black
        0xff0000, // 1  red
        0x00ff00, // 2  green
        0xffff00, // 3  yellow
        0x0000ff, // 4  blue
        0xff00ff, // 5  magenta
        0x00ffff, // 6  cyan
        0xffffff, // 7  white
        // Bright variants (lighten-40% flattened: saturated channels are fixed points)
        0x7f7f7f, // 8  bright black (hand-tuned, see docblock)
        0xff0000, // 9  bright red
        0x00ff00, // 10 bright green
        0xffff00, // 11 bright yellow
        0x0000ff, // 12 bright blue
        0xff00ff, // 13 bright magenta
        0x00ffff, // 14 bright cyan
        0xffffff, // 15 bright white
    ];

    private static function ansiColorToHex(int $idx, bool $bright = false): int
    {
        // Domain is 0-7 (both callers normalise into it); the historical
        // fallback for anything else — lightened or plain white — is kept.
        if ($idx < 0 || $idx > 7) {
            return 0xffffff;
        }
        return self::PALETTE_16[$idx + ($bright ? 8 : 0)];
    }

    /**
     * Serialization hook for caching/IPC use cases.
     *
     * Older payloads (three keys, pre-palette-slot) restore cleanly:
     * every slot/underline field falls back to null.
     *
     * @return array{fg: ?int, bg: ?int, attrs: int, fgSlot: ?int, bgSlot: ?int, ul: ?int, ulSlot: ?int}
     */
    public function __serialize(): array
    {
        return [
            'fg' => $this->fg,
            'bg' => $this->bg,
            'attrs' => $this->attrs,
            'fgSlot' => $this->fgSlot,
            'bgSlot' => $this->bgSlot,
            'ul' => $this->ul,
            'ulSlot' => $this->ulSlot,
        ];
    }

    /**
     * Unserialization hook for caching/IPC use cases.
     *
     * Fail-closed: palette-slot bounds are re-checked here, so a forged
     * payload cannot mint a style carrying an out-of-range slot.
     *
     * @param array{fg: ?int, bg: ?int, attrs: int, fgSlot?: ?int, bgSlot?: ?int, ul?: ?int, ulSlot?: ?int} $data
     */
    public function __unserialize(array $data): void
    {
        $fgSlot = $data['fgSlot'] ?? null;
        $bgSlot = $data['bgSlot'] ?? null;
        $ulSlot = $data['ulSlot'] ?? null;
        self::checkSlot($fgSlot, 'foreground');
        self::checkSlot($bgSlot, 'background');
        self::checkSlot($ulSlot, 'underline-slot');

        // The constructor's full channel law (RGB saturation + belt pairing)
        // re-runs here, not just the slot bounds: a forged payload cannot
        // mint a style the constructor itself would refuse. Shape first —
        // the keys __serialize always emits must exist (a payload missing
        // one is foreign or forged, not merely empty).
        $this->fg = self::clampRgb(self::requireKey($data, 'fg', 'Style'));
        $this->bg = self::clampRgb(self::requireKey($data, 'bg', 'Style'));
        $this->attrs = self::requireKey($data, 'attrs', 'Style');
        $this->fgSlot = $fgSlot;
        $this->bgSlot = $bgSlot;
        // ul rides ?? null: it joined the payload after the three-key
        // pre-palette-slot shape this hook still restores.
        $this->ul = self::clampRgb($data['ul'] ?? null);
        $this->ulSlot = $ulSlot;

        self::checkBelt($this->fg, $fgSlot, 'foreground');
        self::checkBelt($this->bg, $bgSlot, 'background');
        self::checkBelt($this->ul, $ulSlot, 'underline');
    }

    /**
     * Shape guard shared by the family's __unserialize hooks: every key
     * `__serialize` emits is required, and a miss is a NAMED refusal — never
     * an undefined-key warning, a null-to-typed-readonly TypeError, or a
     * silent zero-cast (the three historical outcomes of one bad payload).
     *
     * @param array<array-key, mixed> $data
     *
     * @throws \InvalidArgumentException naming the payload and the key
     */
    private static function requireKey(array $data, string $key, string $payload): mixed
    {
        if (!array_key_exists($key, $data)) {
            throw new \InvalidArgumentException("{$payload} payload missing key '{$key}'");
        }

        return $data[$key];
    }

    /**
     * JSON serialization support.
     *
     * @return array{fg: ?int, bg: ?int, attrs: int, fgSlot: ?int, bgSlot: ?int, ul: ?int, ulSlot: ?int}
     */
    public function jsonSerialize(): array
    {
        return $this->__serialize();
    }
}
