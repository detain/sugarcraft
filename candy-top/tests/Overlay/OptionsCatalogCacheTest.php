<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Overlay;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Overlay\OptionsCatalog;

/**
 * Finding 154 (S-X19): the catalog lists are a constant per gpu flag and
 * are now derived once, so every caller shares one value. The pins below
 * hold the contract the cache must never break: the answers are stable in
 * both gpu states, exhaustive over the categories, and callers cannot
 * corrupt the shared lists by editing what they were handed (PHP value
 * semantics make that true — the test exists so a future switch to
 * objects or references fails loudly instead of silently).
 */
final class OptionsCatalogCacheTest extends TestCase
{
    public function testTheAnswersAreStableAcrossRepeatedDerivations(): void
    {
        foreach ([true, false] as $gpu) {
            self::assertSame(OptionsCatalog::categories($gpu), OptionsCatalog::categories($gpu));
            foreach (OptionsCatalog::categories($gpu) as $cat) {
                self::assertSame(
                    OptionsCatalog::entries($cat, $gpu),
                    OptionsCatalog::entries($cat, $gpu),
                    $cat . ' must answer identically on every pass',
                );
            }
            self::assertSame(OptionsCatalog::maxItems($gpu), OptionsCatalog::maxItems($gpu));
        }
    }

    public function testTheGpuFlagStillDecidesTheGpuSurfaces(): void
    {
        // The cache must not flatten the flag: with a GPU the tab list and
        // the general entries carry the gpu surfaces; without, they do not.
        self::assertContains('gpu', OptionsCatalog::categories(true));
        self::assertNotContains('gpu', OptionsCatalog::categories(false));
        self::assertContains('graph_symbol_gpu', OptionsCatalog::entries('general', true));
        self::assertNotContains('graph_symbol_gpu', OptionsCatalog::entries('general', false));
        self::assertGreaterThanOrEqual(
            OptionsCatalog::maxItems(false),
            OptionsCatalog::maxItems(true),
            'the gpu tab can only ever grow the largest tab',
        );
    }

    public function testEditingAHandedOutListNeverCorruptsTheNextAnswer(): void
    {
        $first = OptionsCatalog::categories(true);
        $handed = $first;
        array_shift($handed);
        self::assertSame($first, OptionsCatalog::categories(true));

        $entries = OptionsCatalog::entries('general', true);
        $tampered = $entries;
        $tampered[] = '@invented-heading';
        self::assertSame($entries, OptionsCatalog::entries('general', true));
    }
}
