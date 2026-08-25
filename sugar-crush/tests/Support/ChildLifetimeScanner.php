<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

/**
 * Finds every `proc_open()` in a source file and answers two questions about
 * it: does the child OUTLIVE the call, and does the descriptor spec say
 * anything about fd 3 and above.
 *
 * WHY THOSE TWO TOGETHER. `proc_open()` remaps only the descriptors its spec
 * names; every other open descriptor of the parent is inherited by the child.
 * For a child that is drained and `proc_close()`d inside the same function
 * that spawned it, that inheritance lasts microseconds and harms nothing. For
 * a child whose handle is stored in a property or handed back to a caller -
 * an MCP server, a language server, a plugin daemon - the inherited
 * descriptors are held for as long as the child lives, which is where E365
 * came from: a leaked `php -S` held the write end of the CALLER'S STDOUT on
 * fd 4, so `phpunit | tail` blocked forever on an EOF that never came, after a
 * green run. Neither half is a defect on its own. The pair is.
 *
 * WHY A SCANNER RATHER THAN FIVE HAND FIXES, which is E366's own sentence:
 * fixing today's five call sites by hand fixes today's five and meets the
 * sixth next year. The shape is mechanical, so the check is mechanical.
 *
 * WHAT THIS IS NOT. It is not {@see ChildStderrCaptureScanner}, which asks
 * where fd 2 goes for spawns under `tests/`. The two walk the same syntax and
 * ask different questions of it, and they are separate classes on purpose:
 * folding a lifetime analysis into a stderr classifier would give one
 * instrument two answers and one name.
 *
 * WHAT IT CANNOT SEE, said out loud because a scanner that is silent about its
 * blind spots reads as authoritative:
 *
 *  - `pcntl_fork()`. A forked child inherits EVERYTHING by construction and is
 *    not a `proc_open()` site at all. {@see ForkedChildExitScanner} is the
 *    instrument for that family.
 *  - A handle that escapes through a call rather than an assignment -
 *    `$this->register(proc_open(...))`. That is reported as
 *    {@see LIFETIME_UNCLASSIFIED}, never as short-lived, because guessing
 *    "short" for a shape it cannot follow is the polarity that waves a real
 *    leak through.
 *  - Whether an inherited fd is actually OPEN at the moment of the spawn. This
 *    is a syntactic instrument; the fd table is a runtime fact. What it can
 *    say is that the spec declined to have an opinion, which is the thing a
 *    reviewer can act on.
 */
final class ChildLifetimeScanner
{
    /** The child is drained and closed inside the function that spawned it. */
    public const LIFETIME_SHORT = 'short';

    /** The handle is stored on the object or handed back to a caller. */
    public const LIFETIME_LONG = 'long';

    /**
     * The scanner could not follow the handle.
     *
     * A FAILURE, NOT A DEFAULT, and the distinction is the whole reason this
     * constant exists rather than the site being silently treated as short.
     * A guard that quietly ignores what it cannot parse has a hole shaped
     * exactly like the next defect, so the guard reds on this and the site
     * has to be looked at by a person.
     */
    public const LIFETIME_UNCLASSIFIED = 'unclassified';

    /**
     * Calls that reap a handle ON EVERY PATH, besides `proc_close()` itself.
     *
     * WHY THIS IS A ROSTER AND NOT A NAME. A tree that grows a reaping helper
     * stops spelling `proc_close()` at the call sites, and a scanner that
     * knows only the builtin then reports every one of those sites as a child
     * nothing happens to. That is not a missed finding, it is an INVENTED one:
     * the guard reds code that was just made stricter, and the row somebody
     * adds to quiet it is an exemption written for correct code - which is
     * where the next real offender hides.
     *
     * MEASURED, not anticipated. Scanning a concurrent lane's `src/` with this
     * class showed exactly that: `Providers/ClaudeCodeProvider.php` spells its
     * reap `ProcessReaper::terminateAndClose($process)` where this tree still
     * spells `proc_close($process)`, and the site went from short-lived to
     * "nothing returns, stores or proc_close()s it" on a change that added a
     * bounded SIGTERM->SIGKILL ladder.
     *
     * ⚠️ A ROW HERE IS A CLAIM THAT THE HELPER REALLY CLOSES, ON EVERY PATH
     * OUT OF ITSELF. This is the one roster in this class whose rows can HIDE
     * a finding rather than raise one - a wrong row here turns an exposed
     * spawn into {@see LIFETIME_SHORT}, and
     * `DescriptorInheritanceGuardTest::exposedIn()` drops every short site
     * without a trace. {@see BEST_EFFORT_REAPERS} is where a helper that
     * reaps only SOMETIMES belongs; putting one here is the polarity this
     * class exists to avoid.
     *
     * WHAT THIS DOC-BLOCK USED TO CLAIM AND WHY THE WARNING IS NOW TWO
     * ROSTERS. It said "a row here is a claim that the helper really closes"
     * and left it at that, and the very commit that wrote the sentence also
     * added `processreaper::reapifexited` underneath it. WHAT IS TRUE NOW,
     * read off that helper's own source rather than its name:
     * `ProcessReaper::reapIfExited()` waits WITHOUT signalling and, when the
     * child is still running at the end of the budget, `return null`s with
     * the handle untouched - by design, for a launcher that must not SIGTERM
     * the process mid-double-fork. It is a conditional best-effort reap, so
     * it belongs in the other roster. WHY THIS STILL EARNS ITS PLACE: the
     * warning was right and was still not enough, because "really closes" is
     * a judgement a reader makes about a method in ANOTHER package, at a
     * glance, from its name. Splitting the rosters makes the safe answer the
     * easy one - if you are not certain it closes on every path, the other
     * list is always correct.
     *
     * Keys are matched case-insensitively on the trailing `Class::method`,
     * because that is the part a `use` statement cannot change. The value is
     * the measured reason the helper qualifies, and
     * {@see \SugarCraft\Crush\Tests\Support\ChildLifetimeScannerFixtureTest}
     * refuses an empty one.
     *
     * @var array<string, string>
     */
    public const CLOSING_HELPERS = [
        'processreaper::terminateandclose'
            => 'SIGTERM, a polled grace period, signal 9, then proc_close() - the only early '
                . 'return is the !is_resource() idempotency guard, where there is no live handle '
                . 'to close in the first place. Every path that is handed a live child ends in '
                . 'proc_close().',
    ];

    /**
     * Calls that MIGHT reap a handle, and might leave it alone.
     *
     * THE SAFE HALF OF THE ROSTER SPLIT, and the asymmetry is the point. A
     * wrong row in {@see CLOSING_HELPERS} deletes a finding; a wrong row here
     * cannot, because every row here produces {@see LIFETIME_UNCLASSIFIED},
     * which the guard treats as exposed and requires a person to account for.
     * When in doubt, this is the list.
     *
     * WHY IT IS NOT SIMPLY LEFT TO THE FALL-THROUGH. A handle handed to an
     * unrostered call already reads as unclassified, but with the reason
     * "handed to X, which this scanner cannot follow; if one of those reaps
     * the child, roster it in CLOSING_HELPERS" - which is an instruction to
     * commit exactly the defect described above. A named best-effort reaper
     * gets a sentence saying what it really does instead.
     *
     * @var array<string, string>
     */
    public const BEST_EFFORT_REAPERS = [
        'processreaper::reapifexited'
            => 'waits WITHOUT signalling and returns null with the handle untouched when the '
                . 'child is still running at the end of the budget, so it closes on some paths '
                . 'and not others. Deliberate: it exists for a launcher that must not signal a '
                . 'process in the middle of a double fork.',
    ];

    /** `proc_open` appearing as something other than a direct global call. */
    public const REF_METHOD = 'method call';
    public const REF_STATIC = 'static call';
    public const REF_DECLARATION = 'function declaration';
    public const REF_STRING = 'string reference';
    public const REF_BARE = 'bare name';

    /**
     * Every `proc_open()` call site, and every OTHER appearance of the name.
     *
     * The second list is the rule-14 half. `$this->proc_open(...)`,
     * `Foo::proc_open(...)` and `'proc_open'` passed to something are not
     * direct calls, and dropping them silently is how an alphabet grows a hole
     * that matches the next defect exactly. They are reported as unresolved
     * appearances so a guard can require each one to be accounted for, rather
     * than being invisible.
     *
     * @return array{
     *     sites: list<array{
     *         line:int, function:string, lifetime:string, reason:string,
     *         closedBy:string|null, fds:list<int>|null, highFds:list<int>
     *     }>,
     *     unresolved: list<array{line:int, function:string, kind:string}>
     * }
     */
    public static function scan(string $source): array
    {
        $tokens = \token_get_all($source);
        $functions = TokenFunctionRanges::scan($tokens);
        $sites = [];
        $unresolved = [];

        foreach ($tokens as $i => $token) {
            if (!\is_array($token)) {
                continue;
            }

            // A string literal that IS the function name - `function_exists`,
            // `call_user_func`, a disable-list. Not a call; not droppable.
            if ($token[0] === \T_CONSTANT_ENCAPSED_STRING) {
                $inner = \substr($token[1], 1, -1);
                if (\ltrim($inner, '\\') === 'proc_open') {
                    $unresolved[] = [
                        'line' => $token[2],
                        'function' => self::functionName($functions, $i),
                        'kind' => self::REF_STRING,
                    ];
                }

                continue;
            }

            if (!\in_array($token[0], [\T_STRING, \T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            if (\ltrim(\strtolower($token[1]), '\\') !== 'proc_open') {
                continue;
            }

            $prev = self::prev($tokens, $i);
            $kind = null;
            if ($prev !== null && \is_array($tokens[$prev])) {
                $kind = match ($tokens[$prev][0]) {
                    \T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR => self::REF_METHOD,
                    \T_DOUBLE_COLON => self::REF_STATIC,
                    \T_FUNCTION => self::REF_DECLARATION,
                    default => null,
                };
            }
            if ($kind !== null) {
                $unresolved[] = [
                    'line' => $token[2],
                    'function' => self::functionName($functions, $i),
                    'kind' => $kind,
                ];

                continue;
            }

            $open = self::next($tokens, $i);
            if ($open === null || self::tokenText($tokens[$open]) !== '(') {
                $unresolved[] = [
                    'line' => $token[2],
                    'function' => self::functionName($functions, $i),
                    'kind' => self::REF_BARE,
                ];

                continue;
            }

            $close = self::matching($tokens, $open, '(', ')');
            if ($close === null) {
                $unresolved[] = [
                    'line' => $token[2],
                    'function' => self::functionName($functions, $i),
                    'kind' => self::REF_BARE,
                ];

                continue;
            }

            $closedBy = null;
            [$lifetime, $reason] = self::classifyLifetime($tokens, $i, $close, $functions, $closedBy);
            $fds = self::specFds($tokens, $open, $close, $functions, $i);

            $sites[] = [
                'line' => $token[2],
                'function' => self::functionName($functions, $i),
                'lifetime' => $lifetime,
                'reason' => $reason,
                'closedBy' => $closedBy,
                'fds' => $fds,
                'highFds' => $fds === null ? [] : \array_values(\array_filter($fds, static fn (int $fd): bool => $fd >= 3)),
            ];
        }

        return ['sites' => $sites, 'unresolved' => $unresolved];
    }

    /**
     * Whether the child outlives the call.
     *
     * THE ASSIGNMENT ESCAPE BEATS THE CLOSE, deliberately. A function that
     * both stores the handle on `$this` AND calls `proc_close()` on some path
     * has a child that can outlive the call, and reading the `proc_close()` as
     * proof of a short life is the polarity that hides a leak - so a
     * `$this->handle = $proc` returns {@see LIFETIME_LONG} the moment
     * {@see classifyLocal()} walks onto it, before any close is weighed.
     *
     * "ESCAPE WINS" IS WHAT THIS SAID, FLATLY, AND IT IS TRUE OF ONE OF THE
     * TWO ESCAPE SPELLINGS. MEASURED: `$this->register($h); proc_close($h);`
     * answers {@see LIFETIME_SHORT}, because a CALL escape is only collected
     * and is weighed after the close, while `proc_close($h); $this->h = $h;`
     * answers `long`. WHY THE BEHAVIOUR IS LEFT ALONE AND ONLY THE SENTENCE
     * CHANGED: for the call spelling the short answer is the CORRECT one. The
     * question this class asks is whether the CHILD outlives the call, and an
     * unconditional `proc_close()` ends it whatever some other function did
     * with the handle first. The assignment rule is the conservative one
     * because a stored handle is reachable from a path that never reaches the
     * close; a call that already ran is not. WHY THE PARAGRAPH STILL EARNS ITS
     * PLACE: the ordering it describes is real and load-bearing, and a reader
     * who deletes it will re-derive the hidden-leak polarity the hard way.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @param list<array{name:string,from:int,to:int}> $functions
     * @param ?string $closedBy out-param: the {@see CLOSING_HELPERS} key that
     *                           produced a {@see LIFETIME_SHORT} verdict, or
     *                           null when the verdict came from a literal
     *                           `proc_close()` or is not short at all. E425:
     *                           `DescriptorInheritanceGuardTest` drops every
     *                           short site, so a wrong row in that roster
     *                           deletes a finding with no trace anywhere. This
     *                           is the trace. It is deliberately NOT derived
     *                           from the reason sentence - prose is what the
     *                           reader gets, never what an instrument parses.
     * @return array{0:string,1:string}
     */
    private static function classifyLifetime(
        array $tokens,
        int $at,
        int $close,
        array $functions,
        ?string &$closedBy = null,
    ): array {
        $enclosing = TokenFunctionRanges::enclosing($functions, $at);
        $floor = $enclosing['from'] ?? 0;
        $end = $enclosing['to'] ?? \count($tokens) - 1;

        $prev = self::prev($tokens, $at);
        if ($prev !== null && \is_array($tokens[$prev]) && $tokens[$prev][0] === \T_RETURN) {
            return [self::LIFETIME_LONG, 'the handle is returned directly'];
        }

        if ($prev === null || self::tokenText($tokens[$prev]) !== '=') {
            return [
                self::LIFETIME_UNCLASSIFIED,
                'the result is neither returned nor assigned to anything this scanner can name',
            ];
        }

        $target = self::assignmentTarget($tokens, $prev, $floor);
        if ($target === null) {
            return [self::LIFETIME_UNCLASSIFIED, 'the assignment target could not be read'];
        }

        if (\str_contains($target, '->') || \str_contains($target, '::')) {
            return [self::LIFETIME_LONG, 'the handle is stored in ' . $target];
        }

        if (\preg_match('/^\$\w+$/', $target) !== 1) {
            return [
                self::LIFETIME_UNCLASSIFIED,
                'the assignment target "' . $target . '" is neither a plain local nor a property',
            ];
        }

        return self::classifyLocal($tokens, $close, $end, $target, $closedBy);
    }

    /**
     * Is every block between the floor and here a `finally`?
     *
     * The floor itself is not checked: it is the level the spawn is on, and a
     * call there is already unconditional by depth alone.
     *
     * @param array<int,bool> $finallyAt
     */
    private static function everyBlockIsFinally(array $finallyAt, int $floor, int $depth): bool
    {
        if ($depth <= $floor) {
            return false;
        }

        for ($level = $floor + 1; $level <= $depth; $level++) {
            if (($finallyAt[$level] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * The fate of a handle that lands in a plain local variable.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return array{0:string,1:string}
     */
    private static function classifyLocal(
        array $tokens,
        int $from,
        int $end,
        string $variable,
        ?string &$closedBy = null,
    ): array {
        $unconditionalCloser = null;
        $conditionalCloser = null;
        $escapes = [];
        $bestEffort = [];
        $unfollowedAt = null;
        $depth = 0;
        $floor = 0;

        // Which open blocks were opened by `finally`, keyed by the depth the
        // brace opened. A `finally` block is the ONE nested block that runs on
        // every path out of the function it is in, so a closer inside one is
        // as unconditional as a closer at the function's own level - and this
        // scanner said otherwise about correct code. MEASURED at the round-53
        // merge: `Providers/ClaudeCodeProvider.php::completeStream` reaps its
        // child in a generator `finally`, which covers normal completion, an
        // exception, AND a consumer that `break`s out of the foreach and
        // destroys the generator mid-body. The guard called that "runs only
        // inside a nested block, so it does not cover every path out of this
        // function", which is exactly false about `finally`.
        //
        // KEYED BY DEPTH, not a bare flag, because the qualifying case is
        // "every block between the floor and here is a finally" - a closer
        // inside an `if` inside a `finally` is still conditional, and one
        // inside a `finally` inside a `foreach` did not escape the foreach.
        $finallyAt = [];

        for ($i = $from + 1; $i <= $end; $i++) {
            $token = $tokens[$i];

            // Brace depth relative to the spawn statement, against a RUNNING
            // MINIMUM rather than against zero.
            //
            // A `proc_close()` DEEPER than the spawn sits inside an
            // `if`/`try`/`foreach` the spawn is not inside, so it runs on some
            // paths out of the function and not others. One at the shallowest
            // depth seen so far is on the path the spawn itself is on.
            //
            // THE MINIMUM IS WHAT MAKES IT RIGHT, and comparing against a
            // fixed zero got this wrong in the tree, not in theory: the second
            // spawn in `Hooks/ScriptHook.php::executeStaged()` sits inside an
            // `if`, and the `proc_close()` that covers it is at the function's
            // own level - depth MINUS one from the spawn. Against zero that
            // read as conditional, which is a guard reddening correct code,
            // and an exemption row for correct code is where the next real
            // offender hides. Against the running minimum a close that has
            // merely left the spawn's own block counts, while a close inside a
            // LATER block does not, because entering one takes depth back
            // above the floor.
            if (\is_string($token) && $token === '{') {
                $depth++;
                // The brace's own opener, not the statement's: `finally {`.
                $opener = self::prev($tokens, $i);
                $finallyAt[$depth] = $opener !== null
                    && \is_array($tokens[$opener])
                    && $tokens[$opener][0] === \T_FINALLY;
            } elseif (\is_array($token)
                && \in_array($token[0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
                // String interpolation, never a finally.
                $finallyAt[$depth] = false;
            } elseif (\is_string($token) && $token === '}') {
                unset($finallyAt[$depth]);
                $depth--;
                $floor = \min($floor, $depth);
            }

            // `return ... $handle ...;` - the variable anywhere in the
            // returned expression is an escape, because `return [$proc,
            // $pipes];` is the commonest spelling in this tree and a bare
            // `return $proc;` check would miss every one of them.
            if (\is_array($token) && $token[0] === \T_RETURN) {
                for ($j = $i + 1; $j <= $end; $j++) {
                    if (\is_string($tokens[$j]) && $tokens[$j] === ';') {
                        break;
                    }
                    if (\is_array($tokens[$j]) && $tokens[$j][0] === \T_VARIABLE && $tokens[$j][1] === $variable
                        && !self::isProcCloseArgument($tokens, $j)) {
                        return [self::LIFETIME_LONG, 'the handle in ' . $variable . ' is returned'];
                    }
                }

                continue;
            }

            if (!\is_array($token) || $token[0] !== \T_VARIABLE || $token[1] !== $variable) {
                continue;
            }

            // `$this->handles[] = $handle;` / `self::$live = $handle;`
            $equals = self::prev($tokens, $i);
            if ($equals !== null && self::tokenText($tokens[$equals]) === '=') {
                $target = self::assignmentTarget($tokens, $equals, 0);
                if ($target !== null && (\str_contains($target, '->') || \str_contains($target, '::'))) {
                    return [self::LIFETIME_LONG, 'the handle in ' . $variable . ' is stored in ' . $target];
                }
            }

            $callee = self::calleeTakingArgument($tokens, $i);

            if ($callee !== null && self::isClosingCallee($callee)) {
                // NAMED SEPARATELY BY CONDITIONALITY, because the reason
                // sentence quotes one of them and the reader goes looking for
                // that exact line. A single `$closer` overwritten by whichever
                // close came LAST reported the conditional call as the one
                // that "runs unconditionally" whenever both spellings were
                // present - the same defect the named-closer change existed
                // to remove, one level in.
                if ($depth === $floor || self::everyBlockIsFinally($finallyAt, $floor, $depth)) {
                    $unconditionalCloser ??= $callee;
                } else {
                    $conditionalCloser ??= $callee;
                }

                continue;
            }

            if ($callee !== null && isset(self::BEST_EFFORT_REAPERS[\strtolower($callee)])) {
                $bestEffort[$callee] = true;

                continue;
            }

            // The handle is an argument to something else. That is NOT
            // "nothing happens to it" - it is this scanner failing to follow
            // it, and the two must not share a sentence. A reviewer can act on
            // the name of the call; they cannot act on a false absence.
            if ($callee !== null) {
                $escapes[$callee] = true;

                continue;
            }

            // Not an assignment, not a return, not an argument: the handle
            // appears in a shape with no callee to name - an array member
            // (`$a = ['p' => $h];`), an index, an interpolation. Recording the
            // LINE rather than nothing, because the alternative sentence -
            // "nothing in this function returns, stores or proc_close()s $h" -
            // is flatly false about a function that plainly mentions it.
            $unfollowedAt ??= \is_array($token) ? $token[2] : null;
        }

        if ($unconditionalCloser !== null) {
            // THE ONLY PLACE A ROSTER ROW CAN TURN AN EXPOSED SPAWN SHORT, and
            // therefore the only place worth recording. A literal
            // `proc_close()` is the language closing the child and needs no
            // provenance; a CLOSING_HELPERS key is somebody's claim about a
            // method in another file, and E425 is what it costs when that
            // claim is wrong and invisible.
            $rostered = \strtolower($unconditionalCloser);
            $closedBy = isset(self::CLOSING_HELPERS[$rostered]) ? $rostered : null;

            return [
                self::LIFETIME_SHORT,
                $unconditionalCloser . '(' . $variable . ') runs unconditionally in the same function',
            ];
        }

        if ($conditionalCloser !== null) {
            return [
                self::LIFETIME_UNCLASSIFIED,
                $conditionalCloser . '(' . $variable . ') runs only inside a nested block, so it '
                    . 'does not cover every path out of this function',
            ];
        }

        if ($bestEffort !== []) {
            return [
                self::LIFETIME_UNCLASSIFIED,
                \implode(', ', \array_keys($bestEffort)) . '(' . $variable . ') is a BEST-EFFORT '
                    . 'reap: it closes the child on some paths and leaves the handle alone on '
                    . 'others, so it does not end the child\'s life the way proc_close() does',
            ];
        }

        if ($escapes !== []) {
            return [
                self::LIFETIME_UNCLASSIFIED,
                $variable . ' is handed to ' . \implode(', ', \array_keys($escapes))
                    . ', which this scanner cannot follow; if one of those reaps the child on '
                    . 'EVERY path out of itself, roster it in CLOSING_HELPERS, and if it reaps '
                    . 'only sometimes, in BEST_EFFORT_REAPERS',
            ];
        }

        if ($unfollowedAt !== null) {
            return [
                self::LIFETIME_UNCLASSIFIED,
                $variable . ' appears again on line ' . $unfollowedAt . ' in a shape with no '
                    . 'callee to name - an array member, an index, an interpolation - so this '
                    . 'scanner cannot say where it goes',
            ];
        }

        return [
            self::LIFETIME_UNCLASSIFIED,
            'nothing in this function returns, stores or proc_close()s ' . $variable,
        ];
    }

    /** Whether a callee name ends a child's life on every path out of itself. */
    private static function isClosingCallee(string $callee): bool
    {
        return $callee === 'proc_close'
            || isset(self::CLOSING_HELPERS[\strtolower($callee)]);
    }

    /**
     * Whether the variable at $at is being CONSUMED by a reaping call.
     *
     * USED ONLY BY THE RETURN TEST NOW, and that use is why it is a method.
     * `return proc_close($process);` is a function handing back an EXIT CODE,
     * and the return scan - which deliberately looks anywhere inside the
     * returned expression, because `return [$proc, $pipes];` is the tree's
     * commonest escape - read the handle's appearance there as the handle
     * escaping. It reported a correctly-closed child as long-lived. Found by a
     * fixture written for a different rule, which is the argument for having
     * fixtures at all.
     *
     * BOTH ROSTERS COUNT HERE, unlike everywhere else, and the asymmetry is
     * deliberate rather than sloppy. This method answers "is the thing being
     * returned an exit status rather than the handle", and
     * {@see BEST_EFFORT_REAPERS} members return an exit status too. Whether
     * they reap on every path is a question about the CHILD's lifetime, which
     * {@see classifyLocal()} asks separately with its own branch; conflating
     * the two here would make `return ProcessReaper::reapIfExited($h);` read
     * as the handle escaping, which it is not.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function isProcCloseArgument(array $tokens, int $at): bool
    {
        $callee = self::calleeTakingArgument($tokens, $at);

        if ($callee === null) {
            return false;
        }

        return self::isClosingCallee($callee)
            || isset(self::BEST_EFFORT_REAPERS[\strtolower($callee)]);
    }

    /**
     * The callee whose argument list the token at $at sits directly inside, or
     * null if it is not the first thing after a `(`.
     *
     * SPELLED AS `Class::method` OR `->method` WHEN IT IS ONE, because a bare
     * `terminateAndClose` would let any class of that method name count as a
     * reap. Only the trailing pair is kept: an import can rewrite everything
     * to its left and not change which function runs.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function calleeTakingArgument(array $tokens, int $at): ?string
    {
        $paren = self::prev($tokens, $at);
        if ($paren === null || self::tokenText($tokens[$paren]) !== '(') {
            return null;
        }

        $name = self::prev($tokens, $paren);
        if ($name === null || !\is_array($tokens[$name])
            || !\in_array($tokens[$name][0], [\T_STRING, \T_NAME_FULLY_QUALIFIED, \T_NAME_QUALIFIED], true)) {
            return null;
        }

        $callee = \ltrim($tokens[$name][1], '\\');
        if (\str_contains($callee, '\\')) {
            $callee = \substr($callee, \strrpos($callee, '\\') + 1);
        }

        $separator = self::prev($tokens, $name);
        if ($separator === null || !\is_array($tokens[$separator])) {
            return $callee;
        }

        if ($tokens[$separator][0] === \T_DOUBLE_COLON) {
            $class = self::prev($tokens, $separator);
            if ($class !== null && \is_array($tokens[$class])) {
                $owner = \ltrim($tokens[$class][1], '\\');
                if (\str_contains($owner, '\\')) {
                    $owner = \substr($owner, \strrpos($owner, '\\') + 1);
                }

                return $owner . '::' . $callee;
            }
        }

        if (\in_array($tokens[$separator][0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR], true)) {
            return '->' . $callee;
        }

        return $callee;
    }

    /**
     * The fds the descriptor spec names, or null when the spec is unreadable.
     *
     * THREE SPELLINGS ARE FOLLOWED and no more: an inline array literal, a
     * local variable assigned one inside the same function, and a class
     * constant declared in the same file (`self::DESCRIPTOR`, which is how
     * `Backend/CommandBackend.php` spells it). Anything else - a constant from
     * another file, a method call, a spread - is null, which the guard treats
     * as a finding rather than as an absence of one.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @param list<array{name:string,from:int,to:int}> $functions
     * @return list<int>|null
     */
    private static function specFds(array $tokens, int $open, int $close, array $functions, int $at): ?array
    {
        $arguments = self::topLevelArguments($tokens, $open, $close);
        if (!isset($arguments[1])) {
            return null;
        }

        [$from, $to] = $arguments[1];
        $first = self::next($tokens, $from - 1);
        if ($first === null || $first > $to) {
            return null;
        }

        $literal = null;

        if (self::tokenText($tokens[$first]) === '[' || (\is_array($tokens[$first]) && $tokens[$first][0] === \T_ARRAY)) {
            $literal = self::codeText($tokens, $from, $to);
        } elseif (\is_array($tokens[$first]) && $tokens[$first][0] === \T_VARIABLE) {
            $enclosing = TokenFunctionRanges::enclosing($functions, $at);
            $literal = self::nearestAssignment($tokens, $first, $tokens[$first][1], $enclosing['from'] ?? 0);
        } else {
            $doubleColon = self::next($tokens, $first);
            $name = $doubleColon === null ? null : self::next($tokens, $doubleColon);
            if ($doubleColon !== null && $name !== null && $name <= $to
                && \is_array($tokens[$doubleColon]) && $tokens[$doubleColon][0] === \T_DOUBLE_COLON
                && \is_array($tokens[$name]) && $tokens[$name][0] === \T_STRING) {
                $literal = self::constantValue($tokens, $tokens[$name][1]);
            }
        }

        if ($literal === null) {
            return null;
        }

        return self::keysOf($literal);
    }

    /**
     * The integer fds an array literal's keys name.
     *
     * A MIXED SPEC IS UNREADABLE, not half-read. PHP assigns a positional
     * element ONE GREATER THAN THE LARGEST INTEGER KEY IT HAS ASSIGNED SO FAR,
     * so in a spec that mixes the two spellings the position of an element no
     * longer tells you its fd. No such spec exists in this tree; guessing at
     * one would be the kind of confident wrong answer this class is written to
     * avoid.
     *
     * WHAT THAT SENTENCE USED TO SAY: "the next free integer key". WHAT IS
     * TRUE NOW - and it was never true, it was a wrong rule whose worked
     * examples all happened to agree with the right one. MEASURED on PHP
     * 8.3.6: `[5 => 'a', 0 => 'b', 'c']` has keys 5, 0 and **6**. "Next free"
     * predicts 1. WHY THE SENTENCE STILL EARNS ITS PLACE: the conclusion it
     * supports is unchanged and is if anything stronger - a rule that depends
     * on the running maximum is even less recoverable from an element's
     * position than one that depends on occupancy.
     *
     * A STRING-SPELLED INTEGER KEY IS AN INTEGER KEY. PHP canonicalises
     * `"2" => x` to `2 => x` (measured, 8.3.6), and refusing to read it would
     * make {@see \SugarCraft\Crush\Tests\Support\DescriptorInheritanceGuardTest::testNoDescriptorSpecInSrcIsUnreadable()}
     * red a perfectly ordinary spec while telling its author not to add an
     * exemption. Only the CANONICAL spelling converts: `"01"` and `"2 "` stay
     * strings, so the digits are round-tripped through `(int)` before being
     * believed.
     *
     * @return list<int>|null
     */
    private static function keysOf(string $literal): ?array
    {
        $elements = self::topLevelArrayElements($literal);
        if ($elements === null) {
            return null;
        }
        if ($elements === []) {
            return [];
        }

        $keyed = [];
        $positional = 0;

        foreach ($elements as $element) {
            if (\preg_match('/^\s*(?:(\d+)|\'(\d+)\'|"(\d+)")\s*=>/', $element, $key) === 1) {
                // Trailing alternation groups that did not participate are
                // simply ABSENT from $key, not empty - `??` rather than a
                // truthiness test, or a bare-integer key raises a notice.
                $digits = ($key[3] ?? '') !== '' ? $key[3] : ((($key[2] ?? '') !== '') ? $key[2] : $key[1]);
                if ((string) (int) $digits !== $digits) {
                    // `"01"` is NOT an integer key in PHP; a non-canonical
                    // spelling names a STRING key and no fd at all.
                    return null;
                }
                $keyed[] = (int) $digits;

                continue;
            }
            if (self::hasTopLevelArrow($element)) {
                // A non-integer-literal key: a constant, a variable, an
                // expression. The fd it names is not knowable from here.
                return null;
            }
            $positional++;
        }

        if ($keyed !== [] && $positional > 0) {
            return null;
        }
        if ($keyed !== []) {
            return $keyed;
        }

        return \range(0, $positional - 1);
    }

    /** Whether an array element's own top level carries a `=>`. */
    private static function hasTopLevelArrow(string $element): bool
    {
        $depth = 0;

        foreach (\token_get_all('<?php ' . $element . ';') as $token) {
            if (\is_array($token) && $token[0] === \T_DOUBLE_ARROW && $depth === 0) {
                return true;
            }
            if (!\is_string($token)) {
                continue;
            }
            if (\in_array($token, ['[', '(', '{'], true)) {
                $depth++;
            } elseif (\in_array($token, [']', ')', '}'], true)) {
                $depth--;
            }
        }

        return false;
    }

    /**
     * The source of `const NAME = ...;` declared in this file.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function constantValue(array $tokens, string $name): ?string
    {
        foreach ($tokens as $i => $token) {
            if (!\is_array($token) || $token[0] !== \T_CONST) {
                continue;
            }
            $nameAt = self::next($tokens, $i);
            if ($nameAt === null || !\is_array($tokens[$nameAt]) || $tokens[$nameAt][1] !== $name) {
                continue;
            }
            $equals = self::next($tokens, $nameAt);
            if ($equals === null || self::tokenText($tokens[$equals]) !== '=') {
                continue;
            }

            return self::untilSemicolon($tokens, $equals + 1);
        }

        return null;
    }

    /**
     * The source of the nearest `$var = ...;` before $before, floored at the
     * enclosing function's opening brace.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function nearestAssignment(array $tokens, int $before, string $variable, int $floor): ?string
    {
        for ($i = $before - 1; $i >= $floor; $i--) {
            if (!\is_array($tokens[$i]) || $tokens[$i][0] !== \T_VARIABLE || $tokens[$i][1] !== $variable) {
                continue;
            }
            $equals = self::next($tokens, $i);
            if ($equals === null || self::tokenText($tokens[$equals]) !== '=') {
                continue;
            }

            return self::untilSemicolon($tokens, $equals + 1);
        }

        return null;
    }

    /**
     * Source text from $from up to the statement's `;`, stepping over nested
     * brackets so that a `;` inside one does not end it early.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function untilSemicolon(array $tokens, int $from): ?string
    {
        for ($i = $from, $n = \count($tokens); $i < $n; $i++) {
            if (\is_string($tokens[$i]) && $tokens[$i] === ';') {
                return self::codeText($tokens, $from, $i - 1);
            }
            if (\is_string($tokens[$i]) && \in_array($tokens[$i], ['[', '('], true)) {
                $end = self::matching($tokens, $i, $tokens[$i], $tokens[$i] === '[' ? ']' : ')');
                if ($end === null) {
                    return null;
                }
                $i = $end;
            }
        }

        return null;
    }

    /**
     * The source text of the assignment target immediately left of $equals.
     *
     * Walks back to the statement boundary rather than pattern-matching, so
     * `$this->pool[$id]`, `self::$live` and a plain `$proc` are all reported
     * as themselves and the caller decides what they mean.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function assignmentTarget(array $tokens, int $equals, int $floor): ?string
    {
        $parts = [];

        for ($i = $equals - 1; $i >= $floor; $i--) {
            $token = $tokens[$i];

            if (\is_string($token) && \in_array($token, [';', '{', '}', '('], true)) {
                break;
            }
            if (\is_array($token) && \in_array($token[0], [\T_OPEN_TAG, \T_RETURN, \T_ECHO], true)) {
                break;
            }
            if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            $parts[] = self::tokenText($token);
        }

        $target = \trim(\implode('', \array_reverse($parts)));

        return $target === '' ? null : $target;
    }

    /**
     * The token spans of the call's top-level arguments.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return list<array{0:int,1:int}>
     */
    private static function topLevelArguments(array $tokens, int $open, int $close): array
    {
        $args = [];
        $depth = 0;
        $start = $open + 1;

        for ($i = $open + 1; $i < $close; $i++) {
            $token = $tokens[$i];
            if (\is_array($token) && \in_array($token[0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                // `"{$x}"` opens with an ARRAY token and closes with a plain
                // `}`; counting only the closer sends the depth negative and
                // every later top-level comma stops being seen.
                $depth++;

                continue;
            }
            if (!\is_string($token)) {
                continue;
            }
            if (\in_array($token, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (\in_array($token, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($token === ',' && $depth === 0) {
                $args[] = [$start, $i - 1];
                $start = $i + 1;
            }
        }
        $args[] = [$start, $close - 1];

        return $args;
    }

    /**
     * The source text of each top-level element of an array literal, or null
     * if $literal is not one.
     *
     * @return list<string>|null
     */
    private static function topLevelArrayElements(string $literal): ?array
    {
        $tokens = \token_get_all('<?php ' . $literal . ';');
        $count = \count($tokens);
        $start = null;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (\is_array($token) && \in_array($token[0], [\T_OPEN_TAG, \T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            if (self::tokenText($token) === '[') {
                $start = $i + 1;
            } elseif (\is_array($token) && $token[0] === \T_ARRAY) {
                $next = self::next($tokens, $i);
                if ($next === null || self::tokenText($tokens[$next]) !== '(') {
                    return null;
                }
                $start = $next + 1;
            }

            break;
        }

        if ($start === null) {
            return null;
        }

        $elements = [];
        $current = '';
        $depth = 0;

        for ($i = $start; $i < $count; $i++) {
            $text = self::tokenText($tokens[$i]);

            if ($depth === 0 && ($text === ']' || $text === ')')) {
                $current = \trim($current);
                if ($current !== '') {
                    $elements[] = $current;
                }

                return $elements;
            }

            if ($text === '[' || $text === '(') {
                $depth++;
            } elseif ($text === ']' || $text === ')') {
                $depth--;
            }

            if ($depth === 0 && $text === ',') {
                $elements[] = \trim($current);
                $current = '';

                continue;
            }

            $current .= $text;
        }

        // Ran off the end without closing - not something to guess about.
        return null;
    }

    /** @param list<array{name:string,from:int,to:int}> $functions */
    private static function functionName(array $functions, int $at): string
    {
        return TokenFunctionRanges::enclosing($functions, $at)['name'] ?? '';
    }

    /**
     * Source text with comments removed.
     *
     * Prose about a descriptor is not a descriptor: a doc-block naming
     * `2 => ['pipe','w']` sits in the token stream exactly where the code
     * would, and reading it as code is a scanner grading its own comments.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function codeText(array $tokens, int $from, int $to): string
    {
        $out = '';
        for ($i = $from; $i <= $to; $i++) {
            if (\is_array($tokens[$i]) && \in_array($tokens[$i][0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= self::tokenText($tokens[$i]);
        }

        return $out;
    }

    /**
     * NAMED TO MATCH {@see ChildStderrCaptureScanner}, not to taste.
     *
     * `codeText()` above is byte-identical to that class's, and
     * {@see DuplicatedTestHelperDriftTest} exists to catch a private helper
     * copied between two files and then fixed in only one - which it detects
     * as two bodies agreeing except for a single token. Calling this one
     * `text()` made the two `codeText()` bodies differ by exactly that one
     * token and the guard red, correctly: a one-token difference between
     * copies is indistinguishable from a half-applied fix. Renaming it back
     * re-arms that detector.
     *
     * @param array{0:int,1:string,2:int}|string $token
     */
    private static function tokenText(array|string $token): string
    {
        return \is_array($token) ? $token[1] : $token;
    }

    /**
     * The next significant token.
     *
     * `@` IS SKIPPED, and it has to be. `@proc_open(...)` is the dominant
     * spelling in this tree, so a backwards walk that stops at the
     * error-suppression operator finds `@` where it expects `=` and reports
     * the site as unclassified.
     *
     * MEASURED, NOT ASSUMED, on PHP 8.3.6: with this skip deleted, EVERY
     * `@`-suppressed site under `src/` collapsed to `unclassified` - long-lived
     * ones included - while the sites spelled without `@` were unaffected. The
     * proportion is deliberately not written down: a cardinality taken over
     * `src/` is wrong by the next merge, and the mutation IS the measurement.
     * {@see ChildLifetimeScannerFixtureTest} pins the behaviour itself.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function next(array $tokens, int $from): ?int
    {
        for ($i = $from + 1, $n = \count($tokens); $i < $n; $i++) {
            if (self::skippable($tokens[$i])) {
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
            if (self::skippable($tokens[$i])) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /** @param array{0:int,1:string,2:int}|string $token */
    private static function skippable(array|string $token): bool
    {
        if (\is_string($token)) {
            return $token === '@';
        }

        return \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true);
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function matching(array $tokens, int $openAt, string $open, string $close): ?int
    {
        $depth = 0;
        for ($i = $openAt, $n = \count($tokens); $i < $n; $i++) {
            if (!\is_string($tokens[$i])) {
                if ($open === '{' && \is_array($tokens[$i])
                    && \in_array($tokens[$i][0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $depth++;
                }

                continue;
            }
            if ($tokens[$i] === $open) {
                $depth++;
            } elseif ($tokens[$i] === $close) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }
}
