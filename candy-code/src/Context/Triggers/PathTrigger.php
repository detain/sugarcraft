<?php

declare(strict_types=1);

namespace SugarCraft\Code\Context\Triggers;

use InvalidArgumentException;
use SugarCraft\Code\Util\PathGlob;

/**
 * Fires when a file path handed to it matches one of its glob patterns.
 *
 * DESIGN SOURCE: prompt_expand.md §7.5 and §9.7 (a path trigger fires when a
 * matching file enters context). This is a SugarCraft architecture type, not
 * a port — charmbracelet/crush has no PathTrigger symbol, so the repo's
 * "Mirrors charmbracelet/…" convention does not apply.
 *
 * A MATCHER, NOT A GATEKEEPER — the decision this step pins. The trigger
 * answers only "does this string match this pattern"; it knows nothing about
 * any repository root and touches no filesystem (pure string in, bool out —
 * it must stay invisible to the read-sink census over `src/`). An
 * `/etc/passwd`-style absolute path therefore MATCHES `**` like any other
 * string: matching is not authorisation, and the containment gate belongs to
 * the P6.S2 loader that feeds these values, mirroring how the existing
 * matchers in this repo separate matching from gating
 * ({@see \SugarCraft\Code\Skills\SkillRegistry::pathMatches()}).
 *
 * THE GLOB DIALECT is the one {@see \SugarCraft\Code\Skills\SkillRegistry} has
 * always spoken for `SKILL.md` frontmatter `paths:`, and it is not this class's
 * to define: the compiler lives in {@see PathGlob::compile()}, whose class
 * doc-block states that dialect to the character together with the four
 * measured reasons it was chosen over the stricter shell reading. Reconciling
 * the two is P6.S5a, and it is resolved HERE rather than deferred — see
 * {@see \SugarCraft\Code\Tests\Context\GlobDialectDifferentialTest} for the
 * 33-row differential both sides are pinned against.
 *
 * BEFORE THIS STEP this class compiled its own dialect: a segment-scoped `*`,
 * a `?` that refused `/`, `[…]` that matched only itself, a trailing `/**` that
 * demanded the separator, and no backslash escapes at all. Every one of those
 * five answers differed from the skill channel, and the difference is now gone.
 *
 * WHAT THIS MOVES, stated exactly because "wider" is not the whole truth: over
 * the 33-row differential, 20 rows answer as before, 10 flip from no-match to
 * match, and 3 flip from match to no-match. Every one of the 10 is a wildcard
 * that used to stop at a separator and no longer does - `src/*.php` now fires
 * for `src/deep/x.php`, `src/**` now fires for the bare directory string `src`.
 * All 3 narrowings are a character the strict dialect held as a literal and this
 * one reads as syntax: `[a-z].php` against the same string as itself (row #11),
 * `a\*b` against `a\b` (row #22), and `src\*.php` against `src\a.php` (row #23).
 * Unifying the other way would have narrowed the live skill matcher instead,
 * which repo law forbids; both directions move, and this one moves the half of
 * the tree that answers nothing today.
 *
 * TWO CONSEQUENCES A CALLER MUST KNOW:
 * - The YES-set here is WIDER than it was, deliberately. `src/*.php` now fires
 *   for `src/deep/x.php`, and `src/**` now fires for the bare directory string
 *   `src`. Unifying the other way would have narrowed a live matcher that
 *   announces skills on those very patterns, which repo law forbids.
 * - A glob whose compiled regex PCRE refuses to execute — a backslash-escaped
 *   `]` inside a class, a reversed range — answers NO here, where
 *   {@see \SugarCraft\Code\Skills\SkillRegistry::pathMatches()} answers it from
 *   its `legacyPathMatch()` fallback. That is a POLICY difference and not a
 *   dialect one: this trigger owns no older predicate to fall back to, and a
 *   rule that fired on an answer it never computed is worse than one that stays
 *   silent. Both callers read the same third value out of
 *   {@see PathGlob::matchCompiled()} and decide for themselves.
 *
 * Case-SENSITIVE, BYTE-wise (no `u` modifier, so a path holding bytes that are
 * not valid UTF-8 matches literally instead of failing the subject wholesale),
 * and anchored at both ends — all three inherited from the shared compiler
 * rather than decided here.
 */
final class PathTrigger implements Trigger
{
    /** Memoised compiled patterns, keyed by the original glob. */
    private array $compiled = [];

    /**
     * @param list<string> $globs Glob patterns; non-empty, none blank.
     */
    private function __construct(public readonly array $globs)
    {
    }

    /**
     * @param list<string> $globs Glob patterns following the class-documented dialect.
     *
     * @throws InvalidArgumentException if the list is empty or holds a non-string/blank entry.
     */
    public static function new(array $globs): self
    {
        if ($globs === []) {
            throw new InvalidArgumentException('PathTrigger requires at least one glob; an empty trigger can never fire.');
        }

        $clean = [];
        foreach (array_values($globs) as $i => $glob) {
            if (!is_string($glob)) {
                throw new InvalidArgumentException(sprintf('PathTrigger glob %d must be a string, %s given.', $i, get_debug_type($glob)));
            }
            if (trim($glob) === '') {
                throw new InvalidArgumentException(sprintf('PathTrigger glob %d must not be blank.', $i));
            }
            $clean[] = $glob;
        }

        return new self($clean);
    }

    /**
     * A new trigger value over these globs.
     *
     * @param list<string> $globs
     */
    public function withGlobs(array $globs): self
    {
        return $this->mutate($globs);
    }

    /**
     * @param list<string> $globs
     */
    private function mutate(array $globs): self
    {
        return self::new($globs);
    }

    /**
     * Does the given path string match at least one glob? Pure string test.
     *
     * A glob whose regex PCRE cannot execute answers NO: see the policy
     * paragraph in the class doc-block.
     */
    public function matches(string $path): bool
    {
        foreach ($this->globs as $glob) {
            if (PathGlob::matchCompiled($this->pattern($glob), $path) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * The patterns that hit, in declaration order — the loader uses them to
     * attribute which glob admitted the path. Pure string test.
     *
     * @return list<string>
     */
    public function matchingGlobs(string $path): array
    {
        $hits = [];
        foreach ($this->globs as $glob) {
            if (PathGlob::matchCompiled($this->pattern($glob), $path) === true) {
                $hits[] = $glob;
            }
        }

        return $hits;
    }

    /**
     * The anchored PCRE for one glob, memoised per instance.
     *
     * The translation is {@see PathGlob::compile()} and nothing else — this
     * class owns no dialect of its own. The memo is per-instance rather than
     * shared because a trigger is a long-lived value holding a fixed glob set,
     * so the only cost avoided is re-walking the same characters on every
     * rendered turn, and no cross-instance cache key would ever be evicted.
     */
    private function pattern(string $glob): string
    {
        return $this->compiled[$glob] ??= PathGlob::compile($glob);
    }
}
