<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Tests\Support;

use SugarCraft\Vterm\Buffer\Buffer;

/**
 * The shared rendition-dump oracle (vterm-31 dumper, extracted vterm-33).
 *
 * Deterministic full-grid baseline of the parsed cell state: grapheme,
 * fg/bg (palette slot or truecolour), the attribute bitfield, the DECDHL
 * rendition, the OSC 8 hyperlink URI and combining marks — every cell,
 * every row. GoldenRenderTest (rendition goldens) and FuzzCorpusTest
 * (byte-fixture replay corpus) assert through this ONE helper, so a
 * renderer that quietly drops any dimension of rendition reddens a
 * baseline in both families.
 *
 * Format (one cell per line):
 *   #<cols>x<rows> header, then per row a `r<N>` marker and its cells.
 *   Cell: <grapheme>|<fg>|<bg>|a<attrs-hex>|r<rendition>|h<uri|->|c<comb-hex|->
 *   fg/bg: `12` palette slot, or `#rrggbb` when the truecolour field is set.
 *   Continuation halves of wide glyphs render as `·CONT` (their rendition
 *   fields are still dumped — a half-cell with a colour is a bug to pin).
 */
final class RenditionOracle
{
    /** Full-grid rendition dump — the oracle the goldens baseline against. */
    public static function dumpGrid(Buffer $grid): string
    {
        $out = '#' . $grid->cols . 'x' . $grid->rows . "\n";
        for ($r = 0; $r < $grid->rows; $r++) {
            $out .= "r$r\n";
            for ($c = 0; $c < $grid->cols; $c++) {
                $cell = $grid->cell($r, $c);
                $fg = $cell->fgTruecolor !== null ? '#' . dechex($cell->fgTruecolor) : (string) $cell->fg;
                $bg = $cell->bgTruecolor !== null ? '#' . dechex($cell->bgTruecolor) : (string) $cell->bg;
                $out .= ($cell->continuation ? '·CONT' : $cell->grapheme)
                    . '|' . $fg . '|' . $bg
                    . '|a' . dechex($cell->attrs)
                    . '|r' . $cell->rendition->value
                    . '|h' . ($cell->hyperlink === null ? '-' : $cell->hyperlink->uri)
                    . '|c' . ($cell->combining === '' ? '-' : bin2hex($cell->combining))
                    . "\n";
            }
        }
        return $out;
    }
}
