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
     *         fds:list<int>|null, highFds:list<int>
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
            if ($open === null || self::text($tokens[$open]) !== '(') {
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

            [$lifetime, $reason] = self::classifyLifetime($tokens, $i, $close, $functions);
            $fds = self::specFds($tokens, $open, $close, $functions, $i);

            $sites[] = [
                'line' => $token[2],
                'function' => self::functionName($functions, $i),
                'lifetime' => $lifetime,
                'reason' => $reason,
                'fds' => $fds,
                'highFds' => $fds === null ? [] : \array_values(\array_filter($fds, static fn (int $fd): bool => $fd >= 3)),
            ];
        }

        return ['sites' => $sites, 'unresolved' => $unresolved];
    }

    /**
     * Whether the child outlives the call.
     *
     * THE ESCAPE TEST RUNS BEFORE THE CLOSE TEST, deliberately. A function that
     * both stores the handle on `$this` AND calls `proc_close()` on some path
     * has a child that can outlive the call, and reading the `proc_close()` as
     * proof of a short life is the polarity that hides a leak. Escape wins.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @param list<array{name:string,from:int,to:int}> $functions
     * @return array{0:string,1:string}
     */
    private static function classifyLifetime(array $tokens, int $at, int $close, array $functions): array
    {
        $enclosing = TokenFunctionRanges::enclosing($functions, $at);
        $floor = $enclosing['from'] ?? 0;
        $end = $enclosing['to'] ?? \count($tokens) - 1;

        $prev = self::prev($tokens, $at);
        if ($prev !== null && \is_array($tokens[$prev]) && $tokens[$prev][0] === \T_RETURN) {
            return [self::LIFETIME_LONG, 'the handle is returned directly'];
        }

        if ($prev === null || self::text($tokens[$prev]) !== '=') {
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

        return self::classifyLocal($tokens, $close, $end, $target);
    }

    /**
     * The fate of a handle that lands in a plain local variable.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return array{0:string,1:string}
     */
    private static function classifyLocal(array $tokens, int $from, int $end, string $variable): array
    {
        $closedUnconditionally = false;
        $closedSomewhere = false;
        $depth = 0;
        $floor = 0;

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
            } elseif (\is_array($token)
                && \in_array($token[0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            } elseif (\is_string($token) && $token === '}') {
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
            if ($equals !== null && self::text($tokens[$equals]) === '=') {
                $target = self::assignmentTarget($tokens, $equals, 0);
                if ($target !== null && (\str_contains($target, '->') || \str_contains($target, '::'))) {
                    return [self::LIFETIME_LONG, 'the handle in ' . $variable . ' is stored in ' . $target];
                }
            }

            if (self::isProcCloseArgument($tokens, $i)) {
                $closedSomewhere = true;
                if ($depth === $floor) {
                    $closedUnconditionally = true;
                }
            }
        }

        if ($closedUnconditionally) {
            return [
                self::LIFETIME_SHORT,
                'proc_close(' . $variable . ') runs unconditionally in the same function',
            ];
        }

        if ($closedSomewhere) {
            return [
                self::LIFETIME_UNCLASSIFIED,
                'proc_close(' . $variable . ') runs only inside a nested block, so it does not '
                    . 'cover every path out of this function',
            ];
        }

        return [
            self::LIFETIME_UNCLASSIFIED,
            'nothing in this function returns, stores or proc_close()s ' . $variable,
        ];
    }

    /**
     * Whether the variable at $at is the argument of a `proc_close()`.
     *
     * SHARED BY THE CLOSE TEST AND THE RETURN TEST, and the second use is why
     * it is a method. `return proc_close($process);` is a function handing
     * back an EXIT CODE, and the return scan - which deliberately looks
     * anywhere inside the returned expression, because `return [$proc,
     * $pipes];` is the tree's commonest escape - read the handle's appearance
     * there as the handle escaping. It reported a correctly-closed child as
     * long-lived. Found by a fixture written for a different rule, which is
     * the argument for having fixtures at all.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function isProcCloseArgument(array $tokens, int $at): bool
    {
        $paren = self::prev($tokens, $at);
        if ($paren === null || self::text($tokens[$paren]) !== '(') {
            return false;
        }

        $callee = self::prev($tokens, $paren);

        return $callee !== null
            && \is_array($tokens[$callee])
            && \in_array($tokens[$callee][0], [\T_STRING, \T_NAME_FULLY_QUALIFIED], true)
            && \ltrim(\strtolower($tokens[$callee][1]), '\\') === 'proc_close';
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

        if (self::text($tokens[$first]) === '[' || (\is_array($tokens[$first]) && $tokens[$first][0] === \T_ARRAY)) {
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
     * element the next free integer key, so in a spec that mixes the two
     * spellings the position of an element no longer tells you its fd. No
     * such spec exists in this tree; guessing at one would be the kind of
     * confident wrong answer this class is written to avoid.
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
            if (\preg_match('/^\s*(\d+)\s*=>/', $element, $key) === 1) {
                $keyed[] = (int) $key[1];

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
            if ($equals === null || self::text($tokens[$equals]) !== '=') {
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
            if ($equals === null || self::text($tokens[$equals]) !== '=') {
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

            $parts[] = self::text($token);
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

            if (self::text($token) === '[') {
                $start = $i + 1;
            } elseif (\is_array($token) && $token[0] === \T_ARRAY) {
                $next = self::next($tokens, $i);
                if ($next === null || self::text($tokens[$next]) !== '(') {
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
            $text = self::text($tokens[$i]);

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
            $out .= self::text($tokens[$i]);
        }

        return $out;
    }

    /** @param array{0:int,1:string,2:int}|string $token */
    private static function text(array|string $token): string
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
