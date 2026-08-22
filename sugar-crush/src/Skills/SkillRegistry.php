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
     * The most compiled patterns {@see $compiledPathPatterns} will hold before
     * it is emptied and refilled.
     *
     * WHY THERE IS A CAP AT ALL. The cache is keyed by the raw pattern and
     * lives for the process, and the bound everyone reasoned from — "the
     * pattern set is the skills installed on the box" — is a property of
     * today's CALLERS, not of this class. {@see pathMatches()} is `public
     * static`; a future `/skill` verb taking a glob, or a user-supplied
     * `paths:` filter, would feed it distinct patterns per request and nothing
     * here would object. E99.
     *
     * WHY 1,024, AND NOT A NUMBER A REAL ROSTER COULD REACH. MEASURED on this
     * tree by {@see Skill::fromFile()} over `src/Skills/BuiltIn/*\/SKILL.md`:
     * the twelve shipped built-ins declare FOUR distinct `paths:` globs between
     * them (`composer.json`, `composer.lock`, `**\/*.php`, `**\/*Test.php`),
     * across five `paths:` entries, and no skill declares more than two. 1,024
     * is 256x that, so a roster would need ~200 skills each declaring five
     * DISTINCT globs before it noticed the cap exists. Every one of those
     * numbers is re-derived from the shipped frontmatter and from this constant
     * by {@see \SugarCraft\Crush\Tests\Skills\CompiledPatternCacheBoundTest},
     * and this paragraph reds if any of them stops being true — a figure in a
     * comment is not a measurement. What the cap costs when it is never reached
     * is one integer comparison on a cache MISS — a hit does not reach the
     * branch at all.
     *
     * WHAT IT BOUNDS. Feeding 20,000 fabricated distinct patterns
     * (`src/gen<i>/**\/*.php`, i = 0..19,999) through {@see pathMatches()} and
     * sampling `count()` on every insertion: uncapped the map reaches all
     * 20,000 entries, capped it peaks at exactly 1,024 and settles at 544
     * (20,000 mod 1,024). The ENTRY figures are exact, version-independent and
     * pinned by the test above.
     *
     * THE BYTE FIGURES ARE A GENERATOR'S, NOT THE CACHE'S, and the earlier
     * version of this paragraph presented them as the cache's.
     *
     *   WHAT IT SAID. "Uncapped … 3,661,552 bytes of PHP heap (183 B/entry);
     *   capped … 213,648 bytes (209 B/entry — the per-entry figure is larger at
     *   the smaller size because a PHP hashtable's fixed overhead is amortised
     *   over fewer slots)."
     *
     *   WHAT IS TRUE NOW. Neither byte figure reproduces, and the explanation
     *   attached to them is inverted. MEASURED on PHP 8.3.6 with the instrument
     *   named — a `memory_get_usage(false)` delta around building the array and
     *   nothing else, identical to the byte across three runs — 20,000 entries
     *   cost 3,549,976 B (177.5 B/entry) and 1,024 cost 154,904 B (151.3
     *   B/entry). So per-entry goes UP with n here, not down, and the reason is
     *   the generator rather than the hashtable: `src/gen19999/**\/*.php` is
     *   four bytes longer than `src/gen0/**\/*.php`, and the key and the
     *   compiled value both carry that. The hashtable alone (same keys and
     *   values, pre-built outside the measured window) is 40.1 B/entry at 1,024
     *   and 65.5 B/entry at 20,000.
     *
     *   WHY THE BOUND STILL EARNS ITS PLACE. The decision never rested on the
     *   byte totals — it rests on "20,000 entries or 1,024", which is exact and
     *   reproduces. What the byte figures were for is the reader asking whether
     *   1,024 is a sane ceiling in memory terms, and at ~150 KB it plainly is.
     *   They are not pinned by a test, deliberately: a byte count is an
     *   allocator's answer and would red on PHP 8.4 or another build for no
     *   defect. They carry their generator and their instrument instead, which
     *   is what makes them re-takeable — and is the whole of what was missing.
     *
     * WHAT THE CAP COSTS WHEN IT IS NEVER REACHED, and it is not zero. The
     * lookup went from `??=` to `?? null` plus an explicit null test, because
     * the count check has to sit on the miss branch only. MEASURED as an
     * interleaved A/B in one process — same generator as below, both arms
     * sharing one compile closure, arm order alternated per run, two takes of
     * three runs — the capped lookup is +1.0% to +4.9% (five of six takes
     * 4.2-4.9%), i.e. about +8 ns on a call that costs ~290 ns. Paid
     * deliberately: it buys a bound on a `public static` entry point whose
     * previous bound was a property of its callers.
     *
     * AND WHAT IT COSTS WHEN IT IS REACHED. Cycling 1,025 distinct patterns
     * five times over — every call a miss, every 1,024th a wipe — ran at
     * 3.67/2.73/2.73 us per call across three runs, against 2.48 us for
     * translating on every call with no cache at all. So the degenerate case
     * is "no cache", not "worse than no cache": correct, merely unaccelerated.
     */
    private const MAX_COMPILED_PATTERNS = 1024;

    /**
     * Compiled `paths:` globs, keyed by the raw pattern.
     *
     * Static because the translation is pure and the pattern set is bounded by
     * the skills installed on the box: {@see SkillPathNudge} calls
     * {@see pathMatches()} per pattern per path on tool calls, and a fresh
     * registry per session would otherwise recompile the same handful of
     * frontmatter globs from scratch.
     *
     * WHAT THIS USED TO SAY, AND ONLY THAT: the sentence above, ending at
     * "from scratch". WHAT IS TRUE NOW: the sentence is still right about the
     * shipped callers and was never a bound on the CLASS — see
     * {@see MAX_COMPILED_PATTERNS}, which makes it one. WHY IT STILL EARNS ITS
     * PLACE: it is the reason the cache exists, and the reason the cap can be
     * set high enough to be unreachable in practice rather than tuned.
     *
     * THE MEMOISATION IS NOT DECORATION — this was measured before the cap was
     * chosen, because "just drop the cache" is the other way to bound it.
     *
     * GENERATOR, stated completely because two of its parameters move the
     * answer by more than the run-to-run noise and the first version of this
     * paragraph named neither. 8 patterns x 40 paths x 200 trials = 64,000
     * pairs per arm, no randomness, three runs per configuration, PHP 8.3.6
     * (the only interpreter on the box these were taken on; no 8.4 claim is
     * made). The patterns are the five in {@see pathMatches()}'s perf note plus
     * the three shipped leading-`**` globs, which is `**\/*.php` twice and
     * `**\/*Test.php` — SIX distinct patterns in an eight-slot list, and the
     * duplicates matter because the memoised arm caches by pattern. The paths
     * are `src/` + 8 segments + a filename, and THE SEGMENT LENGTH IS THE FREE
     * PARAMETER: it sets the per-match cost, which is the ratio's denominator.
     *
     * MEASURED, three runs each, ratio = translate-every-call / memoised:
     *
     *   1-char segments  (53-byte paths) -> 8.64x / 8.65x / 8.65x
     *   12-char segments (117-byte paths) -> 7.54x / 7.54x / 7.58x
     *
     * Swap the two duplicate patterns for `composer.json` and `composer.lock`
     * — eight DISTINCT patterns, a more expensive translate arm — and the same
     * two configurations give 9.50x-9.56x and 8.29x-8.36x. So the honest figure
     * is "between roughly 7x and 10x on this box, depending on how long the
     * paths are and how many patterns are distinct", not a two-decimal band.
     *
     *   WHAT THIS USED TO SAY. "8.53x-8.68x, stable well inside the spread …
     *   1.46 us per translation against 0.17 us per match." WHAT IS TRUE NOW.
     *   The 8.53x-8.68x reproduces exactly — but only for the 1-char
     *   configuration, and "the spread" it was called stable inside was one
     *   unstated generator's. The two microsecond figures cannot both belong to
     *   that run: 0.1591s minus 0.0108s over 64,000 pairs is 2.32 us per
     *   translation, not 1.46, and 1.46-1.60 us is what `compilePathPattern()`
     *   costs for the CHEAPEST pattern in the set (`**\/*.php`) measured on its
     *   own. Re-taken here with one generator throughout: 2.16-2.19 us per
     *   translation, 0.13 us per match at 1-char segments and 0.17 us at
     *   12-char. WHY THE PARAGRAPH STILL EARNS ITS PLACE. Every configuration
     *   says the same thing by an order of magnitude, so the DECISION the
     *   figures were taken to support — keep the memo, bound it, do not delete
     *   it — was never in doubt. What was wrong was the precision, and a
     *   two-decimal ratio invites the next reader to treat a re-take that lands
     *   at 7.5x as a regression.
     *
     * Dropping the cache would make this matcher SLOWER than the
     * `str_replace` predicate it replaced, which is the whole reason the cap is
     * a cap and not a deletion.
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
     * the translation is meant to widen and never narrow.
     *
     * "NEVER NARROWS" IS A MEASUREMENT AND NOT A THEOREM, and getting that
     * backwards cost a round. WHAT THIS DOC-BLOCK SAID BEFORE: that never-
     * narrows was a THEOREM, on the grounds that {@see legacyPathMatch()}
     * still holds the three rewrites. WHAT IS TRUE NOW: that inference is
     * inverted. `legacyPathMatch()` is consulted ONLY when the translation
     * emits a regex PCRE will not compile. For every pattern that DOES
     * compile — which is very nearly all of them — the translation is the
     * whole answer and the old predicate is never asked, so keeping the
     * rewrites in the fallback buys correctness for uncompilable patterns and
     * buys exactly nothing for narrowing. WHY THE CLAIM STILL EARNS ITS PLACE:
     * because it is now measured on two independent samples rather than
     * deduced from one. (1) The 46 x 54 = 2,484-pair grid captured against the
     * old predicate at 8416d98e: 0 of its 329 matches lost, 49 gained — and
     * its alphabet was WIDENED this round by exactly the four paths and four
     * patterns the four families below need, so the grid can now see what it
     * was previously green over. (2) A
     * seeded differential fuzz — 200,000 trials per seed at each of
     * `mt_srand(1)`, `(20260822)`, `(987654321)` and `(42)`; pattern alphabet
     * `[a b / * ** *** ? . [ab] [!a] [a-c] [\d] []] [[:alpha:]] \* \] x - _ p
     * h]`, path alphabet `[a b / x . p h \n - _ * [ ] c 5 \]`, both 1-8
     * tokens, PHP 8.3.6 — which reports 0 narrowings on every seed and 14-17
     * widenings, every widening a leading globstar BY CAUSE and not merely by
     * prefix: strip the leading star run and the old predicate claims the
     * path. Each run is deterministic; the four seeds are there because ONE
     * seed is a sample too, and widening the alphabet to include
     * `[[:alpha:]]` is what exposed the fourth family below. The grid is
     * pinned in
     * {@see \SugarCraft\Crush\Tests\Skills\SkillPathPatternTest}; the fuzz is
     * a generator, restated here so the figure can be re-taken.
     *
     * THE FUZZ IS NOT DECORATION: it found FOUR narrowing families the grid
     * was green over, all four of them after the grid was written, which is
     * the whole argument for not trusting a grid alone, and the fourth arrived
     * only after the fuzz's own alphabet was widened — a generator has a
     * window too. Each is closed below and each is pinned by its own case in
     * that test:
     *
     *  - NEWLINE IN THE PATH. PCRE's `.` does not cross `\n` without /s;
     *    `fnmatch()`'s `*` and `?` do. MEASURED, PHP 8.3.6:
     *    `fnmatch('*.php', "a\nb.php")` is true. Closed by the /s on the
     *    compiled delimiter. NO path in the grid as it then stood contained a
     *    newline, so it was blind to this BY CONSTRUCTION rather than by
     *    omission; one has since been added.
     *  - `X/***`, three or more stars after a slash. Closed in the `/**`
     *    branch of {@see compilePathPattern()}. Note that `src/***` was
     *    ALREADY one of the grid's pattern rows: the pattern was characterised
     *    and its 50 paths simply held nothing that could expose the
     *    difference. A window defect, not a coverage gap — the distinction
     *    matters, because adding more patterns would never have found it and
     *    adding one path did.
     *  - PCRE ESCAPES INSIDE A CLASS BODY. `[\d]` means the literal `d` to
     *    `fnmatch()` and the digit class to PCRE. Closed by
     *    {@see compileClassBody()}.
     *  - A POSIX CLASS FOLLOWED BY A SECOND BRACKET GROUP. The scan that finds
     *    a class's closing `]` did not know that `[:alpha:]` carries a `]` of
     *    its own, so it stopped early and the emitted class ran past its own
     *    terminator. Harmless while the result failed to compile; NOT harmless
     *    when a later `[` supplies the missing `]`. MEASURED on PHP 8.3.6:
     *    `[[:alpha:]][!a]` emitted `#^[[:alpha:]\][^a]$#Ds`, which compiles,
     *    folds the second group into the first class, and answers false for
     *    `ab` where `fnmatch()` answers true — a wrong answer with no fallback
     *    under it. Closed in the bracket scan of {@see compilePathPattern()}
     *    and in {@see compileClassBody()}, which now pass POSIX classes
     *    through verbatim rather than routing them anywhere.
     *
     * FASTER, INCIDENTALLY, WHICH MATTERS BECAUSE THIS IS ON A TOOL-CALL PATH:
     * {@see SkillPathNudge} runs this per pattern per path, and a
     * {@see \SugarCraft\Crush\Tools\BuiltIn\Glob} hands over a whole match
     * list. GENERATOR, so the figure can be re-taken: 5 patterns
     * (`**\/*.php`, `src/**\/*.php`, `a/**\/b/**\/c/**\/d`, `docs/**\/*.md`,
     * `**\/node_modules/**`) x 40 paths of the form `src/` + 8 path segments
     * + a filename, 200 trials = 40,000 pairs, no randomness, PHP 8.3.6.
     * RE-TAKEN at the commit that closed the three narrowing families, three
     * runs: old 0.0269s / 0.0277s / 0.0274s, new 0.0087s / 0.0087s / 0.0087s —
     * 0.31x-0.33x, one cached `preg_match` where the old path ran up to four
     * `fnmatch()` calls plus three `str_replace()` rewrites. An earlier take on
     * this same box read 0.033s / 0.0095s = 0.29x; the RATIO is stable to
     * within the box's own drift and the absolute times are not, so quote the
     * ratio and re-take the rest. /s and the class-body walk cost nothing here
     * — the walk happens once per pattern, at compile.
     *
     * A deliberately pathological case (three globstars against a
     * 60-segment non-matching path) ran 2,000 times in 0.0004s: the leading
     * literal anchors it, so PCRE fails before it can backtrack. The
     * `preg_match() === false` branch catches a backtrack limit anyway.
     *
     * AND THE THREE REWRITES ARE STILL HERE, in {@see legacyPathMatch()} —
     * for the reason above this one and NOT for never-narrows: a pattern this
     * translation cannot compile is a SUPPORTED shape, not malformed input.
     *
     * WHAT THIS PARAGRAPH USED TO SAY: that the shape in question was the
     * POSIX character class `fnmatch()` inherits from libc, which the
     * translation could not express.
     * WHAT IS TRUE NOW: POSIX classes are translated, and correctly — PCRE
     * spells them the same way, so `[[:alpha:]]` is a passthrough. The old
     * claim was also broader than the fact even when it was written: a POSIX
     * class only reached the fallback when the malformed class it produced
     * happened NOT to compile, and when a later `[` completed it the pattern
     * got a wrong answer with no fallback at all. That is fixed above.
     * WHY THE FALLBACK STILL EARNS ITS PLACE: because a different supported
     * shape still cannot be compiled — a backslash-escaped `]` inside a class.
     * MEASURED on PHP 8.3.6: `fnmatch('a[\]]b', 'a]b')` is `true`, while the
     * bracket scan here is not escape-aware, stops on the escaped `]`, and
     * emits `#^a[\]\]b$#Ds`, whose class never terminates. Answering `false`
     * there would narrow the matcher, and answering with a bare `fnmatch()`
     * would throw away the zero-directory `**` case the rewrites bought — see
     * the discriminating pair in
     * {@see \SugarCraft\Crush\Tests\Skills\SkillPathPatternTest::testAnEscapedClassTerminatorIsLeftToTheFullOldPredicate()}.
     * Keeping the rewrites as that pattern's answer also means the one thing a
     * compile failure can never do is make the tool call throw.
     */
    public static function pathMatches(string $pattern, string $path): bool
    {
        $regex = self::$compiledPathPatterns[$pattern] ?? null;
        if ($regex === null) {
            // EMPTY AND REFILL, rather than evict one entry. A FIFO drop needs
            // insertion order kept and an `array_shift()` that is O(n) in the
            // cache; an LRU needs a touch on every HIT, which is the path that
            // has to stay cheap. Wiping is O(1) amortised and costs nothing on
            // any workload that stays under the cap — and a workload that does
            // NOT stay under it is, by definition, one this cache was never
            // going to help. The worst case is degrading to a translation per
            // call, which is measured on the property above and is correct,
            // merely slower.
            if (count(self::$compiledPathPatterns) >= self::MAX_COMPILED_PATTERNS) {
                self::$compiledPathPatterns = [];
            }
            $regex = self::$compiledPathPatterns[$pattern] = self::compilePathPattern($pattern);
        }

        $result = @preg_match($regex, $path);
        if ($result === false) {
            // The regex did not compile — a backslash-escaped `]` inside a
            // class, or a reversed range — or PCRE hit a backtrack limit on a
            // pathological pattern. Either way the question is still the skill
            // author's, so it is answered by the predicate this method
            // replaced. (This used to name a POSIX class as the first case.
            // Those compile now; see {@see compilePathPattern()}'s bracket
            // scan. Keeping the stale example here would send the next reader
            // looking for a branch that no longer exists.)
            return self::legacyPathMatch($pattern, $path);
        }

        return $result === 1;
    }

    /**
     * The pre-E85 predicate, kept as the answer for patterns
     * {@see compilePathPattern()} cannot compile.
     *
     * NOT DEAD, and not kept out of sentiment.
     *
     * WHAT THIS SAID: that {@see pathMatches()} reaches it for any `paths:`
     * glob carrying a POSIX character class.
     * WHAT IS TRUE NOW: it does not — POSIX classes are translated correctly
     * as of the round that found them being translated INCORRECTLY, and the
     * claim was never true in the form it was written: a POSIX class reached
     * here only when the malformed class it produced also failed to compile.
     * WHY THIS METHOD STILL EARNS ITS PLACE: a backslash-escaped `]` inside a
     * class — `a[\]]b`, which `fnmatch()` reads and this translation's
     * non-escape-aware bracket scan cannot — still emits an uncompilable
     * regex, and so does a reversed range. Pinned by
     * {@see \SugarCraft\Crush\Tests\Skills\SkillPathPatternTest::testAnEscapedClassTerminatorIsLeftToTheFullOldPredicate()},
     * which discriminates it from a bare `fnmatch()` fallback — the rewrites
     * are part of the answer, not decoration around it, so
     * `src/**\/x[\]]y.php` still claims `src/x]y.php`.
     *
     * Its `**` handling is the flawed one this class no longer uses on the
     * main path; see {@see pathMatches()}'s doc-block for both holes. It is
     * still strictly better here than `false`.
     *
     * IT IS NOT, HOWEVER, THE REASON THE TRANSLATION NEVER NARROWS, and an
     * earlier version of this comment said it was. A compiling pattern never
     * reaches this method, so it can neither rescue nor witness a narrowing —
     * that property is measured over a grid and a seeded fuzz, both restated
     * in {@see pathMatches()}'s doc-block. What this method covers is the
     * disjoint case: patterns that do not compile at all.
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

                // THREE OR MORE STARS AND THE SLASH GOES OPTIONAL TOO. Nobody
                // writes `src/***` on purpose, but the predicate this replaced
                // answered it, and answering it with less is a NARROWING. Its
                // rewrite 3 deleted the `/**` outright and left the extra star
                // behind, so `src/***` became `src*` and claimed `src_x`;
                // folding every star into one `(?:/.*)?` cannot match without
                // the slash. MEASURED on PHP 8.3.6: the old union for
                // `src/***` is exactly `^src.*$`, and for `src/***\/*.php`
                // exactly `src` + anything + `/` + anything + `.php` — both of
                // which the `.*` here reproduces, so this is the old answer and
                // not a fresh widening. TWO stars keep the separator
                // mandatory-or-absent, which is what a globstar means.
                $out .= ($j - ($i + 1)) > 2 ? '.*' : '(?:/.*)?';
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
                    // A POSIX CLASS CARRIES A `]` OF ITS OWN, and a scan that
                    // does not know that stops on it. `[[:alpha:]]` then
                    // yielded the body `[:alpha:` and the emitted class ran on
                    // past its own terminator. That was tolerable exactly while
                    // the result failed to COMPILE — PCRE refused
                    // `#^[[:alpha:]\]x$#Ds` and the pattern routed to
                    // {@see legacyPathMatch()}. It is not tolerable when a
                    // LATER `[` in the pattern supplies the missing `]`:
                    // MEASURED on PHP 8.3.6, `[[:alpha:]][!a]` emitted
                    // `#^[[:alpha:]\][^a]$#Ds`, which compiles, swallows the
                    // second group into the first class, and answers FALSE for
                    // `ab` where `fnmatch()` answers true. A silently wrong
                    // answer, with no fallback, from a supported shape.
                    if ($pattern[$close] === '[' && substr($pattern, $close + 1, 1) === ':') {
                        $classEnd = strpos($pattern, ':]', $close + 2);
                        if ($classEnd !== false) {
                            $close = $classEnd + 2;
                            continue;
                        }
                    }

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
                $out .= '[' . self::compileClassBody($body) . ']';
                $i = $close;
                continue;
            }

            $out .= preg_quote($ch, '#');
        }

        // /D so a TRAILING newline in a path cannot satisfy `$`; /s so an
        // EMBEDDED one cannot defeat a wildcard. The two are independent and
        // both are load-bearing: `fnmatch()`'s `*` and `?` match a newline like
        // any other byte, while PCRE's `.` refuses to without /s. MEASURED on
        // PHP 8.3.6: `fnmatch('*.php', "a\nb.php")` is TRUE and without /s
        // this translation answered false — a narrowing, on a path shape POSIX
        // genuinely permits. Neither modifier substitutes for the other, and
        // {@see \SugarCraft\Crush\Tests\Skills\SkillPathPatternTest} pins
        // one case for each.
        return '#^' . $out . '$#Ds';
    }

    /**
     * Translate the inside of one `[...]` from `fnmatch()`'s reading to PCRE's.
     *
     * EXACTLY TWO CHARACTERS ARE REINTERPRETED, and the restraint is the
     * point: `#` (this class's regex delimiter) and `\`. A POSIX class needs
     * NO handling here and an explicit passthrough for one was written and
     * then removed: PCRE spells `[:alpha:]` exactly as libc's `fnmatch()`
     * does, and none of `[`, `:` or an alphanumeric is a character this method
     * rewrites, so the loop below already copies it verbatim. The branch was
     * unkillable by mutation — deleting it reddened nothing — which is the
     * signature of code that is not doing anything. What POSIX classes DO need
     * is for {@see compilePathPattern()}'s terminator scan to know they carry
     * a `]`; that is where the fix lives. Everything else — `-`
     * ranges, a leading `^`, a first-position `]` — means the same thing to
     * both engines, and quoting it would break it. VERIFIED on PHP 8.3.6 that
     * the tidier-looking alternative is wrong: `preg_quote()` over the whole
     * body turns `[a-c]` into a three-literal class and `[!a]` into a class
     * that contains a literal `^`.
     *
     * THE BACKSLASH IS A NARROWING IF IT IS PASSED THROUGH, which is how it
     * got here. `fnmatch()` reads `\X` inside a class as the literal X and not
     * as a member SET — MEASURED on PHP 8.3.6: `fnmatch('[\d]x', 'dx')` is
     * TRUE, `fnmatch('[\d]x', '5x')` is FALSE, and `fnmatch('[\d]x', '\x')` is
     * FALSE, so the backslash is not itself a member either. Copied verbatim
     * into a PCRE class, `[\d]` becomes the digit escape: it stops claiming
     * `dx`, which the predicate this replaced claimed, and starts claiming
     * `5x`, which it did not. So an escaped alphanumeric is emitted bare and
     * everything else is re-escaped for PCRE.
     *
     * A LONE TRAILING BACKSLASH IS DELIBERATELY LEFT ALONE, and this is the
     * one place where doing less is the safe move rather than the lazy one.
     * The scan in {@see compilePathPattern()} that found this body's closing
     * `]` is not escape-aware, so a body ENDING in a backslash means the `]`
     * it stopped at was itself escaped — `a[\]]b` — and what arrived here is a
     * fragment of a class whose real end this translation cannot see. Emitted
     * unchanged it keeps the regex uncompilable, which routes the pattern to
     * {@see legacyPathMatch()}, which reads it correctly. Making it compile
     * here would make it compile WRONG, which is strictly worse than not
     * compiling. Teaching the scanner to skip escapes is the fix, and it is a
     * bigger one: it must also decide what to do with `[:alpha:]`, and it must
     * leave SOME input uncompilable or the fallback branch stops being pinned.
     */
    private static function compileClassBody(string $body): string
    {
        $len = strlen($body);
        $out = '';

        for ($i = 0; $i < $len; $i++) {
            $ch = $body[$i];

            if ($ch === '\\') {
                if ($i + 1 >= $len) {
                    return str_replace('#', '\\#', $body);
                }

                $literal = $body[++$i];
                $out .= ctype_alnum($literal) ? $literal : '\\' . $literal;
                continue;
            }

            $out .= $ch === '#' ? '\\#' : $ch;
        }

        return $out;
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
