<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Triggers;

use InvalidArgumentException;

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
 * ({@see \SugarCraft\Crush\Skills\SkillRegistry::pathMatches()}).
 *
 * GLOB DIALECT — one deliberate flavour, documented to the character:
 * - `**` spans anything including `/`. A double star followed by a separator
 *   matches zero or more whole leading segments, so the pattern
 *   "src/double-star-separator/test.php" matches "src/test.php" as well as
 *   "src/a/b/test.php".
 * - `*`   matches within ONE segment only — it never crosses `/`, so
 *   `src/*.php` does not match `src/deep/x.php`.
 * - `?`   matches exactly one non-`/` character.
 * - every other character is literal, `preg_quote()`d (`[abc]` classes and
 *   `{a,b}` braces are NOT wildcards here — they match themselves).
 * - Matching is case-SENSITIVE (POSIX path semantics) and BYTE-wise: the
 *   compiled patterns carry no `u` modifier on purpose, because path strings
 *   may contain bytes that are not valid UTF-8 and a `u`-compiled pattern
 *   would fail such a subject wholesale instead of matching it literally.
 * - Patterns are anchored at both ends (`\A…\z`): a glob matches the whole
 *   path, never a substring of it.
 * - A trailing `/**` requires the separator, i.e. `src/**` matches paths
 *   UNDER `src/` but not the bare directory string `src`.
 *
 * UNVERIFIED for stage B to reconcile against `SkillRegistry` (out of this
 * stage's reading scope): whether `pathMatches()`'s cached glob→PCRE
 * translation and `legacyPathMatch()`'s fnmatch fixups use the same
 * segment-scoped `*` as this dialect. The semantics above are self-consistent
 * and pinned here; if the loader later needs the other flavour, that is a
 * P6.S2 reconciliation, not a silent divergence.
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
     */
    public function matches(string $path): bool
    {
        foreach ($this->globs as $glob) {
            if (preg_match($this->pattern($glob), $path) === 1) {
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
            if (preg_match($this->pattern($glob), $path) === 1) {
                $hits[] = $glob;
            }
        }

        return $hits;
    }

    /**
     * Translate one glob to an anchored PCRE per the class dialect, memoised.
     */
    private function pattern(string $glob): string
    {
        if (isset($this->compiled[$glob])) {
            return $this->compiled[$glob];
        }

        $body = '';
        $length = strlen($glob);

        for ($i = 0; $i < $length; $i++) {
            $char = $glob[$i];

            if ($char === '*') {
                if ($i + 1 < $length && $glob[$i + 1] === '*') {
                    $i++;
                    if ($i + 1 < $length && $glob[$i + 1] === '/') {
                        $i++;
                        $body = $body . '(?:[^/]++/)*+';
                    } else {
                        $body .= '.*';
                    }
                } else {
                    $body = $body . '[^/]*';
                }
                continue;
            }

            if ($char === '?') {
                $body .= '[^/]';
                continue;
            }

            $body .= preg_quote($char, '#');
        }

        return $this->compiled[$glob] = '#\A' . $body . '\z#';
    }
}
