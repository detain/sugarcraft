<?php

declare(strict_types=1);

namespace SugarCraft\Tetris;

use InvalidArgumentException;

/**
 * Resolves the command line of `bin/candy-tetris` into a launch mode.
 *
 * The accepted flags are exactly the ones the README and the binary's own
 * help text promise: `-v`/`--vs` for VS Computer mode, `-h`/`--help` for the
 * usage screen, and no flag at all for single player. Anything else — including
 * bare `v`/`h`, which the parser shipped before this fix accepted while no
 * document ever mentioned them — is refused by name so a typo exits loudly
 * instead of silently starting the wrong game.
 *
 * Parsing lives here (not inline in the binary) so the whole argv contract is
 * unit-testable without constructing a terminal-backed Program.
 */
final class LaunchMode
{
    /** Start the single player game. */
    public const SINGLE = 'single';

    /** Start the VS Computer game. */
    public const VS = 'vs';

    /** Print the usage screen and exit. */
    public const HELP = 'help';

    private const VS_FLAGS = ['-v', '--vs'];

    private const HELP_FLAGS = ['-h', '--help'];

    /**
     * @param list<string> $argv Full argument vector (script name at index 0)
     *
     * @throws InvalidArgumentException when a flag is unknown or more than one argument is given
     */
    public static function resolve(array $argv): string
    {
        $flags = array_slice($argv, 1);

        if ($flags === []) {
            return self::SINGLE;
        }

        if (count($flags) > 1) {
            throw new InvalidArgumentException(sprintf(
                'candy-tetris: unexpected argument "%s" (at most one flag is accepted)',
                (string) $flags[1],
            ));
        }

        $flag = $flags[0];

        if (in_array($flag, self::VS_FLAGS, true)) {
            return self::VS;
        }

        if (in_array($flag, self::HELP_FLAGS, true)) {
            return self::HELP;
        }

        throw new InvalidArgumentException(sprintf('candy-tetris: unknown mode "%s"', (string) $flag));
    }

    /**
     * The usage screen, shared by the help exit and the unknown-flag refusal so
     * the two can never drift apart.
     */
    public static function helpText(): string
    {
        return "candy-tetris - Tetris clone\n\n"
            . "Usage: candy-tetris [options]\n"
            . "  (no args)   Start single player game\n"
            . "  -v, --vs    Start VS Computer mode\n"
            . "  -h, --help  Show this help\n";
    }
}