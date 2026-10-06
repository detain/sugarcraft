<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

use SugarCraft\Crush\Hooks\BuiltIn\ProtectFilesHook;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\ToolCall;

/**
 * The "always allow (this session)" grants an interactive session has given
 * on the ENGINE path — as permission-rule PATTERNS rather than exact calls
 * (roadmap 1.C-2, Appendix O §5.1 "`always` scope").
 *
 * WHAT A GRANT COVERS, per tool, derived by {@see patternsFor()} from the call
 * the user answered:
 *
 * | tool                                  | grant                              |
 * |---------------------------------------|------------------------------------|
 * | `Bash`, one simple command            | its command prefix: `git status` → `Bash(git status)` + `Bash(git status *)`; `ls -la` → `Bash(ls)` + `Bash(ls *)` |
 * | `Bash`, a pipeline or chain (`\|` `&&` `\|\|`) | ONE GRANT PER PART: `npm test 2>&1 \| tail -20` → `Bash(npm test)` + `Bash(npm test *)` + `Bash(tail)` + `Bash(tail *)` ({@see segmentPatterns()}) |
 * | `Bash`, a `;` list, or a chain with a launcher / inline code | the WHOLE SHAPE, prefix per segment, operators kept: `make; make test` → `Bash(make *; make test *)` ({@see compoundScope()}) |
 * | `Read`/`Edit`/`Write`/`Glob`/`Grep`/`Lsp` | that exact path: `Edit(src/A.php)` |
 * | `WebFetch`                            | that host: `WebFetch(domain:example.com)` |
 * | `WebSearch`, `Skill`                  | that exact subject                 |
 * | a tool with no subject argument (`mcp__*`, `Task`, …) | the tool: `mcp__git__status` |
 *
 * A LEADING IN-PROJECT `cd <dir> &&` IS A NO-OP for every grant
 * ({@see grantArguments()}, {@see LeadingCd}): `always` on
 * `cd /repo && git status --short` remembers `Bash(git status *)`, and a later
 * `git status`, `cd /repo && git status` or `cd /repo/sub && git status` is
 * covered — the remainder is what is remembered AND what a later call is
 * checked against, exact-call keys included. Only one such prefix comes off;
 * anything else about the line is judged as written. Every consumer passes
 * the session's project root; without one nothing is stripped.
 *
 * Subcommand tools (`git`, `npm`, `composer`, …) keep their subcommand,
 * interpreters (`php`, `python3`, `node`, …) keep their script, and a
 * launcher whose job is to run something else (`bash -c`, `sudo`, `xargs`,
 * `find`, `env`, …) or an interpreter handed inline code (`php -r`) gets no
 * pattern: a prefix grant on those would be a grant on everything.
 *
 * Commands that delete, overwrite, move or re-permission ({@see EXACT_ONLY}:
 * `rm`, `mv`, `cp`, `chmod`, `dd`, `tee`, …) are never generalised by default
 * either: `rm a.txt` is remembered as exactly that. The user can still grant
 * a broader scope by writing it ({@see withPattern()}, the modal's `e`).
 *
 * A PIPELINE OR CHAIN joined by `|` `&&` `||` is remembered PART BY PART
 * (user decision 2026-10-11, {@see segmentPatterns()}), and a later line is
 * covered when each of its commands is covered by some part or is read-only
 * ({@see coversBySegments()}) — the model re-shapes the same commands every
 * time, so a grant on the whole shape was never seen again. Otherwise (a `;`
 * list, a launcher or inline code anywhere) the line is generalised segment
 * by segment ({@see compoundScope()}), its operators kept, into a pattern
 * {@see PermissionRule}'s `Allow` arm matches by STRUCTURE: a later line is
 * covered only when it has the same operators in the same order and each
 * command matches its segment (`x *` covering `x` with no arguments). A
 * segment that cannot be generalised — a launcher, inline code, a destructive
 * command, a writing redirection, a `curl`/`wget` piped on, a `cd`, a shell
 * reserved word — is kept literally in its place. A line with a substitution,
 * a `${…}` expansion, a newline, a background `&` or a subshell gets no
 * pattern, and neither does one no segment of which could be generalised:
 * those (and any call whose subject cannot be read) are remembered as the
 * EXACT call instead, which {@see allows()} answers but the gate's rules
 * never see.
 *
 * THE EXACT CALL IS WHAT RUNS, NOT WHAT THE MODEL SAID ABOUT IT
 * ({@see identityArguments()}): `Bash`'s required `description` is the
 * model's own caption, rewritten on every call, and `timeout` only bounds how
 * long the same command may take. Keyed on them, an "always" for a chain was
 * never seen again — the identical command came back with a new caption and
 * was asked about anew. They are left out of the exact-call identity, here,
 * in {@see \SugarCraft\Crush\Backend\ChildChannel}'s per-turn memo and in the
 * Chat tool path's grant key alike.
 *
 * WHAT A GRANT NEVER DOES: override a refusal. These rules reach the gate
 * through {@see PermissionGate::withSessionRules()}, which consults them only
 * to turn an `Ask` into an `Allow` — a configured `Deny`, Plan mode's refusals
 * and the `rm -rf /` breaker all win, and an `Allow` rule still fires only
 * when every command in a chain matches it (the asymmetry
 * {@see PermissionRule} documents). Nothing here is written to a settings
 * file: a grant lives exactly as long as the session that gave it.
 *
 * STORED IN `Chat::$permissionGrants`, beside the Command path's exact-call
 * keys, under the {@see RULE_KEY} / {@see CALL_KEY} prefixes — the two
 * key spaces cannot collide, because an exact Command-path key opens with a
 * tool name and no tool is named `rule:` or `call:`. {@see fromGrants()} reads
 * back only the prefixed half, so a Command-path grant never becomes a
 * pattern.
 */
final class SessionPermissionMemo
{
    public const RULE_KEY = 'rule:';

    public const CALL_KEY = 'call:';

    /**
     * Commands whose first argument picks WHAT they do (`git push` is not
     * `git log`), so a grant keeps two words of them rather than one.
     */
    private const SUBCOMMAND_TOOLS = [
        'git', 'npm', 'pnpm', 'yarn', 'composer', 'cargo', 'go', 'docker',
        'kubectl', 'gh', 'pip', 'pip3', 'brew', 'apt', 'apt-get', 'systemctl', 'make',
        'dotnet', 'mvn', 'gradle',
    ];

    /**
     * Interpreters: what they do is the SCRIPT they are handed, so a grant
     * keeps the script's name — and one started on inline code (`php -r`,
     * `python3 -c`) has no name to keep and is remembered exactly instead.
     */
    private const INTERPRETERS = ['php', 'python', 'python3', 'node', 'deno', 'bun'];

    /**
     * Commands whose whole job is to run ANOTHER command or arbitrary code
     * (`bash -c …`, `sudo …`, `xargs …`, `find … -exec`), so a prefix grant on
     * them would be a grant on everything. Always remembered exactly.
     */
    private const LAUNCHERS = [
        'bash', 'sh', 'zsh', 'dash', 'fish', 'ksh', 'env', 'sudo', 'doas', 'su', 'xargs',
        'eval', 'exec', 'nohup', 'timeout', 'nice', 'ionice', 'watch', 'time', 'command',
        'builtin', 'source', '.', 'find', 'awk', 'gawk', 'mawk', 'nawk', 'perl', 'ruby', 'ssh',
        'csh', 'tcsh', 'pwsh', 'lua', 'tclsh', 'osascript', 'parallel', 'stdbuf', 'unbuffer',
        'script', 'strace', 'ltrace', 'gdb', 'chroot', 'setsid', 'flock', 'runuser', 'pkexec', 'busybox',
    ];

    /**
     * Arguments that annotate a call rather than choose what it does, per
     * tool: left out of the exact-call identity ({@see identityArguments()}).
     * `interactive` is deliberately NOT here — it changes how the command
     * runs (attached to a terminal), so a grant for one form is not one for
     * the other.
     */
    private const ANNOTATION_ARGUMENTS = [
        'Bash' => ['description', 'timeout'],
    ];

    /**
     * Commands that delete, overwrite, move, re-permission or signal: never
     * generalised as a SUGGESTED grant (`rm a.txt` is remembered exactly, not
     * as `rm *`). A user who wants the broader grant writes it with `e` in the
     * modal ({@see withPattern()}).
     */
    private const EXACT_ONLY = [
        'rm', 'rmdir', 'unlink', 'shred', 'mv', 'cp', 'ln', 'install', 'dd', 'truncate',
        'chmod', 'chown', 'chgrp', 'chattr', 'tee', 'kill', 'killall', 'pkill', 'rsync', 'scp',
        'mount', 'umount', 'crontab', 'reboot', 'shutdown', 'cd', 'pushd', 'popd',
        // Reserved words head a "command" the tokeniser splits off a compound
        // statement (`for d in …; do rm "$d"; done`): `do *` would be any command.
        'if', 'then', 'else', 'elif', 'fi', 'for', 'while', 'until', 'do', 'done', 'case', 'esac',
        'select', 'function', 'coproc', '{', '}', '!', '[[', ']]', '((',
    ];

    /** Commands that fetch from the network: kept literal when their output is piped on. */
    private const FETCHERS = ['curl', 'wget'];

    /** The operators a whole-shape grant may be written around ({@see compoundScope()}). */
    private const STRUCTURE_OPERATORS = ['|', '&&', '||', ';'];

    /**
     * The operators a line may join its commands with to be remembered, and
     * covered, PART BY PART ({@see segmentPatterns()}, {@see coversBySegments()}).
     * Not `;` or a newline: a list of unrelated commands is remembered as its
     * whole shape, and covered per part only when every part is read-only.
     */
    private const SEGMENT_OPERATORS = ['|', '&&', '||'];

    /**
     * Shell reserved words: they head a "command" the tokeniser splits off a
     * compound statement, so a line with one is never judged per part.
     */
    private const RESERVED_WORDS = [
        'if', 'then', 'else', 'elif', 'fi', 'for', 'while', 'until', 'do', 'done', 'case', 'esac',
        'select', 'function', 'coproc', '{', '}', '!', '[[', ']]', '((',
    ];

    /** `<tool> <subcommand>` pairs whose NEXT word picks what they do too (`npm run build`). */
    private const THIRD_WORD_PAIRS = ['npm run', 'pnpm run', 'yarn run', 'docker compose'];

    /**
     * @param list<string> $patterns {@see PermissionRule} patterns, in grant order, unique
     * @param list<string> $calls    exact-call keys, unique
     */
    private function __construct(
        private readonly array $patterns,
        private readonly array $calls,
    ) {
    }

    public static function new(): self
    {
        return new self([], []);
    }

    /**
     * The memo held in a session's grant map — see the class docblock for the
     * key spaces. Keys that are not this class's, and patterns the rule
     * grammar refuses, are skipped.
     *
     * @param array<string, bool> $grants
     */
    public static function fromGrants(array $grants): self
    {
        $patterns = [];
        $calls = [];
        foreach ($grants as $key => $granted) {
            if ($granted !== true || !is_string($key)) {
                continue;
            }
            if (str_starts_with($key, self::RULE_KEY)) {
                $pattern = substr($key, strlen(self::RULE_KEY));
                if ($pattern !== '' && PermissionRule::isWellFormedPattern($pattern)) {
                    $patterns[] = $pattern;
                }
            } elseif (str_starts_with($key, self::CALL_KEY) && strlen($key) > strlen(self::CALL_KEY)) {
                $calls[] = substr($key, strlen(self::CALL_KEY));
            }
        }

        return new self(array_values(array_unique($patterns)), array_values(array_unique($calls)));
    }

    /**
     * This memo as grant-map entries, for merging back into the map it came
     * from.
     *
     * @return array<string, true>
     */
    public function grants(): array
    {
        $grants = [];
        foreach ($this->patterns as $pattern) {
            $grants[self::RULE_KEY . $pattern] = true;
        }
        foreach ($this->calls as $call) {
            $grants[self::CALL_KEY . $call] = true;
        }

        return $grants;
    }

    /**
     * Remember "always" for the call the user answered.
     *
     * @param array<string, mixed> $arguments the arguments that were asked about
     *                                        (after any hook rewrite)
     * @param string|null $projectRoot the session's root, for {@see grantArguments()}
     */
    public function withGrant(string $tool, array $arguments, ?string $projectRoot = null): self
    {
        $patterns = self::patternsFor($tool, $arguments, $projectRoot);
        if ($patterns === []) {
            $key = self::callKey($tool, $arguments, $projectRoot);
            if ($key === null || in_array($key, $this->calls, true)) {
                return $this;
            }

            return new self($this->patterns, [...$this->calls, $key]);
        }

        $merged = array_values(array_unique([...$this->patterns, ...$patterns]));

        return $merged === $this->patterns ? $this : new self($merged, $this->calls);
    }

    /**
     * Remember the EXACT call, never a pattern — for a caller whose tools'
     * subjects this class cannot read (Chat's own tool path, where a
     * subject-less tool would otherwise be granted whole).
     *
     * @param array<string, mixed> $arguments
     */
    public function withExactGrant(string $tool, array $arguments, ?string $projectRoot = null): self
    {
        $key = self::callKey($tool, $arguments, $projectRoot);
        if ($key === null || in_array($key, $this->calls, true)) {
            return $this;
        }

        return new self($this->patterns, [...$this->calls, $key]);
    }

    /**
     * Remember a scope the USER wrote (the modal's `e`) for the call being
     * asked about, or null when it is refused: it must be a well-formed
     * {@see PermissionRule} pattern naming exactly this tool (no tool-name
     * glob), and it must cover the call in front of the user — so a typo
     * cannot quietly grant something else while this call still asks. $scope
     * is either the argument pattern alone (`sed * | sort | uniq`, wrapped as
     * `Bash(…)`) or the whole pattern (`Bash(sed * | sort | uniq)`).
     *
     * Broader than a suggestion may be (`rm *`): that is the point of letting
     * the user write it. A configured `Deny`, Plan mode and the breaker still
     * win — a grant only ever answers an `Ask`.
     *
     * @param array<string, mixed> $arguments the call being asked about
     */
    public function withPattern(string $scope, string $tool, array $arguments, ?string $projectRoot = null): ?self
    {
        $patterns = self::scopeGrant($scope, $tool, $arguments, $projectRoot);
        if ($patterns === null) {
            return null;
        }
        $merged = array_values(array_unique([...$this->patterns, ...$patterns]));

        return $merged === $this->patterns ? $this : new self($merged, $this->calls);
    }

    /**
     * The patterns a scope the USER wrote grants for this call, or null when
     * it is refused (see {@see withPattern()}). The scope is ONE pattern
     * (`sed * | sort * | uniq *`) or a COMMA-SEPARATED LIST of them, one per
     * part of the line (`git log *, head *, echo *` — what the editor is
     * prefilled with for a per-part grant; a comma must be followed by a
     * space to separate, so `sed -n 1,5p` stays one). Read whole first, then
     * as a list. A simple-command pattern ending ` *` also grants its bare
     * form (`git status *` covers `git status` — "any arguments" includes
     * none), as a suggested grant always has.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<string>|null
     */
    private static function scopeGrant(string $scope, string $tool, array $arguments, ?string $projectRoot): ?array
    {
        if (self::escape($tool) !== $tool) {
            return null;
        }
        $call = new ToolCall($tool, self::grantArguments($tool, $arguments, $projectRoot));
        // What each written pattern must match at least one of — the call,
        // or one command of it — so no part of the scope is a typo that
        // quietly grants something else.
        $readings = [$call];
        $command = $call->arguments['command'] ?? null;
        if ($tool === 'Bash' && is_string($command)) {
            $parsed = ShellWords::parse($command);
            foreach (\count($parsed->sources) > 1 ? $parsed->sources : [] as $source) {
                $readings[] = new ToolCall('Bash', ['command' => trim($source)]);
            }
        }

        $candidates = [];
        $list = self::scopePatterns($scope, $tool);
        if ($list !== null && \count($list) > 1) {
            $candidates[] = $list;
        }
        $whole = self::patternFromScope($scope, $tool);
        if ($whole !== null) {
            $candidates[] = [$whole];
        }

        foreach ($candidates as $patterns) {
            $granted = [];
            foreach ($patterns as $pattern) {
                $rule = new PermissionRule($pattern, PermissionAction::Allow);
                if ($rule->toolNamePattern() !== $tool) {
                    continue 2;
                }
                $bare = self::bareSibling($rule);
                $own = $bare === null ? [$rule] : [$rule, new PermissionRule($bare, PermissionAction::Allow)];
                $useful = false;
                foreach ($own as $candidate) {
                    foreach ($readings as $reading) {
                        $useful = $useful || $candidate->matches($reading, true, $projectRoot);
                    }
                }
                if (!$useful) {
                    continue 2;
                }
                if ($bare !== null) {
                    $granted[] = $bare;
                }
                $granted[] = $pattern;
            }
            $granted = array_values(array_unique($granted));
            $rules = array_map(static fn (string $p): PermissionRule => new PermissionRule($p, PermissionAction::Allow), $granted);
            foreach ($rules as $rule) {
                if ($rule->matches($call, true, $projectRoot)) {
                    return $granted;
                }
            }
            if (self::coversBySegments($rules, $call, $projectRoot, ReadOnlyCommands::autoAllowEnabled())) {
                return $granted;
            }
        }

        return null;
    }

    /**
     * `Tool(x)` for a `Tool(x *)` whose `x` is one simple command (no
     * operator), else null.
     */
    private static function bareSibling(PermissionRule $rule): ?string
    {
        $argument = $rule->argumentPattern();
        if ($argument === null || !str_ends_with($argument, ' *') || strlen($argument) < 3) {
            return null;
        }
        $bare = substr($argument, 0, -2);
        if (ShellWords::parse($bare)->operators !== []) {
            return null;
        }
        $pattern = $rule->toolNamePattern() . '(' . $bare . ')';

        return PermissionRule::isWellFormedPattern($pattern) ? $pattern : null;
    }

    /**
     * A scope the user typed read as a COMMA-SEPARATED LIST (a comma
     * followed by whitespace separates), each part a well-formed pattern for
     * $tool — or null when it is empty or any part is not.
     *
     * @return list<string>|null
     */
    public static function scopePatterns(string $scope, string $tool): ?array
    {
        $patterns = [];
        foreach (preg_split('/,\s+/', trim($scope)) ?: [] as $part) {
            $pattern = self::patternFromScope($part, $tool);
            if ($pattern === null) {
                return null;
            }
            $patterns[] = $pattern;
        }

        return $patterns === [] ? null : $patterns;
    }

    /**
     * How a scope the user wrote reads once remembered — the {@see scopeOf()}
     * spelling of what {@see withPattern()} would grant for this call — or
     * null when it would be refused.
     *
     * @param array<string, mixed> $arguments
     */
    public static function scopeLabel(string $scope, string $tool, array $arguments, ?string $projectRoot = null): ?string
    {
        $patterns = self::scopeGrant($scope, $tool, $arguments, $projectRoot);

        return $patterns === null ? null : self::describePatterns($patterns);
    }

    /**
     * Patterns as one line the user reads: a `Tool(x)` granted beside its
     * `Tool(x *)` is the same grant and is not repeated —
     * `Bash(git log *), Bash(head *)`.
     *
     * @param list<string> $patterns
     */
    private static function describePatterns(array $patterns): string
    {
        return implode(', ', self::shownPatterns($patterns));
    }

    /**
     * $patterns without each `Tool(x)` whose `Tool(x *)` is there too.
     *
     * @param list<string> $patterns
     *
     * @return list<string>
     */
    private static function shownPatterns(array $patterns): array
    {
        return array_values(array_filter(
            $patterns,
            static fn (string $p): bool => !(str_ends_with($p, ')') && in_array(substr($p, 0, -1) . ' *)', $patterns, true)),
        ));
    }

    /**
     * The `Tool(…)` pattern a scope the user typed names, or null when it is
     * empty or not well-formed.
     */
    public static function patternFromScope(string $scope, string $tool): ?string
    {
        $scope = trim((string) preg_replace('/\s+/', ' ', $scope));
        if ($scope === '') {
            return null;
        }
        $pattern = str_starts_with($scope, $tool . '(') && str_ends_with($scope, ')') ? $scope : $tool . '(' . $scope . ')';

        return PermissionRule::isWellFormedPattern($pattern) ? $pattern : null;
    }

    /**
     * What the scope editor starts from for this call: the suggested
     * pattern's argument half (`sed * | sort * | uniq *`), or — when only the
     * exact call would be remembered — the command (or subject) itself,
     * escaped so it matches itself. Null for a tool with no subject argument,
     * whose only scope is the tool.
     *
     * @param array<string, mixed> $arguments
     */
    public static function editableScopeOf(string $tool, array $arguments, ?string $projectRoot = null): ?string
    {
        $subjectName = PermissionRule::subjectArgumentName($tool);
        if ($subjectName === null) {
            return null;
        }
        $patterns = self::patternsFor($tool, $arguments, $projectRoot);
        if ($patterns !== []) {
            $shown = self::shownPatterns($patterns);
            $halves = array_map(
                static fn (string $p): ?string => (new PermissionRule($p, PermissionAction::Allow))->argumentPattern(),
                $shown,
            );

            return in_array(null, $halves, true) ? null : implode(', ', $halves);
        }
        $subject = self::grantArguments($tool, $arguments, $projectRoot)[$subjectName] ?? null;
        if (!is_string($subject) || trim($subject) === '' || str_contains($subject, "\n")) {
            return null;
        }

        return self::escape(trim((string) preg_replace('/[ \t]+/', ' ', $subject)));
    }

    /**
     * Every grant, in the order given, as the user reads it in
     * `/permissions`: a pattern as written (`Bash(git status *)` — the bare
     * `Bash(git status)` granted beside it is the same grant and is not listed
     * twice), an exact call as `Tool: <subject>` (or its arguments as JSON).
     *
     * @return list<string>
     */
    public function entries(): array
    {
        return array_map(static fn (array $row): string => $row[0], $this->rows());
    }

    /**
     * This memo without its $index-th {@see entries()} row (1-based), or null
     * when there is no such row. A listed `Tool(p *)` takes its bare
     * `Tool(p)` with it.
     */
    public function without(int $index): ?self
    {
        $row = $this->rows()[$index - 1] ?? null;
        if ($row === null) {
            return null;
        }
        [, $patterns, $calls] = $row;

        return new self(
            array_values(array_diff($this->patterns, $patterns)),
            array_values(array_diff($this->calls, $calls)),
        );
    }

    /**
     * @return list<array{0: string, 1: list<string>, 2: list<string>}> [label, patterns, calls]
     */
    private function rows(): array
    {
        $rows = [];
        foreach ($this->patterns as $pattern) {
            $wide = str_ends_with($pattern, ')') ? substr($pattern, 0, -1) . ' *)' : null;
            if ($wide !== null && in_array($wide, $this->patterns, true)) {
                continue;
            }
            $bare = str_ends_with($pattern, ' *)') ? substr($pattern, 0, -3) . ')' : null;
            $rows[] = [$pattern, $bare !== null && in_array($bare, $this->patterns, true) ? [$pattern, $bare] : [$pattern], []];
        }
        foreach ($this->calls as $call) {
            $rows[] = [self::describeCall($call), [], [$call]];
        }

        return $rows;
    }

    /**
     * An exact-call key as one readable line: `Bash: git push` for a tool
     * whose subject is known, else `<tool> <canonical JSON>`.
     */
    private static function describeCall(string $call): string
    {
        $space = strpos($call, ' ');
        if ($space === false) {
            return $call;
        }
        $tool = substr($call, 0, $space);
        $arguments = json_decode(substr($call, $space + 1), true);
        $subjectName = PermissionRule::subjectArgumentName($tool);
        if (is_array($arguments) && $subjectName !== null && is_string($arguments[$subjectName] ?? null)) {
            $rest = $arguments;
            unset($rest[$subjectName]);

            return $tool . ': ' . $arguments[$subjectName] . ($rest === [] ? '' : ' ' . json_encode($rest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return $call;
    }

    public function isEmpty(): bool
    {
        return $this->patterns === [] && $this->calls === [];
    }

    /**
     * @return list<string>
     */
    public function patterns(): array
    {
        return $this->patterns;
    }

    /**
     * The pattern grants as `Allow` rules, for
     * {@see PermissionGate::withSessionRules()}.
     *
     * @return list<PermissionRule>
     */
    public function rules(): array
    {
        return array_map(
            static fn (string $pattern): PermissionRule => new PermissionRule($pattern, PermissionAction::Allow),
            $this->patterns,
        );
    }

    /**
     * Does a grant in this memo answer an ask about this call?
     *
     * @param array<string, mixed> $arguments
     */
    public function allows(string $tool, array $arguments, ?string $projectRoot = null): bool
    {
        $key = self::callKey($tool, $arguments, $projectRoot);
        if ($key !== null && in_array($key, $this->calls, true)) {
            return true;
        }

        $call = new ToolCall($tool, self::grantArguments($tool, $arguments, $projectRoot));
        foreach ($this->rules() as $rule) {
            if ($rule->matches($call, true, $projectRoot)) {
                return true;
            }
        }

        return self::coversBySegments($this->rules(), $call, $projectRoot, ReadOnlyCommands::autoAllowEnabled());
    }

    /**
     * PER-PART COVERAGE (user decision 2026-10-11): does a `Bash` line split
     * — fail closed — into simple commands joined only by `|`, `&&` and
     * `||`, EVERY ONE of which is covered on its own by one of $rules (a
     * remembered segment grant such as `Bash(git log *)`) or, with
     * $readOnlyCovers, is a read-only command ({@see ReadOnlyCommands})?
     *
     * Where one {@see PermissionRule} must cover every command of a line
     * (its `Allow` arm's intersection), this lets DIFFERENT grants cover
     * different commands: `a` on `npm test 2>&1 | tail -20` remembers
     * `Bash(npm test *)` and `Bash(tail *)`, and a later
     * `cd /repo && npm test | tail -5` is covered. Refused outright, so the
     * mode's question stands:
     *
     * - a line that does not parse completely, or holds a command or process
     *   substitution or a `${…}` expansion anywhere;
     * - any other joining operator — `;`, a newline, a background `&`, `|&`,
     *   a subshell — (a `;` or newline line runs unasked only when EVERY
     *   part is read-only, which the mode itself settles);
     * - a command that is only redirections, or that opens with a shell
     *   reserved word the grammar of {@see ShellCompound} does not read
     *   (`{`, `!`, `case`, `until` …);
     * - a second `cd` that is not absolute (the first moved the directory
     *   the read-only set judges a relative one against).
     *
     * A `for` / `while read` LOOP or an `if` is ONE part (user decision
     * 2026-10-11): covered when its header qualifies and every command of its
     * body is covered on its own, by a grant or as read-only in the loop's
     * scope ({@see ReadOnlyCommands::compoundIsCovered()}); a line naming a
     * protected file, or that {@see SafetyClassifier} flags, gets no
     * read-only cover for its loop bodies. Inside the loop `;` and newlines
     * are the loop's own syntax; between parts the operators above still
     * apply.
     *
     * Each command is judged by its own source text, redirections included,
     * through the same matcher a whole line is — so `echo x > f` needs a
     * grant that spells `> f`, and a `sh` the pipe feeds needs a grant for
     * exactly `sh`.
     *
     * @param list<PermissionRule> $rules
     */
    public static function coversBySegments(array $rules, ToolCall $call, ?string $projectRoot, bool $readOnlyCovers): bool
    {
        $command = $call->name === 'Bash' ? ($call->arguments['command'] ?? null) : null;
        if (!is_string($command) || ($rules === [] && !$readOnlyCovers)) {
            return false;
        }
        $parsed = ShellWords::parse($command);
        if (!$parsed->complete || $parsed->substitutions !== [] || $parsed->parameterExpansions !== []) {
            return false;
        }
        $items = ShellCompound::parse($parsed);
        if ($items !== null && ShellCompound::hasCompound($items)) {
            return self::coversCompoundLine($rules, $parsed, $items, $command, $projectRoot, $readOnlyCovers);
        }
        if (\count($parsed->commands) < 2
            || \count($parsed->commands) !== \count($parsed->operators) + 1
            || \count($parsed->sources) !== \count($parsed->commands)) {
            return false;
        }
        foreach ($parsed->operators as $operator) {
            if (!in_array($operator, self::SEGMENT_OPERATORS, true)) {
                return false;
            }
        }

        $root = $projectRoot === '' ? null : $projectRoot;
        $changedDirectory = false;
        foreach ($parsed->commands as $index => $words) {
            $program = $words[0] ?? null;
            if ($program === null || in_array($program, self::RESERVED_WORDS, true)) {
                return false;
            }
            if ($program === 'cd') {
                if ($changedDirectory && !str_starts_with((string) ($words[1] ?? ''), '/')) {
                    return false;
                }
                $changedDirectory = true;
            }
            $source = trim($parsed->sources[$index]);
            $covered = $readOnlyCovers && ReadOnlyCommands::autoAllows($source, $root);
            if (!$covered && !self::ruleCoversSource($rules, $source, $projectRoot)) {
                return false;
            }
        }

        return true;
    }

    /**
     * {@see coversBySegments()} for a line holding a loop or an `if`: its
     * top-level items joined only by {@see SEGMENT_OPERATORS}, each a plain
     * command covered as one, or a compound covered body command by body
     * command.
     *
     * @param list<PermissionRule>       $rules
     * @param list<array<string, mixed>> $items
     */
    private static function coversCompoundLine(array $rules, ShellWords $parsed, array $items, string $command, ?string $projectRoot, bool $readOnlyCovers): bool
    {
        if (in_array('&', $parsed->operators, true)) {
            // A background job, at any depth: outside the per-part operators.
            return false;
        }
        $root = $projectRoot === '' ? null : $projectRoot;
        // The loop's own words (`for f in .env`) are in no body command's
        // source, so the auto-allow's two whole-line refusals are made here.
        $bodiesReadOnly = $readOnlyCovers && !ProtectFilesHook::namesProtectedFile($command)
            && (new SafetyClassifier())->classify(new ToolCall('Bash', ['command' => $command])) === null;
        $covers = static fn (array $node): bool => !in_array($node['words'][0] ?? '', self::RESERVED_WORDS, true)
            && self::ruleCoversSource($rules, trim($node['source']), $projectRoot);

        $last = \count($items) - 1;
        $changedDirectory = false;
        foreach ($items as $position => $item) {
            if ($position < $last && !in_array($item['terminator'], self::SEGMENT_OPERATORS, true)) {
                return false;
            }
            if ($item['kind'] !== 'simple') {
                if (!ReadOnlyCommands::compoundIsCovered($parsed, $item, $root, $bodiesReadOnly, $covers)) {
                    return false;
                }
                continue;
            }
            $program = $item['words'][0] ?? null;
            if ($program === null || in_array($program, self::RESERVED_WORDS, true)) {
                return false;
            }
            if ($program === 'cd') {
                if ($changedDirectory && !str_starts_with((string) ($item['words'][1] ?? ''), '/')) {
                    return false;
                }
                $changedDirectory = true;
            }
            $source = trim($item['source']);
            $covered = $readOnlyCovers && ReadOnlyCommands::autoAllows($source, $root);
            if (!$covered && !self::ruleCoversSource($rules, $source, $projectRoot)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Does one of $rules cover ONE command, judged by its own source text
     * (redirections included) through the matcher a whole line is?
     *
     * @param list<PermissionRule> $rules
     */
    private static function ruleCoversSource(array $rules, string $source, ?string $projectRoot): bool
    {
        $segment = new ToolCall('Bash', ['command' => $source]);
        foreach ($rules as $rule) {
            if ($rule->matches($segment, true, $projectRoot)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What an "always" on this call would remember, as the user reads it on
     * the modal's `a` row: the broadest pattern granted (`Bash(git status *)`
     * — the bare `Bash(git status)` beside it only covers the same command
     * with no arguments; `Bash(sed * | sort * | uniq *)` for a pipeline), or,
     * when no pattern fits, `this exact command` / `this exact call`. A `Bash`
     * line gets the exact form when it is a launcher, a destructive command, a
     * writing redirection or a substitution — see the class docblock.
     * Computed on the {@see grantArguments()}, so a dropped leading `cd` shows:
     * `cd /repo && git status` reads `Bash(git status *)`.
     *
     * @param array<string, mixed> $arguments
     */
    public static function scopeOf(string $tool, array $arguments, ?string $projectRoot = null): string
    {
        $patterns = self::patternsFor($tool, $arguments, $projectRoot);
        if ($patterns !== []) {
            return self::describePatterns($patterns);
        }

        return self::exactScopeOf($tool, $arguments, $projectRoot);
    }

    /**
     * How an EXACT-call grant on this call reads: `this exact call`,
     * `this exact command`, or — when {@see grantArguments()} dropped a
     * leading in-project `cd` — `this exact command without the leading cd`,
     * so the user can see the remembered call is the remainder.
     *
     * @param array<string, mixed> $arguments
     */
    public static function exactScopeOf(string $tool, array $arguments, ?string $projectRoot = null): string
    {
        if ($tool !== 'Bash') {
            return Lang::t('chat.permission.scope.exact_call');
        }

        return self::grantArguments($tool, $arguments, $projectRoot) === $arguments
            ? Lang::t('chat.permission.scope.exact_command')
            : Lang::t('chat.permission.scope.exact_command_without_cd');
    }

    /**
     * The arguments a grant is remembered under and checked against:
     * $arguments with a `Bash` command's leading in-project `cd <dir> &&`
     * removed ({@see LeadingCd::strip()}), or $arguments unchanged — any other
     * tool, no root, or a prefix that does not qualify. THE one normaliser
     * every grant path shares: {@see patternsFor()} and {@see callKey()} (and
     * so {@see withGrant()}, {@see allows()}, Chat's exact-call key and
     * {@see \SugarCraft\Crush\Backend\ChildChannel}'s per-turn memo) and
     * {@see PermissionGate}'s session-rule check. Applied ONCE: a remainder
     * that itself opens with a `cd` is a chain, judged as written.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public static function grantArguments(string $tool, array $arguments, ?string $projectRoot): array
    {
        if ($tool !== 'Bash') {
            return $arguments;
        }
        $subjectName = PermissionRule::subjectArgumentName($tool) ?? 'command';
        $command = $arguments[$subjectName] ?? null;
        if (!is_string($command)) {
            return $arguments;
        }
        $remainder = LeadingCd::strip($command, $projectRoot);
        if ($remainder !== null) {
            $arguments[$subjectName] = $remainder;
        }

        return $arguments;
    }

    /**
     * The arguments that make two calls the SAME call for an exact-call
     * grant: all of them, minus the tool's {@see ANNOTATION_ARGUMENTS}. With
     * the root in hand this is computed on the {@see grantArguments()}, so a
     * leading in-project `cd` is not part of the identity either.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public static function identityArguments(string $tool, array $arguments, ?string $projectRoot = null): array
    {
        $arguments = self::grantArguments($tool, $arguments, $projectRoot);
        foreach (self::ANNOTATION_ARGUMENTS[$tool] ?? [] as $annotation) {
            unset($arguments[$annotation]);
        }

        return $arguments;
    }

    /**
     * The {@see PermissionRule} patterns an "always" on this call grants, or
     * an empty list when no pattern fits and the exact call is remembered
     * instead. See the class docblock's table; judged on the
     * {@see grantArguments()}.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<string>
     */
    public static function patternsFor(string $tool, array $arguments, ?string $projectRoot = null): array
    {
        $arguments = self::grantArguments($tool, $arguments, $projectRoot);
        if ($tool === '' || !PermissionRule::isWellFormedPattern(self::escape($tool))) {
            return [];
        }
        $name = self::escape($tool);

        $subjectName = PermissionRule::subjectArgumentName($tool);
        if ($subjectName === null) {
            return [$name];
        }

        $subject = $arguments[$subjectName] ?? null;
        if (!is_string($subject) || trim($subject) === '') {
            return [];
        }

        if ($tool === 'Bash') {
            $prefix = self::commandPrefix($subject);
            if ($prefix !== null) {
                return [$name . '(' . $prefix . ')', $name . '(' . $prefix . ' *)'];
            }
            $segments = self::segmentPatterns($subject, $projectRoot);
            if ($segments !== null) {
                return $segments;
            }
            $structure = self::compoundScope($subject);

            return $structure === null ? [] : [$name . '(' . $structure . ')'];
        }

        if ($tool === 'WebFetch') {
            $target = FetchTarget::fromUrl(trim($subject));

            return $target === null ? [] : [$name . '(domain:' . self::escape($target->host) . ')'];
        }

        $pattern = $name . '(' . self::escape(trim($subject)) . ')';

        return PermissionRule::isWellFormedPattern($pattern) ? [$pattern] : [];
    }

    /**
     * The command prefix a `Bash` grant keeps, escaped for `fnmatch()`, or
     * null when the line is not one plain simple command whose program may
     * be generalised.
     */
    private static function commandPrefix(string $command): ?string
    {
        $parsed = ShellWords::parse($command);
        if (!$parsed->complete || count($parsed->commands) !== 1
            || $parsed->substitutions !== [] || $parsed->parameterExpansions !== []) {
            return null;
        }
        foreach ($parsed->redirections as $redirection) {
            if (!ShellWords::isInertRedirection($redirection)) {
                return null;
            }
        }

        return self::segmentPrefix($parsed->commands[0], $parsed->expandable[0] ?? []);
    }

    /**
     * What "always" remembers for a PIPELINE or CHAIN joined only by `|`,
     * `&&` and `||`: ONE GRANT PER PART (user decision 2026-10-11), so a
     * later line of a different shape made of the same commands is covered
     * ({@see coversBySegments()}). `cd /repo && git log -3 | head -5 && echo x`
     * → `Bash(git log)`, `Bash(git log *)`, `Bash(head)`, `Bash(head *)`,
     * `Bash(echo)`, `Bash(echo *)`.
     *
     * Each part keeps what {@see segmentPrefix()} keeps, as the pair a
     * simple command gets (bare and ` *`); a part that cannot be
     * generalised — a destructive command (`rm a.txt`), one with a writing
     * redirection (`sort a > out.txt`), a `curl`/`wget` piped on, a `cd`, an
     * assignment in front, an expandable program name — is remembered as
     * that EXACT part, escaped.
     *
     * Null — the line keeps its whole-shape grant ({@see compoundScope()}) or
     * the exact call — when it is not such a line (one command; `;`, a
     * newline, `&`, a subshell; a substitution or `${…}`; a parse failure),
     * or when a part is a launcher or an interpreter that runs inline or
     * piped-in code (`sh`, `bash -c`, `xargs rm`, `env`, `find`, `awk`,
     * `php -r`, `python3` …) or a shell reserved word: covered on its own, an
     * exact `sh` or `xargs rm` would run whatever a later pipe fed it, so
     * those lines stay remembered as their whole shape (`Bash(ls * | sh)`).
     * The parts must, together, cover the line they came from, or this is
     * null too.
     *
     * @return list<string>|null
     */
    private static function segmentPatterns(string $command, ?string $projectRoot): ?array
    {
        $parsed = ShellWords::parse($command);
        $items = $parsed->complete ? ShellCompound::parse($parsed) : null;
        if ($items !== null && ShellCompound::hasCompound($items)) {
            return self::compoundPatterns($parsed, $items, $command, $projectRoot);
        }
        if (!$parsed->complete || count($parsed->commands) < 2
            || count($parsed->commands) !== count($parsed->operators) + 1
            || count($parsed->sources) !== count($parsed->commands)
            || $parsed->substitutions !== [] || $parsed->parameterExpansions !== []) {
            return null;
        }
        foreach ($parsed->operators as $operator) {
            if (!in_array($operator, self::SEGMENT_OPERATORS, true)) {
                return null;
            }
        }

        $writes = [];
        foreach ($parsed->redirections as $redirection) {
            if (!ShellWords::isInertRedirection($redirection)) {
                $writes[$redirection['command']] = true;
            }
        }

        $patterns = [];
        foreach ($parsed->commands as $index => $words) {
            $words = array_values(array_filter($words, static fn (mixed $w): bool => is_string($w) && $w !== ''));
            $program = $words[0] ?? null;
            if ($program === null || in_array($program, self::RESERVED_WORDS, true)
                || in_array($program, self::LAUNCHERS, true)) {
                return null;
            }
            $prefix = isset($writes[$index]) ? null : self::segmentPrefix($parsed->commands[$index], $parsed->expandable[$index] ?? []);
            if ($prefix === null && in_array($program, self::INTERPRETERS, true)) {
                return null;
            }
            if ($prefix !== null && in_array($program, self::FETCHERS, true) && ($parsed->operators[$index] ?? null) === '|') {
                $prefix = null;
            }
            if ($prefix !== null) {
                $patterns[] = 'Bash(' . $prefix . ')';
                $patterns[] = 'Bash(' . $prefix . ' *)';
                continue;
            }
            $source = trim((string) preg_replace('/\s+/', ' ', $parsed->sources[$index]));
            if ($source === '' || !PermissionRule::isWellFormedPattern('Bash(' . self::escape($source) . ')')) {
                return null;
            }
            $patterns[] = 'Bash(' . self::escape($source) . ')';
        }
        $patterns = array_values(array_unique($patterns));

        $rules = array_map(static fn (string $p): PermissionRule => new PermissionRule($p, PermissionAction::Allow), $patterns);

        return self::coversBySegments($rules, new ToolCall('Bash', ['command' => $command]), $projectRoot, false)
            ? $patterns
            : null;
    }

    /**
     * {@see segmentPatterns()} for a line holding a `for` / `while read` loop
     * or an `if` (user decision 2026-10-11): one grant per COMMAND — the
     * loops' bodies included, the loop syntax itself never — so
     * `for t in tests/*Test.php; do vendor/bin/phpunit "$t" | tail -3; done`
     * remembers `Bash(vendor/bin/phpunit *)` and `Bash(tail *)`, and a later
     * loop of another shape over other files running the same commands is
     * covered ({@see coversBySegments()}).
     *
     * Null — the exact call is remembered — for a substitution or `${…}`
     * anywhere, a launcher, a reserved word the grammar does not read, an
     * interpreter on inline code, and for any command INSIDE a loop or an
     * `if` that would have to be remembered exactly (`rm "$f"`, a writing
     * redirection): an exact part's text there holds the loop variable, and
     * a grant on `rm "$f"` would cover the same text in any later loop, over
     * any list.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<string>|null
     */
    private static function compoundPatterns(ShellWords $parsed, array $items, string $command, ?string $projectRoot): ?array
    {
        if ($parsed->substitutions !== [] || $parsed->parameterExpansions !== []) {
            return null;
        }
        $writes = [];
        foreach ($parsed->redirections as $redirection) {
            if (!ShellWords::isInertRedirection($redirection)) {
                $writes[$redirection['command']] = true;
            }
        }
        $topLevel = [];
        foreach ($items as $item) {
            if ($item['kind'] === 'simple') {
                $topLevel[$item['index']] = true;
            }
        }

        $patterns = [];
        foreach (ShellCompound::simpleCommands($items) as $node) {
            $words = array_values(array_filter($node['words'], static fn (mixed $w): bool => is_string($w) && $w !== ''));
            $program = $words[0] ?? null;
            if ($program === null || in_array($program, self::RESERVED_WORDS, true)
                || in_array($program, self::LAUNCHERS, true)) {
                return null;
            }
            $prefix = isset($writes[$node['index']]) ? null : self::segmentPrefix($node['words'], $node['flags']);
            if ($prefix !== null && in_array($program, self::FETCHERS, true) && $node['terminator'] === '|') {
                $prefix = null;
            }
            if ($prefix !== null) {
                $patterns[] = 'Bash(' . $prefix . ')';
                $patterns[] = 'Bash(' . $prefix . ' *)';
                continue;
            }
            if (in_array($program, self::INTERPRETERS, true) || !isset($topLevel[$node['index']])) {
                return null;
            }
            $source = trim((string) preg_replace('/\s+/', ' ', $node['source']));
            if ($source === '' || !PermissionRule::isWellFormedPattern('Bash(' . self::escape($source) . ')')) {
                return null;
            }
            $patterns[] = 'Bash(' . self::escape($source) . ')';
        }
        $patterns = array_values(array_unique($patterns));
        if ($patterns === []) {
            return null;
        }

        $rules = array_map(static fn (string $p): PermissionRule => new PermissionRule($p, PermissionAction::Allow), $patterns);

        return self::coversBySegments($rules, new ToolCall('Bash', ['command' => $command]), $projectRoot, false)
            ? $patterns
            : null;
    }

    /**
     * The pattern "always" remembers for a PIPELINE or CHAIN, generalised per
     * segment — `sed -n 1,5p f | sort -u | uniq -c` → `sed * | sort * | uniq *`,
     * `cd sub && git log -3` (a `cd` not stripped) → `cd sub && git log *` —
     * or null when the line is not one (a single command, or one this cannot
     * honestly generalise: a substitution or `${…}` anywhere, an operator
     * other than `|` `&&` `||` `;`, a line that does not parse) or when no
     * segment could be generalised (the exact call is the same grant, and
     * reads as one).
     *
     * The operators and their order are kept, so the grant covers the same
     * SHAPE of line and nothing else ({@see PermissionRule}'s structured
     * `Allow` arm). Each segment keeps what {@see segmentPrefix()} keeps
     * plus ` *` — any arguments, none included — except a segment that is
     * written out LITERALLY instead:
     * - a launcher, interpreter-on-inline-code or destructive command
     *   ({@see LAUNCHERS}, {@see INTERPRETERS}, {@see EXACT_ONLY});
     * - a `curl`/`wget` whose output is piped on (fetched data into a program);
     * - one with a writing redirection (`> out.txt`: the target stays literal);
     * - an environment assignment in front, a program name bash would expand,
     *   a `cd`/`pushd`/`popd`.
     *
     * The result is checked against the line it came from through the same
     * matcher that will judge later calls; a pattern that does not cover its
     * own line (quoting this escaping cannot reproduce) is no pattern.
     */
    private static function compoundScope(string $command): ?string
    {
        $parsed = ShellWords::parse($command);
        if (!$parsed->complete || count($parsed->commands) < 2
            || count($parsed->commands) !== count($parsed->operators) + 1
            || count($parsed->sources) !== count($parsed->commands)
            || $parsed->substitutions !== [] || $parsed->parameterExpansions !== []) {
            return null;
        }
        foreach ($parsed->operators as $operator) {
            if (!in_array($operator, self::STRUCTURE_OPERATORS, true)) {
                return null;
            }
        }

        $writes = [];
        foreach ($parsed->redirections as $redirection) {
            if (!ShellWords::isInertRedirection($redirection)) {
                $writes[$redirection['command']] = true;
            }
        }

        $segments = [];
        $generalised = false;
        foreach ($parsed->commands as $index => $words) {
            $prefix = isset($writes[$index]) ? null : self::segmentPrefix($words, $parsed->expandable[$index] ?? []);
            $program = $words[0] ?? '';
            if ($prefix !== null && in_array($program, self::FETCHERS, true) && ($parsed->operators[$index] ?? null) === '|') {
                $prefix = null;
            }
            if ($prefix === null) {
                $source = trim((string) preg_replace('/\s+/', ' ', $parsed->sources[$index]));
                if ($source === '') {
                    return null;
                }
                $segments[] = self::escape($source);
                continue;
            }
            $segments[] = $prefix . ' *';
            $generalised = true;
        }
        if (!$generalised) {
            return null;
        }

        $pattern = $segments[0];
        foreach ($parsed->operators as $index => $operator) {
            $pattern .= ($operator === ';' ? '; ' : ' ' . $operator . ' ') . $segments[$index + 1];
        }

        $rule = new PermissionRule('Bash(' . $pattern . ')', PermissionAction::Allow);

        return PermissionRule::isWellFormedPattern($rule->pattern) && $rule->matches(new ToolCall('Bash', ['command' => $command]))
            ? $pattern
            : null;
    }

    /**
     * The words a grant keeps of ONE simple command, escaped and joined, or
     * null when that command must be remembered exactly. See the class
     * docblock: subcommand tools keep their subcommand, interpreters their
     * script, and launchers, inline code, an environment assignment in front,
     * an expandable program name and {@see EXACT_ONLY} commands keep nothing.
     *
     * @param list<string> $words      the command's quote-removed words
     * @param list<bool>   $expandable parallel to $words ({@see ShellWords::$expandable})
     */
    private static function segmentPrefix(array $words, array $expandable): ?string
    {
        if (($expandable[0] ?? false) === true) {
            // `s?d …`, `$TOOL …`: what runs is not the word written.
            return null;
        }
        $words = array_values(array_filter(
            $words,
            static fn (mixed $word): bool => is_string($word) && $word !== '',
        ));
        if ($words === [] || str_contains($words[0], '=')) {
            // An environment assignment in front (`FOO=1 make`) changes what
            // runs; the exact call is the honest grant.
            return null;
        }

        if (in_array($words[0], self::LAUNCHERS, true) || in_array($words[0], self::EXACT_ONLY, true)
            || str_starts_with($words[0], 'mkfs')) {
            return null;
        }

        $keep = [$words[0]];
        if (in_array($words[0], self::INTERPRETERS, true)) {
            if (!self::isSubcommand($words[1] ?? null)) {
                return null;
            }
            $keep[] = $words[1];
            if ($words[0] . ' ' . $words[1] === 'php artisan' && self::isSubcommand($words[2] ?? null)) {
                $keep[] = $words[2];
            }
        } elseif (in_array($words[0], self::SUBCOMMAND_TOOLS, true) && self::isSubcommand($words[1] ?? null)) {
            $keep[] = $words[1];
            if (in_array($words[0] . ' ' . $words[1], self::THIRD_WORD_PAIRS, true) && self::isSubcommand($words[2] ?? null)) {
                $keep[] = $words[2];
            }
        }

        foreach ($keep as $word) {
            if (preg_match('/\s/', $word) === 1) {
                return null;
            }
        }

        return implode(' ', array_map(self::escape(...), $keep));
    }

    private static function isSubcommand(?string $word): bool
    {
        return $word !== null && $word !== '' && !str_starts_with($word, '-');
    }

    /**
     * The exact-call identity: tool name plus canonical JSON of the
     * {@see identityArguments()}, so neither key order nor the model's
     * caption makes two calls different.
     *
     * @param array<string, mixed> $arguments
     */
    public static function callKey(string $tool, array $arguments, ?string $projectRoot = null): ?string
    {
        try {
            $json = json_encode(
                self::canonical(self::identityArguments($tool, $arguments, $projectRoot)),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                    | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException) {
            return null;
        }

        return $tool . ' ' . $json;
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /**
     * Escape the `fnmatch()` metacharacters, so a granted spelling matches
     * itself and nothing a glob would make of it.
     */
    private static function escape(string $text): string
    {
        return addcslashes($text, '\\*?[]');
    }
}
