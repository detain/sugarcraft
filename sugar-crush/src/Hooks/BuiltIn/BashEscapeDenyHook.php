<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks\BuiltIn;

use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Permissions\ShellWords;

/**
 * OPT-IN guard that denies Bash commands referencing filesystem paths outside a
 * jail root — the closest a PreToolUse hook can get to the PathJail that
 * {@see \SugarCraft\Crush\Tools\BuiltIn\Edit} et al. enforce, given that Bash
 * itself is intentionally un-jailed.
 *
 * IMPORTANT: this is a best-effort HEURISTIC, NOT a security boundary. For real
 * containment run the process in a jail/container. It is deliberately NOT
 * registered by {@see \SugarCraft\Crush\Hooks\HookManager::registerBuiltIns()}
 * — a caller must construct it with a root and register it explicitly
 * ({@see \SugarCraft\Crush\Backend\EngineBackend::withWorktreeRoot()}).
 *
 * HOW IT READS A COMMAND (audit F-J5). The first version split the raw string
 * on whitespace, so every spelling bash treats as a separator or a redirection
 * hid the path inside a larger token — `cat</etc/shadow`, `cd ..;ls`,
 * `echo x >/tmp/out` were all allowed — while `> /dev/null` and
 * `/usr/bin/php -v` were denied. It now judges the words bash will produce,
 * via the shared quote-aware {@see ShellWords} tokenizer:
 *
 * - every simple command's words and every redirection TARGET are candidates,
 *   so separators, pipes, subshells and glued redirections no longer hide one;
 * - `~`, `~/…`, `$HOME`/`${HOME}` expand to the home directory and
 *   `$PWD`/`${PWD}` to the root (Bash runs every command from it); `$OLDPWD`,
 *   `cd -` and `~user` are unknowable from here and are denied;
 * - the bodies of `$(…)`, backticks, `<(…)`/`>(…)`, `sh -c '…'` (and the other
 *   POSIX shells) and `eval …` are parsed and judged the same way, as is each
 *   alternative of a one-level brace expansion (`{/etc/shadow,}`);
 * - the value of a `--opt=/path` flag is judged; a bare flag is not;
 * - `/dev/null`, `/dev/std{in,out,err}`, `/dev/fd/N` and the read-only random
 *   devices are allowed, as is an absolute path to an existing EXECUTABLE in
 *   command position (`/usr/bin/php -v` runs a program, it names no data).
 *
 * WHAT STILL EVADES IT, stated so nobody reads the list above as containment:
 * a variable it cannot know (`X=/etc; cat $X/passwd` — only a `..` beside an
 * unknown expansion is denied), a symlink inside the root pointing out, a
 * path built at run time (`printf`, `xargs`, a script file), a here-doc body
 * fed to an interpreter, glued short options (`-o/tmp/x`), and anything a
 * program opens on its own. Relative paths are judged against the root even
 * after an in-command `cd sub`: resolving against the shallowest directory the
 * command can be in is the conservative choice, so `cd sub && cat ../x` is
 * over-denied rather than `cd nope; cat ../../x` under-denied.
 */
final readonly class BashEscapeDenyHook implements HookInterface
{
    /**
     * Devices that read or write no file: the inert redirect targets
     * {@see ShellWords::INERT_OUTPUT_TARGETS} plus the input side.
     */
    private const SAFE_DEVICES = [
        '/dev/null', '/dev/stdin', '/dev/stdout', '/dev/stderr',
        '/dev/zero', '/dev/random', '/dev/urandom',
    ];

    /** Shells whose `-c` operand is itself a command line. */
    private const SHELLS = ['sh', 'bash', 'dash', 'zsh', 'ksh', 'mksh', 'ash'];

    /**
     * Words that run the NEXT word as the program, so that word is in command
     * position too (`env /usr/bin/php -v`).
     */
    private const WRAPPERS = ['env', 'command', 'exec', 'nohup', 'time', 'sudo', 'builtin'];

    /** Recursion bound for `sh -c`, `eval` and substitution bodies. */
    private const MAX_DEPTH = 4;

    /** Bound on one word's brace-expansion alternatives. */
    private const MAX_BRACE_ALTERNATIVES = 64;

    /**
     * @param ?string $home the directory `~` and `$HOME` expand to; null reads
     *        `HOME` from the environment the Bash child inherits. An empty or
     *        unknown home makes every `~`/`$HOME` path a denial, never a pass.
     */
    public function __construct(
        private string $root,
        private ?string $home = null,
    ) {}

    public function name(): string
    {
        return 'bash-escape-deny';
    }

    public function event(): HookEvent
    {
        return HookEvent::PreToolUse;
    }

    public function matcher(): string
    {
        return '^Bash$';
    }

    public function execute(HookContext $context): HookResult
    {
        $command = $context->toolArgs['command'] ?? '';
        if (!is_string($command) || trim($command) === '') {
            return HookResult::allow();
        }

        $offender = $this->scanLine($command, 0);
        if ($offender !== null) {
            return HookResult::deny(
                "This hook denies Bash paths outside the workspace root: $offender"
            );
        }

        return HookResult::allow();
    }

    /**
     * The first word of $line that names (or may name) a path outside the
     * root, or null.
     */
    private function scanLine(string $line, int $depth): ?string
    {
        if ($depth > self::MAX_DEPTH) {
            // Nesting this deep is not something a coding agent writes; refusing
            // is cheaper than a recursion nobody has reasoned about.
            return $line;
        }

        $parsed = ShellWords::parse($line);

        foreach ($parsed->commands as $index => $words) {
            $offender = $this->scanCommand($words, $parsed->expandable[$index] ?? [], $depth);
            if ($offender !== null) {
                return $offender;
            }
        }

        foreach ($parsed->redirections as $redirection) {
            $offender = $this->scanRedirection($redirection, $depth);
            if ($offender !== null) {
                return $offender;
            }
        }

        if (!$parsed->complete) {
            // An unterminated quote or here-doc still runs (bash warns and
            // carries on for a here-doc), and the tokenizer's words are only a
            // best effort then — so the raw text gets the old whitespace scan
            // too, split on the operator characters the first version missed.
            foreach (preg_split('/[\s;&|<>()]+/', $line) ?: [] as $raw) {
                $offender = $this->judgeArgument(trim($raw, "\"'`"), true);
                if ($offender !== null) {
                    return $offender;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $words
     * @param list<bool>   $expandable
     */
    private function scanCommand(array $words, array $expandable, int $depth): ?string
    {
        // Substitution bodies run whatever word they sit in — an assignment, a
        // flag, the command name — so they are judged before anything is skipped.
        foreach ($words as $i => $word) {
            if (($expandable[$i] ?? false) === false) {
                continue;
            }
            foreach ($this->substitutionBodies($word) as $body) {
                $offender = $this->scanLine($body, $depth + 1);
                if ($offender !== null) {
                    return $offender;
                }
            }
        }

        $commandAt = $this->commandIndex($words);
        if ($commandAt === null) {
            return null; // assignments only
        }

        $program = basename($words[$commandAt]);
        $innerScript = null;

        foreach ($words as $i => $word) {
            $isExpandable = $expandable[$i] ?? false;
            if ($i < $commandAt && $this->isAssignment($word)) {
                continue; // an environment assignment names no file
            }

            if ($i === $commandAt) {
                if ($this->isExecutable($word)) {
                    continue;
                }
                $offender = $this->judgeArgument($word, $isExpandable);
                if ($offender !== null) {
                    return $offender;
                }
                continue;
            }

            if ($program === 'cd' && $word === '-') {
                return 'cd - (returns to $OLDPWD, which this hook cannot know)';
            }

            if (str_starts_with($word, '-')) {
                if (in_array($program, self::SHELLS, true) && $this->isDashC($word)) {
                    $innerScript = $i + 1;
                }
                $eq = strpos($word, '=');
                if ($eq !== false) {
                    $offender = $this->judgeArgument(substr($word, $eq + 1), $isExpandable);
                    if ($offender !== null) {
                        return $offender;
                    }
                }
                continue;
            }

            if ($i === $innerScript || $program === 'eval') {
                $offender = $this->scanLine($word, $depth + 1);
                if ($offender !== null) {
                    return $offender;
                }
                if ($program !== 'eval') {
                    continue; // the script text itself is not a path
                }
            }

            $offender = $this->judgeArgument($word, $isExpandable);
            if ($offender !== null) {
                return $offender;
            }
        }

        return null;
    }

    /**
     * @param array{command: int, fd: ?string, op: string, target: ?string} $redirection
     */
    private function scanRedirection(array $redirection, int $depth): ?string
    {
        $target = $redirection['target'] ?? '';
        if ($target === '') {
            return null;
        }

        switch ($redirection['op']) {
            case '<<':
            case '<<-':
                return null; // a here-doc delimiter, not a path
            case '<<<':
                // A here-string is data on stdin; only a substitution in it runs.
                foreach ($this->substitutionBodies($target) as $body) {
                    $offender = $this->scanLine($body, $depth + 1);
                    if ($offender !== null) {
                        return $offender;
                    }
                }

                return null;
            case '>&':
            case '<&':
                if (preg_match('/^(?:\d+-?|-)$/', $target) === 1) {
                    return null; // fd duplication or close
                }
                break;
        }

        foreach ($this->substitutionBodies($target) as $body) {
            $offender = $this->scanLine($body, $depth + 1);
            if ($offender !== null) {
                return $offender;
            }
        }

        // ShellWords does not record per-target expandability; a redirection
        // target is judged as expandable so `> ~/x` and `> $HOME/x` are read.
        return $this->judgeArgument($target, true);
    }

    /**
     * Judge one word bash will hand a program (or open itself): null when it
     * stays inside the root, the offending spelling otherwise.
     */
    private function judgeArgument(string $word, bool $expandable): ?string
    {
        if ($word === '') {
            return null;
        }

        foreach ($this->braceAlternatives($word, $expandable) as $candidate) {
            if ($this->judgePath($candidate, $expandable) !== null) {
                return $word;
            }
        }

        return null;
    }

    private function judgePath(string $word, bool $expandable): ?string
    {
        $path = $word;

        if (str_starts_with($path, '~')) {
            $slash = strpos($path, '/');
            $user = $slash === false ? substr($path, 1) : substr($path, 1, $slash - 1);
            if ($user !== '') {
                return $word; // `~user`: another account's home is never inside the root
            }
            $home = $this->homeDir();
            if ($home === null) {
                return $word;
            }
            $path = $home . ($slash === false ? '' : substr($path, $slash));
        }

        if ($expandable && str_contains($path, '$')) {
            if (preg_match('/\$(?:\{OLDPWD\}|OLDPWD(?![A-Za-z0-9_]))/', $path) === 1) {
                return $word; // the directory before Bash's `cd ROOT`, unknowable here
            }
            $home = $this->homeDir();
            $path = (string) preg_replace_callback(
                '/\$(?:\{(HOME|PWD)\}|(HOME|PWD)(?![A-Za-z0-9_]))/',
                function (array $m) use ($home): string {
                    $name = $m[1] !== '' ? $m[1] : $m[2];
                    // An unknown home must not expand to '' (that would
                    // judge `$HOME/.ssh` as `/.ssh`); a NUL marks the word
                    // unresolvable so the check below denies it.
                    return $name === 'PWD' ? $this->root : ($home ?? "\0");
                },
                $path,
            );
            if (str_contains($path, "\0")) {
                return $word;
            }
        }

        if (in_array($path, self::SAFE_DEVICES, true) || preg_match('#^/dev/fd/\d+$#', $path) === 1) {
            return null;
        }

        $unresolved = $expandable && (str_contains($path, '$') || str_contains($path, '`'));
        if ($unresolved && $this->hasDotDot($path)) {
            // `$(pwd)/..`, `$X/../..`: the base is unknown, so `..` beside it
            // cannot be proven to stay inside.
            return $word;
        }

        if (!str_starts_with($path, '/') && !$this->hasDotDot($path)) {
            // A plain relative word stays inside the root by construction; a
            // word that STARTS with an unknown expansion cannot be judged.
            return null;
        }

        $resolved = $this->lexicalResolve($this->root, $path);
        foreach ($this->roots() as $root) {
            if ($this->within($root, $resolved)) {
                return null;
            }
        }

        return $word;
    }

    /**
     * The index of the word bash runs as the program: past leading
     * `NAME=value` assignments and through {@see WRAPPERS} (with their flags
     * and, for `env`, their assignments). Null when the command is only
     * assignments.
     *
     * @param list<string> $words
     */
    private function commandIndex(array $words): ?int
    {
        $i = 0;
        $count = count($words);
        while ($i < $count && $this->isAssignment($words[$i])) {
            $i++;
        }
        while ($i < $count && in_array($words[$i], self::WRAPPERS, true)) {
            $i++;
            while ($i < $count && (str_starts_with($words[$i], '-') || $this->isAssignment($words[$i]))) {
                $i++;
            }
        }

        return $i < $count ? $i : null;
    }

    private function isAssignment(string $word): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*\+?=/', $word) === 1;
    }

    /** `-c`, or a cluster carrying it (`-lc`, `-ec`). */
    private function isDashC(string $word): bool
    {
        return preg_match('/^-[A-Za-z]*c[A-Za-z]*$/', $word) === 1;
    }

    private function isExecutable(string $word): bool
    {
        return str_starts_with($word, '/') && is_file($word) && is_executable($word);
    }

    /**
     * The command text inside every `$(…)`, `<(…)`, `>(…)` and backtick
     * substitution in $word (outermost only; {@see scanLine()} recurses).
     *
     * @return list<string>
     */
    private function substitutionBodies(string $word): array
    {
        $bodies = [];
        $length = strlen($word);
        for ($i = 0; $i < $length; $i++) {
            $char = $word[$i];
            if ($char === '`') {
                $end = strpos($word, '`', $i + 1);
                $bodies[] = substr($word, $i + 1, $end === false ? null : $end - $i - 1);
                if ($end === false) {
                    break;
                }
                $i = $end;
                continue;
            }
            if (($char === '$' || $char === '<' || $char === '>') && ($word[$i + 1] ?? '') === '(') {
                if ($char === '$' && ($word[$i + 2] ?? '') === '(') {
                    continue; // `$((…))` is arithmetic, not a command
                }
                $level = 0;
                for ($j = $i + 1; $j < $length; $j++) {
                    if ($word[$j] === '(') {
                        $level++;
                    } elseif ($word[$j] === ')' && --$level === 0) {
                        break;
                    }
                }
                $bodies[] = substr($word, $i + 2, $j - $i - 2);
                $i = $j;
            }
        }

        return $bodies;
    }

    /**
     * One level of brace expansion: `{/etc/shadow,}` yields `/etc/shadow` and
     * the empty word. Sequences (`{1..3}`) and nested braces come back as the
     * literal word, which {@see judgePath()} then reads as written.
     *
     * @return list<string>
     */
    private function braceAlternatives(string $word, bool $expandable): array
    {
        if (!$expandable || preg_match('/^(.*?)\{([^{}]*,[^{}]*)\}(.*)$/s', $word, $m) !== 1) {
            return [$word];
        }

        $out = [];
        foreach (explode(',', $m[2]) as $alternative) {
            foreach ($this->braceAlternatives($m[1] . $alternative . $m[3], true) as $expanded) {
                $out[] = $expanded;
                if (count($out) >= self::MAX_BRACE_ALTERNATIVES) {
                    return $out;
                }
            }
        }

        return $out;
    }

    private function homeDir(): ?string
    {
        $home = $this->home ?? (getenv('HOME') ?: null);
        if ($home === null || $home === '' || !str_starts_with($home, '/')) {
            return null;
        }

        return rtrim($home, '/') ?: '/';
    }

    /**
     * The root as written AND as resolved: a path spelled through the
     * symlinked form (`/home/me/proj` when `/home` links to `/data/home`) is
     * as inside as the canonical one.
     *
     * @return list<string>
     */
    private function roots(): array
    {
        $written = $this->normalize($this->root);
        $real = realpath($this->root);

        return $real === false || $real === $written ? [$written] : [$written, $real];
    }

    private function hasDotDot(string $path): bool
    {
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                return true;
            }
        }

        return false;
    }

    /**
     * Lexically resolve a token against the root WITHOUT touching the
     * filesystem (the target may not exist yet), collapsing `.` and `..`.
     */
    private function lexicalResolve(string $root, string $token): string
    {
        $base = str_starts_with($token, '/') ? $token : $root . '/' . $token;

        return $this->normalize($base);
    }

    private function normalize(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/');
        $stack = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($stack);
                continue;
            }
            $stack[] = $part;
        }

        return ($isAbsolute ? '/' : '') . implode('/', $stack);
    }

    private function within(string $root, string $path): bool
    {
        return $path === $root || str_starts_with($path, $root . '/');
    }
}
