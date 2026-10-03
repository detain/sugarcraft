<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tui\TerminalBackground;
use SugarCraft\Sprinkles\Theme as SprinklesTheme;

/**
 * Pins what {@see TerminalBackground::detect()} and sugar-crush's own
 * {@see \SugarCraft\Crush\Theme::adaptive()} docblocks say about
 * candy-sprinkles' {@see SprinklesTheme::adaptive()}.
 *
 * Both used to say the sprinkles rule was inverted (any index `>= 8` light)
 * and could not read rxvt's three-field `COLORFGBG`. candy-sprinkles
 * 1845eebf9 fixed that, so the comments now claim the two rules agree on every
 * value carrying a `;` and part on exactly one shape — a lone index with no
 * `;`. If either side moves, this goes red and the comments get re-read
 * rather than quietly rotting the way the old ones did.
 */
final class TerminalBackgroundSprinklesAgreementTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function sharedValues(): iterable
    {
        foreach ([
            'empty' => '',
            'default pair' => 'default;default',
            'no separator, not a number' => 'nonsense',
            'white bg (15)' => '0;15',
            'plain white bg (7)' => '0;7',
            'black bg' => '15;0',
            'bright-black bg (8)' => '15;8',
            'greyscale top (255)' => '0;255',
            'cube white (231)' => '0;231',
            'near-black ramp (232)' => '15;232',
            'out of range' => '0;256',
            'rxvt three-field light' => '0;default;15',
            'rxvt three-field dark' => '15;default;0',
            'empty foreground' => ';15',
            'empty background' => '15;',
        ] as $label => $value) {
            yield $label => [$value];
        }
    }

    #[DataProvider('sharedValues')]
    public function testBothRulesAgreeOnEveryValueCarryingASeparatorOrNoIndex(string $colorfgbg): void
    {
        self::assertSame(
            TerminalBackground::detect(['COLORFGBG' => $colorfgbg]),
            self::sprinklesSaysDark($colorfgbg),
            "COLORFGBG='{$colorfgbg}': TerminalBackground::detect() and SprinklesTheme::adaptive() disagree — the docblocks claiming agreement are stale",
        );
    }

    /**
     * The one divergence the comments name: a bare index with no `;`.
     * sugar-crush reads it as the background; sprinkles (after termenv)
     * says it names no background and falls back to dark.
     */
    public function testALoneIndexIsTheOneShapeTheRulesReadDifferently(): void
    {
        self::assertFalse(TerminalBackground::detect(['COLORFGBG' => '15']), 'sugar-crush reads a lone 15 as a white background');
        self::assertTrue(self::sprinklesSaysDark('15'), 'sprinkles reads a lone index as no background, so dark');

        self::assertTrue(TerminalBackground::detect(['COLORFGBG' => '0']));
        self::assertTrue(self::sprinklesSaysDark('0'), 'a lone dark index agrees by coincidence of the fallback');
    }

    /**
     * SprinklesTheme::adaptive() reads the process environment itself, so the
     * value has to be put there; the previous value (or its absence) is
     * restored whatever happens.
     */
    private static function sprinklesSaysDark(string $colorfgbg): bool
    {
        $previous = getenv('COLORFGBG');
        putenv('COLORFGBG=' . $colorfgbg);
        try {
            $theme = SprinklesTheme::adaptive();
        } finally {
            putenv(is_string($previous) ? 'COLORFGBG=' . $previous : 'COLORFGBG');
        }

        $dark = SprinklesTheme::dark();
        $light = SprinklesTheme::light();
        self::assertNotEquals($dark, $light, 'the dark and light presets became equal, so this probe cannot tell them apart');
        self::assertTrue($theme == $dark || $theme == $light, 'SprinklesTheme::adaptive() returned neither preset');

        return $theme == $dark;
    }
}
