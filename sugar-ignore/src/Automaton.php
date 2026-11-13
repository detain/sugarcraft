<?php

declare(strict_types=1);

namespace SugarCraft\Ignore;

/**
 * Backtrack-free matcher for one {@see Pattern}, built lazily by subset
 * construction.
 *
 * Accepts exactly the language of the pattern's `#^…$#` regex, quirks
 * included — `**` (`.*`) does not cross `\n`, and `$` also matches before one
 * trailing `\n` — so which matcher runs never changes a verdict.
 *
 * NFA state k is "before token k", m is accept, and m+1+k is "inside a name of
 * the `(?:[^/]+/)*` at k". Each set of live NFA states is interned as one DFA
 * state the first time it is reached and every transition is cached, so a
 * fresh byte costs O(tokens) and a repeat costs one array lookup.
 *
 * Refuses (null) only inputs whose path × pattern product exceeds
 * {@see MATCH_BUDGET}: such a rule is undecidable rather than "not matching",
 * and the caller resolves it toward HIDING.
 */
final class Automaton
{
    /**
     * Upper bound on path length × pattern tokens one match may cost.
     *
     * Sized so no plausible rule reaches it — a 200-token pattern against a
     * PATH_MAX (4,096-byte) path is 820k — while a pathological line, which is
     * all that can, is refused before any work rather than after it.
     */
    public const MATCH_BUDGET = 1_000_000;

    /** DFA states one rule's automaton may intern before it is rebuilt. */
    private const STATE_CAP = 4096;

    /** @var array{ids: array<string, int>, sets: list<list<int>>, accepts: list<bool>, next: array<int, array<string, int>>} */
    private array $automaton = ['ids' => [], 'sets' => [], 'accepts' => [], 'next' => []];

    private function __construct(private readonly Pattern $pattern) {}

    public static function for(Pattern $pattern): self
    {
        return new self($pattern);
    }

    /**
     * Match the WHOLE of $subject; null when it cannot be decided in budget.
     */
    public function match(string $subject): ?bool
    {
        $tokens = $this->pattern->tokens;
        $m = count($tokens);
        $n = strlen($subject);
        if ($n * ($m + 1) > self::MATCH_BUDGET) {
            return null;
        }

        if ($this->automaton['sets'] === [] || count($this->automaton['sets']) > self::STATE_CAP) {
            // A cap rather than an unbounded cache: the subsets are at most
            // exponential in the pattern, and starting over loses only speed.
            $this->automaton = ['ids' => [], 'sets' => [], 'accepts' => [], 'next' => []];
            $start = [];
            self::close($start, 0, $tokens, $m);
            self::intern($this->automaton, $start, $m);
        }
        $automaton = &$this->automaton;

        $state = 0;
        for ($i = 0; $i < $n; $i++) {
            $byte = $subject[$i];
            if ($i === $n - 1 && $byte === "\n" && $automaton['accepts'][$state]) {
                return true;
            }
            if (!isset($automaton['next'][$state][$byte])) {
                $live = self::step($automaton['sets'][$state], $byte, $tokens, $m);
                if ($live === null) {
                    return null;
                }
                $automaton['next'][$state][$byte] = self::intern($automaton, $live, $m);
            }
            $state = $automaton['next'][$state][$byte];
            if ($automaton['sets'][$state] === []) {
                return false;
            }
        }

        return $automaton['accepts'][$state];
    }

    /**
     * The NFA states live after $byte, from the live set $states; null when a
     * `[...]` class could not be tested.
     *
     * A class is still tested by PCRE, one byte against one class — which
     * cannot backtrack, but CAN still report a limit error when the limit is
     * set absurdly low. That is an unknown, not a "no", so it is passed up to
     * fail closed rather than guessed.
     *
     * @param list<int> $states
     * @param list<array{0: int, 1: string}> $tokens
     * @return array<int, true>|null
     */
    private static function step(array $states, string $byte, array $tokens, int $m): ?array
    {
        $next = [];
        foreach ($states as $state) {
            if ($state === $m) {
                continue;
            }
            if ($state > $m) {
                // Inside one `[^/]+/` iteration: more name, or the `/` that
                // returns to the boundary before the same token.
                if ($byte === '/') {
                    self::close($next, $state - $m - 1, $tokens, $m);
                } else {
                    $next[$state] = true;
                }
                continue;
            }

            [$type, $payload] = $tokens[$state];
            if ($type === Token::DIRS) {
                if ($byte !== '/') {
                    $next[$m + 1 + $state] = true;
                }
                continue;
            }
            if ($type === Token::CLASS_) {
                $inClass = preg_match('#' . $payload . '#', $byte);
                if ($inClass === false) {
                    return null;
                }
                if ($inClass === 1) {
                    self::close($next, $state + 1, $tokens, $m);
                }
                continue;
            }
            $advance = match ($type) {
                Token::LITERAL => $byte === $payload ? $state + 1 : null,
                Token::ONE => $byte !== '/' ? $state + 1 : null,
                Token::STAR => $byte !== '/' ? $state : null,
                Token::GLOBSTAR => $byte !== "\n" ? $state : null,
                default => null,
            };
            if ($advance !== null) {
                self::close($next, $advance, $tokens, $m);
            }
        }

        return $next;
    }

    /**
     * Add $state and every state reachable from it without consuming a byte:
     * a zero-length `*`, `**` or `(?:[^/]+/)*` steps straight to the next.
     *
     * @param array<int, true> $set
     * @param list<array{0: int, 1: string}> $tokens
     */
    private static function close(array &$set, int $state, array $tokens, int $m): void
    {
        while (!isset($set[$state])) {
            $set[$state] = true;
            if ($state >= $m) {
                return;
            }
            $type = $tokens[$state][0];
            if ($type !== Token::STAR && $type !== Token::GLOBSTAR && $type !== Token::DIRS) {
                return;
            }
            $state++;
        }
    }

    /**
     * The DFA state id for NFA set $set, creating it on first sight.
     *
     * @param array{ids: array<string, int>, sets: list<list<int>>, accepts: list<bool>, next: array<int, array<string, int>>} $automaton
     * @param array<int, true> $set
     */
    private static function intern(array &$automaton, array $set, int $m): int
    {
        $states = array_keys($set);
        sort($states);
        $key = implode(',', $states);
        if (isset($automaton['ids'][$key])) {
            return $automaton['ids'][$key];
        }

        $id = count($automaton['sets']);
        $automaton['ids'][$key] = $id;
        $automaton['sets'][] = $states;
        $automaton['accepts'][] = isset($set[$m]);

        return $id;
    }
}
