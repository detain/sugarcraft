<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server\Pairing;

/**
 * Renders a QR module matrix as terminal text using Unicode half blocks.
 *
 * Each module row pair collapses into one text line: the two vertical
 * neighbours select one of U+2588 FULL BLOCK (both dark), U+2580 UPPER HALF
 * (top dark), U+2584 LOWER HALF (bottom dark) or a space (both light), so a
 * square module costs one character cell and a single line. Terminals whose
 * glyph cell is roughly twice as tall as wide — the pragmatic default for
 * monospace fonts — reproduce the square aspect ratio scanners expect.
 *
 * A four-module quiet zone (the minimum ISO 18004 requires at the symbol
 * edge; decoders tolerate more, never less) wraps the matrix on all sides.
 * The bottom pair of a row count that is odd after padding pairs the last
 * real row with the quiet zone, never with data.
 *
 * Deliberately ANSI-free: no colours, no cursor moves, so the output rides
 * the transcript-purity filters unchanged and stays copyable.
 */
final class QrTerminal
{
    /** Quiet-zone modules ISO 18004 clause 8.9 requires on every symbol edge. */
    public const QUIET_ZONE = 4;

    private function __construct()
    {
    }

    /**
     * @param list<list<int>> $modules square matrix of 0/1 values (no quiet zone)
     *
     * @return string multi-line block, lines joined by "\n", no trailing newline
     */
    public static function render(array $modules, int $size): string
    {
        if ($size < 21 || $size > 177 || $size !== \count($modules)) {
            throw new \InvalidArgumentException('QR matrix must be a square module list');
        }

        $quiet = self::QUIET_ZONE;
        $width = $size + 2 * $quiet;

        // Padded grid indexed [row][col]; PHP list() building keeps this O(rows*cols).
        $padRow = \array_fill(0, $width, 0);
        $padded = [];
        for ($r = 0; $r < $quiet; $r++) {
            $padded[] = $padRow;
        }
        foreach ($modules as $row) {
            $line = $padRow;
            for ($c = 0; $c < $size; $c++) {
                $line[$quiet + $c] = $row[$c];
            }
            $padded[] = $line;
        }
        for ($r = 0; $r < $quiet; $r++) {
            $padded[] = $padRow;
        }
        if (\count($padded) % 2 === 1) {
            $padded[] = $padRow; // bottom pairing rides the quiet zone
        }

        $out = [];
        for ($r = 0; $r < \count($padded); $r += 2) {
            $top = $padded[$r];
            $bottom = $padded[$r + 1];
            $glyphs = '';
            for ($c = 0; $c < $width; $c++) {
                $glyphs .= self::glyph($top[$c], $bottom[$c]);
            }
            $out[] = $glyphs;
        }

        return \implode("\n", $out);
    }

    /**
     * @return array{lines: int, width: int} shape a caller may pin or clamp
     */
    public static function shape(int $size): array
    {
        $rows = $size + 2 * self::QUIET_ZONE;
        if ($rows % 2 === 1) {
            $rows++;
        }

        return ['lines' => $rows >> 1, 'width' => $size + 2 * self::QUIET_ZONE];
    }

    private static function glyph(int $top, int $bottom): string
    {
        if ($top === 1) {
            return $bottom === 1 ? "\u{2588}" : "\u{2580}";
        }

        return $bottom === 1 ? "\u{2584}" : ' ';
    }
}
