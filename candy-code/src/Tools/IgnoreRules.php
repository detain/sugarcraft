<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools;

use SugarCraft\Ignore\IgnoreRules as LibIgnoreRules;

/**
 * The one place that decides whether a path is worth showing the model.
 *
 * Three separate rules live here, deliberately in ONE class rather than
 * reimplemented per tool - the same seam {@see Concerns\BuildsUnifiedDiff} was
 * extracted for. `Glob`, `Grep` and `Ls` answer the same question ("which
 * paths count?") and must not answer it differently:
 *
 * 1. A **default-exclude list** of machine-generated directory names, applied
 *    whether or not the project has a `.gitignore` at all.
 * 2. **`.gitignore` matching**, including nested files applying to their own
 *    subtree and `.git/info/exclude` ranked below every `.gitignore`.
 * 3. A **symlinked-directory hard stop** - see {@see halts()}.
 *
 * S-T06 (G-10=A): rule 2 is no longer implemented here. The gitignore(5)
 * engine - syntax, nested scoping, git's "no re-include under an ignored
 * directory" rule, the bounded PCRE-or-automaton matcher and the fail-CLOSED
 * handling of undecidable (hostile, too-costly) rules - lives in sugar-ignore
 * ({@see \SugarCraft\Ignore\IgnoreRules}), which matches over INJECTED file
 * contents and never touches the filesystem; its suite owns those pins. This
 * class is the adapter and keeps what is candy-code's: the default-exclude
 * list, the symlink stop, the `grep --exclude-dir` push-down, the model-facing
 * {@see undecidableNote()} wording, and the disk reads.
 *
 * ## What it deliberately does NOT support
 *
 * - `core.excludesFile` (the user's global ignore file) and any other git
 *   config: reading a user's `~/.config/git/ignore` would make an agent's
 *   answers depend on machine-local state the repo cannot see, and the failure
 *   mode is a silently-missing file.
 * - `core.ignoreCase` - matching is always case-SENSITIVE.
 * - `.gitattributes`, sparse-checkout, and the index. Nothing here consults
 *   git itself, so it works in a directory that was never a repository.
 * - Tracked-file precedence. A committed file matching an ignore pattern is
 *   treated as ignored; the `include_ignored` argument on both tools is the
 *   escape hatch.
 *
 * ## Lazy reads
 *
 * The library engine takes its ignore files up front, but a walk must not read
 * every directory's `.gitignore` before it knows it will descend there. So the
 * adapter feeds the engine on demand: `.git/info/exclude` and the root
 * `.gitignore` on first use, and a directory's `.gitignore` only when a path
 * BELOW a non-ignored directory is asked about. A `.gitignore` inside an
 * ignored directory is therefore never read, exactly as before. Rules the
 * engine could not decide within its budget are accumulated across engine
 * rebuilds and resolved toward hiding: {@see undecidablePatterns()} lists them
 * as root-relative `<ignore file>: <pattern>` strings and {@see undecidableNote()}
 * tells the model which rule may have hidden what it expected.
 *
 * NOT `readonly` as a class: the engine, the loaded-directory set and the
 * verdict memo are caches for the duration of one walk, not state - every
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

    /** How many undecidable rules {@see undecidableNote()} names before it only counts. */
    public const UNDECIDABLE_NOTE_MAX_RULES = 3;

    /** How much of one undecidable pattern {@see undecidableNote()} quotes. */
    public const UNDECIDABLE_NOTE_MAX_PATTERN_BYTES = 80;

    /**
     * The library engine over every ignore file read so far. Rebuilt (it is
     * immutable) whenever a newly discovered file joins it.
     */
    private ?LibIgnoreRules $engine = null;

    /**
     * Root-relative directory prefixes whose ignore file has been consulted
     * (read or found absent), `''` being the root.
     *
     * @var array<string, true>
     */
    private array $loadedDirs = [];

    /**
     * Verdict per root-relative DIRECTORY prefix. Valid across engine rebuilds:
     * a prefix is only judged after every ignore file above it is loaded, and a
     * later rebuild only adds files in directories BELOW it.
     *
     * @var array<string, bool>
     */
    private array $directoryIgnored = [];

    /**
     * Rules the engine could not decide, accumulated across rebuilds (a rebuilt
     * engine forgets what its predecessor recorded), keyed so each is kept once.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private array $undecidable = [];

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
     * SIBLING libraries — `candy-code/vendor/sugarcraft/sugar-core` →
     * `../../../sugar-core`. Following those from the monorepo root means
     * `sugar-core` is walked once per lib that depends on it, and
     * `sugar-core/vendor/sugarcraft/sugar-ansi-parser/vendor/…` is a cycle with no
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
     * cannot answer - and the caller usually knows already (a directory walk
     * has just stat'ed it), so asking beats a redundant `is_dir()`.
     *
     * Every ANCESTOR is tested before the path itself, shallowest first, and
     * the first ignored ancestor wins outright (git's re-inclusion rule). The
     * ignore file of a directory is read only once the directory itself has
     * survived that test, so a nested `.gitignore` inside an ignored directory
     * correctly never loads.
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

        $this->engine ??= $this->engineWith($this->readIgnoreFile(LibIgnoreRules::INFO_EXCLUDE));
        $this->loadDirectory('');

        $segments = explode('/', $relative);
        $depth = count($segments);

        for ($i = 0; $i < $depth; $i++) {
            $prefix = implode('/', array_slice($segments, 0, $i + 1));
            // Everything above the last segment is by construction a directory.
            if ($i < $depth - 1 || $isDirectory) {
                if (!isset($this->directoryIgnored[$prefix])) {
                    $this->directoryIgnored[$prefix] = $this->decide($prefix, true);
                }
                $ignored = $this->directoryIgnored[$prefix];
            } else {
                $ignored = $this->decide($prefix, false);
            }
            if ($ignored) {
                return true;
            }
            if ($i < $depth - 1 || $isDirectory) {
                // Survived: its own ignore file now governs what is below it.
                $this->loadDirectory($prefix);
            }
        }

        return false;
    }

    /**
     * Ignore rules that were too expensive to decide and were therefore
     * resolved toward hiding, as root-relative `<ignore file>: <pattern>`,
     * once each. An empty list is the normal case.
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
            $named[] = self::printable($file, PHP_INT_MAX)
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
     * The engine's verdict for ONE root-relative path (its own ancestors are
     * judged by the caller's loop, which is what keeps the reads lazy).
     */
    private function decide(string $relative, bool $isDirectory): bool
    {
        $engine = $this->engine;
        \assert($engine !== null);
        $reason = $engine->ignores($relative, $isDirectory);
        foreach ($engine->undecidableRules() as $rule) {
            $this->undecidable[$rule[0] . "\0" . $rule[1]] = $rule;
        }

        return $reason !== null;
    }

    /** Consult $dir's own ignore file once; a missing or empty file adds nothing. */
    private function loadDirectory(string $dir): void
    {
        if (isset($this->loadedDirs[$dir])) {
            return;
        }
        $this->loadedDirs[$dir] = true;

        $file = ($dir === '' ? '' : $dir . '/') . '.gitignore';
        $contents = $this->readIgnoreFile($file);
        if ($contents !== null) {
            $this->engine = $this->engineWith([$file => $contents]);
        }
    }

    /**
     * The engine carrying everything read so far plus $more.
     *
     * @param array<string, string>|string|null $more
     */
    private function engineWith(array|string|null $more): LibIgnoreRules
    {
        $engine = $this->engine ?? LibIgnoreRules::new();
        if (is_string($more) || $more === null) {
            // The `.git/info/exclude` seed: only a real file joins.
            return $more === null ? $engine : $engine->withFile(LibIgnoreRules::INFO_EXCLUDE, $more);
        }
        foreach ($more as $file => $contents) {
            $engine = $engine->withFile($file, $contents);
        }

        return $engine;
    }

    /**
     * The contents of a root-relative ignore file, or null when it is absent,
     * unreadable or empty. @ rather than is_readable(): a directory the walk
     * cannot stat is a "no rules here", not a warning painted under the TUI frame.
     */
    private function readIgnoreFile(string $relative): ?string
    {
        $path = $this->root . '/' . $relative;
        $contents = is_file($path) ? @file_get_contents($path) : false;

        return $contents === false || $contents === '' ? null : $contents;
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
