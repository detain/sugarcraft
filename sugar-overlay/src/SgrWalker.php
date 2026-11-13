<?php

declare(strict_types=1);

namespace SugarCraft\Overlay;

use SugarCraft\CellBuffer\Style as BufferStyle;

/**
 * The lib's ONE SGR parameter parser.
 *
 * Before sugar-overlay-004/005/063/071 the pen merge (`Veil::penFromSgr`),
 * the splice neutralizer (`MarkedFrame::isolationSegments`) and — upstream —
 * sugar-cellbuffer's private `Buffer::styleFromSgr` were three hand-rolled
 * `explode(';')` walks that each drifted: the pen walk dropped the REST of a
 * sequence when a colon sub-parameter form (`\e[38:2:1:2:3;1m`) hit its
 * malformed else-arm (the bold vanished), and the isolation walk blind-slept
 * (`$i += 4`) over colon legs while claiming a bare trailing `\e[38m` armed a
 * foreground the pen never changed — a phantom reset the diff model had
 * never applied. Both call sites now fold through THIS walker, so a miss
 * cannot be made in one without the other noticing: the rules live once.
 *
 * Rules the fold encodes:
 * - Colon sub-parameters parse per ECMA-48/T.416: an extended-color leg may
 *   carry its operands inside one leg (`38:2:1:2:3`, and the color-space-id
 *   form `38:2::1:2:3` — a fourth operand or an empty first operand means a
 *   color-space id and is skipped). Attribute legs ignore trailing colon
 *   operands (there is nothing to model — `4:3` underlines).
 * - Semicolon forms gather their operands from the FOLLOWING legs with the
 *   exact arity of the mode they name (`38;5;n` takes one, `38;2;r;g;b`
 *   three) — never more, so the legs after a palette color stay live.
 * - A malformed or incomplete extended-color leg is a NO-OP: the prior pen
 *   stands and scanning CONTINUES. (The old pen walk's `$i = $count` silently
 *   discarded every later parameter in the same sequence; the old isolation
 *   walk armed a reset for colors it never held.)
 * - Colour means: 0 resets fg, bg and attrs; 39/49 restore one default;
 *   30-37/40-47/90-97/100-107 the base-16 palette (bright black 0x7f7f7f);
 *   38/48 with mode 2 truecolor, mode 5 the xterm 256 index — every operand
 *   channel-clamped into 0–255, never bit-masked, mirroring sugar-cellbuffer.
 *
 * Promotion of this walker to a public sugar-cellbuffer API (finding 004's
 * third copy) is the owner-gated leg of sugar-overlay:SO-06 — report row,
 * not minted here.
 */
final class SgrWalker
{
    /**
     * Fold one SGR parameter string (the bytes between `\e[` and `m`) into
     * the pen state. Pure: the carry comes in and a fresh state comes out.
     *
     * @param array{fg:?int, bg:?int, attrs:int} $pen
     * @return array{fg:?int, bg:?int, attrs:int}
     */
    public static function fold(string $params, array $pen): array
    {
        $legs   = self::legs($params);
        $count  = \count($legs);

        for ($i = 0; $i < $count; $i++) {
            [$code, $subs] = $legs[$i];

            if ($code === 38 || $code === 48) {
                $operands = $subs;
                if ($operands === []) {
                    // Semicolon form: the operands ride on the FOLLOWING
                    // legs, exactly `target` of them — gathering stops at the
                    // arity, so a palette color never swallows `;1;7` behind it.
                    while ($i + 1 < $count && $legs[$i + 1][1] === [] && \count($operands) < self::semitarget($operands)) {
                        $operands[] = (string) $legs[++$i][0];
                    }
                }
                $rgb = self::extendedColor($operands, $subs !== []);
                if ($rgb !== null) {
                    if ($code === 38) {
                        $pen['fg'] = $rgb;
                    } else {
                        $pen['bg'] = $rgb;
                    }
                }
                continue; // malformed extended leg: no-op, keep scanning
            }

            if ($code === 0) {
                $pen['fg']    = null;
                $pen['bg']    = null;
                $pen['attrs'] = 0;
                continue;
            }
            if ($code >= 30 && $code <= 37) {
                $pen['fg'] = self::ansiColorToHex($code - 30);
                continue;
            }
            if ($code >= 40 && $code <= 47) {
                $pen['bg'] = self::ansiColorToHex($code - 40);
                continue;
            }
            if ($code >= 90 && $code <= 97) {
                $pen['fg'] = self::ansiColorToHex($code - 90, bright: true);
                continue;
            }
            if ($code >= 100 && $code <= 107) {
                $pen['bg'] = self::ansiColorToHex($code - 100, bright: true);
                continue;
            }
            if ($code === 39) {
                $pen['fg'] = null;
                continue;
            }
            if ($code === 49) {
                $pen['bg'] = null;
                continue;
            }
            $attr = match ($code) {
                1 => BufferStyle::ATTR_BOLD,
                2 => BufferStyle::ATTR_FAINT,
                3 => BufferStyle::ATTR_ITALIC,
                4 => BufferStyle::ATTR_UNDERLINE,
                5 => BufferStyle::ATTR_BLINK,
                7 => BufferStyle::ATTR_REVERSE,
                8 => BufferStyle::ATTR_INVISIBLE,
                9 => BufferStyle::ATTR_STRIKE,
                default => 0,
            };
            if ($attr !== 0) {
                $pen['attrs'] |= $attr;
            }
        }

        return $pen;
    }

    /**
     * Split a parameter string into colon legs: each leg is
     * [code, subParameterStrings]. An empty parameter string folds to one
     * reset leg — ECMA-48 equates `\e[m` with SGR 0, and both old walkers
     * read `intval('') = 0` the same way.
     *
     * @return list<array{0:int, 1:list<string>}>
     */
    public static function legs(string $params): array
    {
        $out = [];
        foreach (\explode(';', $params) as $leg) {
            $parts  = \explode(':', $leg);
            $out[]  = [\intval($parts[0]), \array_slice($parts, 1)];
        }
        return $out;
    }

    /**
     * Resolve an extended-color leg's operands to 0xRRGGBB, or null when
     * the leg is malformed (unknown mode, missing operands). Non-numeric
     * operands intval to 0 exactly as the old walkers did; out-of-range
     * channels saturate via channel(), never wrap.
     *
     * @param list<string> $operands  first operand is the mode (2 | 5)
     */
    public static function extendedColor(array $operands, bool $colonForm): ?int
    {
        if ($operands === []) {
            return null;
        }
        $mode = $operands[0];
        if ($mode !== '2' && $mode !== '5') {
            return null;
        }
        $rest = \array_slice($operands, 1);

        if ($mode === '2') {
            // T.416 colon form may carry a color-space id as the first
            // operand — `38:2::1:2:3` (empty id) and `38:2:1:2:3:4` alike.
            // Four-or-more trailing operands mean the first is the id.
            if ($colonForm && \count($rest) >= 4) {
                \array_shift($rest);
            }
            if (\count($rest) < 3) {
                return null;
            }
            return (self::channel(\intval($rest[0])) << 16)
                | (self::channel(\intval($rest[1])) << 8)
                | self::channel(\intval($rest[2]));
        }

        if ($colonForm && ($rest[0] ?? '') === '' && \count($rest) > 1) {
            \array_shift($rest); // empty cs field before a palette index
        }
        if (\count($rest) < 1) {
            return null;
        }
        return self::xterm256ToHex(\intval($rest[0]));
    }

    /**
     * How many semicolon legs an extended-color sequence named by the
     * operands gathered so far still needs: the mode decides — 4 legs for
     * `2;r;g;b`, 2 for `5;n`, and 1 for an unknown mode (consume the mode,
     * no-op, leave the following legs live).
     *
     * @param list<string> $gathered
     */
    private static function semitarget(array $gathered): int
    {
        return match ($gathered[0] ?? '') {
            '2'    => 4,
            '5'    => 2,
            default => 1,
        };
    }

    /**
     * Clamp one 38;2 / 48;2 color component into 0–255, the way terminals
     * saturate an out-of-range value — `300` paints as 255, never as the
     * `300 & 0xFF = 44` a bit-mask would produce.
     */
    public static function channel(int $component): int
    {
        return \max(0, \min(255, $component));
    }

    /** Base-16 palette hexes, matching Style::ansiColorToHex() — including
     *  the bright-black special case: an SGR 90 (or 38;5;8) cell must carry
     *  the exact 0x7f7f7f the diff model records, or a style-only change
     *  diffs as a false no-op (xterm bright black is a fixed gray, not a
     *  40% lift of black — which would stay 0x000000). */
    /** @var list<int> Base-16 palette hexes (normal half), xterm defaults;
     *  built once as a class constant, not per 16-color SGR parse
     *  (sugar-overlay-078). */
    private const PALETTE = [
        0x000000, 0xff0000, 0x00ff00, 0xffff00,
        0x0000ff, 0xff00ff, 0x00ffff, 0xffffff,
    ];

    /** Bright-half channel lift: +40% saturating at 255. */
    private static function liftChannel(int $c): int
    {
        return \min(255, (int) ($c + ($c * 0.4)));
    }

    public static function ansiColorToHex(int $idx, bool $bright = false): int
    {
        $base = self::PALETTE[$idx] ?? 0xffffff;
        if ($bright === false) {
            return $base;
        }
        if ($idx === 0) {
            // Bright black is the fixed gray (xterm 'color8' when bold,
            // sugar-colorprofile StandardColors::$brightBlack) — matching
            // Style::ansiColorToHex's identical case (sugar-cellbuffer).
            return 0x7f7f7f;
        }
        return (self::liftChannel(($base >> 16) & 0xFF) << 16)
            | (self::liftChannel(($base >> 8) & 0xFF) << 8)
            | self::liftChannel($base & 0xFF);
    }

    /**
     * Standard xterm 256-color palette index → 0xRRGGBB: 0-7 and 8-15 map
     * through ansiColorToHex(), 16-231 the 6×6×6 cube, 232-255 the grayscale
     * ramp. The index is clamped into 0-255 first (channel(), as sugar-cellbuffer
     * does): unclamped, `38;5;300` ran the gray-ramp formula to 688 per
     * channel and returned 0x2b2b2b0, a value outside 0xRRGGBB.
     */
    public static function xterm256ToHex(int $n): int
    {
        $n = self::channel($n);
        if ($n < 8) {
            return self::ansiColorToHex($n);
        }
        if ($n < 16) {
            return self::ansiColorToHex($n - 8, bright: true);
        }
        if ($n >= 232) {
            $gray = 8 + ($n - 232) * 10;
            return ($gray << 16) | ($gray << 8) | $gray;
        }
        $cube = $n - 16;
        $step = static fn(int $v): int => $v === 0 ? 0 : 55 + $v * 40;
        return ($step(intdiv($cube, 36)) << 16)
            | ($step(intdiv($cube % 36, 6)) << 8)
            | $step($cube % 6);
    }
}
