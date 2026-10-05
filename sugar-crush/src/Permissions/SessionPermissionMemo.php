<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

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
 * | `Read`/`Edit`/`Write`/`Glob`/`Grep`/`Lsp` | that exact path: `Edit(src/A.php)` |
 * | `WebFetch`                            | that host: `WebFetch(domain:example.com)` |
 * | `WebSearch`, `Skill`                  | that exact subject                 |
 * | a tool with no subject argument (`mcp__*`, `Task`, …) | the tool: `mcp__git__status` |
 *
 * Subcommand tools (`git`, `npm`, `composer`, …) keep their subcommand,
 * interpreters (`php`, `python3`, `node`, …) keep their script, and a
 * launcher whose job is to run something else (`bash -c`, `sudo`, `xargs`,
 * `find`, `env`, …) or an interpreter handed inline code (`php -r`) gets no
 * pattern: a prefix grant on those would be a grant on everything.
 *
 * A `Bash` line that is not ONE simple command — a chain, a pipe, a
 * substitution, a redirection — gets no pattern at all either: {@see PermissionRule}'s
 * `Allow` arm demands that EVERY command in a line match one rule, so no
 * prefix rule could ever fire for it again. Those (and any call whose subject
 * cannot be read) are remembered as the EXACT call instead, which
 * {@see allows()} answers but the gate's rules never see.
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
        'builtin', 'source', '.', 'find', 'awk', 'gawk', 'perl', 'ruby', 'ssh',
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
     */
    public function withGrant(string $tool, array $arguments): self
    {
        $patterns = self::patternsFor($tool, $arguments);
        if ($patterns === []) {
            $key = self::callKey($tool, $arguments);
            if ($key === null || in_array($key, $this->calls, true)) {
                return $this;
            }

            return new self($this->patterns, [...$this->calls, $key]);
        }

        $merged = array_values(array_unique([...$this->patterns, ...$patterns]));

        return $merged === $this->patterns ? $this : new self($merged, $this->calls);
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
        $key = self::callKey($tool, $arguments);
        if ($key !== null && in_array($key, $this->calls, true)) {
            return true;
        }

        $call = new ToolCall($tool, $arguments);
        foreach ($this->rules() as $rule) {
            if ($rule->matches($call, true, $projectRoot)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What an "always" on this call would remember, as the user should read
     * it before confirming: the broadest pattern granted (`Bash(git status *)`
     * — the bare `Bash(git status)` beside it only covers the same command
     * with no arguments), or, when no pattern fits, `this exact command` /
     * `this exact call`. A `Bash` line gets the exact form when it is a
     * chain, a pipe, a redirection or a launcher — see the class docblock.
     *
     * @param array<string, mixed> $arguments
     */
    public static function scopeOf(string $tool, array $arguments): string
    {
        $patterns = self::patternsFor($tool, $arguments);
        if ($patterns !== []) {
            return $patterns[\count($patterns) - 1];
        }

        return $tool === 'Bash' ? 'this exact command' : 'this exact call';
    }

    /**
     * The arguments that make two calls the SAME call for an exact-call
     * grant: all of them, minus the tool's {@see ANNOTATION_ARGUMENTS}.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public static function identityArguments(string $tool, array $arguments): array
    {
        foreach (self::ANNOTATION_ARGUMENTS[$tool] ?? [] as $annotation) {
            unset($arguments[$annotation]);
        }

        return $arguments;
    }

    /**
     * The {@see PermissionRule} patterns an "always" on this call grants, or
     * an empty list when no pattern fits and the exact call is remembered
     * instead. See the class docblock's table.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<string>
     */
    public static function patternsFor(string $tool, array $arguments): array
    {
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

            return $prefix === null ? [] : [$name . '(' . $prefix . ')', $name . '(' . $prefix . ' *)'];
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
     * null when the line is not one plain simple command.
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

        $words = array_values(array_filter(
            $parsed->commands[0],
            static fn (mixed $word): bool => is_string($word) && $word !== '',
        ));
        if ($words === [] || str_contains($words[0], '=')) {
            // An environment assignment in front (`FOO=1 make`) changes what
            // runs; the exact call is the honest grant.
            return null;
        }

        if (in_array($words[0], self::LAUNCHERS, true)) {
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
    public static function callKey(string $tool, array $arguments): ?string
    {
        try {
            $json = json_encode(
                self::canonical(self::identityArguments($tool, $arguments)),
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
