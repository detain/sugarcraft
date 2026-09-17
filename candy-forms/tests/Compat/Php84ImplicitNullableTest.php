<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Compat;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * PHP 8.4 readiness guard (E736 plan 7.2).
 *
 * PHP 8.4 deprecates implicitly nullable parameter types (`Type $x = null`
 * without `?`) and removes E_STRICT. This static guard runs on 8.3 today
 * and becomes a hard compatibility pin the moment CI moves.
 *
 * The scan is lexical (token stream, comments stripped) rather than
 * reflection-based so attributes, union types and `null` literals inside
 * defaults are all visible. The one structural limitation — recorded here
 * per the plan's honesty mandate — is that a type clause is recognised as
 * only the contiguous name/union/intersection tokens immediately before
 * the parameter variable; exotic whitespace inside a type would make the
 * parameter look untyped (permissive), never the reverse for our own code
 * style.
 */
final class Php84ImplicitNullableTest extends TestCase
{
    /**
     * PENDING REMOVALS — sites this guard intentionally tolerates because
     * lane u2 (same wave) deletes the whole dead async-suggestions
     * machinery (fetcher + WorkerPool parameters). Roster rows are exact
     * `method:param` pairs, so tolerating these can never mask an
     * unrelated implicit-nullable parameter in the same method.
     *
     * Weld-safe by construction: a one-way allowlist. When the removal
     * lands the entries merely stop matching (permissive direction —
     * never red); a NEW implicit-nullable site can never slip in silently
     * because it cannot already be listed. Staleness of this roster is
     * policed by the wave closeout, not by a red test in the other lane.
     *
     * @var array<string, list<string>>
     */
    private const PENDING_REMOVALS = [
        'src/Field/Input.php'  => [
            '__construct:asyncSuggestionsFetcher',
            '__construct:workerPool',
            'withAsyncSuggestions:workerPool',
            'async:workerPool',
        ],
        'src/Field/Select.php' => [
            '__construct:asyncSuggestionsFetcher',
            '__construct:workerPool',
            'withAsyncSuggestions:workerPool',
            'async:workerPool',
        ],
    ];

    public function testNoImplicitNullableParametersOutsidePendingRemovals(): void
    {
        $offenders = [];
        foreach ($this->sourceFiles() as $relative => $tokens) {
            $allowed = self::PENDING_REMOVALS[$relative] ?? [];
            foreach ($this->implicitNullableParams($tokens) as $name) {
                if (!in_array($name, $allowed, true)) {
                    $offenders[] = $relative . '::' . $name;
                }
            }
        }
        $this->assertSame(
            [],
            $offenders,
            'PHP 8.4 deprecates implicit nullable parameter types — write ?Type $x = null.',
        );
    }

    public function testPositiveControlDetectsADeliberateViolation(): void
    {
        // Proves the walker is not vacuously green: the same detector run
        // over a synthetic buffer must flag exactly the shaped violations.
        $code = <<<'PHP'
            <?php
            class Probe {
                public function okExplicitlyNullable(?Foo $a = null): void {}
                public function okUnionWithNull(Foo|null $b = null): void {}
                public function okNoDefault(Foo $c): void {}
                public function okMixed(mixed $d = null): void {}
                public function bad(Bar $e = null, Baz $f = null): void {}
            }
            PHP;
        $names = $this->implicitNullableParams(\PhpToken::tokenize($code));
        // Both bad params reported as `method:param`; compliant shapes not.
        $this->assertSame(['bad:e', 'bad:f'], $names);
    }

    public function testNoEStrictReferencesInSource(): void
    {
        $hits = [];
        foreach ($this->sourceFiles() as $relative => $tokens) {
            foreach ($tokens as $token) {
                if ($token->is(T_STRING) && $token->text === 'E_STRICT') {
                    $hits[] = $relative . ':' . $token->line;
                }
            }
        }
        $this->assertSame([], $hits, 'E_STRICT was removed in PHP 8.4.');
    }

    /**
     * @return iterable<string, list<\PhpToken>>
     */
    private function sourceFiles(): iterable
    {
        $root = \dirname(__DIR__, 2);
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/src'),
        );
        /** @var list<string> $paths */
        $paths = [];
        foreach ($walk as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths);
        foreach ($paths as $path) {
            yield \substr($path, strlen($root) + 1) => \PhpToken::tokenize((string) file_get_contents($path));
        }
    }

    /**
     * `method:param` identities of every implicitly-nullable typed
     * parameter (`NonNullableType $x = null`).
     *
     * @param list<\PhpToken> $tokens
     * @return list<string>
     */
    private function implicitNullableParams(array $tokens): array
    {
        $dirtyFunctions = [];
        $count = count($tokens);
        $i = 0;
        while ($i < $count) {
            $token = $tokens[$i];
            if (!$token->is([T_FUNCTION, T_FN])) {
                $i++;
                continue;
            }
            // Function name (closures report '{closure}').
            $name = '{closure}';
            $j = $i + 1;
            while ($j < $count && !$tokens[$j]->is('(')) {
                if ($tokens[$j]->is(T_STRING)) {
                    $name = $tokens[$j]->text;
                }
                $j++;
            }
            // Match the parameter parenthesis group, tracking nesting so a
            // nested closure default does not end the segment early.
            $depth = 0;
            $params = [];
            $buffer = [];
            for (; $j < $count; $j++) {
                $t = $tokens[$j];
                if ($t->is('(')) {
                    $depth++;
                    if ($depth === 1) {
                        continue;
                    }
                } elseif ($t->is(')')) {
                    $depth--;
                    if ($depth === 0) {
                        $params[] = $buffer;
                        break;
                    }
                } elseif ($depth === 1 && $t->is(',')) {
                    $params[] = $buffer;
                    $buffer = [];
                    continue;
                }
                if ($depth >= 1) {
                    $buffer[] = $t;
                }
            }
            $i = $j + 1;
            foreach ($params as $param) {
                $ident = $this->paramIsImplicitNullable($param);
                if ($ident !== null) {
                    $dirtyFunctions[] = $name . ':' . $ident;
                }
            }
        }
        return $dirtyFunctions;
    }

    /**
     * @param list<\PhpToken> $param tokens of ONE parameter (no commas)
     *
     * @return ?string the parameter's variable name (without `$`) when the
     *                 parameter is implicitly nullable, null otherwise
     */
    private function paramIsImplicitNullable(array $param): ?string
    {
        $significant = [];
        foreach ($param as $token) {
            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }
            $significant[] = $token;
        }
        // Shape: [modifiers] [attribute*] [readonly] TYPE(s) [&] $var [= default]
        // Find the variable; type tokens are those before it after stripping
        // visibility/readonly modifiers and attribute groups.
        $varPos = null;
        foreach ($significant as $k => $t) {
            if ($t->is(T_VARIABLE)) {
                $varPos = $k;
                break;
            }
        }
        if ($varPos === null || $varPos === 0) {
            return null;
        }
        // Default must be the literal `null`.
        $defaultIsNull = false;
        for ($k = $varPos + 1; $k < count($significant); $k++) {
            if ($significant[$k]->is('=')) {
                $next = $significant[$k + 1] ?? null;
                $defaultIsNull = $next !== null
                    && $next->is(T_STRING)
                    && strtolower($next->text) === 'null';
            }
        }
        if (!$defaultIsNull) {
            return null;
        }
        // Type clause = contiguous name/union/nullable tokens immediately
        // before the variable (and any '&' by-ref marker in between).
        $type = [];
        for ($k = $varPos - 1; $k >= 0; $k--) {
            $t = $significant[$k];
            if ($t->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY])) {
                continue;
            }
            if ($t->is('&')) {
                continue;
            }
            // `?` is an ord token (PHP defines no T_NULLABLE constant).
            if ($t->text === '?') {
                return null; // explicitly nullable — compliant
            }
            if ($t->is([T_STRING, T_ARRAY, T_CALLABLE, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) || $t->is('|')) {
                $type[] = $t->text;
                continue;
            }
            break; // attribute close, default marker of previous param, etc.
        }
        if ($type === []) {
            return null; // untyped parameter — no implicit-nullable issue
        }
        // Union/intersection containing explicit `null` is compliant.
        foreach ($type as $word) {
            if (strtolower($word) === 'null' || strtolower($word) === 'mixed') {
                return null;
            }
        }
        return ltrim($significant[$varPos]->text, '$');
    }
}
