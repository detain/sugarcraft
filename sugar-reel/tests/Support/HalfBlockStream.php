<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests\Support;

use ReflectionMethod;
use SugarCraft\Reel\Decode\RgbFrame;
use SugarCraft\Reel\Player;
use SugarCraft\Reel\Render\HalfBlockRenderer;
use SugarCraft\Reel\Render\Mode;
use SugarCraft\Reel\Tests\FakeDecoder;

/**
 * Reads a rendered half-block ANSI stream back into its per-cell semantics.
 *
 * The two HalfBlock paths (Player's inline Buffer path and the candy-mosaic
 * `Render\HalfBlockRenderer`) encode their escapes differently — one combined
 * `0;38;2;r;g;b;48;2;r;g;bm` run per cell versus a reset-terminated fg/bg pair —
 * so comparing their bytes says nothing useful while comparing glyph counts says
 * too little to catch a colour bug. Normalising both to an ordered list of
 * (glyph, foreground, background) triples is what makes the parity guard in
 * `findings/sugar-reel.md` #9 actually mean "same picture".
 *
 * Shared by `tests/HalfBlockParityTest.php` (the full matrix) and
 * `PlayerTest::testHalfBlockInlineMatchesMosaicRenderer()` (the canonical case the
 * production comments cite by name).
 */
final class HalfBlockStream
{
    public const GLYPH = "\u{2580}";

    /**
     * Byte-level alternation of BOTH half-block glyphs — ▀ (U+2580 = \xe2\x96\x80)
     * and ▄ (U+2584 = \xe2\x96\x84) — with no `/u` modifier so every offset stays
     * in bytes, like the substr()/strpos() arithmetic below. A ▄-based producer
     * (a future mosaic that swaps fg/bg channels instead of rows) is therefore
     * PARSED and RECORDED, not silently missed: the glyph column of `cells()` is
     * the literal bytes found, so parity tests compare real values (round-1 m1).
     */
    private const GLYPH_PATTERN = "/\xe2\x96[\x80\x84]/";

    /**
     * @return list<array{glyph: string, fg: ?string, bg: ?string}>
     */
    public static function cells(string $out): array
    {
        $cells = [];
        $fg = null;
        $bg = null;
        $offset = 0;

        while (true) {
            // Byte offsets throughout: ▀/▄ are 3-byte UTF-8 sequences, so mixing a
            // character-based position (mb_strpos) with substr() would mis-locate
            // every cell after the first.
            if (1 !== preg_match(self::GLYPH_PATTERN, $out, $glyph, PREG_OFFSET_CAPTURE, $offset)) {
                break;
            }
            $glyphPos = (int) $glyph[0][1];
            $glyphText = (string) $glyph[0][0];

            $esc = strpos($out, "\x1b[", $offset);
            while (false !== $esc && $esc < $glyphPos) {
                $end = strpos($out, 'm', $esc);
                if (false === $end) {
                    break 2;
                }
                [$fg, $bg] = self::applySgr(substr($out, $esc + 2, $end - $esc - 2), $fg, $bg);
                $offset = $end + 1;
                $esc = strpos($out, "\x1b[", $offset);
            }

            $cells[] = ['glyph' => $glyphText, 'fg' => $fg, 'bg' => $bg];
            $offset = $glyphPos + strlen($glyphText);
        }

        return $cells;
    }

    /**
     * The runtime path: Player::frameToBuffer() → Buffer::toAnsi().
     *
     * @return list<array{glyph: string, fg: ?string, bg: ?string}>
     */
    public static function inlineCells(RgbFrame $frame, int $cellsW, int $cellsH): array
    {
        $player = Player::fromDecoder(
            new FakeDecoder([$frame]),
            cellsW: $cellsW,
            cellsH: $cellsH,
            fps: 24.0,
            mode: Mode::HalfBlock,
            totalFrames: 1,
            videoPath: '/fake',
        );

        $mutate = new ReflectionMethod(Player::class, 'mutate');
        $mutate->setAccessible(true);
        $player = $mutate->invoke($player, ['currentFrame' => $frame]);

        $toBuffer = new ReflectionMethod(Player::class, 'frameToBuffer');
        $toBuffer->setAccessible(true);

        return self::cells($toBuffer->invoke($player, $frame, Mode::HalfBlock)->toAnsi());
    }

    /**
     * The mosaic path: Render\HalfBlockRenderer (never reached by view()).
     *
     * @return list<array{glyph: string, fg: ?string, bg: ?string}>
     */
    public static function mosaicCells(RgbFrame $frame): array
    {
        return self::cells((new HalfBlockRenderer())->render($frame, Mode::HalfBlock));
    }

    /**
     * A deterministic colour grid where every pixel is unique, so a transposed
     * index, a swapped fg/bg pair or an off-by-one row pairing changes a cell.
     */
    public static function gradient(int $w, int $h): RgbFrame
    {
        $bytes = '';
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $bytes .= chr(($x * 29 + $y * 7) % 256) . chr(($y * 61) % 256) . chr((($x + $y) * 37) % 256);
            }
        }

        return new RgbFrame($bytes, $w, $h);
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private static function applySgr(string $params, ?string $fg, ?string $bg): array
    {
        $parts = explode(';', $params);
        for ($i = 0; $i < count($parts); $i++) {
            $p = $parts[$i];
            if (('38' === $p || '48' === $p) && ($parts[$i + 1] ?? '') === '2') {
                $rgb = sprintf('%s,%s,%s', $parts[$i + 2] ?? '', $parts[$i + 3] ?? '', $parts[$i + 4] ?? '');
                if ('38' === $p) {
                    $fg = $rgb;
                } else {
                    $bg = $rgb;
                }
                $i += 4;

                continue;
            }

            if ('0' === $p || '' === $p) {
                $fg = null;
                $bg = null;
            }
        }

        return [$fg, $bg];
    }
}
