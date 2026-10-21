<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Symbol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Symbol\SymbolDef;
use SugarCraft\Image\Symbol\SymbolMap;
use SugarCraft\Image\Symbol\SymbolSetKana;
use SugarCraft\Image\Symbol\WideSymbolDef;

/**
 * KANA: the opt-in wide kana tier — chafa-symbols-kana.h as 181 16x8
 * WideSymbolDefs, partitioned from the narrow corpus exactly like chafa's
 * init_symbol_array_wide().
 *
 * The table is data transcribed from chafa's own header; the pins here are
 * structural (count / codepoint census / outline shape) plus the whole-table
 * digest frozen against an external anchor, an independent in-test
 * re-derivation from the C header itself when the chafa checkout is present,
 * and spot glyphs whose outlines are read straight off the C source. The
 * map surface (withKana/hasKana/wideDefs, the WIDE effective tags through
 * the selector grammar, and the popcount-sorted findWideCandidates scan)
 * is pinned here; the renderer's wide lookback pass is pinned by the A-09
 * engine tests.
 */
final class SymbolSetKanaTest extends TestCase
{
    /** chafa checkout anchor for the independent re-derivation (skipped when absent). */
    private const HEADER = '/home/sites/chafa/chafa/internal/chafa-symbols-kana.h';

    /**
     * The exact codepoint census of chafa-symbols-kana.h as contiguous
     * ranges: hiragana U+3041-U+3096 (the U+3097-U+309F voiced-iteration
     * slots are unassigned and chafa carries none) and katakana
     * U+30A0-U+30FE. Bidirectional — an added or dropped glyph reddens here.
     */
    private const CENSUS = [
        [0x3041, 0x3096],
        [0x30A0, 0x30FE],
    ];

    public function testTheWholeTableIsDigestPinnedAgainstTheChafaHeader(): void
    {
        // Authoring oracle: the table was transcribed mechanically from
        // chafa/internal/chafa-symbols-kana.h (181 entries, codepoint census
        // pinned below). Each dump line splits every 16-character outline
        // row into its 8-pixel left and right halves ('%04X left right',
        // rows joined by '|'); this digest freezes every pixel of the
        // transcription so a hand-edit or partial re-transcription reddens
        // here; the fix is always to re-generate from the header, never to
        // re-pin this literal.
        $lines = [];
        foreach (SymbolSetKana::OUTLINES as $cp => $outline) {
            $left = [];
            $right = [];
            foreach (explode('|', $outline) as $row) {
                $left[] = substr($row, 0, 8);
                $right[] = substr($row, 8, 8);
            }
            $lines[] = sprintf('%04X %s %s', $cp, implode('|', $left), implode('|', $right));
        }

        $this->assertSame('10ac465b3498527a7a3fb399d509bfcd75cea563', sha1(implode("\n", $lines)));
    }

    public function testTheTableReDerivesIndependentlyFromTheChafaHeader(): void
    {
        if (!\is_file(self::HEADER)) {
            $this->markTestSkipped('chafa checkout not present at ' . self::HEADER);
        }

        // Independent transcription path: parse the C header here with its
        // own regex (not the generator's) and compare EVERY entry against
        // the committed table — all 181 glyphs, comfortably over the 40
        // sample floor for an anchored oracle.
        $src = (string) file_get_contents(self::HEADER);
        $matches = [];
        $count = preg_match_all(
            '/CHAFA_SYMBOL_TAG_NONE,\s*0x([0-9A-Fa-f]+),\s*CHAFA_SYMBOL_OUTLINE_16X8\s*\((.*?)\)\s*\}/s',
            $src,
            $matches,
            PREG_SET_ORDER
        );
        $this->assertNotFalse($count);
        $this->assertGreaterThanOrEqual(40, $count, 'the header itself must carry the tier');

        $seen = 0;
        foreach ($matches as $entry) {
            $cp = (int) hexdec($entry[1]);
            preg_match_all('/"([^"]*)"/', $entry[2], $rows);
            $this->assertCount(8, $rows[1], sprintf('U+%04X header rows', $cp));
            $outline = implode('|', $rows[1]);

            $this->assertArrayHasKey($cp, SymbolSetKana::OUTLINES, sprintf('U+%04X missing from the table', $cp));
            $this->assertSame($outline, SymbolSetKana::OUTLINES[$cp], sprintf('U+%04X outline drifted from the header', $cp));
            $seen++;
        }

        $this->assertSame(\count(SymbolSetKana::OUTLINES), $seen, 'every table entry must come from the header');
    }

    public function testTheKanaSetIsChafasHundredEightyOneGlyphWideTable(): void
    {
        $outlines = SymbolSetKana::OUTLINES;

        $this->assertCount(181, $outlines);

        foreach ($outlines as $cp => $outline) {
            $rows = explode('|', $outline);
            $this->assertCount(8, $rows, sprintf('U+%04X must have 8 rows', $cp));
            foreach ($rows as $row) {
                $this->assertSame(16, \strlen($row), sprintf('U+%04X row "%s" must be 16 cells (two 8-pixel halves)', $cp, $row));
                $this->assertSame(0, preg_match('/[^ X]/', $row), sprintf('U+%04X row "%s" must be spaces and X only', $cp, $row));
            }
            $this->assertNotSame('', trim(str_replace('|', '', $outline)), sprintf('U+%04X must paint something', $cp));
        }
    }

    public function testTheCodepointCensusMatchesChafasRanges(): void
    {
        $codepoints = array_keys(SymbolSetKana::OUTLINES);
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
        $this->assertCount(181, array_unique($codepoints), 'no codepoint may appear twice');
    }

    public function testSpotGlyphsKeepTheirHeaderShapes(): void
    {
        // Outlines read straight off chafa/internal/chafa-symbols-kana.h:
        // the first hiragana, the heaviest glyph, the middle dot (lightest)
        // and a katakana iteration mark.
        $expected = [
            0x3041 => '                |                |   XXXXXXXXX    |      X   X     |     XXXXX XX   |   XX X X   XX  |   X  XX    X   |    XX   XXX    ',
            0x3042 => '      XX        |  XXXXXXXXXXX   |      X   XX    |     XXXXXXXX   |   XX X  X   X  |  X   X X    X  |  X   XX     X  |   XXX   XXXX   ',
            0x30FB => '                |                |                |      XXXX      |       XX       |                |                |                ',
            0x30FD => '                |                |     X          |      XX        |        XX      |          XX    |                |                ',
        ];

        foreach ($expected as $cp => $outline) {
            $this->assertArrayHasKey($cp, SymbolSetKana::OUTLINES, sprintf('U+%04X missing', $cp));
            $this->assertSame($outline, SymbolSetKana::OUTLINES[$cp], sprintf('U+%04X outline drifted', $cp));
        }
    }

    public function testTheDefaultMapCarriesNoWideGlyphs(): void
    {
        // Byte-freeze door: chafa's update_cells_wide early-returns on an
        // empty symbols2 array, so every map that does not select a wide
        // tier renders exactly as before the engine existed.
        $default = SymbolMap::new();

        $this->assertFalse($default->hasKana());
        $this->assertSame([], $default->wideDefs());
        $this->assertCount(676, $default->defs());
    }

    public function testTheKanaTierAppendsAsWideDefsWithoutMovingTheNarrowCorpus(): void
    {
        $default = SymbolMap::new();
        $kana = $default->withKana();

        $this->assertTrue($kana->hasKana());
        $this->assertCount(181, $kana->wideDefs());
        $this->assertSame(self::tuples($default->defs()), self::tuples($kana->defs()), 'the narrow corpus is byte-identical');
        $this->assertFalse($default->hasKana());

        $seen = [];
        foreach ($kana->wideDefs() as $def) {
            $cp = mb_ord($def->char);
            $this->assertArrayNotHasKey($cp, $seen, 'no codepoint may appear twice');
            $seen[$cp] = true;
            $this->assertGreaterThanOrEqual(0x3041, $cp);
            $this->assertLessThanOrEqual(0x30FF, $cp);
            $this->assertSame(0, $def->tags, 'chafa declares TAG_NONE; the WIDE tag comes from default tags');
            $this->assertSame(128 - $def->weight, $def->bgWeight());
            $this->assertSame(
                substr_count(SymbolSetKana::OUTLINES[$cp], 'X'),
                $def->weight,
                'joint weight is the popcount of both halves'
            );
        }
    }

    public function testTheKanaTierIsIdempotentAndNarrowMembershipStaysNarrowOnly(): void
    {
        $once = SymbolMap::new()->withKana();
        $twice = $once->withKana();

        $this->assertCount(181, $twice->wideDefs());
        $this->assertTrue($twice->hasKana());

        // chafa_symbol_map_has_symbol walks the narrow selection: kana is
        // symbols2-only, so the solid ladder and friends can never consult
        // a wide glyph (pinned here as the documented surface law).
        $this->assertFalse($twice->hasSymbol(0x3042));
    }

    /**
     * SYMBOL-LATIN: the wide twin of the latin append filter (W11-5) —
     * withKana() after a selection that already carries the wide tier
     * (`all` and `wide` both include WIDE) adds nothing, because chafa's
     * rebuild_symbols() codepoint-hash uniqueness lets a symbols2 glyph
     * appear at most once. The withKana flag still records withKana()-
     * built provenance only (latin precedent, unchanged).
     */
    public function testAppendingTheKanaTierToAWideCarryingSelectionAddsNoDuplicates(): void
    {
        foreach (['all', 'wide'] as $selector) {
            $selected = SymbolMap::new()->withSelectors($selector);
            $composed = $selected->withKana();

            $this->assertTrue($composed->hasKana());
            $this->assertCount(181, $composed->wideDefs(), "`{$selector}` already carries the kana tier: the append adds nothing");

            $seen = [];
            foreach ($composed->wideDefs() as $def) {
                $cp = mb_ord($def->char);
                $this->assertArrayNotHasKey($cp, $seen, 'no wide codepoint may appear twice');
                $seen[$cp] = true;
            }

            $this->assertEquals($selected->wideDefs(), $composed->wideDefs());
            $this->assertSame($selected->defs(), $composed->defs(), 'the narrow selection is untouched by the wide append');
        }
    }

    public function testWideDefsPackTheTwoHalvesIndependently(): void
    {
        // One X down the left column (half 0, pixel (0,y)) and one down the
        // right column (half 1, pixel (7,y)): each half packs its own 64-bit
        // MSB-first row-major bitmap, chafa's def_to_symbol rowstride 16.
        $both = WideSymbolDef::fromOutline16x8('x', implode('|', array_fill(0, 8, 'X' . str_repeat(' ', 14) . 'X')), 0);

        $this->assertSame(16, $both->weight, 'joint weight counts both halves');
        $this->assertSame(112, $both->bgWeight());
        $this->assertSame(-9187201950435737472, $both->bitsLeft, 'left half: bit 63-(8y) per row');
        $this->assertSame(72340172838076673, $both->bitsRight, 'right half: bit 56-(8y) per row');

        $this->expectException(\InvalidArgumentException::class);
        WideSymbolDef::fromOutline16x8('x', implode('|', array_fill(0, 7, '                ')), 0);
    }

    public function testTheGrammarReachesKanaThroughTheWideClassOnlyViaWideTags(): void
    {
        // chafa derives WIDE | ALPHA for the kana block (isalpha letters,
        // g_unichar_iswide) and never NARROW or AMBIGUOUS, so the `wide`
        // class and every tag that includes them selects the tier, while
        // `narrow` and `ambiguous` never carry kana into the wide pool.
        $wide = SymbolMap::new()->withSelectors('wide');
        $this->assertSame([], $wide->defs(), 'no narrow glyph carries WIDE');
        $this->assertCount(181, $wide->wideDefs());
        $this->assertFalse($wide->hasKana(), 'withSelectors rebuilds the selection; the withKana flag is its own seam (latin precedent)');

        $all = SymbolMap::new()->withSelectors('all');
        $this->assertCount(1251, $all->defs(), 'the narrow all-count is untouched by the wide tier');
        $this->assertCount(181, $all->wideDefs(), 'WIDE is inside all: ~(EXTRA|BAD)');

        // MAJOR-2 parity (audit l6): the generated braille/sextant/octant
        // tiers keep their raw tier tags — chafa's generators set sym->sc
        // directly (chafa-symbols.c:271, :331, :486) and never run
        // def_to_symbol — so they contribute NO NARROW. `narrow` selects
        // the hand-authored tiers only: 711 unique header codepoints less
        // the nine misc-narrow.h AMBIGUOUS BAD-vetoes = 702 (was 1,251
        // while the walk OR-ed derived tags onto the generated tiers).
        $narrow = SymbolMap::new()->withSelectors('narrow');
        $this->assertCount(702, $narrow->defs());
        $this->assertCount(0, $narrow->wideDefs(), 'kana never carries NARROW');

        $ambiguous = SymbolMap::new()->withSelectors('ambiguous');
        $this->assertCount(0, $ambiguous->wideDefs(), 'the kana block is not AMBIGUOUS (unlike CJK ideographs chafa would tag so)');

        $alpha = SymbolMap::new()->withSelectors('alpha');
        $this->assertCount(179, $alpha->wideDefs(), 'letters ride ALPHA; the two punctuation codepoints chafa compiles in (U+30A0 DOUBLE HYPHEN, U+30FB MIDDLE DOT) are not letters in either build');
    }

    public function testRelativeSelectorsAddAndDropTheWideTier(): void
    {
        $map = SymbolMap::new()->withKana();

        $dropped = $map->withSelectors('-wide');
        $this->assertCount(0, $dropped->wideDefs());
        $this->assertCount(676, $dropped->defs(), 'the narrow corpus is untouched by a wide-only removal');

        $added = SymbolMap::new()->withSelectors('+wide');
        $this->assertCount(181, $added->wideDefs());
        $this->assertCount(676, $added->defs());
        $this->assertSame(self::tuples($map->defs()), self::tuples($added->defs()));
        $this->assertFalse($added->hasKana());
    }

    /**
     * @param list<SymbolDef> $defs
     *
     * @return list<array{0:string, 1:int, 2:int}>
     */
    private static function tuples(array $defs): array
    {
        return array_map(static fn ($d): array => [$d->char, $d->bits, $d->tags], $defs);
    }

    public function testFindWideCandidatesMatchesItsOwnPairAtDistanceZero(): void
    {
        $kana = SymbolMap::new()->withKana();

        $samples = [0x3041, 0x3042, 0x3068, 0x3086, 0x30AB, 0x30AE, 0x30FC, 0x30FE];
        $defs = [];
        foreach ($kana->wideDefs() as $def) {
            $defs[mb_ord($def->char)] = $def;
        }

        foreach ($samples as $cp) {
            $def = $defs[$cp];
            $pick = $kana->findWideCandidates($def->bitsLeft, $def->bitsRight, true, 1);
            $this->assertCount(1, $pick, sprintf('U+%04X must find itself', $cp));
            $this->assertSame($cp, mb_ord($pick[0][0]->char), sprintf('U+%04X must win its own bitmap pair', $cp));
            $this->assertFalse($pick[0][1], 'a direct match wins before any inverse trial');
        }
    }

    public function testFindWideCandidatesScansInPopcountSortOrderNotBuildOrder(): void
    {
        // chafa's symbols2 array is sorted by compare_symbols2_popcount at
        // init; on an all-blank probe the winner is simply the lightest
        // glyph in the table — U+30FB at joint weight 6 (unique minimum,
        // verified against the header). Scan-in-build-order would surface
        // a different head, so this pins the sort law itself.
        $kana = SymbolMap::new()->withKana();

        $lightest = null;
        $lightestCp = 0;
        foreach ($kana->wideDefs() as $def) {
            if ($lightest === null || $def->weight < $lightest) {
                $lightest = $def->weight;
                $lightestCp = mb_ord($def->char);
            }
        }
        $this->assertSame(6, $lightest, 'U+30FB (KATAKANA MIDDLE DOT) weighs 6 in the table');

        $pick = $kana->findWideCandidates(0, 0, false, 1);
        $this->assertSame($lightestCp, mb_ord($pick[0][0]->char), 'the popcount-sorted scan must surface the lightest glyph');
    }

    public function testFindWideCandidatesInverseTrialsRideTheBufferAt128MinusHd(): void
    {
        $kana = SymbolMap::new()->withKana();

        $heaviest = null;
        $heaviestCp = 0;
        foreach ($kana->wideDefs() as $def) {
            if ($heaviest === null || $def->weight > $heaviest) {
                $heaviest = $def->weight;
                $heaviestCp = mb_ord($def->char);
            }
        }

        // Full-coverage probe (both halves all ones = -1): direct hd is
        // 128-weight, inverse hd is weight. allowInverse=false keeps the
        // heaviest glyph (smallest direct hd) — three glyphs tie at weight
        // 44 (U+3042/U+307D/U+30ED), and the popcount sort's codepoint
        // tie-break makes U+3042 the buffer head, pinning the sort too.
        // allowInverse=true flips the winner to the LIGHTEST glyph, whose
        // inverse trial enters the buffer at hd = its weight.
        $direct = $kana->findWideCandidates(-1, -1, false, 1);
        $this->assertSame($heaviestCp, mb_ord($direct[0][0]->char));
        $this->assertFalse($direct[0][1]);

        $inverse = $kana->findWideCandidates(-1, -1, true, 1);
        $this->assertSame(6, $inverse[0][0]->weight);
        $this->assertTrue($inverse[0][1], 'the lightest glyph must ride the buffer inverted');
    }

    public function testFindWideCandidatesRespectsTheWindowAndFailsFastOnBadLimits(): void
    {
        $kana = SymbolMap::new()->withKana();

        $eight = $kana->findWideCandidates(0, 0, true, 8);
        $this->assertCount(8, $eight);
        for ($i = 1; $i < \count($eight); $i++) {
            $this->assertLessThanOrEqual(
                $this->jointDistance($eight[$i][0], $eight[$i][1], 0, 0),
                $this->jointDistance($eight[$i - 1][0], $eight[$i - 1][1], 0, 0),
                'the window must stay sorted ascending by joint distance'
            );
        }
        $this->assertSame([], SymbolMap::new()->findWideCandidates(0, 0, true, 8), 'a narrow-only map has no candidates at all');

        $this->expectException(\InvalidArgumentException::class);
        $kana->findWideCandidates(0, 0, true, 0);
    }

    private function jointDistance(WideSymbolDef $def, bool $inverted, int $bitmapLeft, int $bitmapRight): int
    {
        $hd = self::pop64($bitmapLeft ^ $def->bitsLeft) + self::pop64($bitmapRight ^ $def->bitsRight);

        return $inverted ? 128 - $hd : $hd;
    }

    private static function pop64(int $x): int
    {
        $n = 0;
        for ($i = 0; $i < 64; $i++) {
            $n += ($x >> $i) & 1;
        }

        return $n;
    }
}
