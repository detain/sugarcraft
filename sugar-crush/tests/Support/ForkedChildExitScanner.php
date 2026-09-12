<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

/**
 * Finds every IN-PROCESS fork in a test file and classifies how its child
 * branch leaves.
 *
 * WHY A TOKENIZER AND NOT A REGEX. Half the `pcntl_fork()` occurrences in
 * `tests/` are TEXT: they sit inside a heredoc that a test writes to disk and
 * runs as a separate `php` process (see `tests/Chat/ToolChildReapTest.php`,
 * `tests/Hooks/ScriptHookTest.php`, `tests/Providers/TransientFailureTest.php`,
 * `tests/Sessions/BackgroundSessionRunnerTest.php`). A child forked inside a
 * standalone script is not inside PHPUnit and a plain `exit()` there is
 * correct - the convention this scanner exists to police does not apply to
 * them at all, and a regex cannot tell the two apart. PHP's own lexer can:
 * heredoc bodies come back as `T_ENCAPSED_AND_WHITESPACE`, so `pcntl_fork`
 * inside one never becomes a `T_STRING`.
 *
 * WHAT IT REFUSES TO GUESS. Every site is classified into one of the shapes
 * below and there is no "skip" - a site whose child branch the scanner cannot
 * find, or whose terminator it does not recognise, comes back as
 * `unclassified` or `falls-through`, both of which are failures. A guard that
 * quietly ignores what it cannot parse has a hole shaped exactly like the next
 * defect.
 */
final class ForkedChildExitScanner
{
    /**
     * The call spellings that put a second copy of the process on the
     * machine. `forkTracked()` is {@see ReapsForkedChildrenTrait}'s wrapper
     * around `pcntl_fork()`; it is listed here because adopting the reaper
     * trait replaces the raw call at a site and must not thereby make the site
     * invisible to this scanner. `forkProcess()` is listed for the same
     * reason at its own shape (E291): {@see
     * \SugarCraft\Crush\Agents\AgentWorkerPool::forkProcess()} is a
     * seam-wrapped `return pcntl_fork();` whose child branch lives in the
     * CALLER — naming the wrapper here makes every caller of it a scanned
     * site, which is the condition the {@see SHAPE_FORK_WRAPPER} exemption
     * has always existed to enforce: a wrapper cannot buy itself a pass
     * without exposing all of its call sites.
     */
    private const FORK_SPELLINGS = ['pcntl_fork', 'forkTracked', 'forkProcess'];

    /**
     * A fork site is matched on TWO token types, and the second one is not
     * optional.
     *
     * PHP 8's lexer hands back `\pcntl_fork` as a single
     * `T_NAME_FULLY_QUALIFIED`, NOT as a `T_STRING` preceded by a backslash.
     * The first version of this scanner tested `T_STRING` alone, so one
     * leading `\` made a fork site vanish from the census entirely - and
     * `tests/Agents/TaskListTest.php` writes it exactly that way, twice, with
     * a plain `exit(0)` in each child branch. Two live offenders of precisely
     * the shape this scanner exists to find, reported as not existing.
     *
     * This is the same defect {@see ChildStderrCaptureScanner} records in its
     * own doc-block, found there first and not carried across until it had
     * been made twice. A guard that silently sees nothing is worse than no
     * guard: it is a green light with no bulb in it.
     */
    private const FORK_TOKEN_TYPES = [\T_STRING, \T_NAME_FULLY_QUALIFIED];

    /** Leaving shapes that never run PHP's shutdown sequence. */
    public const SHAPE_EXIT_NOW = 'exitNow';

    /**
     * A call to a same-file method declared `: never`, whose own last
     * statement leaves safely. E291 resolved the delegation: a `never`
     * helper ending in a plain `exit()` reports `bare-exit` at the CALLER,
     * because the helper ran the shutdown sequence and the branch that
     * delegated to it inherits that ending - {@see resolveNeverHelper()}.
     */
    public const SHAPE_NEVER_HELPER = 'never-helper';

    /** A bare `exit(...)` / `die(...)`: runs PHPUnit's shutdown a second time. */
    public const SHAPE_BARE_EXIT = 'bare-exit';

    /** The branch ends without leaving at all: returns into the test runner. */
    public const SHAPE_FALLS_THROUGH = 'falls-through';

    /** The scanner could not find a child branch it understands. */
    public const SHAPE_UNCLASSIFIED = 'unclassified';

    /**
     * The child branch RETURNS, inside a function that is itself one of
     * {@see FORK_SPELLINGS} - i.e. the site is a fork WRAPPER, not a fork.
     *
     * `forkTracked()` has to hand 0 back to its caller in the child and the
     * pid in the parent, because that is the whole of its contract; deciding
     * how the child leaves is the CALLER's job. Classifying that as
     * `falls-through` would be false, and exempting the file by name would
     * hand every future fork in it a free pass.
     *
     * The condition is deliberately self-limiting: only a function whose name
     * this scanner ALREADY treats as a fork call spelling can be a wrapper, so
     * a wrapper cannot buy its exemption without also making every one of its
     * call sites a scanned fork site.
     */
    public const SHAPE_FORK_WRAPPER = 'fork-wrapper';

    /**
     * @return list<array{line:int,spelling:string,shape:string,function:?string}>
     */
    public static function scan(string $source): array
    {
        $tokens = \token_get_all($source);
        $never = self::neverReturningMethods($tokens);
        $functions = TokenFunctionRanges::scan($tokens);

        /** @var list<array{line:int,spelling:string,shape:string,function:?string}> $sites */
        $sites = [];

        foreach ($tokens as $i => $token) {
            $spelling = self::forkSpellingAt($tokens, $i);
            if ($spelling === null) {
                continue;
            }
            if (self::next($tokens, $i) === null || self::tokenText($tokens[self::next($tokens, $i)]) !== '(') {
                continue;
            }
            // `function forkTracked()` is the definition, not a call site.
            $prev = self::prev($tokens, $i);
            if ($prev !== null && \is_array($tokens[$prev]) && $tokens[$prev][0] === \T_FUNCTION) {
                continue;
            }

            /** @var array{0:int,1:string,2:int} $token */
            $enclosing = TokenFunctionRanges::enclosing($functions, $i);
            $sites[] = [
                'line' => $token[2],
                'spelling' => $spelling,
                'shape' => self::classify(
                    $tokens,
                    $i,
                    $never,
                    $enclosing['name'] ?? null,
                ),
                // E445: the licence rosters are keyed by SITE, and the site's
                // coordinate is file + function. Exposing it here is the one
                // copy of "which function holds this token" the two guards
                // share; a second walk to derive it in a guard is the drift
                // DuplicatedTestHelperDriftTest exists to red.
                'function' => $enclosing['name'] ?? null,
            ];
        }

        return $sites;
    }

    /**
     * The roster key for one scanned site: the function that holds it, with a
     * named stand-in for file scope so a top-level fork still has a key.
     *
     * Shared by {@see ForkedChildExitConventionTest::ACCEPTED_BARE_EXIT} and
     * {@see ForkedChildReaperAdoptionTest::UNTRACKED_FORKS_ALLOWED} rather
     * than spelled twice - two copies of a key format is where rosters start
     * disagreeing with the walks that consult them.
     *
     * @param array{line:int,spelling:string,shape:string,function?:?string} $site
     */
    public static function functionKey(array $site): string
    {
        return $site['function'] ?? '<top>';
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @param array<string,array{from:int,to:int}> $never
     */
    private static function classify(array $tokens, int $forkAt, array $never, ?string $enclosing): string
    {
        // A fork whose value the enclosing fork-spelling function RETURNS
        // outright (`return pcntl_fork();`) has no child branch here at all -
        // the pid split is the CALLER's `if ($pid === 0)`, and the caller is
        // itself a scanned site because the wrapper's name is in
        // {@see FORK_SPELLINGS}. Without this rule the walk below runs past
        // the end of the function and classifies the next unrelated `if` in
        // the file as this site's branch; `AgentWorkerPool::forkProcess()` is
        // that shape (E291), and the name gate is the same one
        // {@see SHAPE_FORK_WRAPPER} has always used, so a wrapper named
        // anything else still falls through to a red rather than an
        // exemption.
        $before = self::prev($tokens, $forkAt);
        if ($before !== null && \is_array($tokens[$before]) && $tokens[$before][0] === \T_RETURN
            && $enclosing !== null && \in_array($enclosing, self::FORK_SPELLINGS, true)) {
            return self::SHAPE_FORK_WRAPPER;
        }

        $openBrace = null;
        $closeBrace = null;

        // A fork site is conventionally followed by TWO branches - the
        // `=== -1` failure branch and the `=== 0` child branch - in either
        // order, and sometimes with only one of them present. So this walks
        // the `if`s that follow rather than assuming the first one is the
        // child's, and stops at whichever boundary comes first.
        for ($i = $forkAt + 1, $n = \count($tokens); $i < $n; $i++) {
            if (self::forkSpellingAt($tokens, $i) !== null) {
                // The next fork begins; this one never got a child branch.
                return self::SHAPE_UNCLASSIFIED;
            }
            if (!\is_array($tokens[$i]) || $tokens[$i][0] !== \T_IF) {
                continue;
            }

            $openParen = self::next($tokens, $i);
            if ($openParen === null || self::tokenText($tokens[$openParen]) !== '(') {
                return self::SHAPE_UNCLASSIFIED;
            }
            $closeParen = TokenFunctionRanges::matching($tokens, $openParen, '(', ')');
            if ($closeParen === null) {
                return self::SHAPE_UNCLASSIFIED;
            }

            $condition = self::text($tokens, $openParen + 1, $closeParen - 1);

            // The fork-FAILED branch. Skipped rather than treated as a
            // mystery: it is half the convention, and it never contains a
            // child.
            if (\preg_match('/[=!]==?\s*-\s*1\b|\B-\s*1\s*[=!]==?/', $condition) === 1) {
                $i = $closeParen;

                continue;
            }

            // The CHILD branch: an equality against literal 0, in either
            // operand order. `!== 0` is the parent's branch and is not it.
            $isChild = \preg_match('/(?<![!])===?\s*0\b|\b0\s*===?(?![=])/', $condition) === 1
                && \preg_match('/!==?\s*0\b|\b0\s*!==?/', $condition) !== 1;

            if (!$isChild) {
                return self::SHAPE_UNCLASSIFIED;
            }

            $brace = self::next($tokens, $closeParen);
            if ($brace === null || self::tokenText($tokens[$brace]) !== '{') {
                return self::SHAPE_UNCLASSIFIED;
            }
            $end = TokenFunctionRanges::matching($tokens, $brace, '{', '}');
            if ($end === null) {
                return self::SHAPE_UNCLASSIFIED;
            }

            $openBrace = $brace;
            $closeBrace = $end;
            break;
        }

        if ($openBrace === null || $closeBrace === null) {
            return self::SHAPE_UNCLASSIFIED;
        }

        // THE LAST STATEMENT OF THE BRANCH, not the branch's whole text.
        // Measured, and it cost a mutation: with the terminator merely
        // SEARCHED FOR anywhere in the body, replacing the real
        // `ForkedChild::exitNow(0)` at the end of
        // `ParallelToolCallsTest::testAGroupWhoseForksAllFail...`'s child with
        // a plain `exit(0)` left that site still reading `exitNow`, because
        // the same branch contains a NESTED
        // `if ($probe === 0) { ForkedChild::exitNow(0); }` a dozen lines
        // earlier. The guard was green over the exact defect it exists for.
        [$tail, $terminator] = self::lastStatement($tokens, $openBrace, $closeBrace);

        if ($tail === null) {
            // An empty child branch leaves by falling out of the `if`.
            return self::SHAPE_FALLS_THROUGH;
        }

        // A branch ending in a BLOCK (`try {}`/`foreach {}`/a bare `{}`)
        // rather than in a statement: control reaches the closing brace by
        // some path this scanner is not going to model. Named rather than
        // waved through - a shape the guard cannot read is a hole in the
        // guard, not a licence for the site.
        if ($terminator !== ';') {
            return self::SHAPE_UNCLASSIFIED;
        }

        return self::classifyTail($tokens, $tail[0], $tail[1], $never, $enclosing, []);
    }

    /**
     * How a `: never` helper's own body leaves, resolved rather than assumed.
     *
     * E291's census widens to `src/`, and `src/` delegates children to never
     * helpers at four sites - three of which end in
     * `ForkedChild::exitNow()` and one (`BackgroundSessionRunner::exitWorker`)
     * ends in a plain `exit($code)` ON PURPOSE, because the exit code is that
     * fork's protocol. A shape that calls everything ending in a `: never`
     * call "safe" cannot tell those apart, and "leaving without running PHP's
     * shutdown sequence" - the sentence {@see SAFE_SHAPES} hangs on
     * `never-helper` - was false the day a helper was written whose last
     * statement is `exit()`. So the delegation is resolved to its terminus:
     * a helper that ends safely stays `never-helper`; one that ends bare
     * reports the shape it actually leaves by, which puts it in front of the
     * exemption roster with a reason or in front of the offender list.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @param array<string,array{from:int,to:int}> $never
     * @param list<string> $visited cycle guard - mutual `never` delegation is
     *                            legal PHP and cannot terminate
     */
    private static function resolveNeverHelper(array $tokens, string $name, array $never, array $visited): string
    {
        if (\in_array($name, $visited, true)) {
            return self::SHAPE_UNCLASSIFIED;
        }
        $visited[] = $name;

        $body = $never[$name] ?? null;
        if ($body === null) {
            return self::SHAPE_UNCLASSIFIED;
        }

        [$tail, $terminator] = self::lastStatement($tokens, $body['from'], $body['to']);
        if ($tail === null || $terminator !== ';') {
            return self::SHAPE_UNCLASSIFIED;
        }

        $resolved = self::classifyTail($tokens, $tail[0], $tail[1], $never, $name, $visited);

        // Any non-safe resolution is reported AS ITSELF: the site delegates to
        // a helper that ends like the defect, and the census has to see that,
        // not the delegation.
        return \in_array($resolved, [self::SHAPE_BARE_EXIT, self::SHAPE_FALLS_THROUGH, self::SHAPE_UNCLASSIFIED], true)
            ? $resolved
            : self::SHAPE_NEVER_HELPER;
    }

    /**
     * How the branch's last statement leaves, decided on TOKEN IDENTITY rather
     * than on substrings of source text.
     *
     * WHY NOT TEXT. The first version rendered the statement back to source
     * with {@see text()} - which concatenates EVERY token, comments included -
     * and then asked `str_contains($tail, 'ForkedChild::exitNow')`. So a plain
     * `exit(0)` with the line
     * `// ForkedChild::exitNow(0) is not usable on this path.` above it came
     * back as the SAFE shape (measured: it survived a mutation that the same
     * defect without the comment killed). A string literal mentioning the
     * helper did it too, and so did a comment naming a `never` method. Prose
     * about a terminator is not a terminator.
     *
     * Token identity has no such window: `exit`/`die` are `T_EXIT` whatever
     * they are spelled next to, `ForkedChild::exitNow` is a resolvable token
     * triple, and neither a `T_COMMENT` nor a `T_CONSTANT_ENCAPSED_STRING` can
     * impersonate either of them.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @param array<string,array{from:int,to:int}> $never
     * @param list<string> $visited
     */
    private static function classifyTail(
        array $tokens,
        int $from,
        int $to,
        array $never,
        ?string $enclosing,
        array $visited,
    ): string {
        /** @var list<int> $sig indices of the statement's significant tokens */
        $sig = [];
        for ($i = $from; $i <= $to; $i++) {
            if (\is_array($tokens[$i])
                && \in_array($tokens[$i][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            $sig[] = $i;
        }

        if ($sig === []) {
            return self::SHAPE_FALLS_THROUGH;
        }

        $type = static function (int $at) use ($tokens): int {
            return \is_array($tokens[$at]) ? $tokens[$at][0] : -1;
        };
        $text = static function (int $at) use ($tokens): string {
            return self::tokenText($tokens[$at]);
        };

        foreach ($sig as $k => $at) {
            // `ForkedChild::exitNow(` - as three tokens, in that order.
            if (\in_array($type($at), [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)) {
                $name = \ltrim($text($at), '\\');
                $isForkedChild = $name === 'ForkedChild' || \str_ends_with($name, '\\ForkedChild');
                if ($isForkedChild
                    && ($sig[$k + 1] ?? null) !== null && $type($sig[$k + 1]) === \T_DOUBLE_COLON
                    && ($sig[$k + 2] ?? null) !== null && $text($sig[$k + 2]) === 'exitNow') {
                    return self::SHAPE_EXIT_NOW;
                }
            }
        }

        foreach ($sig as $k => $at) {
            // `$this->helper(` / `self::helper(` / `static::helper(`, where
            // `helper` is declared `: never` in this same file. The shape is
            // RESOLVED through to the helper's own terminus - see
            // {@see resolveNeverHelper()} for why a delegation is not yet a
            // safe exit.
            $next = $sig[$k + 1] ?? null;
            $after = $sig[$k + 2] ?? null;
            if ($next === null || $after === null) {
                continue;
            }
            $isThis = $type($at) === \T_VARIABLE && $text($at) === '$this'
                && $type($next) === \T_OBJECT_OPERATOR;
            $isSelf = \in_array($text($at), ['self', 'static'], true) && $type($next) === \T_DOUBLE_COLON;
            if (($isThis || $isSelf) && \array_key_exists($text($after), $never)) {
                return self::resolveNeverHelper($tokens, $text($after), $never, $visited);
            }
        }

        foreach ($sig as $at) {
            if ($type($at) === \T_EXIT) {
                return self::SHAPE_BARE_EXIT;
            }
        }

        // A branch that RETURNS is only ever safe inside a fork wrapper; see
        // {@see SHAPE_FORK_WRAPPER} for why that condition is narrow.
        if ($type($sig[0]) === \T_RETURN) {
            return $enclosing !== null && \in_array($enclosing, self::FORK_SPELLINGS, true)
                ? self::SHAPE_FORK_WRAPPER
                : self::SHAPE_FALLS_THROUGH;
        }

        return self::SHAPE_FALLS_THROUGH;
    }

    /**
     * The final statement of a `{ ... }` block, as a TOKEN INDEX RANGE, plus
     * the character that ended it (`;`, or `}` when the block's last thing was
     * itself a block).
     *
     * Statement boundaries are counted at the block's OWN nesting depth only,
     * so nothing inside a nested block can be mistaken for the branch's last
     * word.
     *
     * A range rather than source text, because the caller classifies on token
     * identity - see {@see classifyTail()} for the comment-in-the-window
     * defect that rendering back to text caused.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return array{0:?array{0:int,1:int},1:string}
     */
    private static function lastStatement(array $tokens, int $openBrace, int $closeBrace): array
    {
        $depth = 0;
        $start = $openBrace + 1;
        /** @var array{0:array{0:int,1:int},1:string}|null $last */
        $last = null;

        for ($i = $openBrace + 1; $i < $closeBrace; $i++) {
            $text = self::tokenText($tokens[$i]);
            $isPunct = \is_string($tokens[$i]);

            // The depth rule is {@see TokenFunctionRanges::opensBraceDepth()}
            // - bare `{` AND the array tokens PHP opens interpolations with -
            // shared with the closer walk since E208's fold. An inline copy
            // here used to name the opener pair a second time in this file,
            // which is one more place a PHP change would have to be noticed.
            if (TokenFunctionRanges::opensBraceDepth($tokens[$i])) {
                $depth++;

                continue;
            }
            if (TokenFunctionRanges::closesBraceDepth($tokens[$i])) {
                $depth--;
                if ($depth === 0) {
                    if (self::hasCode($tokens, $start, $i)) {
                        $last = [[$start, $i], '}'];
                    }
                    $start = $i + 1;
                }

                continue;
            }
            if ($isPunct && $text === ';' && $depth === 0) {
                if (self::hasCode($tokens, $start, $i)) {
                    $last = [[$start, $i], ';'];
                }
                $start = $i + 1;
            }
        }

        // Anything after the final boundary is an unterminated fragment; the
        // last COMPLETE statement is what decides how the branch leaves.
        if ($last === null) {
            return [null, ''];
        }

        return [$last[0], $last[1]];
    }

    /**
     * Method names in this file declared with a `never` return type, mapped to
     * their body's token range - the language's own way of saying "does not
     * come back", which is exactly the property a child branch delegating to a
     * helper needs, AND the body the delegation must be resolved through
     * ({@see resolveNeverHelper()}).
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return array<string,array{from:int,to:int}>
     */
    private static function neverReturningMethods(array $tokens): array
    {
        $names = [];
        foreach ($tokens as $i => $token) {
            if (!\is_array($token) || $token[0] !== \T_FUNCTION) {
                continue;
            }
            $nameAt = self::next($tokens, $i);
            if ($nameAt === null || !\is_array($tokens[$nameAt]) || $tokens[$nameAt][0] !== \T_STRING) {
                continue;
            }
            $openParen = self::next($tokens, $nameAt);
            if ($openParen === null || self::tokenText($tokens[$openParen]) !== '(') {
                continue;
            }
            $closeParen = TokenFunctionRanges::matching($tokens, $openParen, '(', ')');
            if ($closeParen === null) {
                continue;
            }
            $colon = self::next($tokens, $closeParen);
            if ($colon === null || self::tokenText($tokens[$colon]) !== ':') {
                continue;
            }
            $type = self::next($tokens, $colon);
            if ($type !== null && \is_array($tokens[$type]) && $tokens[$type][0] === \T_STRING
                && \strtolower($tokens[$type][1]) === 'never') {
                // The body, so a delegation to this helper can be resolved to
                // how the helper itself leaves ({@see resolveNeverHelper()}).
                // An interface/abstract `never` declaration has no brace and
                // keeps no body to resolve.
                $brace = self::next($tokens, $type);
                if ($brace === null || self::tokenText($tokens[$brace]) !== '{') {
                    continue;
                }
                $end = TokenFunctionRanges::matching($tokens, $brace, '{', '}');
                if ($end === null) {
                    continue;
                }
                $names[$tokens[$nameAt][1]] = ['from' => $brace, 'to' => $end];
            }
        }

        return $names;
    }

    /** @param array{0:int,1:string,2:int}|string $token */
    private static function tokenText(array|string $token): string
    {
        return \is_array($token) ? $token[1] : $token;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function next(array $tokens, int $from): ?int
    {
        for ($i = $from + 1, $n = \count($tokens); $i < $n; $i++) {
            if (\is_array($tokens[$i]) && \in_array($tokens[$i][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function prev(array $tokens, int $from): ?int
    {
        for ($i = $from - 1; $i >= 0; $i--) {
            if (\is_array($tokens[$i]) && \in_array($tokens[$i][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * Whether a token range holds anything but whitespace and comments.
     *
     * A comment-only gap between two statements is not a statement; before
     * this was checked on token types it was checked on `trim()`ed text, and
     * a trailing `// ...` line therefore registered as the branch's last
     * statement.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function hasCode(array $tokens, int $from, int $to): bool
    {
        for ($i = $from; $i <= $to; $i++) {
            if (\is_string($tokens[$i])) {
                if (\trim($tokens[$i]) !== '') {
                    return true;
                }

                continue;
            }
            if (\in_array($tokens[$i][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * The fork spelling at $at, normalised without its leading `\`, or null.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function forkSpellingAt(array $tokens, int $at): ?string
    {
        if (!\is_array($tokens[$at]) || !\in_array($tokens[$at][0], self::FORK_TOKEN_TYPES, true)) {
            return null;
        }

        $name = \ltrim($tokens[$at][1], '\\');

        return \in_array($name, self::FORK_SPELLINGS, true) ? $name : null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function text(array $tokens, int $from, int $to): string
    {
        $out = '';
        for ($i = $from; $i <= $to; $i++) {
            $out .= self::tokenText($tokens[$i]);
        }

        return $out;
    }
}
