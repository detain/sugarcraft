<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use SugarCraft\QuickPick\QuickPick;

/**
 * Doc-drift guard for the flagship fuzzy-ranking README example (-004).
 *
 * The example claims typing `tml` keeps ONLY `terminal`. That is true
 * exactly while the matcher is constructed with `requireFullQuery: true`;
 * the default (false) also keeps PARTIAL subsequence answers — 'editor'
 * (carrying t) and 'compiler' (carrying m, l) survived too, so the doc's
 * flagship contradicted its own prose. Both halves are pinned here: the
 * behaviour on the example's exact data, and the README's exact text.
 */
final class ReadmeExampleTest extends TestCase
{
    public function testFuzzyRankingFlagshipExampleKeepsOnlyTerminal(): void
    {
        $pick = QuickPick::new(['terminal', 'editor', 'compiler'])
            ->setRanker(new SmithWatermanMatcher(requireFullQuery: true))
            ->setMatchStyle("\e[1m")
            ->show()
            ->type('tml');

        self::assertSame(
            ['terminal'],
            \array_map(static fn($item): string => $item->value(), $pick->items()),
            'the example corpus must answer exactly [terminal] — anything else and the README prose lies',
        );
    }

    public function testReadmeFuzzyExampleCarriesTheFullQueryFlag(): void
    {
        $readme = \file_get_contents(\dirname(__DIR__) . '/README.md');
        self::assertIsString($readme);

        self::assertStringContainsString(
            'new SmithWatermanMatcher(requireFullQuery: true)',
            $readme,
            'the flagship example must construct the matcher with the full-query flag',
        );
        // The un-flagged construction that kept editor+compiler alive must
        // not creep back into the README.
        self::assertStringNotContainsString(
            'new SmithWatermanMatcher())',
            $readme,
        );
    }
}
