<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config\Support;

/**
 * WHICH `SUGARCRUSH_*` VARIABLES THE CODE ACTUALLY READS, BY TOKEN STREAM.
 *
 * `grep` cannot answer this question. Every prefixed name in `src/` that a
 * `grep` finds is a mixture of three different things — a real read, a mention
 * inside a doc-comment explaining a read, and a name printed back at the user
 * inside an error string — and only the first belongs on `docs/ENVIRONMENT.md`.
 * A token stream separates them for free: `T_DOC_COMMENT` and `T_COMMENT` are
 * their own tokens, so prose drops out before any matching happens, and what
 * is left is a string LITERAL whose syntactic position can be classified.
 *
 * FOUR SHAPES ARE UNDERSTOOD, and each one is verified rather than assumed —
 * the scanner never trusts a naming convention, it follows the value:
 *
 * - **S1 direct** — the literal is the argument of `getenv(` (or `\getenv(`).
 * - **S3 forwarded** — the literal is argument #k of `self::m(…)`,
 *   `static::m(…)` or `$this->m(…)`; `m` is located in the same file, its
 *   parameter #k is read out of the signature, and that parameter must itself
 *   reach `getenv()` in `m`'s body. `Chat::envFlag()`,
 *   `Bootstrap::backendCommandEnv()` and `Bootstrap::toollessBackend()` are all
 *   found this way, none of them by name.
 * - **S4 foreach** — the literal is an element of an array literal in a
 *   `foreach ([…] as $v)` header whose `$v` reaches `getenv()` in the same
 *   function. This is how the two deprecated-alias pairs are read.
 * - **S2 constant** — the literal is the initialiser of a class constant. The
 *   DECLARATION is not itself a read; instead every `Foo::CONST` occurrence is
 *   re-classified through S1/S3/S4, so a constant that is declared and never
 *   passed to `getenv()` is reported as unresolved rather than counted.
 *   `TerminalBackground::ENV_OVERRIDE` is the case that forced this: it is
 *   never a direct `getenv()` argument, it is an element of an S4 array.
 *
 * ANYTHING ELSE IS AN ERROR, NOT A SKIP. A prefixed occurrence the scanner
 * cannot place, and whose name no other occurrence resolves, lands in
 * {@see unresolved()} — a list the caller is expected to assert is empty. A
 * scanner that silently dropped what it could not parse would have a hole
 * shaped exactly like the next variable someone reads through a fifth shape.
 *
 * MEASURED ON PHP 8.3.6. `token_get_all()`'s categories for the constructs used
 * here (`T_CONSTANT_ENCAPSED_STRING`, `T_NAME_FULLY_QUALIFIED` for `\getenv`)
 * are stable across 8.3 and 8.4; the stamp is provenance, and CI runs both.
 *
 * @internal
 */
final class EnvReadScanner
{
    /**
     * The roster's own alphabet — and it deliberately admits the DEPRECATED
     * spelling `SUGAR_CRUSH_*` as well as the canonical `SUGARCRUSH_*`.
     *
     * A pattern that only matched the canonical prefix would report zero
     * problems while the two legacy aliases in `WorktreeManager` and
     * `ShareCommand` went undocumented — a census cannot find what its alphabet
     * cannot spell.
     */
    public const NAME_PATTERN = '/^SUGAR_?CRUSH_[A-Z0-9_]+$/';

    /** @var array<string, list<string>> name => "label:line [shape]" */
    private array $reads = [];

    /** @var list<array{0: string, 1: string}> [name, "label:line"] */
    private array $unplaced = [];

    /** @var array<string, string> CONSTNAME => literal value */
    private array $constValue = [];

    /** @var array<string, list<string>> name => sites of a `putenv('NAME=…')`-shaped literal */
    private array $exported = [];

    /** @var list<string> literals that look like a roster name cut in half */
    private array $fragments = [];

    /** @param array<string, string> $sources label => PHP source text */
    public function __construct(private readonly array $sources)
    {
        foreach ($this->sources as $src) {
            $this->collectConstants($src);
        }
        foreach ($this->sources as $label => $src) {
            $this->scan($src, $label);
            $this->scanForWritesAndFragments($src, $label);
        }
    }

    /**
     * Names this code EXPORTS rather than reads — `putenv('NAME=' . $value)`.
     *
     * A write is not a read and does not belong in {@see reads()}, but it is
     * still a roster name, and the token scan cannot see it from the read side
     * because the literal carries a trailing `=`. `BackgroundSessionRunner`
     * exports `SUGARCRUSH_MODEL` to the daemon it spawns, and a future export
     * of a name the page does not tabulate would otherwise be invisible to
     * every assertion here.
     *
     * @return array<string, list<string>>
     */
    public function exported(): array
    {
        $out = $this->exported;
        ksort($out);

        return $out;
    }

    /**
     * Literals that look like a roster name assembled from pieces.
     *
     * THE ONE THING THIS SCANNER GENUINELY CANNOT FOLLOW is a name built at
     * runtime — `getenv('SUGARCRUSH_' . $suffix)` would be read by the code and
     * invisible to every pattern here, because `'SUGARCRUSH_'` on its own does
     * not match {@see NAME_PATTERN}. Rather than leave that as a caveat in
     * prose, the fragments are collected so the caller can assert there are
     * none: the blind spot is measured empty rather than assumed empty.
     *
     * @return list<string>
     */
    public function fragments(): array
    {
        $out = $this->fragments;
        sort($out);

        return $out;
    }

    private function scanForWritesAndFragments(string $src, string $label): void
    {
        foreach (self::significant($src) as $token) {
            if ($token['id'] !== \T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $value = self::literal($token['text']);
            if ($value === null || !str_starts_with($value, 'SUGAR')) {
                continue;
            }
            if (preg_match(self::NAME_PATTERN, $value) === 1) {
                continue;
            }
            if (preg_match('/^(SUGAR_?CRUSH_[A-Z0-9_]+)=/', $value, $matches) === 1) {
                $this->exported[$matches[1]][] = $label . ':' . $token['line'];

                continue;
            }
            $this->fragments[] = $label . ':' . $token['line'] . ': ' . $value;
        }
    }

    /** @return array<string, list<string>> variable name => the sites that read it */
    public function reads(): array
    {
        $out = $this->reads;
        ksort($out);

        return $out;
    }

    /**
     * Occurrences the scanner could not place, and constants it could not
     * follow to a `getenv()`.
     *
     * An occurrence is only listed when NO occurrence of the same name
     * resolved: a variable read once in `getenv()` and mentioned again inside
     * an exception message is read, and the exception message is not a defect.
     *
     * @return list<string>
     */
    public function unresolved(): array
    {
        $out = [];
        foreach ($this->unplaced as [$name, $site]) {
            if (!isset($this->reads[$name])) {
                $out[] = $site . ': ' . $name . ' — occurrence matches no understood shape';
            }
        }
        foreach ($this->constValue as $constant => $value) {
            if (!isset($this->reads[$value])) {
                $out[] = 'const ' . $constant . ' = ' . $value . ' — declared, but no occurrence reaches getenv()';
            }
        }
        sort($out);

        return $out;
    }

    /** @return list<array{text: string, id: int, line: int}> */
    public static function significant(string $src): array
    {
        $sig = [];
        foreach (token_get_all($src) as $token) {
            if (\is_array($token)) {
                if (\in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                    continue;
                }
                $sig[] = ['text' => $token[1], 'id' => $token[0], 'line' => $token[2]];
            } else {
                $sig[] = ['text' => $token, 'id' => 0, 'line' => 0];
            }
        }

        return $sig;
    }

    private function collectConstants(string $src): void
    {
        $sig = self::significant($src);
        foreach ($sig as $i => $token) {
            if ($token['id'] !== \T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $value = self::literal($token['text']);
            if ($value === null || preg_match(self::NAME_PATTERN, $value) !== 1) {
                continue;
            }
            if (($sig[$i - 1]['text'] ?? '') === '=' && ($sig[$i - 3]['text'] ?? '') === 'const') {
                $this->constValue[$sig[$i - 2]['text']] = $value;
            }
        }
    }

    private function scan(string $src, string $label): void
    {
        $sig = self::significant($src);
        $n = \count($sig);

        for ($i = 0; $i < $n; $i++) {
            $first = $i;

            if ($sig[$i]['id'] === \T_CONSTANT_ENCAPSED_STRING) {
                $value = self::literal($sig[$i]['text']);
                if ($value === null || preg_match(self::NAME_PATTERN, $value) !== 1) {
                    continue;
                }
                // The constant's DECLARATION is not a read; its uses are.
                if (($sig[$i - 1]['text'] ?? '') === '=' && ($sig[$i - 3]['text'] ?? '') === 'const') {
                    continue;
                }
            } elseif (
                $sig[$i]['id'] === \T_STRING
                && ($sig[$i - 1]['text'] ?? '') === '::'
                && isset($this->constValue[$sig[$i]['text']])
            ) {
                $value = $this->constValue[$sig[$i]['text']];
                $first = $i - 2;
            } else {
                continue;
            }

            $site = $label . ':' . $sig[$i]['line'];
            $shape = $this->classify($sig, $first, $n);
            if ($shape === null) {
                $this->unplaced[] = [$value, $site];

                continue;
            }
            $this->reads[$value][] = $site . ' [' . $shape . ']';
        }
    }

    private static function literal(string $token): ?string
    {
        $quote = $token[0] ?? '';
        if ($quote !== "'" && $quote !== '"') {
            return null;
        }

        return substr($token, 1, -1);
    }

    private static function isGetenv(string $text): bool
    {
        return strtolower(ltrim($text, '\\')) === 'getenv';
    }

    private function classify(array $sig, int $i, int $n): ?string
    {
        if (($sig[$i - 1]['text'] ?? '') === '(' && self::isGetenv($sig[$i - 2]['text'] ?? '')) {
            return 'S1-direct';
        }

        [$bodyStart, $bodyEnd] = $this->enclosingFunctionBody($sig, $i, $n);
        if ($bodyStart === null || $bodyEnd === null) {
            return null;
        }

        $loopVar = $this->foreachLoopVariable($sig, $i, $n);
        if ($loopVar !== null) {
            return $this->reachesGetenv($sig, $bodyStart, $bodyEnd, $loopVar)
                ? 'S4-foreach:' . $loopVar
                : null;
        }

        $call = $this->enclosingCall($sig, $i);
        if ($call !== null) {
            [$callee, $argIndex] = $call;
            $method = $this->methodSignature($sig, $n, $callee);
            if ($method !== null && isset($method['params'][$argIndex])) {
                $reached = $this->reachesGetenv(
                    $sig,
                    $method['start'],
                    $method['end'],
                    $method['params'][$argIndex],
                );
                if ($reached) {
                    return 'S3-forward:' . $callee . '#' . $argIndex;
                }
            }
        }

        return null;
    }

    /**
     * Does `$var` reach `getenv()` inside the body `[$start, $end)`?
     *
     * One extra hop is followed — `$var` handed to another method of the same
     * file that forwards it — because `backendCommandTierIsSelected()` reaches
     * `getenv()` exactly that way: a `foreach` over two literals calling
     * `self::backendCommandEnv($var)`. The depth is bounded so a pair of
     * mutually-forwarding methods cannot spin.
     */
    private function reachesGetenv(array $sig, int $start, int $end, string $var, int $depth = 0): bool
    {
        for ($j = $start; $j < $end; $j++) {
            if (
                self::isGetenv($sig[$j]['text'])
                && ($sig[$j + 1]['text'] ?? '') === '('
                && ($sig[$j + 2]['text'] ?? '') === $var
                && ($sig[$j + 3]['text'] ?? '') === ')'
            ) {
                return true;
            }
            if ($depth >= 2 || $sig[$j]['text'] !== $var) {
                continue;
            }
            $call = $this->enclosingCall($sig, $j);
            if ($call === null) {
                continue;
            }
            [$callee, $argIndex] = $call;
            $method = $this->methodSignature($sig, \count($sig), $callee);
            if ($method === null || !isset($method['params'][$argIndex])) {
                continue;
            }
            $forwarded = $this->reachesGetenv(
                $sig,
                $method['start'],
                $method['end'],
                $method['params'][$argIndex],
                $depth + 1,
            );
            if ($forwarded) {
                return true;
            }
        }

        return false;
    }

    /** The `$v` of a `foreach ([… , HERE, …] as $v)` header, or null. */
    private function foreachLoopVariable(array $sig, int $i, int $n): ?string
    {
        $depth = 0;
        for ($j = $i - 1; $j >= 0; $j--) {
            $text = $sig[$j]['text'];
            if ($text === ']' || $text === ')') {
                $depth++;

                continue;
            }
            if ($text === '[') {
                if ($depth === 0) {
                    break;
                }
                $depth--;

                continue;
            }
            if ($text === '(') {
                if ($depth === 0) {
                    return null;
                }
                $depth--;

                continue;
            }
            if ($text === ';' || $text === '{' || $text === '}') {
                return null;
            }
        }
        if ($j < 0) {
            return null;
        }
        if (($sig[$j - 1]['text'] ?? '') !== '(' || strtolower($sig[$j - 2]['text'] ?? '') !== 'foreach') {
            return null;
        }

        $depth = 0;
        for ($k = $j; $k < $n; $k++) {
            if ($sig[$k]['text'] === '[') {
                $depth++;
            } elseif ($sig[$k]['text'] === ']') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
        }
        if (strtolower($sig[$k + 1]['text'] ?? '') !== 'as') {
            return null;
        }
        $var = $sig[$k + 2]['text'] ?? '';

        return str_starts_with($var, '$') ? $var : null;
    }

    /** @return array{0: string, 1: int}|null the callee's bare name and this argument's index */
    private function enclosingCall(array $sig, int $i): ?array
    {
        $depth = 0;
        $commas = 0;
        for ($j = $i - 1; $j >= 0; $j--) {
            $text = $sig[$j]['text'];
            if ($text === ')' || $text === ']') {
                $depth++;

                continue;
            }
            if ($text === '(') {
                if ($depth === 0) {
                    break;
                }
                $depth--;

                continue;
            }
            if ($text === '[') {
                if ($depth === 0) {
                    return null;
                }
                $depth--;

                continue;
            }
            if ($text === ',' && $depth === 0) {
                $commas++;

                continue;
            }
            if ($text === ';' || $text === '{' || $text === '}') {
                return null;
            }
        }
        if ($j < 0) {
            return null;
        }
        $name = $sig[$j - 1]['text'] ?? '';
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            return null;
        }
        $operator = $sig[$j - 2]['text'] ?? '';
        if ($operator !== '::' && $operator !== '->') {
            return null;
        }

        return [$name, $commas];
    }

    /** @return array{params: array<int, string>, start: int, end: int}|null */
    private function methodSignature(array $sig, int $n, string $name): ?array
    {
        for ($j = 0; $j < $n; $j++) {
            if (strtolower($sig[$j]['text']) !== 'function' || ($sig[$j + 1]['text'] ?? '') !== $name) {
                continue;
            }
            if (($sig[$j + 2]['text'] ?? '') !== '(') {
                continue;
            }
            $params = [];
            $depth = 0;
            $index = 0;
            for ($k = $j + 2; $k < $n; $k++) {
                $text = $sig[$k]['text'];
                if ($text === '(') {
                    $depth++;

                    continue;
                }
                if ($text === ')') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }

                    continue;
                }
                if ($text === ',' && $depth === 1) {
                    $index++;

                    continue;
                }
                if ($depth === 1 && $sig[$k]['id'] === \T_VARIABLE && !isset($params[$index])) {
                    $params[$index] = $text;
                }
            }
            $body = $this->braceRange($sig, $k, $n);
            if ($body === null) {
                return null;
            }

            return ['params' => $params, 'start' => $body[0], 'end' => $body[1]];
        }

        return null;
    }

    /** @return array{0: int, 1: int}|null */
    private function braceRange(array $sig, int $from, int $n): ?array
    {
        for ($b = $from; $b < $n; $b++) {
            if ($sig[$b]['text'] === '{' || $sig[$b]['text'] === ';') {
                break;
            }
        }
        if (($sig[$b]['text'] ?? '') !== '{') {
            return null;
        }
        $depth = 0;
        for ($e = $b; $e < $n; $e++) {
            if ($sig[$e]['text'] === '{') {
                $depth++;
            } elseif ($sig[$e]['text'] === '}') {
                $depth--;
                if ($depth === 0) {
                    return [$b, $e];
                }
            }
        }

        return null;
    }

    /** @return array{0: ?int, 1: ?int} */
    private function enclosingFunctionBody(array $sig, int $i, int $n): array
    {
        for ($j = $i; $j >= 0; $j--) {
            if (strtolower($sig[$j]['text']) !== 'function') {
                continue;
            }
            $body = $this->braceRange($sig, $j, $n);
            if ($body === null) {
                continue;
            }
            if ($i > $body[0] && $i < $body[1]) {
                return [$body[0], $body[1]];
            }
        }

        return [null, null];
    }
}
