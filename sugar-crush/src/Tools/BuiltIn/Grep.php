<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Tools\Concerns\CapturesProcessOutput;
use SugarCraft\Crush\Tools\Concerns\TruncatesOutput;
use SugarCraft\Crush\Tools\IgnoreRules;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Tools\PathJail;

final readonly class Grep implements Tool, ParallelSafe
{
    use CapturesProcessOutput;
    use TruncatesOutput;

    /**
     * $maxOutputBytes bounds the hit list. `grep -rn` over a real tree has no
     * ceiling of its own: a common identifier, or a pattern that happens to
     * match minified assets or a lockfile, returns tens of thousands of lines
     * that would otherwise be replayed into every following request of the
     * turn. Zero or negative disables the cap.
     */
    public function __construct(
        private ?string $root = null,
        private int $maxOutputBytes = self::DEFAULT_MAX_OUTPUT_BYTES,
    ) {}

    /**
     * Unconditionally concurrency-safe: `grep -rn` reads, and this tool holds
     * no session-scoped state for a fork to strand (contrast `Read`/`Glob`,
     * which carry the announce-once collaborators).
     */
    public function isParallelSafe(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'Grep';
    }

    /**
     * The ignore behaviour is stated up front for the same reason `Glob`'s
     * prune list is: a default the model only learns about AFTER it has
     * already asked the wrong question costs a turn, and "no hits" in a tree
     * that was never searched is indistinguishable from "no hits".
     *
     * The regex dialect is stated for the same reason and is the one clause a
     * model is most likely to get wrong unprompted: {@see execute()} builds
     * `grep -rn` with NO `-E` and NO `-P`, so the pattern is GNU basic
     * regular expression (BRE) syntax. crush_code.md section 12 asserted ERE
     * here; that is wrong, and the difference is not cosmetic — in BRE `a|b`
     * matches the literal three characters `a|b`, and `(a)` and `a+` match
     * their own punctuation rather than grouping or repeating.
     *
     * GNU, not "POSIX", and the qualifier is load-bearing rather than pedantic:
     * the escaped operators the text goes on to recommend — `\|`, `\+`, `\?` —
     * are GNU EXTENSIONS. Strict POSIX BRE has no alternation operator at all,
     * so saying "POSIX basic" and then instructing the model to escape its way
     * to alternation describes a dialect that does not exist. The host binary
     * here is GNU grep 3.11, and the escaped forms are proven against it in
     * `ToolDescriptionGuidanceTest`.
     *
     * The no-matches clause names the scoring rule `execute()` actually
     * applies (`isError: exitCode > 1`), which the model cannot otherwise
     * distinguish from a failed search.
     */
    public function description(): string
    {
        return 'Search for a pattern in files, recursively. The pattern is a GNU basic '
            . 'regular expression — this runs `grep -rn`, not PCRE — so `|`, `+`, `?`, `(`, '
            . '`)`, `{` and `}` match themselves unless backslash-escaped. Use include to '
            . 'scope by filename glob (e.g. "*.php"). Finding nothing is a normal result, '
            . 'not an error; only grep itself failing is reported as one. Skips '
            . implode(', ', IgnoreRules::DEFAULT_EXCLUDED_DIRS)
            . ' and anything the project\'s .gitignore excludes; pass include_ignored: true to search those too.';
    }

    public function inputSchema(): array
    {
        // Only a rooted instance is contained, so only a rooted instance says
        // so — the unrooted one (a test, an embedder) genuinely accepts any
        // readable directory.
        $pathScope = $this->root !== null ? ' Must be inside the workspace root.' : '';

        return [
        'type' => 'object',
        'properties' => [
            // Reconciled with description(): the schema used to say only
            // "regex pattern", which a model reasonably reads as PCRE. It is
            // GNU BRE, and the two strings must not disagree about that.
            'pattern' => ['type' => 'string', 'description' => 'The pattern to search for, as a GNU basic regular expression (BRE). Escape |, +, ?, ( and ) to use them as operators rather than literals.'],
            'path' => ['type' => 'string', 'description' => 'Directory path to search in.' . $pathScope],
            'include' => ['type' => 'string', 'description' => 'Filename glob limiting which files are searched (e.g., *.php). Defaults to every file.'],
            'description' => [
                'type' => 'string',
                'description' => 'Clear, concise 5-10 word description in active voice of what this search looks for (e.g. "Locate callers of describeToolCall", not "greps a regex").',
            ],
            'include_ignored' => [
                'type' => 'boolean',
                'description' => 'Search files the project\'s .gitignore excludes, plus vendor/node_modules-style directories. Off by default; the result says when something was hidden.',
            ],
        ],
        'required' => ['pattern', 'path', 'description'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $pattern = $args['pattern'] ?? '';
        $path = $args['path'] ?? '';
        $include = $args['include'] ?? '*';

        if ($pattern === '') {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: pattern cannot be empty',
                isError: true,
            );
        }

        // realpath() THROWS a ValueError on a NUL byte rather than failing, so
        // an unguarded NUL left execute() as an uncaught crash. PathJail also
        // rejects it now, but say so in this tool's own vocabulary instead of
        // reporting a malformed argument as a containment verdict.
        if (str_contains($path, "\0")) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: path contains a NUL byte',
                isError: true,
            );
        }

        if ($this->root !== null) {
            $resolved = PathJail::resolveDir($this->root, $path);
            if ($resolved === null) {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: 'Error: path outside workspace root',
                    isError: true,
                );
            }
            $path = $resolved;
        } elseif (!is_dir($path)) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: "Error: directory not found: $path",
                isError: true,
            );
        }

        $rules = $this->rulesFor($path, self::flag($args['include_ignored'] ?? null));

        $cmd = 'grep -rn';
        // `-r`, deliberately, never `-R`: -r follows a symlink only when it is
        // named on the command line, which is exactly the bounded hatch
        // IgnoreRules::halts() documents for Glob. -R follows every link it
        // meets, and this monorepo's path-repo symlinks make that a
        // non-terminating walk rather than a slow one.
        $cmd .= $rules->grepExcludeFlags();
        if ($include !== '*') {
            $cmd .= ' --include=' . escapeshellarg($include);
        }
        $cmd .= ' ' . escapeshellarg($pattern) . ' ' . escapeshellarg($path);
        // See Bash::execute() -- exec() leaks the child's stderr onto the
        // terminal underneath the TUI. grep exits 1 for "no matches", which
        // is a normal outcome rather than an error.
        $run = $this->runCaptured($cmd, null, $this->maxOutputBytes > 0 ? $this->maxOutputBytes : null);

        $filtered = self::withoutIgnoredHits($run, $rules, $path);

        // See Bash::execute() for why the merge's account is what gets clipped
        // rather than the capture's raw byte totals.
        $content = $this->truncateMerged(
            $this->mergeCapturedOutput($filtered['run']),
            $this->maxOutputBytes,
        );

        $skipped = self::presentExcludedDirs($path, $rules);
        if ($skipped !== []) {
            if ($content !== '' && !str_ends_with($content, "\n")) {
                $content .= "\n";
            }
            $content .= sprintf(
                "... [skipped: %s were not searched. Point path inside one to search it.]",
                implode(', ', $skipped),
            );
        }

        if ($filtered['hidden'] > 0) {
            if ($content !== '' && !str_ends_with($content, "\n")) {
                $content .= "\n";
            }
            // Announced for the same reason truncation is: a quietly shortened
            // hit list reads as a complete one, and "that string appears
            // nowhere" is a confident wrong answer when the file holding it was
            // simply never shown.
            $content .= sprintf(
                "... [gitignored: hid %d hit(s) in files the project's .gitignore excludes. "
                . "Pass include_ignored: true to see them.]",
                $filtered['hidden'],
            );
        }

        return new ToolResult(
            toolCallId: $args['id'] ?? '',
            content: $content,
            isError: $run['exitCode'] > 1,
        );
    }

    /**
     * The ignore ruleset governing this call.
     *
     * $searchRoot's own segments are removed from the exclude list, which is
     * the same "naming it is asking for it" hatch `Glob` already honours:
     * `path: "vendor/acme"` has to search vendor/acme, not report it empty.
     * The `.gitignore` layer gets the same treatment when the search base is
     * itself an ignored directory.
     */
    private function rulesFor(string $searchRoot, bool $includeIgnored): IgnoreRules
    {
        if ($includeIgnored) {
            return IgnoreRules::new($this->root ?? $searchRoot)
                ->withGitignore(false)
                ->withExcludedDirs([]);
        }

        $rules = IgnoreRules::new($this->root ?? $searchRoot)
            ->withoutExcludedDirs(explode('/', $searchRoot));

        return $rules->ignores($searchRoot, true) ? $rules->withGitignore(false) : $rules;
    }

    /**
     * Which default-excluded directories are actually present under
     * $searchRoot, so the result can name what it skipped.
     *
     * `--exclude-dir` spares the traversal but tells us nothing about what it
     * spared, and a hit list silently missing `vendor/` reads as a complete
     * one — the same failure `Glob`'s prune note exists to prevent. Its walk
     * records real skips; grep's cannot, so this probes instead.
     *
     * Bounded to three levels ON PURPOSE. An unbounded search for the
     * directories would cost the whole traversal the exclusion just avoided,
     * and every realistic layout puts `vendor/`/`node_modules/` at the search
     * root or a level or two below it. A skip nested deeper than that goes
     * unannounced; description() still names the exclusions up front, which is
     * the case this note is a backstop for.
     *
     * @return list<string>
     */
    private static function presentExcludedDirs(string $searchRoot, IgnoreRules $rules): array
    {
        $base = rtrim($searchRoot, '/');
        $found = [];

        foreach ($rules->excludedDirs as $dir) {
            foreach (['/', '/*/', '/*/*/'] as $depth) {
                if (glob($base . $depth . $dir, GLOB_ONLYDIR)) {
                    $found[] = $dir;
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Drop hits in files the ruleset excludes.
     *
     * `.gitignore` is enforced HERE rather than on grep's command line because
     * ignore rules can be negated and grep's `--exclude` cannot — see
     * {@see IgnoreRules::grepExcludeFlags()}. Filtering output keeps negation
     * working; the exclude flags still spare the walk the trees that dominate
     * it.
     *
     * The capture's discard counters are deliberately left ALONE. A line this
     * method removes was never going to be shown at any cap, and folding it
     * into `stdoutDropped` would label a complete answer partial — the same
     * mistake {@see CapturesProcessOutput::mergeCapturedOutput()} avoids when
     * it throws a whole stream away.
     *
     * @param array{stdout: string, stderr: string, exitCode: int, truncatedBytes?: int, stdoutDropped?: int, stderrDropped?: int, stdoutMidLine?: bool, stderrMidLine?: bool} $run
     *
     * @return array{run: array{stdout: string, stderr: string, exitCode: int, truncatedBytes?: int, stdoutDropped?: int, stderrDropped?: int, stdoutMidLine?: bool, stderrMidLine?: bool}, hidden: int}
     */
    private static function withoutIgnoredHits(array $run, IgnoreRules $rules, string $searchRoot): array
    {
        if ($run['stdout'] === '') {
            return ['run' => $run, 'hidden' => 0];
        }

        $prefix = rtrim($searchRoot, '/') . '/';
        $kept = [];
        $hidden = 0;

        foreach (explode("\n", $run['stdout']) as $line) {
            $file = self::hitPath($line, $prefix);
            // A line whose path cannot be recovered is kept: grep's own
            // diagnostics look like this, and dropping text nobody can
            // attribute is how a result loses the sentence explaining itself.
            if ($file !== null && ($rules->ignores($file, false) || $rules->excludedDirectoryIn($file) !== null)) {
                $hidden++;
                continue;
            }
            $kept[] = $line;
        }

        if ($hidden === 0) {
            return ['run' => $run, 'hidden' => 0];
        }

        $run['stdout'] = implode("\n", $kept);

        return ['run' => $run, 'hidden' => $hidden];
    }

    /**
     * The file a grep hit refers to, or null when the line is not a hit.
     *
     * Splitting on the FIRST `:` is wrong — a path may contain one — so the
     * search starts past the search root, which every `-r` hit is prefixed
     * with. `Binary file X matches` is recognised separately because grep
     * writes it instead of a hit for a matching binary, and an ignored blob is
     * exactly the sort of file that produces it.
     */
    private static function hitPath(string $line, string $prefix): ?string
    {
        if (preg_match('/^Binary file (.*) matches$/', $line, $m) === 1) {
            return $m[1];
        }

        if (!str_starts_with($line, $prefix)) {
            return null;
        }

        $colon = strpos($line, ':', strlen($prefix));

        return $colon === false ? null : substr($line, 0, $colon);
    }

    /**
     * Read a boolean tool argument.
     *
     * Models routinely send `"true"` and `1` for a boolean, and a bare cast
     * turns the string `"false"` into true — which would silently disable the
     * very filter this flag exists to control.
     */
    private static function flag(mixed $value): bool
    {
        return filter_var($value ?? false, FILTER_VALIDATE_BOOL);
    }
}
