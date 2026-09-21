<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Util;

/**
 * THE ONE path-glob dialect: a glob string and a path string go in, a verdict
 * comes out. No filesystem, no repository root, no state — pure string in,
 * string/bool out, so it stays invisible to the read-sink census over `src/`
 * exactly as {@see \SugarCraft\Crush\Context\Triggers\PathTrigger} documents
 * for its own answer.
 *
 * This is the single compiler P6.S5a asked for. Before it, three places in
 * `sugar-crush` answered "does this path match this pattern" in three
 * mutually incompatible ways — `PathTrigger::pattern()` (segment-scoped `*`),
 * {@see \SugarCraft\Crush\Skills\SkillRegistry::compilePathPattern()} (the
 * dialect below), and {@see \SugarCraft\Crush\Skills\SkillRegistry::legacyPathMatch()}
 * (`fnmatch()` plus three string rewrites). Both PRODUCTION matchers now route
 * through this file; the third is left where it is and its divergence is
 * recorded rather than absorbed — see THE FALLBACKS ARE NOT PART OF THE
 * DIALECT below.
 *
 * WHY THE NAME `PathGlob`. The class is the dialect, not a matcher object: it
 * holds no pattern and answers no per-instance question, so `GlobMatcher` would
 * promise an instance that does not exist and a `Matcher` suffix would collide
 * with the `Match`/`Matcher` vocabulary of `candy-fuzzy`. `PathGlob::compile()`
 * and `PathGlob::matchCompiled()` read as what they do, and the neutral
 * `SugarCraft\Crush\Util\` home follows {@see TokenTracker} — the one existing
 * utility both `Context\` and `Skills\` consumers could reach without either
 * subnamespace depending on the other.
 *
 * WHY THIS DIALECT AND NOT THE STRICTER SHELL ONE — RULED, DO NOT "FIX" THE
 * LOOSENESS BACK. The stricter reading (`*` stops at `/`, as bash without
 * `extglob` and minimatch's default) is defensible in isolation and was the
 * `PathTrigger` answer until this step. It is the wrong answer HERE, for four
 * reasons, each measured over the 33-row differential in
 * {@see \SugarCraft\Crush\Tests\Context\GlobDialectDifferentialTest}:
 *
 *  1. IT IS THE PLAN'S OWN INTENT. The motivating example is a glob of
 *     "`*\/tests/**\/*.php` wherever they live", and row #26 (of the #25-#27
 *     family) measures that `a/b/tests/FooTest.php` — two leading segments —
 *     answers NO under the strict compiler and YES under this one. "Wherever
 *     they live" is this answer; the plan's own example does not work under
 *     the other.
 *  2. NON-NARROWING IS REPO LAW, AND IT BINDS THE LIVE MATCHER. Unifying the
 *     other way turns off capability that exists today: row #6, a skill
 *     declaring `src/*.php` that announces for `src/deep/x.php`, and row #32,
 *     bare `*` against `a/b.php`. Making `*` segment-scoped would silently stop
 *     those announcements. This direction moves nothing that ships: the skill
 *     channel's answer is proven identical over 131,040 pattern-path pairs
 *     (364 patterns x 360 paths), and the thirteen rows the rule trigger
 *     moves on - ten wider, three narrower —
 *     are moves in a matcher that had no production reader when this step ran
 *     (reason 3). The three narrowings are rows #11, #22 and #23, every one of
 *     them a `[` or a `\` the strict dialect matched as a literal and this one
 *     reads as syntax; they are recorded here rather than glossed.
 *  3. THE COST IS ASYMMETRIC. `PathTrigger` had no production reader at all
 *     when this step ran — nothing consumes `Rule->triggers()`; the only
 *     references were `Rule.php`'s own `mutate()` carry and a comment in
 *     `Runtime.php`. So changing it affects no shipped behaviour. This
 *     dialect's matcher is live, on a tool-call path.
 *  4. PRODUCT CONSISTENCY. Every other user-facing `paths:` in this product is
 *     a `SKILL.md` frontmatter field read here. A rule author copying a working
 *     `paths:` value out of a `SKILL.md` into a rule must not get a different
 *     language for it.
 *
 * THE DIALECT, stated to the character:
 * - `**` spans anything, including `/`. Preceded by a separator it makes that
 *   separator optional, so `a/**\/b` claims `a/b` as well as `a/x/y/b`, and
 *   `a/**` claims `a` itself (row #5 — the strict compiler required the
 *   separator). Three or more stars keep the older predicate's wider answer on
 *   purpose; see the comment at that branch.
 * - A leading `**\/` is zero or more leading directories, so `**\/*.php` claims
 *   `a.php` at the root of the tree (row #31).
 * - `*` matches ANY run of characters including `/` (rows #6, #32). `?`
 *   matches any single character including `/` (rows #8, #9).
 * - `[…]` is a character class, `fnmatch()`'s reading of it: `!` negates, a
 *   POSIX class such as `[[:alpha:]]` passes through, and an unterminated `[`
 *   is a literal (rows #10-#13). `{a,b}` braces are NOT alternation — they
 *   match themselves (rows #14, #15).
 * - A backslash escapes the next character, because that is what `fnmatch()`
 *   does when `FNM_NOESCAPE` is not passed (rows #21-#23). Windows separators
 *   therefore do not survive as literals; that is `fnmatch()`'s answer, not a
 *   choice made here.
 * - Matching is case-SENSITIVE (row #16), anchored at both ends, and BYTE-wise:
 *   the compiled pattern carries `#Ds` and deliberately NO `u`, so a path
 *   holding bytes that are not valid UTF-8 matches literally instead of failing
 *   the subject wholesale (row #24). `/D` stops a trailing newline in a path
 *   from satisfying `$`; `/s` stops an embedded one from defeating a wildcard
 *   (rows #28, #29).
 *
 * THE FALLBACKS ARE NOT PART OF THE DIALECT, and a reader who "simplifies" this
 * file must keep both facts true:
 * - {@see \SugarCraft\Crush\Skills\SkillRegistry::legacyPathMatch()} answers
 *   the patterns this compiler cannot compile (a backslash-escaped `]` inside a
 *   class, a reversed range). It is a THIRD dialect: row #31 measures
 *   `**\/*.php` against `a.php` as YES here and NO there. It stays reachable
 *   exactly as it was — not deleted, not stubbed, not narrowed.
 * - {@see \SugarCraft\Crush\Context\Triggers\PathTrigger} answers a glob this
 *   compiler cannot compile as NO match, where the skill channel answers it
 *   from that fallback. Both callers get `matchCompiled()`'s `null` and decide
 *   their own policy; the policy difference is pinned in
 *   {@see \SugarCraft\Crush\Tests\Context\GlobDialectDifferentialTest}.
 *
 * THIS FILE MUST NEVER TOUCH THE FILESYSTEM or read a repository root.
 * Matching is not authorisation: `/etc/passwd` matches `**` like any other
 * string (row #17), and the containment gate belongs to whoever calls this.
 */
final class PathGlob
{
    /**
     * Translate one glob into the anchored PCRE that IS this dialect.
     *
     * Moved verbatim from `SkillRegistry::compilePathPattern()` at P6.S5a — a
     * relocation, not a rewrite: every branch below carries the argument that
     * put it there, and the equivalence harness in
     * {@see \SugarCraft\Crush\Tests\Context\GlobDialectDifferentialTest} proves
     * the skill channel's answer did not move by even one path.
     */
    public static function compile(string $glob): string
    {
        $out = '';
        $len = strlen($glob);

        for ($i = 0; $i < $len; $i++) {
            $ch = $glob[$i];

            // fnmatch() honours backslash escapes unless FNM_NOESCAPE is
            // passed, and this call site never passed it. VERIFIED on PHP
            // 8.3.6: fnmatch('a\*b', 'a*b') is true and fnmatch('a\*b', 'aXb')
            // is false.
            if ($ch === '\\' && $i + 1 < $len) {
                $out .= preg_quote($glob[++$i], '#');
                continue;
            }

            // `/**` — the whole point of this translation. Zero-or-more
            // `/segment` groups, so `a/**/b` claims `a/b` as well as
            // `a/x/y/b`, and `a/**` claims `a` itself.
            if ($ch === '/' && substr($glob, $i + 1, 2) === '**') {
                $j = $i + 3;
                while ($j < $len && $glob[$j] === '*') {
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

            if ($ch === '*' && substr($glob, $i + 1, 1) === '*') {
                $j = $i + 1;
                while ($j < $len && $glob[$j] === '*') {
                    ++$j;
                }

                // A LEADING `**/` — the case none of the three rewrites could
                // see, because each of them needed a slash in front of the
                // stars and at position 0 there is none. Zero-or-more leading
                // directories, so `**/*.php` finally claims `a.php`.
                if ($i === 0 && $j < $len && $glob[$j] === '/') {
                    $out .= '(?:.*/)?';
                    $i = $j;
                    continue;
                }

                $out .= '.*';
                $i = $j - 1;
                continue;
            }

            if ($ch === '*') {
                // Segment-scoped `*` is the stricter shell dialect and it is
                // DELIBERATELY not this one — see reason 2 in the class
                // doc-block. Changing this line to `[^/]*` narrows the live
                // skill matcher, which repo law forbids.
                $out .= '.*';
                continue;
            }

            if ($ch === '?') {
                $out .= '.';
                continue;
            }

            if ($ch === '[') {
                $close = $i + 1;
                if ($close < $len && ($glob[$close] === '!' || $glob[$close] === '^')) {
                    ++$close;
                }
                // A `]` in first position is a literal member, not the
                // terminator — the POSIX rule, and PHP's: fnmatch('[]]x', ']x')
                // is true on 8.3.6.
                if ($close < $len && $glob[$close] === ']') {
                    ++$close;
                }
                while ($close < $len && $glob[$close] !== ']') {
                    // A POSIX CLASS CARRIES A `]` OF ITS OWN, and a scan that
                    // does not know that stops on it. `[[:alpha:]]` then
                    // yielded the body `[:alpha:` and the emitted class ran on
                    // past its own terminator. That was tolerable exactly while
                    // the result failed to COMPILE — PCRE refused
                    // `#^[[:alpha:]\]x$#Ds` and the pattern routed to
                    // {@see \SugarCraft\Crush\Skills\SkillRegistry::legacyPathMatch()}.
                    // It is not tolerable when a LATER `[` in the pattern
                    // supplies the missing `]`: MEASURED on PHP 8.3.6,
                    // `[[:alpha:]][!a]` emitted `#^[[:alpha:]\][^a]$#Ds`, which
                    // compiles, swallows the second group into the first class,
                    // and answers FALSE for `ab` where `fnmatch()` answers true.
                    // A silently wrong answer, with no fallback, from a
                    // supported shape.
                    if ($glob[$close] === '[' && substr($glob, $close + 1, 1) === ':') {
                        $classEnd = strpos($glob, ':]', $close + 2);
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

                $body = substr($glob, $i + 1, $close - $i - 1);
                if ($body !== '' && $body[0] === '!') {
                    $body = '^' . substr($body, 1);
                }
                $out .= '[' . self::classBody($body) . ']';
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
     * Run a regex this class compiled and report whether the ENGINE answered.
     *
     * `null` means the pattern did not execute: it did not compile, or PCRE hit
     * a backtrack limit on a pathological subject. That is a third answer, not
     * a `false`, precisely because the two callers give it DIFFERENT meanings —
     * the skill channel falls back to `legacyPathMatch()`, the rule trigger
     * treats it as no match. Collapsing it to `false` here would delete the
     * only signal either of them has that the question went unanswered.
     *
     * `@` is load-bearing and is the same suppression
     * {@see \SugarCraft\Crush\Skills\SkillRegistry::pathMatches()} has always
     * carried: a pattern that fails to compile is a SUPPORTED shape, not
     * malformed input, and the one thing a glob match can never do is throw or
     * warn its way into a failed tool call.
     */
    public static function matchCompiled(string $regex, string $path): ?bool
    {
        $result = @preg_match($regex, $path);

        return $result === false ? null : $result === 1;
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
     * is for {@see compile()}'s terminator scan to know they carry a `]`; that
     * is where the fix lives. Everything else — `-`
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
     * The scan in {@see compile()} that found this body's closing `]` is not
     * escape-aware, so a body ENDING in a backslash means the `]` it stopped at
     * was itself escaped — `a[\]]b` — and what arrived here is a fragment of a
     * class whose real end this translation cannot see. Emitted unchanged it
     * keeps the regex uncompilable, which routes the pattern to
     * {@see \SugarCraft\Crush\Skills\SkillRegistry::legacyPathMatch()}, which
     * reads it correctly. Making it compile here would make it compile WRONG,
     * which is strictly worse than not compiling. Teaching the scanner to skip
     * escapes is the fix, and it is a bigger one: it must also decide what to
     * do with `[:alpha:]`, and it must leave SOME input uncompilable or the
     * fallback branch stops being pinned.
     */
    private static function classBody(string $body): string
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
}
