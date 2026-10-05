<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Fuzzy\MatchResult;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;

/**
 * The "/" popup's filter on candy-fuzzy's `requireFullQuery` mode, plus its
 * per-(prefix, names) memo (crush_libs candy-fuzzy #2/#3).
 *
 * The hand-rolled coverage check it replaced compared the matched-index count
 * of plain mode's best LOCAL alignment with the prefix length, so an in-order
 * prefix whose best adjacent run was shorter than the whole query was dropped:
 * "/seo" found nothing although s-e-o is typed straight down "sessions". Full
 * query mode scores the best alignment that covers every typed character.
 */
final class CommandFilterFullQueryTest extends TestCase
{
    public function testAnAnchoredSpreadOutPrefixFindsItsCommand(): void
    {
        self::assertSame(['sessions'], self::names(CommandRegistry::filter('seo')));
        self::assertSame(['permissions'], self::names(CommandRegistry::filter('pns')));
        self::assertSame(['rewind'], self::names(CommandRegistry::filter('rwd')), 'the typo-tolerant case still holds');
    }

    public function testEverySurvivorCoversTheWholePrefixFromTheNamesFirstCharacter(): void
    {
        foreach (['re', 'se', 'seo', 'pro', 'ag', 'mo', 'c'] as $prefix) {
            $results = CommandRegistry::filterMatchResults($prefix);
            self::assertNotSame([], $results, $prefix);

            foreach ($results as $result) {
                self::assertCount(mb_strlen($prefix), $result->matchedIndices, "/{$prefix} vs {$result->haystack}");
                self::assertSame(0, $result->matchedIndices[0], "/{$prefix} is anchored at the start of {$result->haystack}");
            }
        }
    }

    public function testThePartialHitAndTheUnanchoredHitStayOut(): void
    {
        // "agents" carries the "e" of "re" (a partial local alignment), and
        // "rules"/"rewind" carry "es"/"e…d" — none starts with "e".
        self::assertNotContains('agents', self::names(CommandRegistry::filter('re')));
        self::assertSame([], CommandRegistry::filter('es'));
        self::assertSame([], CommandRegistry::filter('xq'));
    }

    public function testARepeatedPrefixIsServedFromTheMemoNotRealigned(): void
    {
        $first = CommandRegistry::filterMatchResults('re');
        $again = CommandRegistry::filterMatchResults('re');

        self::assertNotSame([], $first);
        foreach ($first as $i => $result) {
            self::assertSame($result, $again[$i], 'the same MatchResult instance: aligned once per keystroke, not per call');
        }
    }

    public function testADifferentRowSetIsNeverServedAnotherSetsResults(): void
    {
        $registry = CommandRegistry::filterMatchResults('re');
        self::assertContains('rename', array_map(static fn (MatchResult $r): string => $r->haystack, $registry));

        $custom = [
            CommandSpec::new('review', 'Review the diff', 'Custom'),
            CommandSpec::new('release', 'Cut a release', 'Custom'),
        ];
        self::assertSame(['release', 'review'], self::names(CommandRegistry::filter('re', $custom)));

        // And back: the registry list is recomputed, not answered from the custom set.
        self::assertSame(['recompress', 'redo', 'rename', 'rewind', 'rules'], self::names(CommandRegistry::filter('re')));
    }

    /**
     * The anchor is a property of the name, not of the one alignment the
     * matcher reports: for "/ab" the adjacent "ab" run at [3, 4] of "a__ab"
     * outscores the spread-out [0, 4], and reading only that best alignment
     * dropped a name that plainly starts with "a" and has a "b" after it.
     */
    public function testANameWithAnAnchoredPlacementSurvivesALaterHigherScoringRun(): void
    {
        $rows = [CommandSpec::new('a__ab', 'Anchored but outscored', 'Custom')];

        self::assertSame(['a__ab'], self::names(CommandRegistry::filter('ab', $rows)));

        $results = CommandRegistry::filterMatchResults('ab', $rows);
        self::assertCount(1, $results);
        self::assertSame([0, 4], $results[0]->matchedIndices, 'the popup highlights the anchored placement it admitted the name by');
        self::assertSame('ab', $results[0]->needle);
    }

    public function testTheAnchoredPlacementCoversAMultiCharacterRestAndFoldsCase(): void
    {
        $rows = [
            CommandSpec::new('a_bc_abc', 'Anchored, rest spread', 'Custom'),
            CommandSpec::new('A__ab', 'Upper-case anchor', 'Custom'),
        ];

        $abc = CommandRegistry::filterMatchResults('abc', $rows);
        self::assertSame(['a_bc_abc'], array_map(static fn (MatchResult $r): string => $r->haystack, $abc));
        self::assertSame([0, 2, 3], $abc[0]->matchedIndices);

        $ab = CommandRegistry::filterMatchResults('ab', $rows);
        self::assertContains('A__ab', array_map(static fn (MatchResult $r): string => $r->haystack, $ab));
        foreach ($ab as $result) {
            self::assertCount(2, $result->matchedIndices, $result->haystack);
            self::assertSame(0, $result->matchedIndices[0], $result->haystack);
        }
    }

    public function testANameWithNoAnchoredPlacementStaysOut(): void
    {
        $rows = [
            CommandSpec::new('xab', 'Contains it, does not start with it', 'Custom'),
            CommandSpec::new('ba__b', 'Starts with the wrong character', 'Custom'),
            CommandSpec::new('a__ba', 'Anchor, then a later b', 'Custom'),
            CommandSpec::new('a', 'Shorter than the query', 'Custom'),
        ];

        // "a__ba": the "b" after the anchor exists, so it stays; the others
        // either do not start with "a" or have no "b" after their first "a".
        self::assertSame(['a__ba'], self::names(CommandRegistry::filter('ab', $rows)));
        self::assertSame([], CommandRegistry::filter('abz', $rows));
    }

    public function testRecoveringAnAnchoredNameKeepsTheMatchersScoreAndOrder(): void
    {
        $rows = [
            CommandSpec::new('a-b', 'Best alignment already anchored', 'Custom'),
            CommandSpec::new('a__ab', 'Recovered by its anchored placement', 'Custom'),
            CommandSpec::new('abc', 'Literal prefix', 'Custom'),
        ];
        $names = array_map(static fn (CommandSpec $spec): string => $spec->name, $rows);

        $ranked = SmithWatermanMatcher::new(requireFullQuery: true)->matchAll('ab', $names);
        $results = CommandRegistry::filterMatchResults('ab', $rows);

        self::assertSame(
            array_map(static fn (MatchResult $r): array => [$r->haystack, $r->score], $ranked),
            array_map(static fn (MatchResult $r): array => [$r->haystack, $r->score], $results),
            'admission changes, ranking does not: same order, same scores as the matcher',
        );
    }

    public function testARecoveredResultIsMemoizedLikeAnyOther(): void
    {
        $rows = [CommandSpec::new('a__ab', 'Recovered', 'Custom')];

        $first = CommandRegistry::filterMatchResults('ab', $rows);
        $again = CommandRegistry::filterMatchResults('ab', $rows);

        self::assertCount(1, $first);
        self::assertSame($first[0], $again[0]);
    }

    /**
     * @param list<CommandSpec> $specs
     * @return list<string>
     */
    private static function names(array $specs): array
    {
        return array_map(static fn (CommandSpec $spec): string => $spec->name, $specs);
    }
}
