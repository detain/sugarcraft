<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use SugarCraft\Tetris\Tetromino;
use PHPUnit\Framework\TestCase;

final class TetrominoTest extends TestCase
{
    public function testEverShapeReturnsFourCellsAcrossAllRotations(): void
    {
        foreach (Tetromino::cases() as $piece) {
            for ($r = 0; $r < 4; $r++) {
                $cells = $piece->cells($r);
                $this->assertCount(4, $cells, "{$piece->value} rotation {$r}");
            }
        }
    }

    public function testCellsStayWithinFourByFourBox(): void
    {
        foreach (Tetromino::cases() as $piece) {
            for ($r = 0; $r < 4; $r++) {
                foreach ($piece->cells($r) as [$x, $y]) {
                    $this->assertGreaterThanOrEqual(0, $x);
                    $this->assertLessThanOrEqual(3, $x);
                    $this->assertGreaterThanOrEqual(0, $y);
                    $this->assertLessThanOrEqual(3, $y);
                }
            }
        }
    }

    public function testOPieceRotationIsIdempotent(): void
    {
        $a = Tetromino::O->cells(0);
        for ($r = 1; $r < 4; $r++) {
            $this->assertSame($a, Tetromino::O->cells($r), "O rotation {$r}");
        }
    }

    public function testIPieceFlipsBetweenHorizontalAndVertical(): void
    {
        $h = Tetromino::I->cells(0);
        $v = Tetromino::I->cells(1);
        // Horizontal: y is constant.
        $hYs = array_unique(array_map(fn($c) => $c[1], $h));
        $this->assertCount(1, $hYs);
        // Vertical: x is constant.
        $vXs = array_unique(array_map(fn($c) => $c[0], $v));
        $this->assertCount(1, $vXs);
    }

    public function testCellsAreUnique(): void
    {
        foreach (Tetromino::cases() as $piece) {
            for ($r = 0; $r < 4; $r++) {
                $cells = $piece->cells($r);
                $keys = array_map(fn($c) => "{$c[0]},{$c[1]}", $cells);
                $this->assertSame(count($keys), count(array_unique($keys)),
                    "{$piece->value} rotation {$r} has duplicate cell");
            }
        }
    }

    public function testNegativeRotationIsModular(): void
    {
        foreach (Tetromino::cases() as $piece) {
            $this->assertSame($piece->cells(3), $piece->cells(-1));
            $this->assertSame($piece->cells(0), $piece->cells(-4));
        }
    }

    public function testEachPieceHasUniqueColor(): void
    {
        $colors = array_map(static fn(Tetromino $t) => $t->color(), Tetromino::cases());
        $this->assertSame(count($colors), count(array_unique($colors)));
    }

    public function testRgbColorMatchesThePublishedPalette(): void
    {
        $expected = [
            'I' => 0x00d4ff,
            'O' => 0xffd400,
            'T' => 0xbd7dff,
            'S' => 0x00ff5e,
            'Z' => 0xff0030,
            'J' => 0x0070ff,
            'L' => 0xff8c00,
        ];
        foreach (Tetromino::cases() as $piece) {
            $this->assertSame($expected[$piece->name], $piece->rgbColor(), $piece->name);
        }
    }

    public function testRgbColorIsUniqueAndPairedWithTheAnsiCode(): void
    {
        $rgb = array_map(static fn(Tetromino $t) => $t->rgbColor(), Tetromino::cases());
        $this->assertSame(count($rgb), count(array_unique($rgb)));

        // The two palettes must describe the SAME seven colours in the
        // same declaration order — the renderer switched to rgbColor()
        // and must not have picked up a different mapping.
        $ansi = array_map(static fn(Tetromino $t) => $t->color(), Tetromino::cases());
        $this->assertSame(
            [51, 226, 129, 46, 196, 21, 208],
            $ansi,
            'ansi256 palette drifted; re-derive the rgb pairing below',
        );
        $this->assertSame(
            [0x00d4ff, 0xffd400, 0xbd7dff, 0x00ff5e, 0xff0030, 0x0070ff, 0xff8c00],
            $rgb,
            'rgb palette drifted against the ansi palette',
        );
    }
}
