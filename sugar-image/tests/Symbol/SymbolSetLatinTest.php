<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Symbol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Symbol\SymbolMap;
use SugarCraft\Image\Symbol\SymbolSetLatin;
use SugarCraft\Image\Symbol\SymbolTags;

/**
 * SYMBOL-LATIN: the opt-in LATIN symbol tier (chafa-symbols-latin.h).
 *
 * The table is data transcribed from chafa's own header; the pins here are
 * structural (count / codepoint census / outline shape) plus spot glyphs
 * whose Terminus shapes are read straight off the C source, so a
 * transcription slip shows up as a red rather than validating itself.
 * Reachability is pinned through findCandidates(): a latin glyph's own
 * bitmap must win at distance 0 in the latin map and must NOT be present
 * in the default corpus.
 */
final class SymbolSetLatinTest extends TestCase
{
    /**
     * The exact codepoint census of chafa-symbols-latin.h as contiguous
     * ranges: 365 glyphs over U+00A1-U+03F6 (Latin-1 supplement, Latin
     * Extended-A/B, IPA, spacing modifiers, Greek). Bidirectional — an
     * added or dropped glyph reddens here.
     */
    private const CENSUS = [
        [0x00A1, 0x00AC], [0x00AE, 0x017F], [0x0186, 0x0186], [0x018E, 0x0190],
        [0x0192, 0x0192], [0x019D, 0x019E], [0x01B5, 0x01B7], [0x01CD, 0x01D4],
        [0x01E2, 0x01F0], [0x01F4, 0x01F5], [0x01FC, 0x01FF], [0x0218, 0x021B],
        [0x0232, 0x0233], [0x0237, 0x0237], [0x0254, 0x0254], [0x0258, 0x0259],
        [0x025B, 0x025B], [0x0272, 0x0272], [0x0292, 0x0292], [0x02BB, 0x02BD],
        [0x02C6, 0x02C7], [0x02D8, 0x02D9], [0x02DB, 0x02DD], [0x0384, 0x038A],
        [0x038C, 0x038C], [0x038E, 0x03A1], [0x03A3, 0x03CE], [0x03D1, 0x03D1],
        [0x03D5, 0x03D5], [0x03F0, 0x03F6],
    ];

    public function testTheWholeTableIsDigestPinnedAgainstTheChafaHeader(): void
    {
        // Authoring oracle: the table was transcribed mechanically from
        // chafa/internal/chafa-symbols-latin.h (365 entries, codepoint census
        // pinned above). This digest freezes every pixel of the transcription
        // so a hand-edit or partial re-transcription reddens here; the fix is
        // always to re-generate from the header, never to re-pin this literal.
        $lines = [];
        foreach (SymbolSetLatin::OUTLINES as $cp => $outline) {
            $lines[] = sprintf('U+%04X:%s', $cp, $outline);
        }

        $this->assertSame('1c3041a8e0db9735535c3ff8321e45b29fc82725', sha1(implode("\n", $lines)));
    }

    public function testTheLatinSetIsChafasThreeHundredSixtyFiveGlyphTable(): void
    {
        $outlines = SymbolSetLatin::OUTLINES;

        $this->assertCount(365, $outlines);

        foreach ($outlines as $cp => $outline) {
            $rows = explode('|', $outline);
            $this->assertCount(8, $rows, sprintf('U+%04X must have 8 rows', $cp));
            foreach ($rows as $row) {
                $this->assertSame(8, \strlen($row), sprintf('U+%04X row "%s" must be 8 cells', $cp, $row));
                $this->assertSame(0, preg_match('/[^ X]/', $row), sprintf('U+%04X row "%s" must be spaces and X only', $cp, $row));
            }
            $this->assertNotSame('', trim(str_replace('|', '', $outline)), sprintf('U+%04X must paint something', $cp));
        }
    }

    public function testTheCodepointCensusMatchesChafasRanges(): void
    {
        $codepoints = array_keys(SymbolSetLatin::OUTLINES);
        sort($codepoints);

        $derived = [];
        foreach ($codepoints as $cp) {
            $last = \count($derived) - 1;
            if ($last >= 0 && $cp === $derived[$last][1] + 1) {
                $derived[$last][1] = $cp;
            } else {
                $derived[] = [$cp, $cp];
            }
        }

        $this->assertSame(self::CENSUS, $derived);
        $this->assertCount(365, $codepoints);
        $this->assertCount(365, array_unique($codepoints), 'no codepoint may appear twice');
    }

    public function testSpotGlyphsKeepTheirTerminusShapes(): void
    {
        $expected = [
            0x00A1 => '        |   X    |        |   X    |   X    |   X    |   X    |        ',
            0x00C9 => '   XXX  | XXXXXX | X      | X      | XXXX   | X      | XXXXXX |        ',
            0x00DF => '        |  XXX   | X   X  | XXXXX  | X    X | XX   X | X XXX  |        ',
            0x00E9 => '        |    X   |   X    | XXXXXX | X XX X | X      |  XXXX  |        ',
            0x017F => '        |    XXX |   X    |   X    |   X    |   X    |   X    |        ',
            0x03B1 => '        |        |        | XXXXXX | X   X  | X   X  |  XXX X |        ',
            0x03C9 => '        |        |        |XX   XX |X  X  X |X  X  X | XX XX  |        ',
        ];

        foreach ($expected as $cp => $outline) {
            $this->assertArrayHasKey($cp, SymbolSetLatin::OUTLINES, sprintf('U+%04X missing', $cp));
            $this->assertSame($outline, SymbolSetLatin::OUTLINES[$cp], sprintf('U+%04X outline drifted', $cp));
        }
    }

    public function testTheLatinTierAppendsAfterTheNarrowCorpusWithoutMovingIt(): void
    {
        $default = SymbolMap::new();
        $latin = $default->withLatin();

        $this->assertFalse($default->hasLatin());
        $this->assertTrue($latin->hasLatin());

        $base = $default->defs();
        $extended = $latin->defs();

        $this->assertCount(676, $base);
        $this->assertCount(676 + 365, $extended);

        // The default corpus is a byte-identical prefix: same glyphs, same bitmaps, same order.
        foreach ($base as $i => $def) {
            $this->assertSame($def->char, $extended[$i]->char, 'default glyph order must not move');
            $this->assertSame($def->bits, $extended[$i]->bits, 'default bitmaps must not move');
            $this->assertSame($def->tags, $extended[$i]->tags, 'default tags must not move');
        }

        $seen = [];
        foreach ($base as $def) {
            $seen[$def->char] = true;
        }
        for ($i = 676; $i < \count($extended); $i++) {
            $def = $extended[$i];
            $this->assertSame(SymbolTags::LATIN, $def->tags, 'appended glyphs carry the LATIN tag');
            $this->assertArrayNotHasKey($def->char, $seen, 'the tier must not shadow a default glyph');
            $this->assertGreaterThan(0, $def->weight, 'every latin glyph paints at least one pixel');
            $this->assertSame(64 - $def->weight, $def->bgWeight());
        }
    }

    public function testTheLatinTierIsIdempotent(): void
    {
        $once = SymbolMap::new()->withLatin();
        $twice = $once->withLatin();

        $this->assertCount(676 + 365, $twice->defs());
        $this->assertTrue($twice->hasLatin());
    }

    public function testTheLatinFlagResetsAcrossWithSelectors(): void
    {
        // F-17 (rev-w10-2 m2): the W10 weld's union contract — the
        // hasLatin flag is carried by withLatin()-built maps only, and
        // withSelectors() returns a plain corpus pick (SymbolMap.php
        // ctor resets the flag), so the tier stays reachable via the
        // `latin` class at its build-order position instead of riding
        // the appended tail twice. A later withLatin() is a no-op append —
        // its rebuild_symbols-style codepoint filter (W11-5, rev-w11-1 m2)
        // adds only still-missing glyphs, so the seam never duplicates.
        $latin = SymbolMap::new()->withLatin();
        $selected = $latin->withSelectors('+stipple');

        $this->assertTrue($latin->hasLatin());
        $this->assertFalse($selected->hasLatin(), 'withSelectors resets the tier flag');
        $this->assertCount(676 + 365, $selected->defs(), 'the stipple classes already ride the picked corpus; nothing is re-appended');
        $chars = static function (SymbolMap $m): array {
            $out = array_map(static fn ($d) => $d->char, $m->defs());
            sort($out);

            return $out;
        };
        $this->assertSame(
            $chars($latin),
            $chars($selected),
            'same glyph set — the selection re-emits it in full-corpus build order'
        );
    }

    /**
     * SYMBOL-LATIN: withLatin() after withSelectors() cannot duplicate the
     * tier (W11-5, rev-w11-1 m2). Mirrored chafa semantics: rebuild_symbols()
     * keys a hash by codepoint, so a codepoint can never appear twice in the
     * symbol array — the append filters codepoints the selection already
     * carries, and an already-carried slot keeps its selected spelling (the
     * same uniqueness law fullCorpus() applies to the U+00B7 cross-table
     * collision, and the same set posture as the withAsciiIbm() in-place
     * walk). `all` selects 1,251 narrow glyphs including every latin tier
     * codepoint; composing withLatin() on it adds nothing.
     */
    public function testAppendingTheLatinTierToALatinCarryingSelectionAddsNoDuplicates(): void
    {
        $all = SymbolMap::new()->withSelectors('all');
        $composed = $all->withLatin();

        $this->assertTrue($composed->hasLatin());
        $this->assertCount(1251, $composed->defs(), 'the selection already carries every latin codepoint: the append adds nothing');

        $seen = [];
        foreach ($composed->defs() as $def) {
            $cp = mb_ord($def->char);
            $this->assertArrayNotHasKey($cp, $seen, 'no codepoint may appear twice');
            $seen[$cp] = true;
        }

        // Byte-identical to the selection it started from: order, glyphs,
        // bitmaps and tags of the carried slots are untouched.
        $this->assertEquals($all->defs(), $composed->defs());

        // The documented W10 seam shape: a relative withSelectors() resets
        // the flag (F-17), yet re-composing withLatin() on the surviving
        // tier is a no-op append, not the 365-fold duplicate it was.
        $rel = SymbolMap::new()->withLatin()->withSelectors('+stipple');
        $again = $rel->withLatin();

        $this->assertFalse($rel->hasLatin(), 'withSelectors resets the tier flag (F-17, unchanged)');
        $this->assertCount(676 + 365, $again->defs());
        $this->assertTrue($again->hasLatin());
        $this->assertEquals($rel->defs(), $again->defs());
    }

    public function testALatinGlyphWinsItsOwnBitmapOnlyInTheLatinTier(): void
    {
        $default = SymbolMap::new();
        $latin = $default->withLatin();

        // A representative sample across the four sub-families the table spans.
        foreach ([0x00A1, 0x00C9, 0x00DF, 0x00E9, 0x017F, 0x02C7, 0x03B1, 0x03C9] as $cp) {
            $char = mb_chr($cp, 'UTF-8');
            $this->assertNotFalse($char);

            $def = null;
            foreach ($latin->defs() as $candidate) {
                if ($candidate->char === $char) {
                    $def = $candidate;
                    break;
                }
            }
            $this->assertNotNull($def, sprintf('U+%04X must be in the latin corpus', $cp));

            $pick = $latin->findCandidates($def->bits, false, 1);
            $this->assertCount(1, $pick);
            $this->assertSame(0, $def->bits ^ $pick[0][0]->bits, sprintf('U+%04X must be an exact match for its own bitmap', $cp));

            $narrow = $default->findCandidates($def->bits, false, 1);
            $this->assertNotSame(
                $def->bits,
                $narrow[0][0]->bits,
                sprintf('U+%04X must be unreachable from the default corpus', $cp)
            );
        }
    }
}
