<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\Settings\UiSettings;
use SugarCraft\Crush\Hooks\BuiltIn\ProtectFilesHook;
use SugarCraft\Crush\ToolCall;

/**
 * THE one list of shell commands that provably change nothing, and the one
 * judgement of whether a whole `Bash` line is made of them. Two policies read
 * it:
 *
 * - `plan` ({@see planAllows()}): a `Bash` call runs only when this proves it
 *   read-only, and is DENIED otherwise (audit F-P2);
 * - `default` and `accept-edits` ({@see autoAllows()}, user decision
 *   2026-10-11, setting {@see SETTING}): such a line runs WITHOUT ASKING,
 *   alone or as a chain or pipeline made entirely of these commands —
 *   `cd /repo && ls -d *\/ | head -80 && echo "---GIT---" && git log --oneline -3`
 *   is one question fewer, every time the model re-shapes it.
 *
 * The two differ in exactly three places: `plan` lets `cd` go anywhere (it
 * withholds writes, not visibility), while the auto-allow lets `cd` go only
 * into an existing directory inside the project ({@see LeadingCd::landsInside()};
 * no root, no `cd`; after one `cd`, only an absolute one); the auto-allow
 * refuses a background `&` (a job that outlives the call); and it refuses a
 * line that names a protected file ({@see ProtectFilesHook}: `.env*`, keys,
 * policy files) or that {@see SafetyClassifier} flags (`env | grep SECRET`) — those go back to
 * the mode's question, and the hook still refuses what it refuses.
 *
 * THIS IS AN ALLOW-LIST ON A GRANT PATH, both on purpose. Until audit F-P2
 * plan did the opposite: it allowed every `Bash` call and denied only what a
 * three-regex deny list (`\s+>\s+`, `\s+>>\s+`, `|\s*tee`) caught. Measured
 * under `plan`, that denied `echo x > f` and ALLOWED `echo x >f`, `echo x>f`,
 * `echo x 2> f`, `cat a >| f`, `sed -i s/a/b/ src.php`, `git commit -am wip`,
 * `git push --force`, `rm src/main.php`, `mv src /tmp/`, `curl -o f …`,
 * `cp /dev/null README.md`, `python3 -c "open('f','w')"` and
 * `truncate -s0 f`. A deny list over shell text can only ever enumerate the
 * spellings somebody thought of, so the question is inverted: not "does this
 * write?" but "can every part of this be shown not to?", and anything this
 * class cannot show is `false` — Deny under plan, a question under default.
 *
 * The line is tokenised by {@see ShellWords} (quote removal, every control
 * operator, redirections pulled out), and ALL of the following must hold:
 *
 * - it parses COMPLETELY — an unterminated quote, substitution or here-doc
 *   is not something this class can reason about;
 * - no command or process substitution (`$(…)`, backtick, `<(…)`, `>(…)`)
 *   anywhere, quoted or not: whatever runs inside one is a command this class
 *   never sees;
 * - no `${…}` or `$[…]` expansion, because bash evaluates array subscripts
 *   and substring offsets ARITHMETICALLY and `${x@P}` prompt-expands, and
 *   each of those runs a `$(…)` that lives in a variable's VALUE — one
 *   `${x:=…}` earlier on the same line can put it there. Measured on bash
 *   5.2: `echo ${x:=\$\(id\)} ${x@P}` runs `id`. Plain `$NAME` stays
 *   allowed; it substitutes a value and evaluates nothing;
 * - every redirection is inert ({@see ShellWords::isInertRedirection()}) — an
 *   fd duplication or a write to `/dev/null`, never a file (`tee` is not on
 *   the list at all);
 * - every simple command in every pipeline and list names a command in
 *   {@see COMMANDS}, LITERALLY (not a glob, not a brace, not a path —
 *   `/bin/cat` is not `cat` here, and a `NAME=value` prefix is not a
 *   command), and its arguments pass that command's check.
 *
 * Control operators are fine — `cat a | grep b | wc -l`, `ls; pwd`,
 * `git status && git log` — because every command they join is judged on its
 * own; a separator cannot introduce a command this loop does not visit.
 * `ls; rm x` is refused by its second command, not by its `;`.
 *
 * The honest limit: a read-only command is still a READ. `cat ~/.ssh/id_rsa`
 * is read-only exactly as `Read` would be — these policies withhold writes,
 * not visibility, and secret-file guarding is {@see ProtectFilesHook}'s job
 * (which is why the auto-allow consults it first). And `git` honours the
 * repository's own configuration (`core.fsmonitor`, `core.pager`,
 * `diff.external`, textconv drivers), `composer` its installed plugins and
 * `npm` its `.npmrc`, any of which can name a program: these trust a
 * checkout's own configuration the way running the command in it by hand
 * does, and nothing judged read-only here can write that configuration.
 */
final class ReadOnlyCommands
{
    /**
     * The setting that switches the `default` / `accept-edits` auto-allow
     * ({@see autoAllowEnabled()}). User tier; a project may only switch it off.
     */
    public const SETTING = 'permissions.autoAllowReadOnly';

    /**
     * The commands, mapped to how their arguments are judged by
     * {@see argumentsAreReadOnly()}.
     *
     * AN ALLOW-LIST ON PURPOSE: a command missing from it costs one question
     * (or, under plan, one denied exploration step — the model can use
     * `Read`/`Grep`/`Glob` instead); a writing command wrongly on it costs the
     * guarantee both policies exist to give. Additions need the same scrutiny
     * as a security fix.
     *
     * `null` means no spelling of that command's arguments can write a file or
     * run another program, so its words are not inspected — and therefore a
     * glob or a `$VAR` in them is fine (`cat src/*.php`, `ls $HOME`).
     * A string names the per-command check for the ones where SOME argument
     * writes or executes: `find -delete`/`-exec`, `sort -o`, `uniq IN OUT`,
     * `rg --pre CMD`, `ag --pager CMD`, `tree -o`, `file -C`, `date -s`,
     * `printf -v` (which evaluates an array subscript, so it runs code —
     * measured), `env`/`printenv` with any argument (`env rm x` runs `rm`),
     * `php` other than `-l`, `composer`/`npm` other than their listing
     * subcommands, `git`'s many writing subcommands, and `cd` (see the class
     * docblock). A checked command refuses ANY word bash may still rewrite
     * ({@see ShellWords::$expandable}), because `find . {-delete,}` and a file
     * named `-delete` matched by `find *` both hand find a flag no literal
     * word showed.
     *
     * Left out deliberately, with the reason, so nobody re-adds one in passing:
     * `sed`/`awk`/`perl`/`python*`/`node`/`ruby` (interpreters — `awk`
     * writes with `print > f` and runs `system()`, `sed` writes with `w` and
     * GNU sed's `e` runs a command); `xargs`, `sudo`, `nohup`, `timeout`,
     * `nice`, `command`, `exec`, `eval`, `source`/`.`, `bash`/`sh -c`, `time`
     * (each runs ANOTHER command, which would escape this list); `test`/`[`
     * (`[ -v 'a[$(cmd)]' ]` evaluates the subscript and runs `cmd` — measured
     * on bash 5.2); `less`/`more` (interactive, and `LESSOPEN` runs a
     * preprocessor); `xxd` and `tee` (a second operand or any operand is an
     * output file); `curl`/`wget` (network, and `-o`); `pushd`/`popd`.
     *
     * @var array<string, ?string>
     */
    private const COMMANDS = [
        'ag' => 'ag',
        'basename' => null,
        'cat' => null,
        'cd' => 'cd',
        'cmp' => null,
        'column' => null,
        'comm' => null,
        'composer' => 'composer',
        'cut' => null,
        'date' => 'date',
        'df' => null,
        'diff' => null,
        'dirname' => null,
        'du' => null,
        'echo' => null,
        'egrep' => null,
        'env' => 'no-arguments',
        'false' => null,
        'fgrep' => null,
        'file' => 'file',
        'find' => 'find',
        'git' => 'git',
        'grep' => null,
        'head' => null,
        'id' => null,
        'jq' => null,
        'ls' => null,
        'nl' => null,
        'npm' => 'npm',
        'php' => 'php',
        'printenv' => 'no-arguments',
        'printf' => 'printf',
        'pwd' => null,
        'readlink' => null,
        'realpath' => null,
        'rg' => 'rg',
        'sort' => 'sort',
        'stat' => null,
        'tail' => null,
        'tr' => null,
        'tree' => 'tree',
        'true' => null,
        'type' => null,
        'uname' => null,
        'uniq' => 'uniq',
        'wc' => null,
        'whereis' => null,
        'which' => null,
        'whoami' => null,
    ];

    /**
     * `find` primaries that delete, run a program or write a file. Matched
     * against quote-removed words, so `'-delete'` is caught too.
     */
    private const FIND_REFUSED = [
        '-delete', '-exec', '-execdir', '-ok', '-okdir',
        '-fprint', '-fprint0', '-fprintf', '-fls',
    ];

    /**
     * `git` subcommands that may run, each further narrowed by
     * {@see gitIsReadOnly()}. Anything else — `commit`, `push`, `checkout`,
     * `reset`, `stash`, `fetch`, an alias (which may be `!shell`) — is not.
     */
    private const GIT_SUBCOMMANDS = [
        'status', 'log', 'show', 'diff', 'blame', 'shortlog', 'rev-parse', 'rev-list',
        'ls-files', 'ls-tree', 'describe', 'cat-file', 'grep', 'branch', 'tag', 'remote', 'config',
    ];

    /** `composer` subcommands that only read: list packages, check the manifest. */
    private const COMPOSER_SUBCOMMANDS = ['show', 'info', 'validate'];

    /** `npm` subcommands that only read: the dependency tree, a package's registry entry. */
    private const NPM_SUBCOMMANDS = ['ls', 'list', 'la', 'll', 'view', 'info', 'show', 'v'];

    private function __construct()
    {
    }

    /**
     * Plan mode's judgement: is every command of $command read-only? `cd`
     * may go anywhere — plan withholds writes, not visibility.
     */
    public static function planAllows(string $command): bool
    {
        return self::judge($command, null, cdAnywhere: true);
    }

    /**
     * The `default` / `accept-edits` auto-allow's judgement: is $command made
     * entirely of read-only commands, with every `cd` landing inside
     * $projectRoot (no root: no `cd` at all), naming no protected file and
     * flagged by nothing {@see SafetyClassifier} knows? Does NOT read the
     * setting — {@see autoAllowEnabled()} is the caller's to consult.
     */
    public static function autoAllows(string $command, ?string $projectRoot): bool
    {
        if (ProtectFilesHook::namesProtectedFile($command)) {
            return false;
        }
        if ((new SafetyClassifier())->classify(new ToolCall('Bash', ['command' => $command])) !== null) {
            return false;
        }

        return self::judge($command, $projectRoot === '' ? null : $projectRoot, cdAnywhere: false);
    }

    /**
     * Whether the read-only auto-allow is on ({@see SETTING}, default on). The
     * user tier decides; a trusted project may switch it OFF and never back
     * on: the merged value (project included) AND the value without the
     * project tier must both be true.
     */
    public static function autoAllowEnabled(): bool
    {
        if (!UiSettings::bool(self::SETTING)) {
            return false;
        }
        try {
            $userTier = Bootstrap::readUserConfigWithoutProjectTier()[self::SETTING] ?? true;
        } catch (\Throwable) {
            $userTier = true;
        }

        return $userTier !== false;
    }

    /**
     * Is ONE simple command — its quote-removed words, the {@see ShellWords::$expandable}
     * flags for them — a read-only one? {@see judge()} has already refused the
     * line's substitutions and writing redirections. $changedDirectory carries
     * "an earlier `cd` of this line ran" from one command to the next.
     *
     * @param list<string> $words
     * @param list<bool>   $flags
     */
    private static function commandIsReadOnly(array $words, array $flags, ?string $projectRoot, bool $cdAnywhere, bool &$changedDirectory): bool
    {
        $name = $words[0] ?? null;
        if ($name === null || ($flags[0] ?? true) || !array_key_exists($name, self::COMMANDS)) {
            return false;
        }
        $args = array_slice($words, 1);
        $argFlags = array_slice($flags, 1);

        if ($name === 'cd') {
            if ($cdAnywhere) {
                return true;
            }
            // One literal operand landing inside the project. After an
            // earlier `cd`, a relative operand would be judged against the
            // root while bash resolves it against the new directory, so only
            // an absolute one is judged.
            if (count($args) !== 1 || ($argFlags[0] ?? true)
                || ($changedDirectory && !str_starts_with($args[0], '/'))
                || !LeadingCd::landsInside($args[0], $projectRoot)) {
                return false;
            }
            $changedDirectory = true;

            return true;
        }

        return self::argumentsAreReadOnly($name, $args, $argFlags);
    }

    private static function judge(string $command, ?string $projectRoot, bool $cdAnywhere): bool
    {
        if (trim($command) === '') {
            return false;
        }

        $parsed = ShellWords::parse($command);
        if (!$parsed->complete || $parsed->hasSubstitution() || $parsed->parameterExpansions !== []) {
            return false;
        }

        foreach ($parsed->redirections as $redirection) {
            if (!ShellWords::isInertRedirection($redirection)) {
                return false;
            }
        }
        // A background job outlives the call that started it (`tail -f x &`
        // would keep running after the tool row says done): plan's judgement
        // is about writes only, the auto-allow also about what it leaves behind.
        if (!$cdAnywhere && in_array('&', $parsed->operators, true)) {
            return false;
        }

        $judged = false;
        $changedDirectory = false;
        foreach ($parsed->commands as $index => $words) {
            if ($words === []) {
                // Redirections only (`2>/dev/null` on its own) — judged above.
                continue;
            }
            if (!self::commandIsReadOnly($words, $parsed->expandable[$index] ?? [], $projectRoot, $cdAnywhere, $changedDirectory)) {
                return false;
            }
            $judged = true;
        }

        return $judged;
    }

    /**
     * @param list<string> $args  the words after the command name
     * @param list<bool>   $flags {@see ShellWords::$expandable} for those words
     */
    private static function argumentsAreReadOnly(string $name, array $args, array $flags): bool
    {
        $check = self::COMMANDS[$name];
        if ($check === null) {
            return true;
        }
        if (count($flags) !== count($args) || in_array(true, $flags, true)) {
            return false;
        }

        return match ($check) {
            'find' => array_intersect($args, self::FIND_REFUSED) === [],
            'git' => self::gitIsReadOnly($args),
            // `-o FILE` / `--output` writes; `--compress-program` runs a
            // program; `-T DIR` / `--temporary-directory` writes there. GNU
            // getopt clusters short options (`-uo f`) and accepts any
            // unambiguous long-option prefix (`--out=f`), hence the shape.
            'sort' => self::noOptionMatches($args, '/^-[^-]*[oT]|^--(?:o|com|te)/'),
            // `uniq [OPTION]... [INPUT [OUTPUT]]` — a second operand is
            // written. Option values must be attached (`-f1`, not `-f 1`):
            // a detached value counts as an operand, which only over-refuses.
            'uniq' => self::operandCount($args) <= 1,
            // `--pre CMD` runs CMD on every file; `--hostname-bin` runs one too.
            'rg' => self::noOptionMatches($args, '/^--(?:pre|hostname-bin)/'),
            // `--pager CMD` pipes the output through CMD.
            'ag' => self::noOptionMatches($args, '/^--pag/'),
            // `-o FILE` writes the listing; `-R` (with `-H`) writes
            // `00Tree.html` into every directory.
            'tree' => self::noOptionMatches($args, '/^-[^-]*[oR]|^--o/'),
            // `-C` / `--compile` writes a compiled `.mgc` magic file.
            'file' => self::noOptionMatches($args, '/^-[^-]*C|^--co/'),
            'date' => self::dateIsReadOnly($args),
            // `printf -v NAME` assigns, and a NAME of `a[$(cmd)]` runs `cmd`
            // (the subscript is evaluated arithmetically — measured). Only a
            // FORMAT may come first.
            'printf' => $args === [] || $args[0] === '--' || !str_starts_with($args[0], '-'),
            // Bare `env` / `printenv` print the environment; any argument is
            // a command to run (`env rm x`) or an assignment in front of one.
            'no-arguments' => $args === [],
            // `php -l FILE…` lints: it compiles and runs nothing. Every other
            // option (`-r`, `-d auto_prepend_file=…`, `-S`) or a bare script
            // runs code.
            'php' => self::phpIsLintOnly($args),
            'composer' => self::subcommandIs($args, self::COMPOSER_SUBCOMMANDS),
            'npm' => self::subcommandIs($args, self::NPM_SUBCOMMANDS),
            default => false,
        };
    }

    /**
     * True when no OPTION word (one starting with `-`, other than a bare `-`)
     * before a `--` matches `$refused`.
     *
     * @param list<string> $args
     */
    private static function noOptionMatches(array $args, string $refused): bool
    {
        foreach ($args as $arg) {
            if ($arg === '--') {
                return true;
            }
            if ($arg !== '-' && str_starts_with($arg, '-') && preg_match($refused, $arg) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * The number of operands (non-option words, and every word after `--`).
     *
     * @param list<string> $args
     */
    private static function operandCount(array $args): int
    {
        $count = 0;
        $optionsEnded = false;
        foreach ($args as $arg) {
            if (!$optionsEnded && $arg === '--') {
                $optionsEnded = true;
                continue;
            }
            if (!$optionsEnded && $arg !== '-' && str_starts_with($arg, '-')) {
                continue;
            }
            ++$count;
        }

        return $count;
    }

    /**
     * `php -l` (or `--syntax-check`) followed by files, and nothing else: no
     * other option anywhere, at least one file.
     *
     * @param list<string> $args
     */
    private static function phpIsLintOnly(array $args): bool
    {
        if ($args === [] || !in_array($args[0], ['-l', '--syntax-check'], true)) {
            return false;
        }
        $files = 0;
        foreach (array_slice($args, 1) as $arg) {
            if (str_starts_with($arg, '-')) {
                return false;
            }
            ++$files;
        }

        return $files > 0;
    }

    /**
     * The first word is one of $subcommands. Options may follow it (they
     * format or filter a listing); none may come before it, where a global
     * option could change what runs (`--working-dir`, `--prefix` are fine to
     * spell after the subcommand).
     *
     * @param list<string> $args
     * @param list<string> $subcommands
     */
    private static function subcommandIs(array $args, array $subcommands): bool
    {
        return $args !== [] && in_array($args[0], $subcommands, true);
    }

    /**
     * `date` DISPLAYS with `+FORMAT`, `-u`, `-R`, `-I…`, `-d DATE`,
     * `-r FILE` and `-f FILE`, and SETS the clock with `-s`/`--set` or a bare
     * `MMDDhhmm` operand. An allow-list of the display forms, since a
     * deny-list would have to know every way an operand can look like a date.
     *
     * @param list<string> $args
     */
    private static function dateIsReadOnly(array $args): bool
    {
        $count = count($args);
        for ($i = 0; $i < $count; ++$i) {
            $arg = $args[$i];
            if (in_array($arg, ['-d', '--date', '-r', '--reference', '-f', '--file'], true)) {
                ++$i; // the next word is that option's value, not an operand
                continue;
            }
            if (str_starts_with($arg, '+')
                || in_array($arg, ['-u', '--utc', '--universal', '-R', '--rfc-email', '--debug'], true)
                || preg_match('/^(?:-I[a-z]*|--iso-8601(?:=[a-z]+)?|--rfc-3339=[a-z]+|--(?:date|reference|file)=.*|-[dfr].+)$/', $arg) === 1
            ) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * `git`, narrowed to inspection. Global options before the subcommand are
     * refused except `--no-pager`/`-P`: `-c core.pager=CMD`, `--exec-path`,
     * `-C`, `--git-dir` all change what runs or where.
     *
     * @param list<string> $args the words after `git`
     */
    private static function gitIsReadOnly(array $args): bool
    {
        while ($args !== [] && in_array($args[0], ['--no-pager', '-P'], true)) {
            array_shift($args);
        }
        $subcommand = array_shift($args);
        if ($subcommand === null || !in_array($subcommand, self::GIT_SUBCOMMANDS, true)) {
            return false;
        }

        return match ($subcommand) {
            'branch' => self::gitListingIsReadOnly(
                $args,
                ['-a', '--all', '-r', '--remotes', '-v', '-vv', '--verbose', '--show-current', '--color',
                    '--no-color', '--column', '--no-column', '-i', '--ignore-case', '--omit-empty', '--no-abbrev'],
                ['-l', '--list'],
                '/^-[arvil]+$/',
                '/l/',
            ),
            'tag' => self::gitListingIsReadOnly(
                $args,
                ['-i', '--ignore-case', '--color', '--no-color', '--column', '--no-column', '--omit-empty'],
                ['-l', '--list', '-n'],
                '/^-(?:[il]+|n\d*)$/',
                '/[ln]/',
            ),
            'remote' => self::gitRemoteIsReadOnly($args),
            'config' => self::gitConfigIsReadOnly($args),
            default => self::gitInspectionIsReadOnly($subcommand, $args),
        };
    }

    /**
     * `log`, `show`, `diff`, `blame`, `grep` and the plumbing readers. Their
     * one write is `--output=FILE` (log/show/diff), and `git grep -O CMD` /
     * `--open-files-in-pager` runs a program. git accepts any unambiguous
     * long-option prefix (`--out=f`), so every `--o…` option is refused except
     * the read-only ones a model actually types. Words after `--` are
     * pathspecs.
     *
     * @param list<string> $args
     */
    private static function gitInspectionIsReadOnly(string $subcommand, array $args): bool
    {
        foreach ($args as $arg) {
            if ($arg === '--') {
                return true;
            }
            if (str_starts_with($arg, '--o')
                && !in_array($arg, ['--oneline', '--only-matching', '--ours'], true)
            ) {
                return false;
            }
            if ($subcommand === 'grep' && preg_match('/^-[^-]*O/', $arg) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * `git branch` / `git tag` LIST with no arguments, with `--list`, or with
     * a filter (`--contains`, `--merged`, `--points-at` — git switches to list
     * mode for those itself), and otherwise treat a bare operand as the name of
     * a branch or tag to CREATE. So an operand is allowed only in list mode,
     * and every option must be a known listing option (`-d`, `-D`, `-m`, `-c`
     * are not).
     *
     * A filter's value is skipped only when it does not start with `-`: git
     * itself takes `--contains -d x` as `--contains` (defaulted) and then
     * `-d x`, a delete — consuming `-d` as a value here would miss it.
     *
     * @param list<string> $args
     * @param list<string> $plainOptions  options that neither list nor take a value
     * @param list<string> $listOptions   options that switch to list mode
     * @param string       $cluster       a regex for a valid short-option cluster
     * @param string       $listInCluster which cluster letters switch to list mode
     */
    private static function gitListingIsReadOnly(
        array $args,
        array $plainOptions,
        array $listOptions,
        string $cluster,
        string $listInCluster,
    ): bool {
        $listMode = false;
        $operand = false;
        $count = count($args);
        for ($i = 0; $i < $count; ++$i) {
            $arg = $args[$i];
            $isFilter = in_array($arg, ['--contains', '--no-contains', '--merged', '--no-merged', '--points-at'], true);
            if ($isFilter || in_array($arg, ['--sort', '--format'], true)) {
                $listMode = $listMode || $isFilter;
                if ($i + 1 < $count && !str_starts_with($args[$i + 1], '-')) {
                    ++$i;
                }
                continue;
            }
            if (preg_match('/^--(?:contains|no-contains|merged|no-merged|points-at)=/', $arg) === 1) {
                $listMode = true;
                continue;
            }
            if (preg_match('/^--(?:sort|format|color|column|abbrev)=/', $arg) === 1
                || in_array($arg, $plainOptions, true)
            ) {
                continue;
            }
            if (in_array($arg, $listOptions, true)) {
                $listMode = true;
                continue;
            }
            if (preg_match($cluster, $arg) === 1) {
                $listMode = $listMode || preg_match($listInCluster, $arg) === 1;
                continue;
            }
            if (str_starts_with($arg, '-')) {
                return false;
            }
            $operand = true;
        }

        return !$operand || $listMode;
    }

    /**
     * `git remote`, `git remote -v`, `git remote get-url …` and
     * `git remote show …` read; `add`/`remove`/`rename`/`set-url`/`prune`
     * write.
     *
     * @param list<string> $args
     */
    private static function gitRemoteIsReadOnly(array $args): bool
    {
        while ($args !== [] && in_array($args[0], ['-v', '--verbose'], true)) {
            array_shift($args);
        }
        if ($args === []) {
            return true;
        }
        $options = match (array_shift($args)) {
            'get-url' => ['--push', '--all'],
            'show' => ['-n'],
            default => null,
        };
        if ($options === null) {
            return false;
        }
        foreach ($args as $arg) {
            if (str_starts_with($arg, '-') && !in_array($arg, $options, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * `git config` READS only with an explicit read action — `--get`,
     * `--get-all`, `--get-regexp`, `--get-urlmatch`, `--list` (or the
     * `get`/`list` subcommands of git 2.46+). The old-style `git config KEY`
     * also reads, but `git config KEY VALUE` writes, and telling them apart by
     * operand count is the kind of judgement this class does not make. A
     * write action next to a read one makes git refuse ("only one action at a
     * time"), but it is refused here as well, including by unambiguous prefix
     * (`--ad` is `--add`).
     *
     * @param list<string> $args
     */
    private static function gitConfigIsReadOnly(array $args): bool
    {
        $writes = ['--add', '--unset', '--unset-all', '--replace-all', '--rename-section', '--remove-section', '--edit'];
        foreach ($args as $arg) {
            if ($arg === '-e') {
                return false;
            }
            if (str_starts_with($arg, '--') && strlen($arg) > 3) {
                $option = explode('=', $arg, 2)[0];
                foreach ($writes as $write) {
                    if (str_starts_with($write, $option)) {
                        return false;
                    }
                }
            }
        }

        if ($args !== [] && in_array($args[0], ['get', 'list'], true)) {
            return true;
        }

        return array_intersect(
            $args,
            ['--get', '--get-all', '--get-regexp', '--get-urlmatch', '--get-color', '--get-colorbool', '--list', '-l'],
        ) !== [];
    }
}
