<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Handler;

use SugarCraft\Vt\Color\Color;
use SugarCraft\Vt\Sgr\Sgr;
use SugarCraft\Vt\Sgr\UnderlineStyle;

/**
 * Applies CSI m (Select Graphic Rendition) parameters to an Sgr value.
 *
 * Handles the full set of SGR codes: reset, bold/dim/italic/underline/
 * blink/reverse/hidden/strikethrough toggles, 16-color and bright fg/bg,
 * 256-color via 38;5;N / 48;5;N, truecolor via 38;2;R;G;B / 48;2;R;G;B,
 * and the matching off-toggles.
 *
 * SGR underline styles via subparameters:
 *   4:0 = none (underline off)
 *   4:1 = single underline (also plain 4 with no subparam)
 *   4:2 = double underline
 *   4:3 = curly underline
 *   4:4 = dotted underline
 *   4:5 = dashed underline
 *   24   = underline off (any style)
 *
 * Mirrors charmbracelet/x/vt SGR parser semantics. Default params (-1
 * sentinel from the parser) are treated as 0 = reset.
 */
final class SgrHandler
{
    /**
     * Apply a CSI m parameter list to the current SGR state.
     *
     * @param list<int> $params Parser params; -1 sentinels mean default.
     * @param list<bool>|null $subparams Continuation flags from
     *   {@see \SugarCraft\Ansi\Parser\Parser::subparams()} for this dispatch:
     *   true at index i means params[i+1] is a COLON sub-parameter of
     *   params[i], not an independent SGR (`4:3` curly vs `4;3` underline +
     *   italic). Null — no parser wired — keeps the historical peek: any
     *   usable next slot is treated as a sub-parameter, preserving the
     *   direct-call behaviour SgrUnderlineStylesTest pins.
     */
    public function apply(array $params, Sgr $current, ?array $subparams = null): Sgr
    {
        if (empty($params)) {
            $params = [0];
        }

        $sgr = $current;
        $i = 0;
        $n = count($params);
        while ($i < $n) {
            $p = $params[$i];
            if ($p === -1) {
                $p = 0;
            }
            [$sgr, $i] = $this->step($p, $i, $params, $sgr, $subparams);
        }
        return $sgr;
    }

    /**
     * @param list<int> $params
     * @param list<bool>|null $subparams
     * @return array{0: Sgr, 1: int}
     */
    private function step(int $p, int $i, array $params, Sgr $sgr, ?array $subparams): array
    {
        return match (true) {
            $p === 0 => [Sgr::empty(), $i + 1],
            $p === 1 => [$sgr->withBold(true), $i + 1],
            $p === 2 => [$sgr->withDim(true), $i + 1],
            $p === 3 => [$sgr->withItalic(true), $i + 1],
            $p === 4 => $this->underlineStyle($i, $params, $sgr, $subparams),
            $p === 5, $p === 6 => [$sgr->withBlink(true), $i + 1], // slow + rapid both fold to blink
            $p === 7 => [$sgr->withReverse(true), $i + 1],
            $p === 8 => [$sgr->withHidden(true), $i + 1],
            $p === 9 => [$sgr->withStrikethrough(true), $i + 1],
            $p === 22 => [$sgr->withBold(false)->withDim(false), $i + 1],
            $p === 23 => [$sgr->withItalic(false), $i + 1],
            $p === 24 => [$sgr->withUnderline(false)->withUnderlineStyle(UnderlineStyle::None), $i + 1],
            $p === 25 => [$sgr->withBlink(false), $i + 1],
            $p === 27 => [$sgr->withReverse(false), $i + 1],
            $p === 28 => [$sgr->withHidden(false), $i + 1],
            $p === 29 => [$sgr->withStrikethrough(false), $i + 1],

            $p >= 30 && $p <= 37 => [$sgr->withForeground(Color::indexed16($p - 30)), $i + 1],
            $p === 38 => $this->extended($i, $params, $sgr, fg: true),
            $p === 39 => [$sgr->withForeground(Color::default()), $i + 1],

            $p >= 40 && $p <= 47 => [$sgr->withBackground(Color::indexed16($p - 40)), $i + 1],
            $p === 48 => $this->extended($i, $params, $sgr, fg: false),
            $p === 49 => [$sgr->withBackground(Color::default()), $i + 1],

            $p >= 90 && $p <= 97 => [$sgr->withForeground(Color::indexed16($p - 90 + 8)), $i + 1],
            $p >= 100 && $p <= 107 => [$sgr->withBackground(Color::indexed16($p - 100 + 8)), $i + 1],

            // SGR 58/59 — underline colour (ECMA-48 8.4.43 set / 8.4.44 reset,
            // xterm ctlseqs). Neither this pen model nor the renderer's Cell
            // stores an underline colour, but the extended triplet MUST be
            // consumed: without an arm here, `58;5;33` fell through to the
            // default case as three independent SGRs and repainted the
            // foreground green (33 - 30). Guarded identically in
            // {@see \SugarCraft\Vt\Parser\CsiHandlerImpl::sgrExtendedDiscard()}.
            $p === 58 => [$sgr, $this->extendedLength($i, $params)],
            $p === 59 => [$sgr, $i + 1],

            default => [$sgr, $i + 1],
        };
    }

    /**
     * Handle CSI 4 (underline) with optional sub-parameter N in 4:N.
     *
     * With parser flags, `CSI 4 ; 3 m` is TWO independent SGRs (underline +
     * italic); only the COLON form `4:3` is one curly-underline parameter —
     * the flattened param list alone cannot tell them apart, the
     * continuation flags can. Without flags the historical peek remains.
     *
     * @param list<int> $params
     * @param list<bool>|null $subparams
     * @return array{0: Sgr, 1: int}
     */
    private function underlineStyle(int $i, array $params, Sgr $sgr, ?array $subparams): array
    {
        $sub = $params[$i + 1] ?? -1;
        $hasSubparam = isset($params[$i + 1]) && $params[$i + 1] !== -1;
        if (!$hasSubparam || $sub === 1) {
            // Plain 4 (no subparam) or 4:1 → single underline (existing behavior)
            return [$sgr->withUnderlineStyle(UnderlineStyle::Single), $i + 1];
        }
        $colon = $subparams === null ? true : ($subparams[$i] ?? false);
        if (!$colon) {
            // `4;N` — semicolon form: N is an INDEPENDENT SGR, not a style.
            return [$sgr->withUnderlineStyle(UnderlineStyle::Single), $i + 1];
        }
        if ($sub === 0) {
            return [$sgr->withUnderlineStyle(UnderlineStyle::None), $i + 2];
        }
        if ($sub === 2) {
            return [$sgr->withUnderlineStyle(UnderlineStyle::Double), $i + 2];
        }
        if ($sub === 3) {
            return [$sgr->withUnderlineStyle(UnderlineStyle::Curly), $i + 2];
        }
        if ($sub === 4) {
            return [$sgr->withUnderlineStyle(UnderlineStyle::Dotted), $i + 2];
        }
        if ($sub === 5) {
            return [$sgr->withUnderlineStyle(UnderlineStyle::Dashed), $i + 2];
        }
        // Unknown subparameter — treat as single underline
        return [$sgr->withUnderlineStyle(UnderlineStyle::Single), $i + 2];
    }

    /**
     * Parse 38;5;N / 48;5;N (256-color) or 38;2;R;G;B / 48;2;R;G;B (truecolor).
     *
     * @param list<int> $params
     * @return array{0: Sgr, 1: int}
     */
    private function extended(int $i, array $params, Sgr $sgr, bool $fg): array
    {
        $kind = $params[$i + 1] ?? -1;
        if ($kind === 5) {
            $idx = $this->resolveByte($params[$i + 2] ?? -1);
            $color = Color::indexed256($idx);
            return [$fg ? $sgr->withForeground($color) : $sgr->withBackground($color), $i + 3];
        }
        if ($kind === 2) {
            $r = $this->resolveByte($params[$i + 2] ?? -1);
            $g = $this->resolveByte($params[$i + 3] ?? -1);
            $b = $this->resolveByte($params[$i + 4] ?? -1);
            $color = Color::truecolor($r, $g, $b);
            return [$fg ? $sgr->withForeground($color) : $sgr->withBackground($color), $i + 5];
        }
        // Unknown sub-form — skip just the 38/48 marker.
        return [$sgr, $i + 1];
    }

    /**
     * How many slots an extended-colour form consumes starting at $i —
     * marker + kind (+ index for ;5, + triple for ;2), defaulting to just
     * the marker for unknown kinds. Used by the parse-and-discard 58 arm.
     *
     * @param list<int> $params
     */
    private function extendedLength(int $i, array $params): int
    {
        return match ($params[$i + 1] ?? -1) {
            5 => $i + 3,
            2 => $i + 5,
            default => $i + 1,
        };
    }

    private function resolveByte(int $value): int
    {
        if ($value === -1) {
            return 0;
        }
        return max(0, min(255, $value));
    }
}
