<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools;

/**
 * The one place that decides whether a path is worth showing the model.
 *
 * Three separate rules live here, deliberately in ONE class rather than
 * reimplemented per tool — the same seam {@see Concerns\BuildsUnifiedDiff} was
 * extracted for. `Glob` and `Grep` answer the same question ("which paths
 * count?") and must not answer it differently:
 *
 * 1. A **default-exclude list** of machine-generated directory names, applied
 *    whether or not the project has a `.gitignore` at all.
 * 2. A real **`.gitignore` parser**, including nested files applying to their
 *    own subtree.
 * 3. A **symlinked-directory hard stop** — see {@see halts()}.
 *
 * ## `.gitignore` syntax this SUPPORTS
 *
 * - `#` comments, and `\#` for a literal leading `#`
 * - blank lines, and trailing whitespace stripped unless backslash-escaped
 * - `!` negation, later rules winning over earlier ones
 * - trailing `/` — directory-only patterns
 * - leading `/` — anchored to the `.gitignore`'s own directory
 * - a `/` anywhere in the middle — also anchored, per git's rule
 * - a pattern with no `/` at all — matched at any depth below the file
 * - `*` and `?` (neither crosses `/`), and `[...]` / `[!...]` classes
 * - `**\/` (any depth prefix), `/**` (everything below), `a/**\/b` (zero or
 *   more intervening directories)
 * - nested `.gitignore` files, each scoped to its own subtree, deeper files
 *   overriding shallower ones
 * - `$root/.git/info/exclude`, evaluated at LOWER precedence than any
 *   `.gitignore`, matching git
 * - git's "a re-include cannot resurrect a file under an excluded directory"
 *   rule: once an ancestor directory is ignored, nothing below it can be
 *   negated back in
 *
 * ## What it deliberately does NOT support
 *
 * - `core.excludesFile` (the user's global ignore file) and any other git
 *   config: reading a user's `~/.config/git/ignore` would make an agent's
 *   answers depend on machine-local state the repo cannot see, and the failure
 *   mode is a silently-missing file.
 * - `core.ignoreCase` — matching is always case-SENSITIVE. Emulating a
 *   case-insensitive checkout would ignore paths git does not on the case
 *   sensitive filesystems this runs on.
 * - `.gitattributes`, sparse-checkout, and the index. Nothing here consults
 *   git itself; the parser is pure filesystem so it works in a directory that
 *   was never a repository.
 * - Tracked-file precedence. Git ignores nothing that is already tracked; this
 *   has no index to consult, so a committed file matching an ignore pattern is
 *   treated as ignored. It is the one place this is stricter than git, and the
 *   `include_ignored` argument on both tools is the escape hatch.
 *
 * NOT `readonly` as a class: {@see $parsed} memoizes parsed `.gitignore` files
 * for the duration of one walk, which is a cache rather than state — every
 * public field is `readonly` and every `with*()` still returns a new instance.
 */
final class IgnoreRules
{
    /**
     * Directory names skipped even in a project with no `.gitignore` at all.
     *
     * These are the trees that make a recursive walk unusable: large,
     * machine-generated, and almost never what a question about "the code"
     * means. Measured on the SugarCraft monorepo, `**\/*.php` returned 8,916
     * paths of which 8,439 were under `vendor/`.
     *
     * A default list is needed IN ADDITION to `.gitignore` because the two
     * miss in opposite directions: a repo that commits its `vendor/` has no
     * ignore rule for it, and a freshly-scaffolded directory has no
     * `.gitignore` yet.
     */
    public const DEFAULT_EXCLUDED_DIRS = ['.git', 'vendor', 'node_modules', '.phpunit.cache'];

    /**
     * Parsed rules per directory, keyed by the absolute directory path.
     *
     * Absent and empty `.gitignore` files are memoized as `[]` too — a walk
     * over a deep tree asks about the same directories thousands of times, and
     * the negative answer is the common one.
     *
     * @var array<string, list<array{regex: string, dirOnly: bool, negate: bool}>>
     */
    private array $parsed = [];

    /**
     * @param list<string> $excludedDirs
     */
    private function __construct(
        public readonly string $root,
        public readonly bool $honoursGitignore,
        public readonly array $excludedDirs,
        public readonly bool $followsSymlinks,
    ) {}

    public static function new(string $root): self
    {
        return new self(self::normalizeRoot($root), true, self::DEFAULT_EXCLUDED_DIRS, false);
    }

    public function withGitignore(bool $honours): self
    {
        return $this->mutate(honoursGitignore: $honours);
    }

    /**
     * @param list<string> $names
     */
    public function withExcludedDirs(array $names): self
    {
        return $this->mutate(excludedDirs: array_values($names));
    }

    /**
     * Drop $names from the exclude list.
     *
     * The caller that needs this is a tool whose request NAMED one of the
     * excluded directories — `path: "vendor/acme"`, or a pattern spelling
     * `vendor/` out. Spelling a directory out is an explicit request for it,
     * and a search that silently returns nothing for the thing you asked for
     * by name is worse than a slow one.
     *
     * @param list<string> $names
     */
    public function withoutExcludedDirs(array $names): self
    {
        $drop = array_flip($names);

        return $this->mutate(excludedDirs: array_values(array_filter(
            $this->excludedDirs,
            static fn (string $dir): bool => !isset($drop[$dir]),
        )));
    }

    /**
     * Whether a symlinked directory may be descended into.
     *
     * Deliberately NOT exposed as a tool argument on `Glob`/`Grep`; see
     * {@see halts()} for why, and for the hatch the model gets instead.
     */
    public function withFollowSymlinks(bool $follows): self
    {
        return $this->mutate(followsSymlinks: $follows);
    }

    /**
     * Is $absoluteDir a place a recursive walk must stop dead?
     *
     * A symlinked directory is a hard stop by default, and the reason is not
     * hypothetical: SugarCraft is built on composer path repositories, so
     * every lib's `vendor/sugarcraft/` is a fan of symlinks back to its
     * SIBLING libraries — `sugar-crush/vendor/sugarcraft/candy-core` →
     * `../../../candy-core`. Following those from the monorepo root means
     * `candy-core` is walked once per lib that depends on it, and
     * `candy-core/vendor/sugarcraft/candy-ansi/vendor/…` is a cycle with no
     * termination condition at all.
     *
     * There is no `follow_symlinks` tool argument to turn this off, on
     * purpose: an unbounded follow re-creates exactly the non-terminating walk
     * the stop exists to prevent, and the model already has a bounded hatch
     * that costs it nothing — point `path` AT the link. Seeding a walk at a
     * symlinked directory works (the base is resolved before the walk starts),
     * and the stop still applies to links found underneath it, so the search
     * is one level of indirection deep and always terminates.
     */
    public function halts(string $absoluteDir): bool
    {
        return !$this->followsSymlinks && is_link($absoluteDir) && is_dir($absoluteDir);
    }

    public function excludesDirectoryNamed(string $name): bool
    {
        return in_array($name, $this->excludedDirs, true);
    }

    /**
     * The first default-excluded directory name on $absolutePath's way down
     * from the root, or null when there is none.
     *
     * Returns the NAME rather than a bool because what a walk left out is part
     * of its answer: a result that skipped `vendor/` has to be able to say so.
     */
    public function excludedDirectoryIn(string $absolutePath): ?string
    {
        $relative = $this->relative($absolutePath);
        if ($relative === null || $relative === '') {
            return null;
        }

        foreach (explode('/', $relative) as $segment) {
            if ($this->excludesDirectoryNamed($segment)) {
                return $segment;
            }
        }

        return null;
    }

    /**
     * Does `.gitignore` exclude $absolutePath?
     *
     * $isDirectory decides trailing-`/` patterns, which a path string alone
     * cannot answer — and the caller usually knows already (a directory walk
     * has just stat'ed it), so asking beats a redundant `is_dir()`.
     *
     * Every ANCESTOR is tested before the path itself, shallowest first, and
     * the first ignored ancestor wins outright. That is git's re-inclusion
     * rule ("it is not possible to re-include a file if a parent directory of
     * that file is excluded"), and it is also what makes a nested
     * `.gitignore` inside an ignored directory correctly never load.
     */
    public function ignores(string $absolutePath, bool $isDirectory): bool
    {
        if (!$this->honoursGitignore) {
            return false;
        }

        $relative = $this->relative($absolutePath);
        if ($relative === null || $relative === '') {
            return false;
        }

        $segments = explode('/', $relative);
        $depth = count($segments);

        for ($i = 0; $i < $depth; $i++) {
            $prefix = implode('/', array_slice($segments, 0, $i + 1));
            // Everything above the last segment is by construction a directory.
            $verdict = $this->verdict($prefix, $i < $depth - 1 || $isDirectory);
            if ($verdict === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `--exclude-dir` fragment for a `grep -r` command line.
     *
     * ONLY the default-exclude list is pushed down to grep, never the parsed
     * `.gitignore` rules, even though grep's `--exclude`/`--exclude-dir` could
     * express some of them. A gitignore ruleset can NEGATE (`!vendor/keep.php`
     * in any nested file), and grep has no re-include flag — so a rule handed
     * to grep is a rule that can no longer be taken back, and the result would
     * silently omit a file the project explicitly un-ignored. `.gitignore` is
     * therefore enforced by filtering grep's output instead, where negation
     * still works.
     *
     * The traversal saving that matters survives anyway: `vendor/`,
     * `node_modules/` and `.git/` are the trees that dominate the walk, and
     * they are in the default list.
     */
    public function grepExcludeFlags(): string
    {
        $flags = '';
        foreach ($this->excludedDirs as $dir) {
            $flags .= ' --exclude-dir=' . escapeshellarg($dir);
        }

        return $flags;
    }

    private function mutate(
        ?bool $honoursGitignore = null,
        ?array $excludedDirs = null,
        ?bool $followsSymlinks = null,
    ): self {
        return new self(
            $this->root,
            $honoursGitignore ?? $this->honoursGitignore,
            $excludedDirs ?? $this->excludedDirs,
            $followsSymlinks ?? $this->followsSymlinks,
        );
    }

    /**
     * The verdict of every `.gitignore` that governs $relative: true ignored,
     * false explicitly re-included, null nobody had an opinion.
     *
     * Files are consulted shallowest-first so the deepest one wins, and
     * `.git/info/exclude` goes first of all — git ranks it BELOW every
     * `.gitignore`, so anything the repo's own files say overrides it.
     */
    private function verdict(string $relative, bool $isDirectory): ?bool
    {
        $verdict = null;

        foreach ($this->rulesetFiles($relative) as [$dir, $file]) {
            $scoped = $dir === '' ? $relative : substr($relative, strlen($dir) + 1);

            foreach ($this->rulesIn($file) as $rule) {
                if ($rule['dirOnly'] && !$isDirectory) {
                    continue;
                }
                if (preg_match($rule['regex'], $scoped) === 1) {
                    $verdict = !$rule['negate'];
                }
            }
        }

        return $verdict;
    }

    /**
     * Ignore files governing $relative, in ASCENDING precedence order, each
     * paired with the root-relative directory its patterns are scoped to.
     *
     * A list of pairs rather than a `dir => file` map because the root
     * contributes TWO files at the same scope — `.git/info/exclude` and
     * `.gitignore` — which a map keyed by directory cannot hold.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function rulesetFiles(string $relative): array
    {
        $files = [
            ['', $this->root . '/.git/info/exclude'],
            ['', $this->root . '/.gitignore'],
        ];

        $segments = explode('/', $relative);
        array_pop($segments);

        $walked = '';
        foreach ($segments as $segment) {
            $walked = $walked === '' ? $segment : $walked . '/' . $segment;
            $files[] = [$walked, $this->root . '/' . $walked . '/.gitignore'];
        }

        return $files;
    }

    /**
     * @return list<array{regex: string, dirOnly: bool, negate: bool}>
     */
    private function rulesIn(string $file): array
    {
        if (isset($this->parsed[$file])) {
            return $this->parsed[$file];
        }

        $rules = [];
        // @ rather than is_readable(): a directory the walk cannot stat is a
        // "no rules here", not a warning painted under the TUI frame.
        $contents = is_file($file) ? @file_get_contents($file) : false;
        if ($contents !== false) {
            foreach (preg_split('/\R/', $contents) ?: [] as $line) {
                $rule = self::parseLine($line);
                if ($rule !== null) {
                    $rules[] = $rule;
                }
            }
        }

        return $this->parsed[$file] = $rules;
    }

    /**
     * @return array{regex: string, dirOnly: bool, negate: bool}|null
     */
    private static function parseLine(string $line): ?array
    {
        // Trailing whitespace is not part of a pattern unless escaped. Leading
        // whitespace IS, so only the right side is trimmed.
        $line = self::trimUnescapedTrailingSpace($line);
        if ($line === '' || str_starts_with($line, '#')) {
            return null;
        }

        $negate = str_starts_with($line, '!');
        if ($negate) {
            $line = substr($line, 1);
        } elseif (str_starts_with($line, '\\#') || str_starts_with($line, '\\!')) {
            // `\#foo` / `\!foo` are literals; the backslash is not part of the
            // name and would otherwise escape the character into the regex
            // twice.
            $line = substr($line, 1);
        }

        $dirOnly = str_ends_with($line, '/');
        if ($dirOnly) {
            $line = rtrim($line, '/');
        }

        if ($line === '') {
            return null;
        }

        $anchored = str_starts_with($line, '/');
        if ($anchored) {
            $line = ltrim($line, '/');
        } elseif (str_contains($line, '/')) {
            // git: a separator anywhere but the end anchors the pattern to the
            // .gitignore's own directory.
            $anchored = true;
        }

        if ($line === '') {
            return null;
        }

        $regex = self::compile($line, $anchored);
        if (!self::compiles($regex)) {
            // A malformed bracket expression is a broken line in someone's
            // .gitignore, not a reason to fail the search. Git skips what it
            // cannot parse; so does this.
            return null;
        }

        return ['regex' => $regex, 'dirOnly' => $dirOnly, 'negate' => $negate];
    }

    /**
     * Strip trailing spaces and tabs that are not backslash-escaped.
     *
     * `foo\ ` is a pattern for a name ending in a space; `foo   ` is a pattern
     * for `foo` written carelessly. Only the second may be trimmed.
     */
    private static function trimUnescapedTrailingSpace(string $line): string
    {
        $end = strlen($line);
        while ($end > 0 && ($line[$end - 1] === ' ' || $line[$end - 1] === "\t")) {
            // Count the backslashes immediately before this space: an odd
            // number means the space is escaped and the trim stops here.
            $slashes = 0;
            $probe = $end - 2;
            while ($probe >= 0 && $line[$probe] === '\\') {
                $slashes++;
                $probe--;
            }
            if ($slashes % 2 === 1) {
                break;
            }
            $end--;
        }

        return substr($line, 0, $end);
    }

    /**
     * Compile one gitignore pattern into an anchored PCRE, matched against a
     * path relative to the `.gitignore`'s own directory.
     *
     * The `(?:[^/]+/)*` prefix on an UNANCHORED pattern is what makes a bare
     * `build` match `a/b/build` — git's "match at any level below" rule. An
     * anchored pattern gets no such prefix, so `/build` is the top-level one
     * only.
     */
    private static function compile(string $pattern, bool $anchored): string
    {
        $out = '';
        $length = strlen($pattern);

        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];

            if ($char === '\\' && $i + 1 < $length) {
                $out .= preg_quote($pattern[++$i], '#');
                continue;
            }

            if ($char === '*') {
                if (($pattern[$i + 1] ?? '') === '*') {
                    $i++;
                    if (($pattern[$i + 1] ?? '') === '/') {
                        $i++;
                        // Zero OR MORE directories, so `a/**\/b` matches `a/b`.
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
                $compiled = self::compileClass($pattern, $i);
                if ($compiled !== null) {
                    $out .= $compiled['regex'];
                    $i = $compiled['end'];
                    continue;
                }
                // Unterminated `[` is a literal, as it is to fnmatch().
            }

            $out .= preg_quote($char, '#');
        }

        return '#^' . ($anchored ? '' : '(?:[^/]+/)*') . $out . '$#';
    }

    /**
     * Translate the bracket expression starting at $start into a PCRE class.
     *
     * POSIX puts a literal `]` first in the class, so `[]]` means "a literal
     * ]"; searching for the terminator from position 1 would find that leading
     * `]` and emit the empty — and invalid — class `[]`.
     *
     * @return array{regex: string, end: int}|null null when unterminated
     */
    private static function compileClass(string $pattern, int $start): ?array
    {
        $bodyStart = $start + 1;
        if (($pattern[$bodyStart] ?? '') === '!' || ($pattern[$bodyStart] ?? '') === '^') {
            $bodyStart++;
        }
        if (($pattern[$bodyStart] ?? '') === ']') {
            $bodyStart++;
        }

        $close = strpos($pattern, ']', $bodyStart);
        if ($close === false) {
            return null;
        }

        $body = substr($pattern, $start + 1, $close - $start - 1);
        if (str_starts_with($body, '!')) {
            $body = '^' . substr($body, 1);
        }

        // `#` is the delimiter and ends the pattern even inside a class, so it
        // has to be escaped here as well as outside.
        return [
            'regex' => '[' . str_replace(['\\', '#', ']'], ['\\\\', '\\#', '\\]'], $body) . ']',
            'end' => $close,
        ];
    }

    /**
     * Does $regex compile?
     *
     * The handler swap is not belt-and-braces over `@`: `@` only lowers
     * error_reporting, so an ambient convert-warnings-to-exceptions handler
     * still sees the compilation failure. Probing a stranger's `.gitignore`
     * must be silent by construction.
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

    /**
     * $absolutePath relative to the root, or null when it is not under it.
     *
     * Null rather than false-and-a-guess: a path outside the root is one this
     * ruleset has no jurisdiction over, and answering "not ignored" for it is
     * the only honest verdict.
     */
    private function relative(string $absolutePath): ?string
    {
        $path = rtrim($absolutePath, '/');

        if ($path === $this->root) {
            return '';
        }

        if (!str_starts_with($path, $this->root . '/')) {
            return null;
        }

        return substr($path, strlen($this->root) + 1);
    }

    /**
     * The filesystem root is the one path whose rtrim'd form is empty, and an
     * empty root still has to concatenate into `/.gitignore` rather than
     * `//.gitignore`, so '' is exactly the right normal form for it.
     */
    private static function normalizeRoot(string $root): string
    {
        $real = realpath($root);

        return rtrim($real === false ? $root : $real, '/');
    }
}
