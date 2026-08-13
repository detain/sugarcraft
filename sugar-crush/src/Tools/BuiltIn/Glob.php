<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Skills\SkillPathNudge;
use SugarCraft\Crush\Tools\CarriesSessionState;
use SugarCraft\Crush\Tools\Concerns\TruncatesOutput;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Tools\PathJail;

final readonly class Glob implements Tool, ParallelSafe, CarriesSessionState
{
    use TruncatesOutput;

    /**
     * Directories a recursive walk skips unless the caller asks for them.
     *
     * These are the four trees that make `**` unusable in a real project: they
     * are large, machine-generated, and almost never what a question about
     * "the code" means. Measured on this very repo, `**\/*.php` returned 8,916
     * paths of which 8,439 were under `vendor/` — the signal was 5% of the
     * answer and the other 95% was replayed into every request of the turn.
     *
     * Pruned during the WALK, not filtered afterwards: the cost being avoided
     * is the traversal itself (and, for each match, an
     * {@see InstructionFileLoader} tree-walk on top of it).
     */
    private const DEFAULT_PRUNED_DIRS = ['.git', 'vendor', 'node_modules', '.phpunit.cache'];

    /**
     * Ceiling on how many paths one call collects.
     *
     * A count cap rather than only a byte cap because the traversal is the
     * expensive half: stopping at 1,000 entries means the walk ENDS there,
     * instead of collecting 112,000 and discarding 111,000 of them. 1,000
     * paths is also about what the byte cap admits, so the two agree rather
     * than one silently pre-empting the other.
     */
    private const DEFAULT_MAX_MATCHES = 1000;

    /**
     * $skillNudge turns a skill's `paths:` frontmatter into a live signal
     * (crush_feat.md section 7 E4): the whole match list is scoped in ONE
     * call, so a 500-file glob costs one in-memory pass, not one per file.
     * Null keeps the tool standalone.
     *
     * $prunedDirs null means {@see DEFAULT_PRUNED_DIRS}; pass `[]` to walk
     * everything. Note that an explicit prune list is not the only escape
     * hatch — see {@see prunedDirs()} for the per-pattern opt-out.
     */
    public function __construct(
        private ?string $root = null,
        private ?InstructionFileLoader $instructionLoader = null,
        private array $sessionCache = [],
        private ?SkillPathNudge $skillNudge = null,
        private int $maxOutputBytes = self::DEFAULT_MAX_OUTPUT_BYTES,
        private int $maxMatches = self::DEFAULT_MAX_MATCHES,
        private ?array $prunedDirs = null,
    ) {}

    /**
     * Walking the tree mutates nothing a sibling call could observe. The
     * announce-once marks `execute()` sets on the shared
     * {@see InstructionFileLoader}/{@see SkillPathNudge} DO need to survive
     * the fork, hence {@see CarriesSessionState}.
     */
    public function isParallelSafe(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     *
     * Kept byte-identical to {@see Read::exportSessionState()} — see the note
     * there on why the two must not drift.
     */
    public function exportSessionState(): array
    {
        return [
            'emittedInstructionPaths' => $this->instructionLoader?->emittedPaths() ?? [],
            'announcedSkills' => $this->skillNudge?->announced() ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function mergeSessionState(array $state): void
    {
        $paths = $state['emittedInstructionPaths'] ?? null;
        if (is_array($paths)) {
            $this->instructionLoader?->markEmitted(array_values($paths));
        }

        $skills = $state['announcedSkills'] ?? null;
        if (is_array($skills)) {
            $this->skillNudge?->markAnnounced(array_values($skills));
        }
    }

    public function name(): string
    {
        return 'Glob';
    }
    /**
     * The prune list is stated here, not just in the result, because a default
     * the model only learns about AFTER it has already asked the wrong
     * question costs a turn. Naming the directories up front lets it write
     * `vendor/**\/*.php` the first time.
     */
    public function description(): string
    {
        $pruned = $this->prunedDirNames();
        if ($pruned === []) {
            return 'Find files matching a glob pattern';
        }

        return sprintf(
            'Find files matching a glob pattern. A recursive `**` walk skips %s by default; '
            . 'naming one of those directories in the pattern (e.g. "%s/**/*.php") or pointing '
            . 'path inside it searches there instead.',
            implode(', ', $pruned),
            self::pruneExample($pruned),
        );
    }
    public function inputSchema(): array
    {
        $pruned = $this->prunedDirNames();
        $patternHint = $pruned === [] ? '' : sprintf(
            ' A recursive `**` walk skips %s unless you name the directory here (e.g. "%s/**/*.php").',
            implode(', ', $pruned),
            self::pruneExample($pruned),
        );
        $pathHint = $pruned === [] ? '' : sprintf(
            ' Pointing this inside a skipped directory (e.g. "%s/acme/pkg") searches it in full.',
            self::pruneExample($pruned),
        );

        return [
        'type' => 'object',
        'properties' => [
            'pattern' => [
                'type' => 'string',
                'description' => 'The glob pattern to match (e.g., **/*.php).' . $patternHint,
            ],
            'path' => [
                'type' => 'string',
                'description' => 'Base directory path.' . $pathHint,
            ],
            'description' => [
                'type' => 'string',
                'description' => 'Clear, concise 5-10 word description in active voice of what this search looks for (e.g. "Find every provider test file", not "globs *.php").',
            ],
        ],
        'required' => ['pattern', 'path', 'description'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $pattern = $args['pattern'] ?? '';
        $path = $args['path'] ?? '';

        if ($pattern === '') {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: pattern cannot be empty',
                isError: true,
            );
        }

        // SECURITY: the jail below validates `path` and nothing else, but the
        // non-recursive branch then concatenates `pattern` onto the resolved
        // base — so `path: "."` with `pattern: "../*.php"` walked straight out
        // of the workspace with the jail reporting success. `..` has no
        // legitimate use here: the tool already takes a base directory, which
        // is the supported way to search somewhere else.
        if (self::hasTraversalSegment($pattern)) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: pattern may not contain a ".." segment; use the path argument to choose a base directory',
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

        // rtrim eats the root directory entirely — "/" becomes "" — and an
        // empty string is a \ValueError out of RecursiveDirectoryIterator,
        // not the \UnexpectedValueException the walk is prepared for. `path:
        // "/"` is a plausible thing for a model to send, and it must not
        // surface as a raw internal PHP message.
        $baseDir = rtrim($path, '/');
        if ($baseDir === '') {
            $baseDir = '/';
        }

        // A permission problem is not an answer. Left to the walk it came back
        // as content='' / isError=false, i.e. a confident "no such files" for
        // a directory nobody was allowed to look inside — the same
        // stated-with-confidence wrong answer the truncation markers exist to
        // avoid.
        if (!is_dir($baseDir) || !is_readable($baseDir)) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: sprintf('Error: directory is not readable: %s', $baseDir),
                isError: true,
            );
        }

        $normalized = ltrim($pattern, '/');

        // Compile ONCE, up front. Compiling per walked entry meant a malformed
        // bracket expression emitted one `preg_match(): Compilation failed`
        // warning PER FILE -- on a large tree, six figures of them, written to
        // stdout underneath the TUI frame -- and then returned isError=false
        // with empty content, i.e. "no files match" for a pattern that was
        // never valid. A bad pattern is a bad request, and says so once.
        $regex = null;
        if (str_contains($normalized, '**')) {
            $regex = self::patternToRegex($normalized);
            if (!self::compiles($regex)) {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: sprintf(
                        'Error: pattern "%s" is not a valid glob (it compiles to an invalid expression). '
                        . 'Check the bracket expressions -- e.g. an empty "[]" or a reversed range "[z-a]".',
                        $pattern,
                    ),
                    isError: true,
                );
            }
        }

        $found = $this->match($baseDir, $normalized, $regex);
        if ($found['error'] !== null) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: $found['error'],
                isError: true,
            );
        }
        $files = $this->withinRoot($found['files']);

        // Prepend nested instruction file content for each matched file
        $output = '';
        foreach ($files as $file) {
            $nestedContent = $this->instructionLoader?->loadForPath($file);
            if ($nestedContent !== null) {
                $output .= $nestedContent . "\n";
            }
            $output .= $file . "\n";
        }

        if ($found['capped']) {
            $output .= sprintf(
                "... [truncated: stopped after %d matches. This list is PARTIAL, not the complete answer "
                . "— narrow the pattern or point path at a subdirectory to see the rest.]\n",
                $this->maxMatches,
            );
        }

        // Clip before the nudge so the nudge is not what gets cut off: it is
        // the shortest and most actionable part of the result.
        $output = $this->truncateOutput($output, $this->maxOutputBytes);

        // The pruning has to announce itself for the same reason the byte cap
        // and the match cap do: a silently shortened list reads as a complete
        // one, so `**\/Controller.php` over a project whose real source lives
        // in vendor/ came back empty and the model concluded the file does not
        // exist. Naming what was skipped, and how to ask for it, is the whole
        // difference between a partial answer and a wrong one.
        //
        // Appended AFTER the clip, like the nudge: it is one line, it is the
        // only place the escape hatch appears in the result, and it must not
        // be the thing the budget sacrifices.
        if ($found['pruned'] !== []) {
            // truncateOutput() ends on its marker with no trailing newline, so
            // the note has to supply its own separator or it is glued to the
            // end of that sentence.
            if ($output !== '' && !str_ends_with($output, "\n")) {
                $output .= "\n";
            }
            $output .= sprintf(
                "... [pruned: skipped %s. Files inside those directories are NOT in this list — "
                . "name the directory in the pattern (e.g. \"%s/**/*.php\") or point path inside it "
                . "to search there.]\n",
                implode(', ', $found['pruned']),
                self::pruneExample($found['pruned']),
            );
        }

        $nudge = $this->skillNudge?->forPaths($files);
        if ($nudge !== null) {
            $output .= "\n" . $nudge;
        }

        return new ToolResult(
            toolCallId: $args['id'] ?? '',
            content: $output,
            isError: false,
        );
    }

    /**
     * Resolve $pattern against $baseDir.
     *
     * PHP's native glob() has NO globstar support: it treats a doubled star
     * as an ordinary single-segment wildcard, so a recursive pattern silently
     * returned only the files exactly one directory down — missing both the
     * base directory and anything deeper — with no error and no warning.
     * This tool's own inputSchema advertises that very pattern shape, so the
     * advertised usage was the broken one.
     *
     * glob() is still the fast path for every pattern WITHOUT `**`: it is a
     * single libc call and already correct there.
     *
     * @param ?string $regex precompiled and already validated by execute();
     *                       null on the non-recursive fast path
     *
     * @return array{files: list<string>, capped: bool, pruned: list<string>, error: ?string}
     *         absolute paths, sorted; `pruned` names the default-skipped
     *         directories this walk ACTUALLY passed over, so the result can
     *         say what it is missing instead of pretending to be complete
     */
    private function match(string $baseDir, string $pattern, ?string $regex): array
    {
        // The separator-free form, used only where this method JOINS a base to
        // something ("$stripped/$pattern", "$stripped/$relative"): at "/" that
        // is "" and the join still produces exactly one slash. It is
        // deliberately not what the iterator is seeded with — "" is a
        // \ValueError there — nor what the offset below is measured from.
        $stripped = rtrim($baseDir, '/');

        if ($regex === null) {
            $files = glob($stripped . '/' . $pattern);
            $files = $files === false ? [] : array_values($files);

            return $this->capped($files);
        }

        $hidden = self::hiddenPolicy($pattern);
        $pruned = $this->prunedDirs($pattern, $baseDir);
        // Measured against $baseDir, NOT the stripped form:
        // RecursiveDirectoryIterator builds every pathname as the string it was
        // HANDED plus "/" plus the filename, so seeding it with "/" yields
        // "//admin" and the prefix to drop is 2, not 1. Deriving the offset
        // from $stripped left a leading "/" on the relative path, which the
        // anchored regex can never match — a recursive glob based at "/"
        // returned zero matches with isError=false, and since nothing matched,
        // the cap never engaged and the walk ran the whole filesystem.
        $baseLen = strlen($baseDir) + 1;
        $skipped = [];

        try {
            $directory = new \RecursiveDirectoryIterator(
                $baseDir,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME,
            );
        } catch (\Throwable $e) {
            // \Throwable, not \UnexpectedValueException: the constructor also
            // raises \ValueError (an empty path), which is an \Error and would
            // have escaped execute() as a raw internal PHP message.
            return [
                'files' => [],
                'capped' => false,
                'pruned' => [],
                'error' => sprintf('Error: cannot read directory %s: %s', $baseDir, $e->getMessage()),
            ];
        }

        // Returning false here does double duty: the entry is dropped AND, if
        // it is a directory, never descended into. That is what makes this a
        // prune rather than a post-hoc filter.
        $filtered = new \RecursiveCallbackFilterIterator(
            $directory,
            static function (string $path) use ($hidden, $pruned, &$skipped): bool {
                $base = basename($path);

                if (isset($pruned[$base]) && is_dir($path)) {
                    // Recorded, not merely skipped: what the walk left out is
                    // part of the answer.
                    $skipped[$base] = true;

                    return false;
                }

                // glob() never matches a leading dot with a wildcard, so
                // neither does the walk. Deciding this PER SEGMENT (rather
                // than "the pattern mentions a dot somewhere") is what keeps
                // `.github/**/*.yml` out of `.git/`: naming one hidden
                // directory opts into that directory, not into all of them.
                if (str_starts_with($base, '.')) {
                    return $hidden['all'] || isset($hidden['names'][$base]);
                }

                return true;
            },
        );

        // CATCH_GET_CHILD: one unreadable subdirectory must not abort the
        // whole walk. SELF_FIRST so a pattern can match a directory, as
        // glob() does.
        $walker = new \RecursiveIteratorIterator(
            $filtered,
            \RecursiveIteratorIterator::SELF_FIRST,
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );

        $matches = [];
        $capped = false;
        foreach ($walker as $path) {
            $relative = substr((string) $path, $baseLen);
            if ($relative !== '' && preg_match($regex, $relative) === 1) {
                // Rebuilt from $stripped rather than reported as the iterator
                // spelled it: at "/" the iterator's own pathnames carry the
                // doubled separator ("//admin"), and a path handed back to the
                // model is a path it will feed to Read or Bash next.
                $matches[] = $stripped . '/' . $relative;
                if ($this->maxMatches > 0 && count($matches) >= $this->maxMatches) {
                    // Abandon the walk rather than finish it and throw the
                    // surplus away -- the traversal is the expensive part.
                    $capped = true;
                    break;
                }
            }
        }

        sort($matches);

        $skippedNames = array_keys($skipped);
        sort($skippedNames);

        return ['files' => $matches, 'capped' => $capped, 'pruned' => $skippedNames, 'error' => null];
    }

    /**
     * @param list<string> $files
     *
     * @return array{files: list<string>, capped: bool, pruned: list<string>, error: ?string}
     */
    private function capped(array $files): array
    {
        // The non-recursive fast path is glob(), which never descends, so
        // there is nothing for it to have pruned.
        if ($this->maxMatches > 0 && count($files) > $this->maxMatches) {
            return [
                'files' => array_slice($files, 0, $this->maxMatches),
                'capped' => true,
                'pruned' => [],
                'error' => null,
            ];
        }

        return ['files' => $files, 'capped' => false, 'pruned' => [], 'error' => null];
    }

    /**
     * Second half of the jail (see the `..` rejection in {@see execute()}).
     *
     * Rejecting `..` closes the textual escape; this closes the rest — a
     * symlink inside the workspace whose target is outside it resolves out of
     * the jail without any `..` appearing anywhere in the request.
     *
     * @param list<string> $files
     *
     * @return list<string>
     */
    private function withinRoot(array $files): array
    {
        if ($this->root === null) {
            return $files;
        }

        $rootReal = realpath($this->root);
        if ($rootReal === false) {
            return [];
        }

        return array_values(array_filter($files, static function (string $file) use ($rootReal): bool {
            $real = realpath($file);

            return $real !== false
                && ($real === $rootReal || str_starts_with($real, $rootReal . '/'));
        }));
    }

    /**
     * Does $regex compile?
     *
     * The handler swap is not belt-and-braces over `@`: `@` only lowers
     * error_reporting, so an ambient handler (PHPUnit's, or a host app's
     * convert-warnings-to-exceptions one) still sees the compilation failure
     * and can turn a well-formed error ToolResult into a crash. Probing for a
     * bad pattern must be silent by construction, not by convention.
     */
    private static function compiles(string $regex): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            return preg_match($regex, '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    /** True when any `/`-separated segment of $pattern is exactly `..`. */
    private static function hasTraversalSegment(string $pattern): bool
    {
        return in_array('..', explode('/', str_replace('\\', '/', $pattern)), true);
    }

    /**
     * Which dot-prefixed names this pattern opts into.
     *
     * `names` holds the literal hidden segments the pattern actually spells
     * out (`.github/**\/*.yml` → `.github`). `all` is the fallback for a
     * pattern whose hidden segment is itself a wildcard (`.*\/config`), where
     * there is nothing concrete to whitelist.
     *
     * @return array{all: bool, names: array<string, true>}
     */
    private static function hiddenPolicy(string $pattern): array
    {
        $all = false;
        $names = [];

        foreach (explode('/', $pattern) as $segment) {
            if ($segment === '' || !str_starts_with($segment, '.')) {
                continue;
            }

            if (strpbrk($segment, '*?[') === false) {
                $names[$segment] = true;
                continue;
            }

            $all = true;
        }

        return ['all' => $all, 'names' => $names];
    }

    /**
     * The prune set in force for this call, as a lookup keyed by directory
     * name.
     *
     * The list is a default, not a prohibition. Naming a directory in the
     * pattern (`vendor/**\/*.php`) or searching from inside it (`path:
     * "vendor/foo"`) un-prunes it, on the same reasoning the hidden-segment
     * rule uses: spelling a directory out is an explicit request for it, and
     * a search that silently returns nothing for the thing you asked for by
     * name is worse than a slow one. A caller wanting no pruning at all
     * passes `prunedDirs: []` to the constructor.
     *
     * @return array<string, true>
     */
    /**
     * The prune list this instance was built with, for the schema text.
     *
     * Kept separate from {@see prunedDirs()} because the schema is written
     * once per session with no pattern in hand, whereas the per-call set
     * depends on what the pattern named.
     *
     * @return list<string>
     */
    private function prunedDirNames(): array
    {
        return array_values($this->prunedDirs ?? self::DEFAULT_PRUNED_DIRS);
    }

    /**
     * Which prune name to illustrate the opt-out with.
     *
     * A dot-prefixed example (`.git/**\/*.php`) teaches the syntax while
     * naming a directory nobody actually wants searched; the first ordinary
     * one is the case the hatch exists for.
     *
     * @param non-empty-list<string> $names
     */
    private static function pruneExample(array $names): string
    {
        foreach ($names as $name) {
            if (!str_starts_with($name, '.')) {
                return $name;
            }
        }

        return $names[0];
    }

    private function prunedDirs(string $pattern, string $baseDir): array
    {
        $named = array_flip(array_merge(
            explode('/', $pattern),
            explode('/', $baseDir),
        ));

        $pruned = [];
        foreach ($this->prunedDirs ?? self::DEFAULT_PRUNED_DIRS as $dir) {
            if (!isset($named[$dir])) {
                $pruned[$dir] = true;
            }
        }

        return $pruned;
    }

    /**
     * Compile a glob pattern into an anchored PCRE matched against a path
     * RELATIVE to the base directory.
     *
     * The globstar rule that matters: a doubled star followed by a slash
     * matches ZERO or more directory segments, so a recursive `.php` pattern
     * has to find `top.php` in the base directory as well as `a/b/deep.php`.
     * A plain `*` stays confined to one segment.
     *
     * Two deliberate deviations from bash's `globstar`, both kept because the
     * alternative is worse for a tool the model drives:
     *
     * 1. A `**` NOT followed by `/` compiles to `.*`, which crosses `/`. bash
     *    demotes it to a single-segment `*`, so `**.php` there means only
     *    `top.php`; here it also matches `a/b/c.php`. A model that writes
     *    `**.php` means "recursively", and silently answering the
     *    single-level question is the exact failure mode this tool was fixed
     *    to stop doing.
     * 2. `src/**` compiles to `#^src/.*$#` and so does not match `src`
     *    itself, where bash's globstar includes the directory. Listing the
     *    directory you named back to you is noise, and the base directory is
     *    already known to the caller.
     */
    private static function patternToRegex(string $pattern): string
    {
        $out = '';
        $len = strlen($pattern);

        for ($i = 0; $i < $len; $i++) {
            $char = $pattern[$i];

            if ($char === '*') {
                if (($pattern[$i + 1] ?? '') === '*') {
                    $i++;
                    if (($pattern[$i + 1] ?? '') === '/') {
                        $i++;
                        $out .= '(?:[^/]+/)*';
                    } else {
                        $out .= '.*';
                    }
                    continue;
                }

                $out .= '[^/]*';
                continue;
            }

            if ($char === '?') {
                $out .= '[^/]';
                continue;
            }

            if ($char === '[') {
                // POSIX puts a literal `]` first in the class, so `[]]` means
                // "a literal ]" and `[!]]` means "anything but ]". Searching
                // for the terminator from position 1 found that leading `]`
                // and produced the empty class `[]` -- invalid PCRE, and the
                // source of the warning storm. Start the search past it.
                $bodyStart = $i + 1;
                if (($pattern[$bodyStart] ?? '') === '!') {
                    $bodyStart++;
                }
                if (($pattern[$bodyStart] ?? '') === ']') {
                    $bodyStart++;
                }

                $close = strpos($pattern, ']', $bodyStart);
                if ($close !== false) {
                    $body = substr($pattern, $i + 1, $close - $i - 1);
                    // glob negates with `!`, PCRE with `^`.
                    if (str_starts_with($body, '!')) {
                        $body = '^' . substr($body, 1);
                    }
                    // `]` is escaped too: PCRE has no leading-`]` rule, so the
                    // POSIX idiom has to be spelled `[\]]` to survive.
                    // The `#` delimiter ends the pattern even inside a
                    // bracket expression, so it has to be escaped here too.
                    $out .= '[' . str_replace(['\\', '#', ']'], ['\\\\', '\\#', '\\]'], $body) . ']';
                    $i = $close;
                    continue;
                }

                // Unterminated `[` -- glob() treats it as a literal, so do we.
            }

            $out .= preg_quote($char, '#');
        }

        return '#^' . $out . '$#';
    }
}
