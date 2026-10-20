<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Symbol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Symbol\SymbolMap;
use SugarCraft\Image\Symbol\SymbolSetTables;
use SugarCraft\Image\Symbol\SymbolTags;

/**
 * SYMBOL-TIER: ascii-ibm — chafa's orphaned IBM ASCII face, made live as
 * a codepoint-identical swap for the ascii tier (W11 narrow-tiers lane).
 *
 * chafa SHIPS chafa/internal/chafa-symbols-ascii-ibm.h (95 entries, U+0020
 * U+007E, IBM PC/VGA 8x8 font via Daniel Hepper's public-domain font8x8)
 * but never #includes it: the symbol_defs[] list in chafa-symbols.c:150-157
 * names only ascii/latin/block/kana/misc-narrow, and the tag enum has no
 * IBM bit. Its codepoints collide 1:1 with chafa-symbols-ascii.h, and
 * chafa's codepoint-keyed corpus hosts ONE face per map, so the faithful
 * port shape is the opt-in face swap SymbolMap::withAsciiIbm() — never a
 * selector class (that would invent chafa vocabulary and break the 1:1
 * SELECTOR_NAMES↔parse_symbol_tag correspondence; kana-tier precedent).
 *
 * Pins: whole-table digest (regenerated from the header, never re-pinned
 * by hand), the verbatim tag census, face distinctness spot-reads, and
 * grammar behavior under the swap — all=1,251 effective, default corpus
 * byte-identical (the frozen render digests stay untouched because the
 * default map carries no ascii codepoints and chafa's own default selects
 * only BLOCK/BORDER/SPACE, chafa-canvas-config.c:73-75).
 */
final class SymbolSetAsciiIbmTest extends TestCase
{
    /**
     * @return array<int, array{0:string, 1:int}> codepoint => [outline, tags]
     */
    private static function ibm(): array
    {
        return SymbolSetTables::asciiIbm();
    }

    /**
     * @param list<\SugarCraft\Image\Symbol\SymbolDef> $defs
     */
    private static function digest(array $defs): string
    {
        return sha1(implode("\n", array_map(
            static fn($d): string => sprintf('U+%04X:%d:%d', mb_ord($d->char), $d->bits, $d->tags),
            $defs,
        )));
    }

    public function testTheWholeTableIsDigestPinnedAgainstTheChafaHeader(): void
    {
        // Authoring oracle: the table was transcribed mechanically from
        // chafa/internal/chafa-symbols-ascii-ibm.h:55-1288 @ 413b76e4d. This
        // digest freezes every pixel and every declared tag of the
        // transcription so a hand-edit or partial re-transcription reddens
        // here; the fix is always to re-generate from the header, never to
        // re-pin this literal.
        $lines = [];
        foreach (self::ibm() as $cp => [$outline, $tags]) {
            $lines[] = sprintf('U+%04X:%s:%d', $cp, $outline, $tags);
        }

        $this->assertSame('e4aeddc5b5f7bf9dcce7c609a2b3a4445c3bfe95', sha1(implode("\n", $lines)));
    }

    public function testTheTableIsChafasNinetyFiveGlyphIbmAsciiFace(): void
    {
        $table = self::ibm();
        $this->assertCount(95, $table);
        $this->assertSame(range(0x20, 0x7E), array_keys($table));

        foreach ($table as $cp => [$outline, $tags]) {
            $rows = explode('|', $outline);
            $this->assertCount(8, $rows, sprintf('U+%04X must have 8 rows', $cp));
            foreach ($rows as $row) {
                $this->assertSame(8, strlen($row), sprintf('U+%04X row "%s" must be 8 cells', $cp, $row));
                $this->assertSame(0, preg_match('/[^ X]/', $row), sprintf('U+%04X row "%s" must be spaces and X only', $cp, $row));
            }
            if ($cp !== 0x20) {
                $this->assertNotSame('', trim(str_replace('|', '', $outline)), sprintf('U+%04X must paint something', $cp));
            }
            $this->assertNotSame(0, $tags, sprintf('U+%04X must carry declared tags', $cp));
        }

        // The one blank glyph is the space, exactly like every other chafa table.
        $this->assertSame(str_repeat('        |', 7) . '        ', $table[0x20][0]);
    }

    public function testDeclaredTagsMirrorTheHeaderVerbatimNotTheAsciiTable(): void
    {
        // chafa-symbols-ascii.h declares ASCII|LATIN for all 94 printable
        // entries (ASCII symbols "also count as Latin" per that header); the
        // ibm header declares ASCII, ALPHA (A-Z/a-z), DIGIT (0-9) and SPACE —
        // NO LATIN bit. Verbatim transcription keeps the difference, and it
        // is pinned, not smoothed over: under the face swap the 'latin'
        // selector no longer reaches the ascii codepoints (see the
        // grammar test). Every table tag is a subset of the declared set.
        $expected = [0x20 => SymbolTags::ASCII | SymbolTags::SPACE];
        foreach (range(0x30, 0x39) as $cp) {
            $expected[$cp] = SymbolTags::ASCII | SymbolTags::DIGIT;
        }
        foreach ([range(0x41, 0x5A), range(0x61, 0x7A)] as $letters) {
            foreach ($letters as $cp) {
                $expected[$cp] = SymbolTags::ASCII | SymbolTags::ALPHA;
            }
        }
        foreach ($expected as $cp => $tags) {
            $this->assertSame($tags, self::ibm()[$cp][1], sprintf('U+%04X declared tags', $cp));
        }
        foreach (self::ibm() as $cp => [, $tags]) {
            if (isset($expected[$cp])) {
                continue;
            }
            $this->assertSame(SymbolTags::ASCII, $tags, sprintf('U+%04X must be plain ASCII', $cp));
        }
    }

    public function testTheIbmFaceDiffersFromTheAsciiTableEverywhereButTheSpace(): void
    {
        $ibm = self::ibm();
        $ascii = SymbolSetTables::ascii();

        $differing = 0;
        foreach ($ibm as $cp => [$outline, $tags]) {
            if ($outline !== $ascii[$cp][0]) {
                $differing++;
            }
        }
        $this->assertSame(94, $differing, 'only the blank space may agree across faces');

        // Spot-read straight off the C headers: '!' is a two-pixel-wide IBM
        // cross (chafa-symbols-ascii-ibm.h:71-78) against ascii.h's single
        // vertical stroke (chafa-symbols-ascii.h:45-54).
        $this->assertSame(
            '   XX   |  XXXX  |  XXXX  |   XX   |   XX   |        |   XX   |        ',
            $ibm[0x21][0],
        );
        $this->assertSame(
            '        |   X    |   X    |   X    |   X    |        |   X    |        ',
            $ascii[0x21][0],
        );
    }

    public function testWithAsciiIbmFlagsTheMapAndLeavesTheDefaultCorpusByteIdentical(): void
    {
        // The default corpus (676 defs, U+2500+) carries no ascii codepoints,
        // so the swap is a value-identical no-op there — the frozen renderer
        // digests (24829bdb…, 47d7e3d7… family) cannot move.
        $map = SymbolMap::new();
        $plain = $map->defs();
        $swapped = $map->withAsciiIbm();

        $this->assertFalse($map->hasAsciiIbm());
        $this->assertTrue($swapped->hasAsciiIbm());
        $this->assertSame($plain, $swapped->defs());
        $this->assertSame('36bb73aa5806d8a3e513872cc8ea64cc0269de2f', self::digest($plain));
    }

    public function testTheSelectorGrammarSelectsTheFaceAndStaysCountStable(): void
    {
        $plain = SymbolMap::new()->withSelectors('ascii');
        $faceBothOrders = [
            SymbolMap::new()->withAsciiIbm()->withSelectors('ascii'),
            SymbolMap::new()->withSelectors('ascii')->withAsciiIbm(),
        ];

        $this->assertCount(95, $plain->defs());
        $this->assertSame('7980112f51a510605fa043420cc7a26b9fdaaa66', self::digest($plain->defs()));
        foreach ($faceBothOrders as $i => $face) {
            $this->assertCount(95, $face->defs(), "order $i");
            $this->assertSame('96655601e51e831a2d35988d54964b68bf876ed1', self::digest($face->defs()), "order $i");
            $this->assertTrue($face->hasAsciiIbm(), "order $i");
        }

        // Effective-corpus math is face-independent: the AMBIGUOUS BAD-vetoes
        // live in misc-narrow, so 'all' selects 1,251 of 1,260 under either
        // face (chafa char_is_selected semantics, w10-2fx pin family).
        $this->assertCount(1251, SymbolMap::new()->withSelectors('all')->defs());
        $this->assertCount(1251, SymbolMap::new()->withAsciiIbm()->withSelectors('all')->defs());

        // ALPHA/DIGIT still reach the face via chafa's default-tag law even
        // though the ibm header spells them verbatim too; 'digit' under the
        // face is exactly 0-9.
        $this->assertSame(range(0x30, 0x39), array_map(
            static fn($d): int => mb_ord($d->char),
            SymbolMap::new()->withAsciiIbm()->withSelectors('digit')->defs(),
        ));
        $this->assertCount(
            count(SymbolMap::new()->withSelectors('alpha')->defs()),
            SymbolMap::new()->withAsciiIbm()->withSelectors('alpha')->defs(),
        );

        // Disclosed verbatim-transcription consequence: ascii.h declares
        // LATIN, the ibm header does not, so the 'latin' selector count
        // drops by the 95 ascii glyphs the plain face contributed to it.
        $this->assertCount(
            count(SymbolMap::new()->withAsciiIbm()->withSelectors('latin')->defs()) + 95,
            SymbolMap::new()->withSelectors('latin')->defs(),
        );
    }

    public function testTheFaceSurvivesSelectorsAndComposesWithTheLatinTier(): void
    {
        $braille = SymbolMap::new()->withAsciiIbm()->withSelectors('braille');
        $this->assertTrue($braille->hasAsciiIbm(), 'face is not an appended tier; withSelectors must preserve it');
        $this->assertCount(256, $braille->defs());

        $this->assertTrue(SymbolMap::new()->withLatin()->withAsciiIbm()->hasLatin());
        $faceLatin = SymbolMap::new()->withAsciiIbm()->withLatin();
        $this->assertTrue($faceLatin->hasAsciiIbm());
        $this->assertCount(676 + 365, $faceLatin->defs());

        // Idempotent: a face-swapped map returns itself.
        $swapped = SymbolMap::new()->withSelectors('ascii')->withAsciiIbm();
        $this->assertSame($swapped, $swapped->withAsciiIbm());
    }

    public function testFullCorpusWithoutTheFaceStaysTheW10Digest(): void
    {
        // The base (ascii.h-face) full corpus reached through the grammar is
        // digest-pinned: every existing consumer, renderer and golden that
        // selects through withSelectors() keeps the exact bytes it shipped
        // with in W10. Movement here means the face swap leaked into the
        // default path — fix the engine, never re-pin this literal.
        $this->assertSame(
            '255e3dad20bd4b3c1febe5c12de432eaaa874252',
            self::digest(SymbolMap::new()->withSelectors('all')->defs()),
        );
        $this->assertNotSame(
            '255e3dad20bd4b3c1febe5c12de432eaaa874252',
            self::digest(SymbolMap::new()->withAsciiIbm()->withSelectors('all')->defs()),
        );
    }
}
