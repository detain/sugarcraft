<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Handler;

/**
 * Horizontal tab-stop arithmetic.
 *
 * Stateless: callers (ScreenHandler) own the `array<int, bool>` of stops
 * keyed by column index, and pass it into each method. Default stops
 * are every 8 columns starting at column 8 (so col 0 is never a stop).
 *
 * Covers HT (forward to next stop), CHT (CSI I — N forward), CBT
 * (CSI Z — N back), HTS (ESC H / 0x88 — set stop at cursor), and TBC
 * (CSI g — clear stop at cursor for mode 0, all stops for mode 3).
 */
final class TabHandler
{
    /**
     * Build the default tab-stop set: every 8 columns starting at 8.
     *
     * @return array<int, bool>
     */
    public static function defaults(int $cols): array
    {
        $stops = [];
        for ($c = 8; $c < $cols; $c += 8) {
            $stops[$c] = true;
        }
        return $stops;
    }

    /**
     * Move forward to the next tab stop after `$col`, clamping at the
     * right edge if no stop is set.
     *
     * @param array<int, bool> $stops
     */
    public function forward(int $col, array $stops, int $cols): int
    {
        if ($cols <= 0) {
            return 0;
        }
        for ($c = $col + 1; $c < $cols; $c++) {
            if (!empty($stops[$c])) {
                return $c;
            }
        }
        return $cols - 1;
    }

    /**
     * Move backward to the previous tab stop before `$col`, clamping at 0.
     *
     * @param array<int, bool> $stops
     */
    public function backward(int $col, array $stops): int
    {
        for ($c = $col - 1; $c >= 0; $c--) {
            if (!empty($stops[$c])) {
                return $c;
            }
        }
        return 0;
    }

    /**
     * Apply CSI I (CHT) or CSI Z (CBT) — repeat forward/backward N times.
     *
     * @param list<int>        $params
     * @param array<int, bool> $stops
     */
    public function applyCsi(int $final, array $params, int $col, array $stops, int $cols): int
    {
        $first = $params[0] ?? -1;
        $count = $first === -1 ? 1 : max(1, $first);
        $current = $col;

        if ($final === 0x49 /* 'I' */) {
            for ($i = 0; $i < $count; $i++) {
                $current = $this->forward($current, $stops, $cols);
            }
        } elseif ($final === 0x5A /* 'Z' */) {
            for ($i = 0; $i < $count; $i++) {
                $current = $this->backward($current, $stops);
            }
        }
        return $current;
    }

    /**
     * TBC (CSI g): clear tab stops.
     *
     * - Mode 0 (default): clear the stop at `$col`.
     * - Mode 3: clear all stops.
     * - Other modes: no-op.
     *
     * @param array<int, bool> $stops
     * @return array<int, bool>
     */
    public function clear(int $mode, int $col, array $stops): array
    {
        if ($mode === 3) {
            return [];
        }
        if ($mode === 0) {
            unset($stops[$col]);
        }
        return $stops;
    }
}
