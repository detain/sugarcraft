<?php

declare(strict_types=1);

namespace SugarCraft\Tetris;

use InvalidArgumentException;

/**
 * Resolves the command line of `bin/candy-tetris` into a launch mode.
 *
 * The accepted flags are exactly the ones the README and the binary's own
 * help text promise: `-v`/`--vs` for VS Computer mode, `-h`/`--help` for the
 * usage screen, and no flag at all for single player. Alongside any of those
 * rides at most one `--seed=N` (candy-tetris-018): it names no mode, it only
 * pins the run's RNG streams so `--seed=N` replays piece-for-piece. Anything
 * else — including bare `v`/`h`, which the parser shipped before this fix
 * accepted while no document ever mentioned them, and any token merely
 * LOOKING like a seed (`--seed`, `--seed=x`, a second `--seed=`) — is refused
 * by name so a typo exits loudly instead of silently starting the wrong game.
 * The second `--seed` reaching the extras refusal is deliberate: it is an
 * unexpected extra argument, and the refusal names exactly the token the user
 * typed. The diagnostic `--version` short-circuits in the binary before this
 * contract runs (it names no mode).
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

    /** `--seed=N` with an optional sign — the ONLY shape that carries a seed. */
    private const SEED_TOKEN = '/^--seed=(-?\d+)$/';

    /**
     * @param list<string> $argv Full argument vector (script name at index 0)
     *
     * @throws InvalidArgumentException when a flag is unknown or more than one argument is given
     */
    public static function resolve(array $argv): string
    {
        return self::parse($argv)[0];
    }

    /**
     * The `--seed=N` value, or null when the run takes fresh entropy
     * (candy-tetris-018). Parses the same grammar as {@see self::resolve()},
     * so the two views of argv can never drift apart.
     *
     * @param list<string> $argv
     */
    public static function resolveSeed(array $argv): ?int
    {
        return self::parse($argv)[1];
    }

    /**
     * One pass over argv: the mode token (extras refuse inside) plus the
     * single seed token, if any.
     *
     * @param list<string> $argv
     *
     * @return array{0: string, 1: ?int}
     */
    private static function parse(array $argv): array
    {
        $seed = null;
        $modeFlags = [];

        foreach (array_slice($argv, 1) as $token) {
            if ($seed === null && preg_match(self::SEED_TOKEN, (string) $token) === 1) {
                $seed = (int) substr((string) $token, strlen('--seed='));
                continue;
            }
            $modeFlags[] = $token;
        }

        if ($modeFlags === []) {
            return [self::SINGLE, $seed];
        }

        if (count($modeFlags) > 1) {
            $extras = array_slice($modeFlags, 1);
            $listed = '"' . implode('", "', array_map(strval(...), $extras)) . '"';

            // One localized key (candy-tetris-033): the count rides a
            // plural-safe frame in every locale, so the English-only
            // 'argument%s' 's' append and the hardcoded sentence are gone.
            throw new InvalidArgumentException(Lang::t('launch.extras', [
                'count' => (string) count($extras),
                'list' => $listed,
            ]));
        }

        $flag = $modeFlags[0];

        if (in_array($flag, self::VS_FLAGS, true)) {
            return [self::VS, $seed];
        }

        if (in_array($flag, self::HELP_FLAGS, true)) {
            return [self::HELP, $seed];
        }

        throw new InvalidArgumentException(Lang::t('mode.unknown', ['flag' => (string) $flag]));
    }

    /**
     * The usage screen, shared by the help exit and the unknown-flag refusal so
     * the two can never drift apart.
     */
    public static function helpText(): string
    {
        // candy-tetris-095: flags were the whole story — the game, its
        // keys and its terminal floor went undocumented. The key column is
        // the SAME derived card the sidebar paints (live default table:
        // --help answers before any user keys file is loaded), so help and
        // registry can never drift apart; the floor numbers are read from
        // the renderer's own constants for the same reason.
        $lines = [
            rtrim(Lang::t('help_text'), "\n"),
            '',
            Lang::t('help.keys'),
        ];
        foreach (explode("\n", Support\Bindings::helpCard(Support\Bindings::table())) as $row) {
            $lines[] = '  ' . $row;
        }
        $lines[] = '';
        $lines[] = Lang::t('help.floor', [
            'cols' => (string) Renderer::MIN_COLS,
            'rows' => (string) Renderer::MIN_ROWS,
        ]);

        return implode("\n", $lines) . "\n";
    }
}