<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Picture;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\Picture\Sixel;
use SugarCraft\Core\Util\Color;

/**
 * Stripe emission must be one pass per stripe (sugar-charts-038, CH-24):
 * the old per-color column re-walk costs O(rows × activeColors × cols × 6).
 *
 * The annotation sized the win as "320×200@16 multi-second → <200ms"; that
 * premise overestimates the small-K case (measured: the legacy scan at the
 * default 16-color palette is ~60ms for this grid — CH-14 class annotation
 * correction, disclosed). The multi-second regime the finding actually
 * describes is the many-active-colors case, so the budget pins a
 * memorized-quant, 180-color-active 256-palette grid: quant stays cheap,
 * the walk is everything. Measured here: new ≈198ms, legacy ≈680ms —
 * a 450ms budget fails the legacy shape by a safe margin.
 */
final class SixelPerfTest extends TestCase
{
    public function testManyActiveColoursEncodeWithinBudget(): void
    {
        $tab = [];
        for ($i = 0; $i < 180; $i++) {
            $tab[] = Color::rgb((13 * $i) % 256, (7 * $i) % 256, (11 * $i + 31) % 256);
        }
        $pixels = [];
        for ($r = 0; $r < 200; $r++) {
            $row = [];
            for ($c = 0; $c < 320; $c++) {
                $row[] = $tab[($r * 17 + $c * 13) % 180];
            }
            $pixels[] = $row;
        }

        $best = INF;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $start   = hrtime(true);
            $out     = Sixel::encode($pixels, 256);
            $elapsed = (hrtime(true) - $start) / 1e6;
            $best    = min($best, $elapsed);
            $this->assertNotSame('', $out);
        }
        $this->assertLessThan(450.0, $best, sprintf('per-color stripe re-walk is back: %.0f ms', $best));
    }
}
