<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Handler;

use SugarCraft\Vt\Color\Color;
use SugarCraft\Vt\Sgr\Sgr;

/**
 * Applies CSI m (Select Graphic Rendition) parameters to an Sgr value.
 *
 * Handles the full set of SGR codes: reset, bold/dim/italic/underline/
 * blink/reverse/hidden/strikethrough toggles, 16-color and bright fg/bg,
 * 256-color via 38;5;N / 48;5;N, truecolor via 38;2;R;G;B / 48;2;R;G;B,
 * and the matching off-toggles.
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
     */
    public function apply(array $params, Sgr $current): Sgr
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
            [$sgr, $i] = $this->step($p, $i, $params, $sgr);
        }
        return $sgr;
    }

    /**
     * @param list<int> $params
     * @return array{0: Sgr, 1: int}
     */
    private function step(int $p, int $i, array $params, Sgr $sgr): array
    {
        return match (true) {
            $p === 0 => [Sgr::empty(), $i + 1],
            $p === 1 => [$sgr->withBold(true), $i + 1],
            $p === 2 => [$sgr->withDim(true), $i + 1],
            $p === 3 => [$sgr->withItalic(true), $i + 1],
            $p === 4 => [$sgr->withUnderline(true), $i + 1],
            $p === 5, $p === 6 => [$sgr->withBlink(true), $i + 1], // slow + rapid both fold to blink
            $p === 7 => [$sgr->withReverse(true), $i + 1],
            $p === 8 => [$sgr->withHidden(true), $i + 1],
            $p === 9 => [$sgr->withStrikethrough(true), $i + 1],
            $p === 22 => [$sgr->withBold(false)->withDim(false), $i + 1],
            $p === 23 => [$sgr->withItalic(false), $i + 1],
            $p === 24 => [$sgr->withUnderline(false), $i + 1],
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

            default => [$sgr, $i + 1],
        };
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

    private function resolveByte(int $value): int
    {
        if ($value === -1) {
            return 0;
        }
        return max(0, min(255, $value));
    }
}
