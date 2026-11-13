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
 * instead of silently starting the wrong game. The diagnostic `--version`
 * short-circuits in the binary before this contract runs (it names no mode).
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
            $extras = array_slice($flags, 1);
            $listed = '"' . implode('", "', array_map(strval(...), $extras)) . '"';

            // One localized key (candy-tetris-033): the count rides a
            // plural-safe frame in every locale, so the English-only
            // 'argument%s' 's' append and the hardcoded sentence are gone.
            throw new InvalidArgumentException(Lang::t('launch.extras', [
                'count' => (string) count($extras),
                'list' => $listed,
            ]));
        }

        $flag = $flags[0];

        if (in_array($flag, self::VS_FLAGS, true)) {
            return self::VS;
        }

        if (in_array($flag, self::HELP_FLAGS, true)) {
            return self::HELP;
        }

        throw new InvalidArgumentException(Lang::t('mode.unknown', ['flag' => (string) $flag]));
    }

    /**
     * The usage screen, shared by the help exit and the unknown-flag refusal so
     * the two can never drift apart.
     */
    public static function helpText(): string
    {
        return Lang::t('help_text');
    }
}