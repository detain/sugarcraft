<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Skills;

final class SkillRegistry
{
    /** @var array<string, Skill> */
    private array $skills = [];

    /** @var array<string, true> disabled skills */
    private array $disabledSkills = [];

    /**
     * Compiled `paths:` globs, keyed by the raw pattern.
     *
     * Static because the translation is pure and the pattern set is bounded by
     * the skills installed on the box: {@see SkillPathNudge} calls
     * {@see pathMatches()} per pattern per path on tool calls, and a fresh
     * registry per session would otherwise recompile the same handful of
     * frontmatter globs from scratch.
     *
     * @var array<string, string>
     */
    private static array $compiledPathPatterns = [];

    /**
     * Register skills from an array.
     *
     * KEYED BY `$skill->name`, AND THE INCOMING KEY IS IGNORED — E67. Every
     * lookup on this class is by name: {@see get()}, {@see isAutoInvocable()},
     * {@see isUserInvocable()}, {@see isContextFork()} and {@see isDisabled()}
     * all index `$this->skills` (or `$this->disabledSkills`) with a skill NAME.
     * Storing under whatever key the caller happened to use made every one of
     * those a coin toss on the caller's array shape: a list-shaped
     * `register([$a, $b])` stored under `0` and `1`, after which
     * `isAutoInvocable($skill->name)` missed and EVERY skill in that batch was
     * silently non-auto-invocable — a skill that loads, lists, and never fires.
     *
     * VERIFIED, not assumed, that no shipped caller passed a list. There are
     * exactly two: {@see SkillManager::loadAll()}'s
     * `register($this->foreign->discoverClaude(...))` and its `discoverOpencode`
     * sibling. Both come from {@see ForeignSkillDiscovery::discover()}, which
     * builds `$skills[$name] = $this->tag($skill, $source)` over
     * {@see SkillLoader::loadFromDirectory()}, which itself builds
     * `$skills[$skill->name] = $skill` after `withName($skillName)` — and
     * `tag()` copies `name` through. So this is a latent trap the next caller
     * would spring, not a live defect, and the fix is to remove the trap rather
     * than to document the convention.
     *
     * The signature still declares `array<string, Skill>` because a name-keyed
     * array remains the shape callers should pass — the key is now redundant
     * rather than load-bearing, which is a weaker promise to make than "get it
     * right or your skills go dark".
     *
     * This does NOT make {@see all()}'s cast unreachable, and that cast stays.
     * PHP coerces a decimal-integer STRING to `int` on any array-key insert, so
     * a skill genuinely named `123` is stored under `int(123)` whether the key
     * came from the caller's array or from `$skill->name` — the coercion is a
     * property of the array, not of where the key was read.
     *
     * @param array<string, Skill> $skills
     */
    public function register(array $skills): void
    {
        foreach ($skills as $skill) {
            $this->skills[$skill->name] = $skill;
        }
    }

    /**
     * Get a skill by name.
     */
    public function get(string $name): ?Skill
    {
        if ($this->isDisabled($name)) {
            return null;
        }

        return $this->skills[$name] ?? null;
    }

    /**
     * Get all enabled skills.
     *
     * @return array<string, Skill>
     */
    public function all(): array
    {
        return array_filter(
            $this->skills,
            // Cast because PHP coerces a decimal-integer string array key to
            // `int` on insertion: a skill named `123` is stored under
            // `int(123)` and reaches this callback as an int, which
            // isDisabled(string) rejects with a TypeError — crashing every
            // caller of all() for the whole session.
            //
            // STILL NEEDED AFTER E67 made register() key by `$skill->name`.
            // The coercion is a property of the array, not of where the key
            // came from: `$this->skills[$skill->name]` with a name of `123`
            // stores under `int(123)` exactly as the caller's own key did.
            // The two defences do not disagree — register() decides WHICH name
            // a skill is filed under, this decides what type comes back out.
            fn($name) => !$this->isDisabled((string) $name),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Find skills matching a prompt.
     *
     * @return array<Skill>
     */
    public function findForPrompt(string $prompt): array
    {
        $matches = [];

        foreach ($this->all() as $skill) {
            // A skill with disable-model-invocation: true is only reachable via
            // explicit user invocation (getUserInvocable()) — it must never be
            // surfaced for auto-triggering off a free-text prompt, even if its
            // description keywords match. Route through isAutoInvocable() itself
            // (rather than re-inlining the disableModelInvocation check here) so
            // this stays in lockstep with any future change to what "auto-invocable"
            // means.
            if (!$this->isAutoInvocable($skill->name)) {
                continue;
            }

            if ($skill->matchesPrompt($prompt)) {
                $matches[] = $skill;
            }
        }

        // Sort by relevance (exact matches first)
        usort($matches, function (Skill $a, Skill $b) use ($prompt) {
            $aMatches = substr_count(strtolower($a->description), strtolower($prompt));
            $bMatches = substr_count(strtolower($b->description), strtolower($prompt));
            return $bMatches <=> $aMatches;
        });

        return $matches;
    }

    /**
     * Get user-invokable skills.
     *
     * @return array<Skill>
     */
    public function getUserInvocable(): array
    {
        // Route through isUserInvocable() (rather than re-inlining the
        // $skill->userInvocable check here) so this stays in lockstep with
        // any future change to what "user-invocable" means — mirrors the
        // same rationale findForPrompt() follows via isAutoInvocable().
        return array_values(array_filter(
            $this->all(),
            fn($skill) => $this->isUserInvocable($skill->name)
        ));
    }

    /**
     * Check if a skill is auto-invocable (not disabled for model invocation).
     */
    public function isAutoInvocable(string $skillName): bool
    {
        $skill = $this->skills[$skillName] ?? null;

        if ($skill === null) {
            return false;
        }

        return !$skill->disableModelInvocation;
    }

    /**
     * Check if a skill is user-invokable.
     */
    public function isUserInvocable(string $skillName): bool
    {
        $skill = $this->skills[$skillName] ?? null;

        if ($skill === null) {
            return false;
        }

        return $skill->userInvocable;
    }

    /**
     * Check if a skill runs in fork context (spawned sub-agent).
     */
    public function isContextFork(string $skillName): bool
    {
        $skill = $this->skills[$skillName] ?? null;

        if ($skill === null) {
            return false;
        }

        return $skill->context === 'fork';
    }

    /**
     * Register a skill from a manifest array (e.g., from SkillLoader::loadSkillManifest).
     *
     * paths must come through from the manifest -- it drives path-based
     * auto-scoping (getForPaths()) and, unlike content, is cheap frontmatter
     * data the Stage-1 manifest already carries; hardcoding it to [] here
     * would silently break every path-scoped skill loaded via the lazy
     * manifest path (crush_feat.md section 7 E3/E4).
     *
     * @param array{name:string,description:string,disableModelInvocation:bool,userInvocable:bool,context:string,paths:array<string>,sourcePath:string} $manifest
     */
    public function registerFromManifest(array $manifest): void
    {
        $skill = new Skill(
            name: $manifest['name'],
            description: $manifest['description'],
            userInvocable: $manifest['userInvocable'],
            disableModelInvocation: $manifest['disableModelInvocation'],
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: 'medium',
            context: $manifest['context'],
            paths: $manifest['paths'],
            content: '',
            sourcePath: $manifest['sourcePath'],
        );

        $this->skills[$manifest['name']] = $skill;
    }

    /**
     * Get skills that match file paths.
     *
     * @param array<string> $paths
     * @return array<Skill>
     */
    public function getForPaths(array $paths): array
    {
        $matches = [];

        foreach ($this->all() as $skill) {
            foreach ($skill->paths as $pattern) {
                $patternMatched = false;
                foreach ($paths as $path) {
                    if (self::pathMatches($pattern, $path)) {
                        $patternMatched = true;
                        break;
                    }
                }

                if ($patternMatched) {
                    $matches[] = $skill;
                    break;
                }
            }
        }

        return $matches;
    }

    /**
     * Does one `paths:` frontmatter glob claim one file path?
     *
     * WHAT THE CODE HERE USED TO SAY: `fnmatch($pattern, $path)` first, and
     * then — only when the pattern contained `**` — three textual rewrites
     * whose comments read "Convert /**\/ to /*\/ (matches one directory
     * level)", "Convert /** at end to /* (matches one directory or zero)" and
     * "Also try without the ** entirely (matches zero directories)", ORed
     * together.
     *
     * WHAT IS TRUE NOW: all three rewrites were keyed on a SLASH BEFORE the
     * `**`, so a pattern that STARTS with one — `**\/*.php`, the first form
     * most people write — matched none of them and fell through to the bare
     * `fnmatch()`, which on PHP 8.3.6 with no flags reads `**\/` as "some
     * characters, then a literal slash". MEASURED on this box, PHP 8.3.6:
     * `fnmatch('**\/*.php', 'a.php')` is `false`, so a skill scoped to every
     * PHP file in the tree did not claim the PHP files at the tree's root, and
     * the same hole silenced `**\/node_modules/**` for `node_modules/x/y.js`.
     * That is E85.
     *
     * AND A SECOND HOLE THE BRIEF DID NOT NAME, found by the characterisation
     * table rather than by reading the rewrites: `str_replace()` replaces
     * NON-OVERLAPPING occurrences, and the `/**\/` needle ends in the slash
     * the next one would have started with. So on `a/**\/**\/b` only the
     * first is rewritten — the scan resumes past the shared slash — and the
     * result `a/*\/**\/b` demands three separators where the pattern's author
     * asked for one or more. VERIFIED on PHP 8.3.6: none of the four old
     * branches claimed `a/x/b`. A regex translation replaces the rewrites and
     * closes both.
     *
     * WHY THE OLD REASONING STILL EARNS ITS PLACE: the three rewrites were
     * describing real semantics that `fnmatch` has no way to express — `**`
     * spanning zero directories is exactly what a bare `fnmatch` cannot do,
     * because its wildcards cannot match "nothing, INCLUDING the separator
     * beside it". Every case the rewrites bought is bought again below, on
     * purpose: `/**` compiles to `(?:/.*)?`, which is the union of "one or
     * more directories" (their rewrite 2) and "zero directories" (their
     * rewrite 3), and a `/**\/` in the middle is the same union followed by
     * the next literal segment (their rewrite 1).
     *
     * SEMANTICS DELIBERATELY PRESERVED, not tidied: a single `*` here matches
     * ACROSS `/`, and `?` matches `/` too. That is not the POSIX-shell reading
     * — it is what `fnmatch()` does when the caller passes no `FNM_PATHNAME`,
     * which this call site never did. VERIFIED on PHP 8.3.6:
     * `fnmatch('*.php', 'src/a.php')` is `true` without flags and `false` with
     * `FNM_PATHNAME`. Narrowing `*` to a single segment would be the more
     * conventional glob, and it would silently stop matching paths that match
     * today — every `src/*` skill would quietly lose its subdirectories. So
     * the translation widens and never narrows: CHARACTERISED before the
     * change over 42 patterns x 50 paths = 2,100 pairs evaluated against the
     * old predicate, this one loses none of the 290 that matched and gains 41,
     * every one of them attributable to one of the two holes above. The table
     * is pinned in
     * {@see \SugarCraft\Crush\Tests\Skills\SkillPathPatternTest}.
     *
     * AND THE THREE REWRITES ARE STILL HERE, in {@see legacyPathMatch()},
     * because "never narrows" has to be a THEOREM and not a sample. The
     * translation reproduces `fnmatch()`'s `*`, `?`, `\\` and `[...]` — but
     * not the POSIX class syntax `fnmatch()` inherits from libc. MEASURED on
     * PHP 8.3.6: `fnmatch('[[:alpha:]]x', 'ax')` is `true`, while this
     * translation emits `#^[[:alpha:]\\]x$#D`, whose class is never
     * terminated, so PCRE refuses to compile it. So the compile failure is not
     * a malformed-frontmatter edge — it is a SUPPORTED pattern shape landing
     * on a translation that does not cover it, and answering `false` there
     * would narrow the matcher for every skill that uses one. The rewrites are
     * kept as that pattern's answer rather than deleted, which also means the
     * one thing a compile failure can never do is make the tool call throw.
     */
    public static function pathMatches(string $pattern, string $path): bool
    {
        $regex = self::$compiledPathPatterns[$pattern] ??= self::compilePathPattern($pattern);

        $result = @preg_match($regex, $path);
        if ($result === false) {
            // The class did not compile (a POSIX class, a reversed range), or
            // PCRE hit a backtrack limit on a pathological pattern. Either way
            // the question is still the skill author's, so it is answered by
            // the predicate this method replaced.
            return self::legacyPathMatch($pattern, $path);
        }

        return $result === 1;
    }

    /**
     * The pre-E85 predicate, kept as the answer for patterns
     * {@see compilePathPattern()} cannot compile.
     *
     * NOT DEAD, and not kept out of sentiment: {@see pathMatches()} reaches it
     * for any `paths:` glob carrying a POSIX character class, which
     * `fnmatch()` supports and the translation does not. Pinned by
     * {@see \SugarCraft\Crush\Tests\Skills\SkillPathPatternTest::testAPosixClassPatternFallsBackToTheFullOldPredicate()},
     * which discriminates it from a bare `fnmatch()` fallback — the rewrites
     * are part of the answer, not decoration around it, so
     * `src/**\/[[:alpha:]]*.php` still claims `src/abc.php`.
     *
     * Its `**` handling is the flawed one this class no longer uses on the
     * main path; see {@see pathMatches()}'s doc-block for both holes. It is
     * still strictly better here than `false`.
     */
    private static function legacyPathMatch(string $pattern, string $path): bool
    {
        if (fnmatch($pattern, $path)) {
            return true;
        }

        if (!str_contains($pattern, '**')) {
            return false;
        }

        return fnmatch(str_replace('/**/', '/*/', $pattern), $path)
            || fnmatch(str_replace('/**', '/*', $pattern), $path)
            || fnmatch(str_replace('/**', '', $pattern), $path);
    }

    /**
     * Translate one frontmatter glob into an anchored PCRE.
     *
     * Cached by {@see pathMatches()} because {@see SkillPathNudge} runs this
     * per pattern per path on tool calls: the compile is a character walk, the
     * match is not.
     */
    private static function compilePathPattern(string $pattern): string
    {
        $out = '';
        $len = strlen($pattern);

        for ($i = 0; $i < $len; $i++) {
            $ch = $pattern[$i];

            // fnmatch() honours backslash escapes unless FNM_NOESCAPE is
            // passed, and this call site never passed it. VERIFIED on PHP
            // 8.3.6: fnmatch('a\*b', 'a*b') is true and fnmatch('a\*b', 'aXb')
            // is false.
            if ($ch === '\\' && $i + 1 < $len) {
                $out .= preg_quote($pattern[++$i], '#');
                continue;
            }

            // `/**` — the whole point of this translation. Zero-or-more
            // `/segment` groups, so `a/**/b` claims `a/b` as well as
            // `a/x/y/b`, and `a/**` claims `a` itself.
            if ($ch === '/' && substr($pattern, $i + 1, 2) === '**') {
                $j = $i + 3;
                while ($j < $len && $pattern[$j] === '*') {
                    ++$j;
                }
                $out .= '(?:/.*)?';
                $i = $j - 1;
                continue;
            }

            if ($ch === '*' && substr($pattern, $i + 1, 1) === '*') {
                $j = $i + 1;
                while ($j < $len && $pattern[$j] === '*') {
                    ++$j;
                }

                // A LEADING `**/` — the case none of the three rewrites could
                // see, because each of them needed a slash in front of the
                // stars and at position 0 there is none. Zero-or-more leading
                // directories, so `**/*.php` finally claims `a.php`.
                if ($i === 0 && $j < $len && $pattern[$j] === '/') {
                    $out .= '(?:.*/)?';
                    $i = $j;
                    continue;
                }

                $out .= '.*';
                $i = $j - 1;
                continue;
            }

            if ($ch === '*') {
                $out .= '.*';
                continue;
            }

            if ($ch === '?') {
                $out .= '.';
                continue;
            }

            if ($ch === '[') {
                $close = $i + 1;
                if ($close < $len && ($pattern[$close] === '!' || $pattern[$close] === '^')) {
                    ++$close;
                }
                // A `]` in first position is a literal member, not the
                // terminator — the POSIX rule, and PHP's: fnmatch('[]]x', ']x')
                // is true on 8.3.6.
                if ($close < $len && $pattern[$close] === ']') {
                    ++$close;
                }
                while ($close < $len && $pattern[$close] !== ']') {
                    ++$close;
                }

                if ($close >= $len) {
                    // Unterminated: fnmatch treats the bracket as a literal
                    // (fnmatch('a[b', 'a[b') is true on 8.3.6), so this does
                    // too, and the rest of the pattern keeps translating.
                    $out .= '\\[';
                    continue;
                }

                $body = substr($pattern, $i + 1, $close - $i - 1);
                if ($body !== '' && $body[0] === '!') {
                    $body = '^' . substr($body, 1);
                }
                $out .= '[' . str_replace('#', '\\#', $body) . ']';
                $i = $close;
                continue;
            }

            $out .= preg_quote($ch, '#');
        }

        // /D so a trailing newline in a path cannot satisfy `$`.
        return '#^' . $out . '$#D';
    }

    /**
     * Disable a skill.
     */
    public function disable(string $name): void
    {
        $this->disabledSkills[$name] = true;
    }

    /**
     * Enable a disabled skill.
     */
    public function enable(string $name): void
    {
        unset($this->disabledSkills[$name]);
    }

    /**
     * Check if a skill is disabled.
     */
    public function isDisabled(string $name): bool
    {
        return isset($this->disabledSkills[$name]);
    }

    /**
     * Disable multiple skills.
     *
     * @param array<string> $names
     */
    public function disableMultiple(array $names): void
    {
        foreach ($names as $name) {
            $this->disable($name);
        }
    }

    /**
     * Get skill names.
     *
     * @return array<string>
     */
    public function names(): array
    {
        return array_keys($this->skills);
    }
}
