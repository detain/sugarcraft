<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Symbol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Symbol\SymbolMap;

/**
 * Tier-mutator full state-carry — every tier ADDER composes onto the
 * current map, so narrow defs, the wide symbols2 selection, and the latin /
 * ascii-ibm face / kana flags all ride through. Before the fix,
 * withLatin() and withAsciiIbm() rebuilt the map without the wide tier:
 * withKana()->withLatin() silently dropped the 181-glyph kana selection
 * (the isomorphic withAsciiIbm() twin dropped it too). chafa's model is
 * that symbol sets are cumulative per map instance and the face swap is
 * orthogonal to the narrow/wide partition.
 *
 * withSelectors() is audited too, and it is NOT an adder: it rebuilds both
 * arrays through the tag grammar (chafa apply_selectors feeds both
 * init_symbol_array_* passes), so the flags reset by the documented F-17
 * provenance law while wide glyphs survive exactly the narrow tiers do —
 * reachable via `all`/`wide`/`alpha` (kana carries effective WIDE | ALPHA),
 * pruned by `block`. That reset is coherent and is pinned here as such.
 */
final class SymbolMapTierStateCarryTest extends TestCase
{
    public function testTheDefaultMapIsANarrowOnlyPrefixAndTheAddersKeepItSo(): void
    {
        $default = SymbolMap::new();
        $this->assertCount(676, $default->defs(), 'byte-frozen default corpus');
        $this->assertCount(0, $default->wideDefs());

        // The byte-freeze door: carrying an EMPTY wide selection through
        // the adders must never mint wide defs out of nothing.
        $this->assertSame([], $default->withLatin()->wideDefs());
        $this->assertSame([], $default->withAsciiIbm()->wideDefs());
        $this->assertFalse($default->withLatin()->hasKana());
        $this->assertFalse($default->withAsciiIbm()->hasKana());
    }

    public function testKanaThenLatinKeepsTheWideTier(): void
    {
        $composed = SymbolMap::new()->withKana()->withLatin();

        $this->assertTrue($composed->hasLatin());
        $this->assertTrue($composed->hasKana(), 'withLatin() must not drop the kana flag');
        $this->assertCount(181, $composed->wideDefs(), 'withLatin() must carry the wide selection');
        $this->assertCount(181, $composed->wideCorpus());
        $this->assertCount(676 + 365, $composed->defs());
        $this->assertNoDuplicateCodepoints($composed);
    }

    public function testTheLatinAndKanaAddersCommuteToTheIdenticalState(): void
    {
        $kanaFirst = SymbolMap::new()->withKana()->withLatin();
        $latinFirst = SymbolMap::new()->withLatin()->withKana();

        // chafa cumulative-sets semantics: order of independent tier
        // appends cannot change the resulting selection, only its history.
        // assertEquals, not assertSame: each chain mints its own def
        // instances; value equality is the state-carry claim.
        $this->assertEquals($latinFirst->defs(), $kanaFirst->defs());
        $this->assertEquals($latinFirst->wideDefs(), $kanaFirst->wideDefs());
        $this->assertSame($latinFirst->hasLatin(), $kanaFirst->hasLatin());
        $this->assertSame($latinFirst->hasKana(), $kanaFirst->hasKana());
        $this->assertNoDuplicateCodepoints($kanaFirst);
    }

    public function testKanaThenAsciiIbmKeepsTheWideTierAndTheFlags(): void
    {
        $composed = SymbolMap::new()->withKana()->withAsciiIbm();

        $this->assertTrue($composed->hasAsciiIbm());
        $this->assertTrue($composed->hasKana(), 'withAsciiIbm() must not drop the kana flag');
        $this->assertFalse($composed->hasLatin());
        $this->assertCount(181, $composed->wideDefs(), 'the face swap must carry the wide selection');
        $this->assertCount(676, $composed->defs(), 'the default corpus carries no ASCII slots: the face swap is a no-op there');
    }

    public function testTheFaceSwapResultIsIndependentOfTheWideTierItCarries(): void
    {
        $all = SymbolMap::new()->withSelectors('all');
        $ibmPlain = $all->withAsciiIbm();
        $ibmKana = $all->withKana()->withAsciiIbm();

        // Orthogonality: the narrow walk sees the identical face result…
        $this->assertEquals($ibmPlain->defs(), $ibmKana->defs());
        // …and it really is the IBM face (the ascii slots differ from the
        // regular selection)…
        $this->assertNotEquals($all->defs(), $ibmPlain->defs());
        // …while the composed map keeps the grammar-selected wide tier.
        $this->assertCount(181, $ibmPlain->wideDefs());
        $this->assertCount(181, $ibmKana->wideDefs());
        $this->assertTrue($ibmKana->hasKana());
        $this->assertTrue($ibmKana->hasAsciiIbm());
        $this->assertNoDuplicateCodepoints($ibmKana);
    }

    /**
     * withSelectors() rebuild contract (documented, NOT the adder defect):
     * flags reset to withKana()/withLatin() provenance, while the wide
     * SELECTION survives exactly as far as the grammar reaches it.
     */
    public function testSelectorsRebuildTheWideSelectionByReachabilityAndResetTheFlags(): void
    {
        $kana = SymbolMap::new()->withKana();

        // kana carries effective WIDE | ALPHA (declared NONE + default tags),
        // but ALPHA is derived from \p{L}: U+30A0 (GRAVE TONE MARK, Sk) and
        // U+30FB (KATAKANA MIDDLE DOT, Po) are letters to nobody, so the
        // alpha class reaches 179 of the 181 — the same tag-reachability
        // pruning the narrow tiers live under, applied to symbols2.
        $alpha = $kana->withSelectors('alpha');
        $unreached = [];
        foreach ($kana->wideDefs() as $def) {
            if (preg_match('/^\p{L}$/u', $def->char) !== 1) {
                $unreached[] = $def->char;
            }
        }
        $this->assertSame(["\u{30A0}", "\u{30FB}"], array_values($unreached));
        $this->assertCount(181 - count($unreached), $alpha->wideDefs(), 'alpha keeps every letter-shaped kana glyph');
        $this->assertFalse($alpha->hasKana(), 'the rebuild resets the provenance flag (F-17 law)');

        // `all` reaches every non-BAD class, WIDE included
        $all = $kana->withSelectors('all');
        $this->assertCount(181, $all->wideDefs());
        $this->assertFalse($all->hasKana());
        $this->assertFalse($all->hasLatin());

        // `block` cannot reach a kana tag: the same walk that prunes narrow
        // classes prunes the wide tier — a documented filter, not a drop.
        $block = $kana->withSelectors('block');
        $this->assertCount(0, $block->wideDefs());

        // relative selectors seed from the current selection, wide included
        $relative = $kana->withSelectors('+stipple');
        $this->assertCount(181, $relative->wideDefs(), 'a relative rebuild carries the current wide selection');
        $this->assertFalse($relative->hasKana(), 'relative or absolute, the result records selector provenance');
    }

    public function testTripleCompositionIsIdempotentAndDupFree(): void
    {
        $twice = SymbolMap::new()->withLatin()->withKana();
        $thrice = $twice->withLatin();

        // post-w11-5: the flag short-circuit returns the identical map,
        // and even across a selector-built base the codepoint filter keeps
        // every append dup-free.
        $this->assertSame($twice, $thrice);
        $this->assertSame($twice, $twice->withKana());

        $viaGrammar = SymbolMap::new()->withSelectors('all')->withLatin()->withKana();
        $this->assertCount(1251, $viaGrammar->defs());
        $this->assertCount(181, $viaGrammar->wideDefs());
        $this->assertTrue($viaGrammar->hasLatin());
        $this->assertTrue($viaGrammar->hasKana());
        $armed = $viaGrammar->withAsciiIbm();
        $this->assertNotSame($viaGrammar, $armed);
        $this->assertSame($armed, $armed->withLatin()->withKana()->withAsciiIbm());
        $this->assertNoDuplicateCodepoints($armed);
    }

    private function assertNoDuplicateCodepoints(SymbolMap $map): void
    {
        $seen = [];
        foreach ($map->defs() as $def) {
            $cp = mb_ord($def->char);
            $this->assertArrayNotHasKey($cp, $seen, sprintf('U+%04X appears twice in the narrow selection', $cp));
            $seen[$cp] = true;
        }
        foreach ($map->wideDefs() as $def) {
            $cp = mb_ord($def->char);
            $this->assertArrayNotHasKey($cp, $seen, sprintf('U+%04X collides across the narrow and wide selections', $cp));
            $seen[$cp] = true;
        }
    }
}
