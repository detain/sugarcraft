<?php

declare(strict_types=1);

/*
 * M1 (lib-fixes-1103): Veil's SGR-to-RGB conversion must agree with
 * sugar-cellbuffer's Style/Buffer for every palette index, bright and
 * normal.
 *
 * The cellbuffer convention special-cases bright black (SGR 90 /
 * 38;5;8-bright) to 0x7F7F7F — the xterm "grey" convention — while the
 * overlay's private copy applied only the +40% channel lift, turning
 * index 0 bright into pure 0x000000. A modal dimmed with SGR 90 over a
 * buffer painted with the grey convention produced a false diff: the
 * cell model and the emitted frame disagreed, so every later frame
 * re-Emitted the row.
 *
 * The conversion lives in {@see Style::ansiColorToHex()} on the cellbuffer
 * side (private, reached by reflection as the oracle) and in
 * {@see SgrWalker::ansiColorToHex()} on this side (public since SO-06 moved
 * the pen math into the ONE shared walker) — these pins run BOTH tables
 * across the whole 16-color range and require identical results, so a
 * silent fork in either lib's table now reddens here.
 */

namespace SugarCraft\Overlay\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\CellBuffer\Style;
use SugarCraft\Overlay\SgrWalker;
use SugarCraft\Overlay\Veil;

final class GrayPenParityTest extends TestCase
{
    public function testBrightBlackIsTheGreyConventionInBothLibraries(): void
    {
        $this->assertSame(0x7f7f7f, $this->veilHex(0, true), 'Veil must paint bright black as xterm grey');
        $this->assertSame(0x7f7f7f, $this->styleHex(0, true), 'Cellbuffer paints bright black as xterm grey (oracle)');
    }

    public function testTheFullSixteenColorTableAgreesBetweenVeilAndBuffer(): void
    {
        foreach ([false, true] as $bright) {
            for ($idx = 0; $idx <= 7; $idx++) {
                $this->assertSame(
                    $this->styleHex($idx, $bright),
                    $this->veilHex($idx, $bright),
                    sprintf('SGR %s index %d must resolve to the same RGB in Veil and Buffer', $bright ? 'bright' : 'normal', $idx)
                );
            }
        }
    }

    /**
     * M6 (docs-reviews-1104): dims do not compound. The pen closest to the
     * text wins, so wrapping an already-dimmed line leaves the first pen
     * bytes intact and sitting directly before the glyph — the outer scrim
     * can never overwrite what an inner dim already touched.
     */
    public function testTheFirstDimPenStillWinsInsideASecondScrim(): void
    {
        $inner = Veil::new()->withBackdrop(70);
        $outer = Veil::new()->withBackdrop(30);

        $once = $this->dimLine($inner, 'x');
        $twice = $this->dimLine($outer, $once);

        $firstPen = substr($once, 0, strpos($once, 'x'));
        $this->assertNotSame('', $firstPen, 'the inner dim must carry a pen');
        $this->assertStringContainsString(
            $firstPen,
            $twice,
            'a second wrap must not strip the first pen — dims do not replace each other'
        );
        $this->assertSame(
            strpos($twice, 'x'),
            strpos($twice, $firstPen) + strlen($firstPen),
            'the first pen must sit directly before the glyph: it is the pen the terminal honours'
        );
    }

    private function veilHex(int $idx, bool $bright): int
    {
        // Public API of the shared walker since SO-06 — no reflection needed.
        return SgrWalker::ansiColorToHex($idx, $bright);
    }

    private function styleHex(int $idx, bool $bright): int
    {
        static $method = null;
        $method ??= new ReflectionMethod(Style::class, 'ansiColorToHex');

        return $method->invoke(null, $idx, $bright);
    }

    private function dimLine(Veil $veil, string $line): string
    {
        static $method = null;
        $method ??= new ReflectionMethod(Veil::class, 'dimLine');

        return $method->invoke($veil, $line);
    }
}
