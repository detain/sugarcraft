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
 *   5.2: `echo ${x:=\$\(id\)} ${x@P}` runs `id`. Plain `$NAME` and
 *   `${NAME}` stay allowed; they substitute a value and evaluate nothing;
 * - every redirection is inert ({@see ShellWords::isInertRedirection()}) — an
 *   fd duplication or a write to `/dev/null`, never a file (`tee` is not on
 *   the list at all);
 * - every simple command in every pipeline and list names a command in
 *   {@see COMMANDS}, LITERALLY (not a glob, not a brace, not a path —
 *   `/bin/cat` is not `cat` here, and a `NAME=value` prefix is not a
 *   command), and its arguments pass that command's check.
 *
 * COMPOUND STATEMENTS (user decision 2026-10-11): the line is read through
 * {@see ShellCompound}, so a `for NAME in WORD…` or `while read NAME` loop,
 * and an `if`, qualifies when every command of its body does, judged with
 * the loop variables bound — see {@see simpleIsReadOnly()} for what a body
 * may do with them. Anything that grammar does not read is `false`.
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
     * `sed` is here because its script is PARSED ({@see SedCommand}): no
     * `-i`, no `-f` script file, no `w`/`W`/`e` command or `s///w`/`s///e`
     * flag, nothing the parser cannot read. `awk` / `gawk` / `mawk` are here
     * ONLY FOR SIMPLE INLINE PROGRAMS ({@see AwkCommand}): `-F` and `-v`
     * alone, and a program with no `system`, `close`, `fflush`, `@`, pipe,
     * `getline <`, or any `>` that is not provably a comparison.
     *
     * Left out deliberately, with the reason, so nobody re-adds one in passing:
     * `perl`/`python*`/`node`/`ruby` (interpreters handed inline code that can
     * do anything); `xargs`, `sudo`, `nohup`, `timeout`,
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
        'awk' => 'awk',
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
        'fold' => null,
        'free' => null,
        'gawk' => 'awk',
        'git' => 'git',
        'grep' => null,
        'head' => null,
        'id' => null,
        'jq' => null,
        'ls' => null,
        'mawk' => 'awk',
        'nl' => null,
        'npm' => 'npm',
        'nproc' => null,
        'php' => 'php',
        'printenv' => 'no-arguments',
        'printf' => 'printf',
        'pwd' => null,
        'readlink' => null,
        'realpath' => null,
        'rg' => 'rg',
        'sed' => 'sed',
        'sort' => 'sort',
        'stat' => null,
        'tail' => null,
        'tr' => null,
        'tree' => 'tree',
        'true' => null,
        'type' => null,
        'uname' => null,
        'uniq' => 'uniq',
        'uptime' => null,
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

    /**
     * What an argument becomes when its text depends on a loop variable whose
     * values are not known (a glob, `while read` input): a NUL byte, which no
     * real argument can hold, so a check that only asks "is this an option?"
     * sees an operand, and one that must READ the text (a sed script, a
     * subcommand, a date) refuses it.
     */
    public const UNKNOWN = "\0";

    /** At most this many combinations of known loop values are judged one by one. */
    private const MAX_BINDINGS = 64;

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
        if (!$parsed->complete || $parsed->hasSubstitution() || !self::expansionsArePlain($parsed)) {
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

        $items = ShellCompound::parse($parsed);
        if ($items === null) {
            return false;
        }
        $judged = 0;
        $changedDirectory = false;

        return self::itemsQualify($items, [], $projectRoot, $cdAnywhere, $changedDirectory, $judged, true, null)
            && $judged > 0;
    }

    /**
     * PER-PART COVERAGE of one compound item — a `for` / `while read` loop
     * or an `if` — for {@see SessionPermissionMemo::coversBySegments()}
     * (user decision 2026-10-11): the loop is ONE part, covered when its
     * header qualifies (the same rules as the read-only judgement: no
     * substitution, the list's variables in scope, no re-bound name) and
     * EVERY command of its body is covered on its own — read-only in the
     * loop's scope (when $readOnlyCovers), or by $covers, a remembered
     * grant matched against that command's own source text. A `cd` inside
     * one is never covered by a grant: it moves every later iteration.
     *
     * Redirections on the statement itself (`done > out`, `fi 2> f`) must be
     * inert — no command of the body carries them, so no grant can cover them
     * — and a body that writes a file anywhere gets no read-only cover at all.
     *
     * @param array<string, mixed>               $item   a non-`simple` node of {@see ShellCompound::parse()}
     * @param \Closure(array<string, mixed>): bool $covers a grant covers this `simple` node
     */
    public static function compoundIsCovered(ShellWords $parsed, array $item, ?string $projectRoot, bool $readOnlyCovers, \Closure $covers): bool
    {
        $structural = ShellCompound::structuralIndices([$item]);
        $commands = array_column(ShellCompound::simpleCommands([$item]), 'index');
        foreach ($parsed->redirections as $redirection) {
            if (ShellWords::isInertRedirection($redirection)) {
                continue;
            }
            if (in_array($redirection['command'], $structural, true)) {
                return false;
            }
            // A body command that writes a file is no read-only command: only
            // a grant that spells the redirection may cover it.
            if (in_array($redirection['command'], $commands, true)) {
                $readOnlyCovers = false;
            }
        }
        $changedDirectory = false;
        $judged = 0;

        return self::itemsQualify([$item], [], $projectRoot === '' ? null : $projectRoot, false, $changedDirectory, $judged, $readOnlyCovers, $covers);
    }

    /**
     * Every `${…}` is a plain `${name}` — which substitutes a value exactly
     * as `$name` does and evaluates nothing — and there is no `$[…]`. The
     * forms this refuses are the ones that RUN code: an array subscript or a
     * substring offset is arithmetic, `${x@P}` prompt-expands, `${x:=…}`
     * assigns (see the class docblock).
     */
    private static function expansionsArePlain(ShellWords $parsed): bool
    {
        if (\count($parsed->parameterExpansionBodies) !== \count($parsed->parameterExpansions)) {
            return false;
        }
        foreach ($parsed->parameterExpansionBodies as $body) {
            if (preg_match('/^\{[A-Za-z_][A-Za-z0-9_]*\}$/', $body) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Do all of $items qualify — every command read-only (or, in per-part
     * mode, covered by $covers) with the loop variables in $scope bound?
     *
     * @param list<array<string, mixed>>                                 $items
     * @param array<string, array{values: ?list<string>, optionSafe: bool}> $scope
     *        each loop variable in force: its values when every one is known
     *        literally, else null; and whether no value can begin with `-`
     * @param ?\Closure(array<string, mixed>): bool $covers
     */
    private static function itemsQualify(
        array $items,
        array $scope,
        ?string $projectRoot,
        bool $cdAnywhere,
        bool &$changedDirectory,
        int &$judged,
        bool $readOnly,
        ?\Closure $covers,
    ): bool {
        foreach ($items as $item) {
            $ok = match ($item['kind']) {
                'simple' => self::simpleQualifies($item, $scope, $projectRoot, $cdAnywhere, $changedDirectory, $judged, $readOnly, $covers),
                'for' => self::forQualifies($item, $scope, $projectRoot, $cdAnywhere, $changedDirectory, $judged, $readOnly, $covers),
                'while' => self::whileQualifies($item, $scope, $projectRoot, $cdAnywhere, $changedDirectory, $judged, $readOnly, $covers),
                'if' => self::ifQualifies($item, $scope, $projectRoot, $cdAnywhere, $changedDirectory, $judged, $readOnly, $covers),
                default => false,
            };
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed>                                         $node
     * @param array<string, array{values: ?list<string>, optionSafe: bool}> $scope
     * @param ?\Closure(array<string, mixed>): bool                         $covers
     */
    private static function simpleQualifies(
        array $node,
        array $scope,
        ?string $projectRoot,
        bool $cdAnywhere,
        bool &$changedDirectory,
        int &$judged,
        bool $readOnly,
        ?\Closure $covers,
    ): bool {
        if ($node['words'] === []) {
            // Redirections only (`2>/dev/null` on its own) — judged by the caller.
            return true;
        }
        ++$judged;
        if ($readOnly && self::simpleIsReadOnly($node, $scope, $projectRoot, $cdAnywhere, $changedDirectory)
            && ($covers === null || self::autoAllowRefusesNothing($node['source']))) {
            return true;
        }

        return $covers !== null && $node['words'][0] !== 'cd' && $covers($node);
    }

    /**
     * The two refusals {@see autoAllows()} makes on a whole line, made on
     * one command of a compound judged part by part.
     */
    private static function autoAllowRefusesNothing(string $source): bool
    {
        return !ProtectFilesHook::namesProtectedFile($source)
            && (new SafetyClassifier())->classify(new ToolCall('Bash', ['command' => $source])) === null;
    }

    /**
     * One simple command, read-only with the loop variables in $scope bound.
     *
     * Outside any loop this is {@see commandIsReadOnly()} on the words as
     * written. Inside one, every `$name` / `${name}` must be a loop variable
     * in scope (anything else — `$HOME`, `$1`, `$@` — is refused there), and:
     *
     * - a variable whose values are all KNOWN (`for d in candy-core
     *   candy-forms`) is substituted, and the command is judged once per
     *   combination of values, exactly as each iteration will run it — up to
     *   {@see MAX_BINDINGS} combinations;
     * - a variable whose values are NOT known (a glob, `while read` input) is
     *   fine anywhere in a command that inspects no argument; in a command
     *   that checks its arguments it must be DOUBLE-QUOTED (or bash splits
     *   the value into words, any of which may be an option), sit in no glob,
     *   and provably not begin with `-` — a literal prefix (`./$f`), values
     *   that cannot start with one (`for f in src/*`), or a `--` before it
     *   (not for `find`, which reads a leading `-` as an expression whatever
     *   precedes it) — and is then judged as the {@see UNKNOWN} placeholder:
     *   an operand to every check, and a refusal from any check that must
     *   read the text (a sed script, a command name).
     *
     * A `cd` in a loop body is refused outside plan: each iteration would
     * start where the last one left off.
     *
     * @param array<string, mixed>                                         $node
     * @param array<string, array{values: ?list<string>, optionSafe: bool}> $scope
     */
    private static function simpleIsReadOnly(array $node, array $scope, ?string $projectRoot, bool $cdAnywhere, bool &$changedDirectory): bool
    {
        $words = $node['words'];
        $flags = $node['flags'];
        if ($scope === []) {
            return self::commandIsReadOnly($words, $flags, $projectRoot, $cdAnywhere, $changedDirectory);
        }
        if ($words[0] === 'cd' && !$cdAnywhere) {
            return false;
        }

        $references = [];
        $known = [];
        foreach ($words as $w => $word) {
            $refs = self::references($word, $node['dollars'][$w] ?? []);
            if ($refs === null) {
                return false;
            }
            foreach ($refs as $ref) {
                if (!isset($scope[$ref['name']])) {
                    return false;
                }
                if ($scope[$ref['name']]['values'] !== null) {
                    $known[$ref['name']] = $scope[$ref['name']]['values'];
                }
            }
            $references[$w] = $refs;
        }

        $bindings = [[]];
        foreach ($known as $name => $values) {
            $next = [];
            foreach ($bindings as $binding) {
                foreach ($values as $value) {
                    $next[] = $binding + [$name => $value];
                }
            }
            if ($next === [] || \count($next) > self::MAX_BINDINGS) {
                return false;
            }
            $bindings = $next;
        }

        foreach ($bindings as $binding) {
            $bound = $words;
            $boundFlags = $flags;
            foreach ($references as $w => $refs) {
                if ($refs === []) {
                    continue;
                }
                $unknown = false;
                foreach ($refs as $ref) {
                    $unknown = $unknown || !array_key_exists($ref['name'], $binding);
                }
                if (!$unknown) {
                    $bound[$w] = self::substitute($words[$w], $refs, $binding);
                    // Every `$` in it is now text; what bash may still rewrite
                    // is a glob or brace character — read conservatively as
                    // unquoted.
                    $boundFlags[$w] = preg_match('/[*?\[{]/', $bound[$w]) === 1;
                    continue;
                }
                if ($w === 0 || (self::COMMANDS[$bound[0]] ?? null) === null) {
                    // A command name nobody can read (refused below), or an
                    // argument of a command whose arguments are never inspected.
                    continue;
                }
                if (!self::unknownIsAnOperand($words, $flags, $w, $refs, $scope, $bound[0])) {
                    return false;
                }
                $bound[$w] = self::UNKNOWN;
                $boundFlags[$w] = false;
            }
            if (!self::commandIsReadOnly($bound, $boundFlags, $projectRoot, $cdAnywhere, $changedDirectory)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Can word $w — which reads a loop variable whose values are unknown —
     * only ever reach $command as an OPERAND? See {@see simpleIsReadOnly()}.
     *
     * @param list<string>                                                 $words
     * @param list<bool>                                                   $flags
     * @param list<array{offset: int, length: int, name: string, quoted: bool}> $refs
     * @param array<string, array{values: ?list<string>, optionSafe: bool}> $scope
     */
    private static function unknownIsAnOperand(array $words, array $flags, int $w, array $refs, array $scope, string $command): bool
    {
        $word = $words[$w];
        if (preg_match('/[*?\[{]/', $word) === 1) {
            return false;
        }
        $optionSafe = true;
        foreach ($refs as $ref) {
            if (!$ref['quoted']) {
                return false;
            }
            $optionSafe = $optionSafe && $scope[$ref['name']]['optionSafe'];
        }
        $prefix = substr($word, 0, $refs[0]['offset']);
        if (($prefix !== '' && $prefix[0] !== '-') || $optionSafe) {
            return true;
        }
        if ($command === 'find') {
            return false;
        }
        for ($j = 1; $j < $w; ++$j) {
            if ($words[$j] === '--' && !($flags[$j] ?? true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The variables a word reads, from its live `$` positions
     * ({@see ShellWords::$dollars}): `$name` (longest identifier) or
     * `${name}`, in source order — or null when any `$` is something else
     * (`$1`, `$@`, `$?`, `$$`, a lone `$`).
     *
     * @param list<array{0: int, 1: bool}> $dollars
     *
     * @return list<array{offset: int, length: int, name: string, quoted: bool}>|null
     */
    private static function references(string $word, array $dollars): ?array
    {
        $refs = [];
        foreach ($dollars as [$offset, $quoted]) {
            $rest = substr($word, $offset + 1);
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*/', $rest, $match) === 1) {
                $refs[] = ['offset' => $offset, 'length' => 1 + \strlen($match[0]), 'name' => $match[0], 'quoted' => $quoted];
                continue;
            }
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}/', $rest, $match) === 1) {
                $refs[] = ['offset' => $offset, 'length' => 1 + \strlen($match[0]), 'name' => $match[1], 'quoted' => $quoted];
                continue;
            }

            return null;
        }
        usort($refs, static fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);

        return $refs;
    }

    /**
     * $word with each reference replaced by its bound value.
     *
     * @param list<array{offset: int, length: int, name: string, quoted: bool}> $refs
     * @param array<string, string>                                         $binding
     */
    private static function substitute(string $word, array $refs, array $binding): string
    {
        foreach (array_reverse($refs) as $ref) {
            $word = substr_replace($word, $binding[$ref['name']], $ref['offset'], $ref['length']);
        }

        return $word;
    }

    /**
     * `for NAME in WORD…; do …; done`: no substitution in the list (refused
     * for the whole line already), every variable it reads already in scope,
     * NAME not one already bound (after an inner loop re-binds it, the outer
     * name holds the inner's last value), and the body qualifying with NAME
     * bound. The values are KNOWN when every word is literal text a bare
     * substitution cannot split or glob (`candy-core`, `Core:candy-core`);
     * they are option-safe when none can begin with `-` — a glob or variable
     * word must start with a literal letter, digit, `_`, `.` or `/` and read
     * its variables quoted.
     *
     * @param array<string, mixed>                                         $node
     * @param array<string, array{values: ?list<string>, optionSafe: bool}> $scope
     * @param ?\Closure(array<string, mixed>): bool                         $covers
     */
    private static function forQualifies(
        array $node,
        array $scope,
        ?string $projectRoot,
        bool $cdAnywhere,
        bool &$changedDirectory,
        int &$judged,
        bool $readOnly,
        ?\Closure $covers,
    ): bool {
        if (isset($scope[$node['var']]) || $node['words'] === []) {
            return false;
        }
        $values = [];
        $known = true;
        $optionSafe = true;
        foreach ($node['words'] as $w => $word) {
            $refs = self::references($word, $node['dollars'][$w] ?? []);
            if ($refs === null) {
                return false;
            }
            $unquoted = false;
            foreach ($refs as $ref) {
                if (!isset($scope[$ref['name']])) {
                    return false;
                }
                $unquoted = $unquoted || !$ref['quoted'];
            }
            if ($refs !== [] || ($node['flags'][$w] ?? true)) {
                $known = false;
                if ($unquoted || preg_match('/^[A-Za-z0-9_.\/]/', $word) !== 1) {
                    $optionSafe = false;
                }
                continue;
            }
            if (preg_match('/^[A-Za-z0-9_.,:\/@%+=-]+$/', $word) !== 1) {
                $known = false;
            }
            if ($word === '' || $word[0] === '-') {
                $optionSafe = false;
            }
            $values[] = $word;
        }
        $scope[$node['var']] = ['values' => $known ? $values : null, 'optionSafe' => $optionSafe];

        return self::itemsQualify($node['body'], $scope, $projectRoot, $cdAnywhere, $changedDirectory, $judged, $readOnly, $covers);
    }

    /**
     * `while [IFS=…] read [-r] NAME…; do …; done` (the condition's shape is
     * {@see ShellCompound}'s to check): each NAME bound to values nobody
     * knows — lines of input — and not one already bound. The producer
     * (`grep -l x * | while …`, `done < file`) is judged on its own.
     *
     * @param array<string, mixed>                                         $node
     * @param array<string, array{values: ?list<string>, optionSafe: bool}> $scope
     * @param ?\Closure(array<string, mixed>): bool                         $covers
     */
    private static function whileQualifies(
        array $node,
        array $scope,
        ?string $projectRoot,
        bool $cdAnywhere,
        bool &$changedDirectory,
        int &$judged,
        bool $readOnly,
        ?\Closure $covers,
    ): bool {
        foreach ($node['vars'] as $var) {
            if (isset($scope[$var])) {
                return false;
            }
            $scope[$var] = ['values' => null, 'optionSafe' => false];
        }

        return self::itemsQualify($node['body'], $scope, $projectRoot, $cdAnywhere, $changedDirectory, $judged, $readOnly, $covers);
    }

    /**
     * `if …; then …; [elif …; then …;] [else …;] fi`: every condition and
     * every branch qualifies.
     *
     * @param array<string, mixed>                                         $node
     * @param array<string, array{values: ?list<string>, optionSafe: bool}> $scope
     * @param ?\Closure(array<string, mixed>): bool                         $covers
     */
    private static function ifQualifies(
        array $node,
        array $scope,
        ?string $projectRoot,
        bool $cdAnywhere,
        bool &$changedDirectory,
        int &$judged,
        bool $readOnly,
        ?\Closure $covers,
    ): bool {
        $lists = [];
        foreach ($node['branches'] as [$condition, $body]) {
            $lists[] = $condition;
            $lists[] = $body;
        }
        if ($node['else'] !== null) {
            $lists[] = $node['else'];
        }
        foreach ($lists as $list) {
            if (!self::itemsQualify($list, $scope, $projectRoot, $cdAnywhere, $changedDirectory, $judged, $readOnly, $covers)) {
                return false;
            }
        }

        return true;
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
            'sed' => SedCommand::isReadOnly($args),
            'awk' => AwkCommand::isReadOnly($args),
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
