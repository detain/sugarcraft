<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Palette\PaletteState;
use SugarCraft\Fuzzy\MatchResult;

/**
 * The Ctrl+P palette's filter on candy-fuzzy's `requireFullQuery` mode, plus
 * its per-(query, labels) memo (crush_libs candy-fuzzy #2/#3).
 *
 * The palette used to hand the query to a plain local-alignment matcher with
 * no coverage check at all, so any label sharing one character with the query
 * was listed: "xq" offered "Exit" on its "x", and "mdl" offered seven rows.
 */
final class PaletteFullQueryMatchTest extends TestCase
{
    public function testALabelMatchingOnlySomeQueryCharactersIsNotListed(): void
    {
        self::assertSame([], self::palette('root', 'xq')->paletteMatches(), '"Exit" carries the x but not the q');
        self::assertSame(['Switch model'], self::palette('root', 'mdl')->paletteMatches());
    }

    public function testInitialsStyleQueriesStillMatch(): void
    {
        self::assertContains('Switch model', self::palette('root', 'swm')->paletteMatches());
        self::assertContains('Dock pane left', self::palette('root', 'dpl')->paletteMatches());
        // Listed, not FIRST: since the settings view's "View settings" row
        // (N-P1), the in-word `ngs` run of "settings" scores above the two
        // word initials of "New session" for `ns`. What this test pins is
        // that the initials still match; the ranking between the two is the
        // matcher's word-boundary weighting, not the palette's coverage rule.
        self::assertContains('New session', self::palette('root', 'ns')->paletteMatches());
    }

    public function testEveryRowHighlightsEveryQueryCharacter(): void
    {
        foreach (['s', 'se', 'swi', 'th', 'pane'] as $query) {
            $results = self::palette('root', $query)->paletteMatchResults();
            self::assertNotSame([], $results, $query);

            foreach ($results as $result) {
                self::assertCount(mb_strlen($query), $result->matchedIndices, "'{$query}' vs '{$result->haystack}'");
            }
        }
    }

    public function testARepeatedQueryIsServedFromTheMemoNotRealigned(): void
    {
        $chat = self::palette('root', 'sw');

        $first = $chat->paletteMatchResults();
        $again = $chat->paletteMatchResults();

        self::assertNotSame([], $first);
        foreach ($first as $i => $result) {
            self::assertSame($result, $again[$i], 'the same MatchResult instance: aligned once per keystroke, not once per call');
        }
        self::assertSame(
            array_map(static fn (MatchResult $r): string => $r->haystack, $first),
            self::palette('root', 'sw')->paletteMatches(),
            'a fresh Chat over the same labels reads the same answer',
        );
    }

    public function testAModeSwitchNeverServesAnotherLabelSetsResults(): void
    {
        $root = self::palette('root', 'e')->paletteMatches();
        $themes = self::palette('themes', 'e')->paletteMatches();

        self::assertContains('Exit', $root);
        self::assertNotContains('Exit', $themes, 'the theme list was matched itself, not answered from the root memo');
        self::assertSame($root, self::palette('root', 'e')->paletteMatches(), 'and switching back recomputes the root list');
    }

    public function testANumericQueryRoundTripsThroughTheMemo(): void
    {
        // A numeric-string key becomes an int array key; it must still hit.
        $chat = self::palette('root', '1');

        self::assertSame($chat->paletteMatches(), $chat->paletteMatches());
    }

    private static function palette(string $mode, string $query): Chat
    {
        return new Chat(palette: new PaletteState($mode, $query, 0));
    }
}
