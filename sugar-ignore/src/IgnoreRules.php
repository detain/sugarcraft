<?php

declare(strict_types=1);

namespace SugarCraft\Ignore;

/**
 * gitignore-style ignore matching over INJECTED file contents (no IO).
 *
 * Built from a map of ignore-file path => contents, so the caller decides how
 * files are read (and a TUI, a git server and a tree browser can all share one
 * engine). Each file is scoped to its own directory; deeper files override
 * shallower ones, and `.git/info/exclude` ranks below every other file, as in
 * git.
 *
 * Supported: `#` comments and `\#`, blank lines, trailing whitespace unless
 * escaped, `!` negation (later wins), trailing `/` (directory-only), leading or
 * inner `/` (anchored to the file's directory), bare patterns matching at any
 * depth, `*` `?` `[...]` `[!...]`, `**\/`, `/**`, `a/**\/b`, escapes, and git's
 * rule that nothing under an ignored directory can be re-included.
 *
 * Deliberately NOT supported: global excludes / git config, case folding and
 * the index (tracked-file precedence). Matching is case-SENSITIVE and pure.
 *
 * Matching cost is bounded and an undecidable rule fails CLOSED toward hiding:
 * see {@see Pattern} and {@see Automaton}. Such rules are recorded on
 * {@see undecidableRules()} instead of being logged — stderr under a TUI is
 * nowhere a user reads.
 *
 * Not `readonly` as a class: the verdict and automaton memos are caches, not
 * state. Every public field is `readonly` and every `with*()` returns a new
 * instance.
 *
 * Mirrors git's gitignore(5) semantics.
 */
final class IgnoreRules
{
    /** The one file git ranks below every `.gitignore`. */
    public const INFO_EXCLUDE = '.git/info/exclude';

    /**
     * Ignore-file contents by root-relative path, in the order supplied.
     *
     * @var array<string, string>
     */
    public readonly array $sources;

    /**
     * Rule files in ASCENDING precedence order.
     *
     * @var list<array{dir: string, file: string, patterns: list<Pattern>}>
     */
    private array $rulesets = [];

    /** @var array<string, array{0: ?bool, 1: ?string}> */
    private array $directoryVerdicts = [];

    /** @var array<string, array{0: string, 1: string}> */
    private array $undecidable = [];

    /** @var array<string, Automaton> */
    private array $automata = [];

    /**
     * @param array<string, string> $sources root-relative path => contents
     */
    private function __construct(array $sources, public readonly ?string $root)
    {
        $this->sources = $sources;

        $order = [];
        $i = 0;
        foreach ($sources as $file => $contents) {
            // info/exclude is scoped to the root yet sorts before its `.gitignore`.
            $isInfo = $file === self::INFO_EXCLUDE;
            $dir = $isInfo ? '' : self::dirOf($file);
            $rank = $isInfo ? -1 : ($dir === '' ? 0 : substr_count($dir, '/') + 1);
            $patterns = [];
            foreach (preg_split('/\R/', $contents) ?: [] as $line) {
                $pattern = Pattern::parse($line);
                if ($pattern !== null) {
                    $patterns[] = $pattern;
                }
            }
            $order[] = [$rank, $i++, ['dir' => $dir, 'file' => $file, 'patterns' => $patterns]];
        }
        usort($order, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $this->rulesets = array_map(static fn (array $row): array => $row[2], $order);
    }

    /** An empty ruleset: nothing is ignored. */
    public static function new(?string $root = null): self
    {
        return new self([], self::normalizeRoot($root));
    }

    /**
     * @param array<string, string> $files ignore-file path => contents. Paths are
     *        root-relative (`.gitignore`, `pkg/.gitignore`, `.git/info/exclude`)
     *        or absolute under $root, which is stripped.
     */
    public static function fromPaths(array $files, ?string $root = null): self
    {
        $root = self::normalizeRoot($root);
        $sources = [];
        foreach ($files as $path => $contents) {
            $relative = self::relativeTo((string) $path, $root);
            if ($relative !== null && $relative !== '') {
                $sources[$relative] = $contents;
            }
        }

        return new self($sources, $root);
    }

    /** A copy that also carries $contents for $path (replacing an existing entry). */
    public function withFile(string $path, string $contents): self
    {
        $relative = self::relativeTo($path, $this->root);
        if ($relative === null || $relative === '') {
            return $this;
        }

        return new self([...$this->sources, $relative => $contents], $this->root);
    }

    /**
     * The reason $relPath is ignored as `<ignore file>: <pattern>`, or null when
     * it is not.
     *
     * Every ANCESTOR is tested before the path itself, shallowest first, and
     * the first ignored ancestor wins outright — git's "it is not possible to
     * re-include a file if a parent directory of that file is excluded", and
     * what makes a nested ignore file inside an ignored directory never apply.
     * $isDir decides trailing-`/` patterns, which a path alone cannot answer.
     */
    public function ignores(string $relPath, bool $isDir): ?string
    {
        $relative = self::relativeTo($relPath, $this->root);
        if ($relative === null || $relative === '') {
            return null;
        }

        $segments = explode('/', $relative);
        $depth = count($segments);

        for ($i = 0; $i < $depth; $i++) {
            $prefix = implode('/', array_slice($segments, 0, $i + 1));
            if ($i < $depth - 1 || $isDir) {
                $verdict = array_key_exists($prefix, $this->directoryVerdicts)
                    ? $this->directoryVerdicts[$prefix]
                    : ($this->directoryVerdicts[$prefix] = $this->verdict($prefix, true));
            } else {
                $verdict = $this->verdict($prefix, false);
            }
            if ($verdict[0] === true) {
                return $verdict[1];
            }
        }

        return null;
    }

    /** Boolean form of {@see ignores()}. */
    public function isIgnored(string $relPath, bool $isDir): bool
    {
        return $this->ignores($relPath, $isDir) !== null;
    }

    /**
     * Rules too expensive to decide, resolved toward hiding, as
     * `[ignore file, pattern]` once each. Empty is the normal case; exposed so
     * a caller can say WHY a path it expected is missing.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function undecidableRules(): array
    {
        return array_values($this->undecidable);
    }

    /**
     * {@see undecidableRules()} as `<file>: <pattern>` strings.
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
     * Verdict of every ignore file governing $relative: [true ignored, false
     * explicitly re-included, null no opinion] plus the deciding rule text.
     *
     * @return array{0: ?bool, 1: ?string}
     */
    private function verdict(string $relative, bool $isDirectory): array
    {
        $verdict = null;
        $by = null;

        foreach ($this->rulesets as $set) {
            $dir = $set['dir'];
            if ($dir !== '' && !str_starts_with($relative, $dir . '/')) {
                continue;
            }
            $scoped = $dir === '' ? $relative : substr($relative, strlen($dir) + 1);

            foreach ($set['patterns'] as $rule) {
                if ($rule->dirOnly && !$isDirectory) {
                    continue;
                }
                $matched = $this->matches($rule, $scoped);
                if ($matched === null) {
                    $this->undecidable[$set['file'] . "\0" . $rule->source] ??= [$set['file'], $rule->source];
                    // Fail CLOSED: a hide rule is taken to apply, a negation is
                    // taken NOT to — either way the uncertain path stays hidden.
                    if (!$rule->negate) {
                        $verdict = true;
                        $by = $set['file'] . ': ' . $rule->source;
                    }
                    continue;
                }
                if ($matched) {
                    $verdict = !$rule->negate;
                    $by = $verdict ? $set['file'] . ': ' . $rule->source : null;
                }
            }
        }

        return [$verdict, $by];
    }

    /**
     * A PCRE error (backtrack or JIT-stack limit) is NOT an answer: it falls
     * through to the exact automaton, so an expensive rule never goes quiet.
     */
    private function matches(Pattern $rule, string $subject): ?bool
    {
        if ($rule->pcre) {
            $result = $rule->pcreMatch($subject);
            if ($result !== null) {
                return $result;
            }
        }

        // Keyed by regex so twenty copies of one hostile line share one automaton.
        return ($this->automata[$rule->regex] ??= Automaton::for($rule))->match($subject);
    }

    private static function dirOf(string $file): string
    {
        $pos = strrpos($file, '/');

        return $pos === false ? '' : substr($file, 0, $pos);
    }

    private static function normalizeRoot(?string $root): ?string
    {
        return $root === null ? null : rtrim($root, '/');
    }

    /**
     * $path relative to $root: stripped when absolute under it, null when
     * absolute and elsewhere (no jurisdiction, so "not ignored").
     */
    private static function relativeTo(string $path, ?string $root): ?string
    {
        if ($root !== null && $root !== '' && ($path === $root || str_starts_with($path, $root . '/'))) {
            $path = substr($path, strlen($root));
        } elseif ($root !== null && str_starts_with($path, '/')) {
            // Absolute with a root configured, but not under it.
            return $root === '' ? ltrim($path, '/') : null;
        }

        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        return trim($path, '/');
    }
}
