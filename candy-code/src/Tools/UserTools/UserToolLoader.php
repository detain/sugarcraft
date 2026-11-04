<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\UserTools;

use SugarCraft\Code\Lang;
use SugarCraft\Code\Support\HomeDirectory;

/**
 * The ONE read of the user's script-tool directory (C-TOOL-6.e).
 *
 * Source of truth: `~/.candy-code/tools/*.json` — the USER tier, rooted at
 * {@see HomeDirectory::owned()} exactly like the settings file, and there is
 * deliberately NO project tier: a repository must not be able to mint tools
 * the person trusted by being inside the directory. The same reasoning runs
 * through `mcpConfigDecision()` — reading a `.mcp.json` is where the project's
 * reach toward this machine stops — and a workspace that could declare
 * script tools would start that reach further: not a server the person can
 * see running, but a binary spelling wired into every launch's roster.
 *
 * The directory is read ONCE, by {@see \SugarCraft\Code\Cli\Bootstrap::userScriptTools()}'s
 * first call — the frozen-roster law the MCP shape already keeps: tools that
 * appear after the first read are adopted next session, never mid-session,
 * because a roster that changed under a live conversation would leave the
 * transcript's picture of what the model was offered out of sync with what it
 * could actually call.
 *
 * Every file-level failure — unreadable JSON, a shape the validator refuses,
 * a name already taken by an earlier file (directory order is `sort()`ed, so
 * "earlier" is stable across machines) — skips that ONE file and records an
 * announcement naming it. A bad tool file never bricks the launch and never
 * takes a good file down with it; and nothing here calls `exit()`, throws at
 * the caller, or writes to any channel itself: {@see load()} returns both
 * lists and {@see \SugarCraft\Code\Cli\Bootstrap} decides where the
 * announcements go, exactly once per launch.
 */
final class UserToolLoader
{
    /** The directory, under the owned home, that holds the user's tool files. */
    public const DIRECTORY = '.candy-code/tools';

    private function __construct()
    {
    }

    /**
     * What the user tier holds right now: the validated specs, and one
     * announcement per file that did not make it.
     *
     * The announcements are finished, human-readable rows (the `cli.usertools.skip.*`
     * family) — the caller hands them to the transcript and stderr as-is.
     *
     * @return array{specs: list<UserToolSpec>, skips: list<string>}
     */
    public static function load(): array
    {
        $home = HomeDirectory::owned();
        if ($home === null) {
            // An unowned or undeterminable home is NOT a directory to reach
            // into by fallback — `resolved()`'s posix-passwd answer is right
            // for caches and wrong for a folder that MINTS executables. No
            // tools, no fuss; the settings file already refuses on this same
            // seam and keeps the same silence.
            return ['specs' => [], 'skips' => []];
        }

        return self::loadFrom($home . '/' . self::DIRECTORY);
    }

    /**
     * The seam {@see load()} runs through, exposed for the suite's sandboxed
     * homes and for nothing in production: validate what one directory
     * spells, as if it were the user tier.
     *
     * @return array{specs: list<UserToolSpec>, skips: list<string>}
     */
    public static function loadFrom(string $dir): array
    {
        $specs = [];
        $skips = [];
        $taken = [];

        $names = is_dir($dir) ? scandir($dir) : false;
        if ($names === false) {
            return ['specs' => [], 'skips' => []];
        }

        sort($names, SORT_STRING);

        foreach ($names as $entry) {
            if (!is_string($entry) || !str_ends_with($entry, '.json')) {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (!is_file($path)) {
                // A directory called `x.json`, or a link to nothing, is not a
                // tool file; skipping it without an announcement is the
                // same answer the settings audit gives to stray non-files.
                continue;
            }

            $bytes = @file_get_contents($path);
            if ($bytes === false) {
                $skips[] = Lang::t('cli.usertools.skip.unreadable', ['file' => $path]);

                continue;
            }

            $decoded = json_decode($bytes, true);
            if (!is_array($decoded)) {
                $skips[] = Lang::t('cli.usertools.skip.bad_json', ['file' => $path]);

                continue;
            }

            $refusal = UserToolSpec::refusal($decoded);
            if ($refusal !== null) {
                $skips[] = self::refusalNotice($refusal, $path);

                continue;
            }

            $spec = UserToolSpec::fromDecoded($decoded);
            if (isset($taken[$spec->name])) {
                $skips[] = Lang::t('cli.usertools.skip.duplicate', [
                    'file' => $path,
                    'name' => $spec->name,
                    'first' => $taken[$spec->name],
                ]);

                continue;
            }

            $taken[$spec->name] = $path;
            $specs[] = $spec;
        }

        return ['specs' => $specs, 'skips' => $skips];
    }

    /**
     * The validator's one-word class of failure, as the row the person sees.
     * Every key is spelled whole — the parity and catalogue censuses read
     * `Lang::t()` literals, and a composed prefix would slip past both.
     *
     * RULING (G1-L3A, PHP-m7): the comparison stays over the validator's
     * strings. The eight arms above are exactly the classes
     * UserToolSpec::refusal() can answer, so the match is complete against
     * the vocabulary as it ships; it is not complete as a TYPE, because
     * refusal() is declared ?string and an unknown word would raise an
     * UnhandledMatchError from inside discovery and take every user tool
     * down with one misclassified file. Converting the vocabulary to a real
     * enum would make that illegal state unrepresentable, but it rewrites
     * UserToolSpec (out of this lane) and every arm here — the durable
     * churn is @todo'd below rather than paid in one wave.
     *
     * Until then the default arm is the fail-loud net: it degrades the
     * unknown class to a skip row carrying the least-wrong existing
     * wording plus the classifier word in brackets. The bracketed token is
     * the validator's own machine vocabulary, not prose, so the row adds no
     * user-facing string while the strings lane is closed — no new lang
     * key is minted here.
     *
     * @todo replace the string vocabulary with a UserToolRefusal enum
     *       returned by UserToolSpec::refusal(); arms then match on enum
     *       cases, exhaustiveness is checked by the type, and this default
     *       arm becomes unreachable and is deleted.
     */
    private static function refusalNotice(string $refusal, string $path): string
    {
        return match ($refusal) {
            'bad_shape' => Lang::t('cli.usertools.skip.bad_shape', ['file' => $path]),
            'bad_name' => Lang::t('cli.usertools.skip.bad_name', ['file' => $path]),
            'bad_description' => Lang::t('cli.usertools.skip.bad_description', ['file' => $path]),
            'bad_schema' => Lang::t('cli.usertools.skip.bad_schema', ['file' => $path]),
            'bad_argv' => Lang::t('cli.usertools.skip.bad_argv', ['file' => $path]),
            'argv_placeholder' => Lang::t('cli.usertools.skip.argv_placeholder', ['file' => $path]),
            'undeclared_placeholder' => Lang::t('cli.usertools.skip.undeclared_placeholder', ['file' => $path]),
            'bad_timeout' => Lang::t('cli.usertools.skip.bad_timeout', ['file' => $path]),
            default => Lang::t('cli.usertools.skip.bad_shape', ['file' => $path])
                . ' [' . $refusal . ']',
        };
    }
}
