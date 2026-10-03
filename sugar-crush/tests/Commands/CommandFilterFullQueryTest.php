<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Fuzzy\MatchResult;

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
        self::assertSame(['rename', 'rewind', 'rules'], self::names(CommandRegistry::filter('re')));
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
