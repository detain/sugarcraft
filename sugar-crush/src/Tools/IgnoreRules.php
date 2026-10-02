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
 * ## Matching cost is bounded, and an undecidable rule fails CLOSED
 *
 * A cloned repository supplies both the `.gitignore` and the files it is
 * matched against, so a pattern is hostile input. Translating every glob
 * straight to a backtracking PCRE (the old design) let one 45-byte line —
 * `**a**a**a…**c`, i.e. `.*a.*a.*a…c` — cost ~70 ms per path and then end in
 * "backtrack limit exhausted", which `preg_match() === 1` read as "no match":
 * `Glob '**\/*'` over 2,000 files took 139 s and every such rule silently
 * stopped applying. Collapsing `*` runs is not a fix on its own: `**a**a`
 * still needs two `a`s, so the wildcards are separated, not adjacent.
 *
 * So a pattern is compiled to a token list ({@see tokenize()}) that serves two
 * matchers with the SAME language:
 *
 * - PCRE, only for patterns whose wildcards cannot compete for the same text
 *   ({@see backtracksSafely()} — the `*.log` / `**\/foo` / `foo/**` shapes that
 *   are nearly every real rule), because it is the fast path;
 * - otherwise, and whenever PCRE errors anyway, an automaton built from the
 *   tokens by subset construction ({@see simulate()}): O(path × pattern) for
 *   a fresh path, one lookup per byte for a path like one already seen, and
 *   no backtracking at all.
 *
 * The automaton refuses only inputs whose product exceeds {@see MATCH_BUDGET}.
 * Such a rule is undecidable rather than "not matching", and it is resolved
 * toward HIDING: a hide rule applies, a negation does not re-include. Ignore
 * rules are not a security boundary, but a secret's path being shown because
 * a stranger's rule was too expensive to evaluate is the failure that
 * matters, and over-hiding has an escape hatch (`include_ignored`). Each such
 * rule is recorded once on {@see undecidablePatterns()} rather than
 * `error_log()`ed, since stderr under a full-screen TUI is nowhere the user
 * reads — and Glob and Grep append {@see undecidableNote()} to their result,
 * so the model is told which rule hid what it may have expected to see.
 *
 * NOT `readonly` as a class: {@see $parsed} (parsed `.gitignore` files),
 * {@see $directoryVerdicts} (the verdict per ancestor directory) and
 * {@see $automata} (the matchers) memoize for the duration of one walk, which
 * makes them caches rather than state — every public field is `readonly` and
 * every `with*()` still returns a new instance.
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
     * Upper bound on path length × pattern tokens one NFA match may cost.
     *
     * Sized so no plausible rule reaches it — a 200-token pattern against a
     * PATH_MAX (4,096-byte) path is 820k — while a pathological line, which
     * is all that can, is refused before any work rather than after it. Same
     * order as PCRE's default `pcre.backtrack_limit`, which is the cost the
     * regex path was already allowed per call.
     */
    private const MATCH_BUDGET = 1_000_000;

    /** DFA states one rule's automaton may intern before it is rebuilt. */
    private const AUTOMATON_STATE_CAP = 4096;

    /** How many undecidable rules {@see undecidableNote()} names before it only counts. */
    public const UNDECIDABLE_NOTE_MAX_RULES = 3;

    /** How much of one undecidable pattern {@see undecidableNote()} quotes. */
    public const UNDECIDABLE_NOTE_MAX_PATTERN_BYTES = 80;

    /** One literal byte; payload is the byte. */
    private const T_LITERAL = 0;
    /** `?` — one byte that is not `/`. */
    private const T_ONE = 1;
    /** `[...]` — one byte in the class; payload is the compiled PCRE class. */
    private const T_CLASS = 2;
    /** `*` — any run of bytes without `/`. */
    private const T_STAR = 3;
    /** `**` NOT followed by `/` — any run of bytes except `\n` (PCRE `.*`). */
    private const T_GLOBSTAR = 4;
    /** `**\/`, and an unanchored pattern's implicit prefix — `(?:[^/]+/)*`. */
    private const T_DIRS = 5;

    /**
     * Parsed rules per directory, keyed by the absolute directory path.
     *
     * Absent and empty `.gitignore` files are memoized as `[]` too — a walk
     * over a deep tree asks about the same directories thousands of times, and
     * the negative answer is the common one.
     *
     * @var array<string, list<array{regex: string, tokens: list<array{0: int, 1: string}>, pcre: bool, dirOnly: bool, negate: bool, source: string}>>
     */
    private array $parsed = [];

    /**
     * {@see verdict()} per root-relative DIRECTORY prefix.
     *
     * {@see ignores()} re-tests every ancestor of every path, so a walk over N
     * files in one directory asked the same question about its parents N
     * times. The answer depends only on the memoized {@see $parsed} rules, so
     * it is exactly as stable as they are.
     *
     * @var array<string, ?bool>
     */
    private array $directoryVerdicts = [];

    /**
     * Rules no matcher could decide within {@see MATCH_BUDGET}, keyed so each
     * is recorded once however many paths it was tried against.
     *
     * Kept as the (file, pattern) pair rather than the joined string
     * {@see undecidablePatterns()} returns, so {@see undecidableNote()} can
     * shorten the file to its root-relative form without splitting on a `: `
     * that a path may itself contain.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private array $undecidable = [];

    /**
     * Lazily-built DFA per compiled regex; see {@see simulate()}.
     *
     * @var array<string, array{ids: array<string, int>, sets: list<list<int>>, accepts: list<bool>, next: array<int, array<string, int>>}>
     */
    private array $automata = [];

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
            if ($i < $depth - 1 || $isDirectory) {
                $verdict = array_key_exists($prefix, $this->directoryVerdicts)
                    ? $this->directoryVerdicts[$prefix]
                    : ($this->directoryVerdicts[$prefix] = $this->verdict($prefix, true));
            } else {
                $verdict = $this->verdict($prefix, false);
            }
            if ($verdict === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ignore rules that were too expensive to decide and were therefore
     * resolved toward hiding, as `<gitignore path>: <pattern>`, once each.
     *
     * Exposed so a caller can say WHY a path it expected is missing; an empty
     * list is the normal case.
     *
     * @return list<string>
     */
    public function undecidablePatterns(): array
    {
        return array_map(
            static fn (array $rule): string => $rule[0] . ': ' . $rule[1],
            array_values($this->undecidable),
        );
    }

    /**
     * One `... [gitignore: ...]` line naming the rules {@see undecidablePatterns()}
     * holds, for a tool to append to its result; null when there are none.
     *
     * WHY A TOOL HAS TO SAY THIS (audit F-T5 residual, R5). An undecidable rule
     * is resolved toward HIDING, so a path it would not actually have matched
     * can vanish from a Glob list or a Grep hit list. The tool's own
     * `gitignored` count includes it, which tells the model SOMETHING was
     * hidden but blames the project's rules for it — and the model has no
     * reason to doubt a rule it cannot see. Naming the rule that could not be
     * evaluated, and the argument that bypasses it, is the difference between
     * "the file is not there" and "a pathological ignore line may be hiding
     * it".
     *
     * BOUNDED, because the text is repository content: a hostile `.gitignore`
     * is exactly where an undecidable rule comes from, and its lines are as
     * long as its author likes (the repro's rule is 2,000+ bytes). At most
     * {@see UNDECIDABLE_NOTE_MAX_RULES} rules are named, each pattern clipped
     * to {@see UNDECIDABLE_NOTE_MAX_PATTERN_BYTES} on a UTF-8 boundary, with
     * control bytes shown as `?` so a pattern cannot carry an escape sequence
     * into the transcript. The rest are counted, not listed.
     *
     * No trailing newline: Grep's notes carry none and Glob adds its own.
     */
    public function undecidableNote(): ?string
    {
        $total = count($this->undecidable);
        if ($total === 0) {
            return null;
        }

        $named = [];
        foreach (array_slice(array_values($this->undecidable), 0, self::UNDECIDABLE_NOTE_MAX_RULES) as [$file, $source]) {
            $named[] = self::printable($this->relative($file) ?? $file, PHP_INT_MAX)
                . ': ' . self::printable($source, self::UNDECIDABLE_NOTE_MAX_PATTERN_BYTES);
        }
        $rest = $total - count($named);

        return sprintf(
            '... [gitignore: %d ignore rule%s too costly to evaluate %s resolved toward hiding, so a path '
            . '%s might not really match can be missing here: %s%s. Pass include_ignored: true to bypass .gitignore.]',
            $total,
            $total === 1 ? '' : 's',
            $total === 1 ? 'was' : 'were',
            $total === 1 ? 'it' : 'they',
            implode('; ', $named),
            $rest > 0 ? sprintf('; and %d more', $rest) : '',
        );
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
                $matched = $this->matches($rule, $scoped);
                if ($matched === null) {
                    $this->undecidable[$file . "\0" . $rule['source']] ??= [$file, $rule['source']];
                    // Fail CLOSED, which is direction-dependent: a hide rule
                    // is taken to apply, a negation is taken NOT to — either
                    // way the uncertain path stays hidden.
                    if (!$rule['negate']) {
                        $verdict = true;
                    }
                    continue;
                }
                if ($matched) {
                    $verdict = !$rule['negate'];
                }
            }
        }

        return $verdict;
    }

    /**
     * Does $rule match $subject? Null when it cannot be decided in budget.
     *
     * A PCRE error (backtrack or JIT-stack limit) is NOT an answer: the old
     * `=== 1` read it as "no match", so a rule that was expensive enough
     * quietly stopped applying. It falls through to the exact NFA instead.
     *
     * @param array{regex: string, tokens: list<array{0: int, 1: string}>, pcre: bool, dirOnly: bool, negate: bool, source: string} $rule
     */
    private function matches(array $rule, string $subject): ?bool
    {
        if ($rule['pcre']) {
            $result = preg_match($rule['regex'], $subject);
            if ($result !== false) {
                return $result === 1;
            }
        }

        return $this->simulate($rule, $subject);
    }

    /**
     * Match $rule against the WHOLE of $subject by subset construction.
     *
     * Accepts exactly the language of {@see regexFor()}'s `#^…$#`, quirks
     * included — `**` (`.*`) does not cross `\n`, and `$` also matches before
     * one trailing `\n` — so which matcher runs never changes a verdict.
     *
     * NFA state k is "before token k", m is accept, and m+1+k is "inside a
     * name of the `(?:[^/]+/)*` at k". Each set of live NFA states is interned
     * as one DFA state the first time it is reached and every transition is
     * cached, so a fresh byte costs O(tokens) and a repeat costs one array
     * lookup: no backtracking, and a walk of N similar paths pays the
     * construction once. Keyed by the compiled regex, so twenty copies of one
     * hostile line share one automaton.
     *
     * @param array{regex: string, tokens: list<array{0: int, 1: string}>, pcre: bool, dirOnly: bool, negate: bool, source: string} $rule
     */
    private function simulate(array $rule, string $subject): ?bool
    {
        $tokens = $rule['tokens'];
        $m = count($tokens);
        $n = strlen($subject);
        if ($n * ($m + 1) > self::MATCH_BUDGET) {
            return null;
        }

        $key = $rule['regex'];
        if (!isset($this->automata[$key]) || count($this->automata[$key]['sets']) > self::AUTOMATON_STATE_CAP) {
            // A cap rather than an unbounded cache: the subsets are at most
            // exponential in the pattern, and starting over loses only speed.
            $this->automata[$key] = ['ids' => [], 'sets' => [], 'accepts' => [], 'next' => []];
            $start = [];
            self::close($start, 0, $tokens, $m);
            self::intern($this->automata[$key], $start, $m);
        }
        $automaton = &$this->automata[$key];

        $state = 0;
        for ($i = 0; $i < $n; $i++) {
            $byte = $subject[$i];
            if ($i === $n - 1 && $byte === "\n" && $automaton['accepts'][$state]) {
                return true;
            }
            if (!isset($automaton['next'][$state][$byte])) {
                $live = self::step($automaton['sets'][$state], $byte, $tokens, $m);
                if ($live === null) {
                    return null;
                }
                $automaton['next'][$state][$byte] = self::intern($automaton, $live, $m);
            }
            $state = $automaton['next'][$state][$byte];
            if ($automaton['sets'][$state] === []) {
                return false;
            }
        }

        return $automaton['accepts'][$state];
    }

    /**
     * The NFA states live after $byte, from the live set $states; null when a
     * `[...]` class could not be tested.
     *
     * A class is still tested by PCRE, one byte against one class — which
     * cannot backtrack, but CAN still report a limit error when the limit is
     * set absurdly low. That is an unknown, not a "no", so it is passed up to
     * fail closed rather than guessed.
     *
     * @param list<int> $states
     * @param list<array{0: int, 1: string}> $tokens
     * @return array<int, true>|null
     */
    private static function step(array $states, string $byte, array $tokens, int $m): ?array
    {
        $next = [];
        foreach ($states as $state) {
            if ($state === $m) {
                continue;
            }
            if ($state > $m) {
                // Inside one `[^/]+/` iteration: more name, or the `/` that
                // returns to the boundary before the same token.
                if ($byte === '/') {
                    self::close($next, $state - $m - 1, $tokens, $m);
                } else {
                    $next[$state] = true;
                }
                continue;
            }

            [$type, $payload] = $tokens[$state];
            if ($type === self::T_DIRS) {
                if ($byte !== '/') {
                    $next[$m + 1 + $state] = true;
                }
                continue;
            }
            if ($type === self::T_CLASS) {
                $inClass = preg_match('#' . $payload . '#', $byte);
                if ($inClass === false) {
                    return null;
                }
                if ($inClass === 1) {
                    self::close($next, $state + 1, $tokens, $m);
                }
                continue;
            }
            $advance = match ($type) {
                self::T_LITERAL => $byte === $payload ? $state + 1 : null,
                self::T_ONE => $byte !== '/' ? $state + 1 : null,
                self::T_STAR => $byte !== '/' ? $state : null,
                self::T_GLOBSTAR => $byte !== "\n" ? $state : null,
                default => null,
            };
            if ($advance !== null) {
                self::close($next, $advance, $tokens, $m);
            }
        }

        return $next;
    }

    /**
     * Add $state and every state reachable from it without consuming a byte:
     * a zero-length `*`, `**` or `(?:[^/]+/)*` steps straight to the next.
     *
     * @param array<int, true> $set
     * @param list<array{0: int, 1: string}> $tokens
     */
    private static function close(array &$set, int $state, array $tokens, int $m): void
    {
        while (!isset($set[$state])) {
            $set[$state] = true;
            if ($state >= $m) {
                return;
            }
            $type = $tokens[$state][0];
            if ($type !== self::T_STAR && $type !== self::T_GLOBSTAR && $type !== self::T_DIRS) {
                return;
            }
            $state++;
        }
    }

    /**
     * The DFA state id for NFA set $set, creating it on first sight.
     *
     * @param array{ids: array<string, int>, sets: list<list<int>>, accepts: list<bool>, next: array<int, array<string, int>>} $automaton
     * @param array<int, true> $set
     */
    private static function intern(array &$automaton, array $set, int $m): int
    {
        $states = array_keys($set);
        sort($states);
        $key = implode(',', $states);
        if (isset($automaton['ids'][$key])) {
            return $automaton['ids'][$key];
        }

        $id = count($automaton['sets']);
        $automaton['ids'][$key] = $id;
        $automaton['sets'][] = $states;
        $automaton['accepts'][] = isset($set[$m]);

        return $id;
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
     * @return list<array{regex: string, tokens: list<array{0: int, 1: string}>, pcre: bool, dirOnly: bool, negate: bool, source: string}>
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
     * @return array{regex: string, tokens: list<array{0: int, 1: string}>, pcre: bool, dirOnly: bool, negate: bool, source: string}|null
     */
    private static function parseLine(string $line): ?array
    {
        // Trailing whitespace is not part of a pattern unless escaped. Leading
        // whitespace IS, so only the right side is trimmed.
        $line = self::trimUnescapedTrailingSpace($line);
        if ($line === '' || str_starts_with($line, '#')) {
            return null;
        }
        $source = $line;

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

        $tokens = self::tokenize($line);
        // Classified BEFORE the implicit prefix is added: that prefix only
        // ever splits at `/`, so it multiplies the cost by the path's depth
        // rather than compounding with the pattern's own wildcards.
        $pcre = self::backtracksSafely($tokens);
        if (!$anchored) {
            // git's "match at any level below": a bare `build` matches
            // `a/b/build`. An anchored pattern gets no such prefix, so
            // `/build` is the top-level one only.
            array_unshift($tokens, [self::T_DIRS, '']);
        }

        $regex = self::regexFor($tokens);
        if (!self::compiles($regex)) {
            // A malformed bracket expression is a broken line in someone's
            // .gitignore, not a reason to fail the search. Git skips what it
            // cannot parse; so does this.
            return null;
        }

        return [
            'regex' => $regex,
            'tokens' => $tokens,
            'pcre' => $pcre,
            'dirOnly' => $dirOnly,
            'negate' => $negate,
            'source' => $source,
        ];
    }

    /**
     * Can PCRE run this pattern without catastrophic backtracking?
     *
     * Backtracking explodes when unbounded wildcards can trade the same text
     * between them: `.*a.*a.*c` tries every split of the path among its `.*`s.
     * Allowed: at most two wildcards, at most one of which crosses `/` (`**`
     * or `**\/`). That keeps every real-world shape — `*.log`, `*.min.*`,
     * `**\/*.php`, `foo/**`, `a/**\/b` — on the fast path, and its worst case
     * is quadratic, which PCRE's backtrack limit then bounds and
     * {@see matches()} answers exactly via the NFA. Anything more goes straight
     * to the NFA rather than paying the limit first on every path.
     *
     * @param list<array{0: int, 1: string}> $tokens
     */
    private static function backtracksSafely(array $tokens): bool
    {
        $wildcards = 0;
        $crossing = 0;
        foreach ($tokens as [$type]) {
            if ($type === self::T_STAR) {
                $wildcards++;
            } elseif ($type === self::T_GLOBSTAR || $type === self::T_DIRS) {
                $wildcards++;
                $crossing++;
            }
        }

        return $wildcards <= 2 && $crossing <= 1;
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
     * Split one gitignore pattern into matcher tokens, each byte of it
     * accounted for exactly once.
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function tokenize(string $pattern): array
    {
        $tokens = [];
        $length = strlen($pattern);

        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];

            if ($char === '\\' && $i + 1 < $length) {
                $tokens[] = [self::T_LITERAL, $pattern[++$i]];
                continue;
            }

            if ($char === '*') {
                if (($pattern[$i + 1] ?? '') === '*') {
                    $i++;
                    if (($pattern[$i + 1] ?? '') === '/') {
                        $i++;
                        // Zero OR MORE directories, so `a/**\/b` matches `a/b`.
                        $tokens[] = [self::T_DIRS, ''];
                    } else {
                        $tokens[] = [self::T_GLOBSTAR, ''];
                    }
                    continue;
                }

                $tokens[] = [self::T_STAR, ''];
                continue;
            }

            if ($char === '?') {
                $tokens[] = [self::T_ONE, ''];
                continue;
            }

            if ($char === '[') {
                $compiled = self::compileClass($pattern, $i);
                if ($compiled !== null) {
                    $tokens[] = [self::T_CLASS, $compiled['regex']];
                    $i = $compiled['end'];
                    continue;
                }
                // Unterminated `[` is a literal, as it is to fnmatch().
            }

            $tokens[] = [self::T_LITERAL, $char];
        }

        return $tokens;
    }

    /**
     * The anchored PCRE for $tokens, matched against a path relative to the
     * `.gitignore`'s own directory.
     *
     * @param list<array{0: int, 1: string}> $tokens
     */
    private static function regexFor(array $tokens): string
    {
        $out = '';
        foreach ($tokens as [$type, $payload]) {
            $out .= match ($type) {
                self::T_LITERAL => preg_quote($payload, '#'),
                self::T_ONE => '[^/]',
                self::T_CLASS => $payload,
                self::T_STAR => '[^/]*',
                self::T_GLOBSTAR => '.*',
                self::T_DIRS => '(?:[^/]+/)*',
            };
        }

        return '#^' . $out . '$#';
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
     *
     * Only a COMPILE failure (PREG_INTERNAL_ERROR) counts. The probe also
     * runs a match, and a pattern that exhausts the backtrack or JIT-stack
     * limit even against '' is valid but expensive — {@see matches()} decides
     * it via the automaton. Reading that as "malformed" dropped the rule, the
     * same fail-open the match path had.
     */
    private static function compiles(string $regex): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            return preg_match($regex, '') !== false || preg_last_error() !== PREG_INTERNAL_ERROR;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * $text with control bytes shown as `?` and, past $maxBytes, cut on a
     * UTF-8 boundary and marked with `…`.
     */
    private static function printable(string $text, int $maxBytes): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]/', '?', $text);
        if (strlen($text) <= $maxBytes) {
            return $text;
        }

        $cut = $maxBytes;
        while ($cut > 0 && (ord($text[$cut]) & 0xC0) === 0x80) {
            $cut--;
        }

        return substr($text, 0, $cut) . '…';
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
