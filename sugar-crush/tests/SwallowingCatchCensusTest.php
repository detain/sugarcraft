<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * A CATCH CLAUSE WIDE ENOUGH TO EAT PHPUNIT'S OWN FAILURE, WRAPPED AROUND A TRY
 * BODY THAT ASSERTS, IS A TEST THAT PASSES WHILE ASSERTING NOTHING.
 *
 * MEASURED on PHPUnit 10.5.64 / PHP 8.3.6, `class_parents()` reports:
 *
 *   ExpectationFailedException -> AssertionFailedError
 *                              -> PHPUnit\Framework\Exception
 *                              -> RuntimeException
 *                              -> Exception
 *
 * So `catch (\RuntimeException $e)` catches every failing assertion inside its
 * own try, including the `$this->fail(...)` written on the line above it as the
 * "this should have thrown" guard. The canonical shape was:
 *
 *     try {
 *         $subject->doTheThing();
 *         $this->fail('doTheThing() should have thrown');
 *     } catch (\RuntimeException $e) {
 *         $this->assertNotEmpty($e->getMessage());
 *     }
 *
 * which is green whether `doTheThing()` throws, does nothing, or is deleted
 * outright — the fail() lands in the catch and its own message satisfies the
 * assertion. That is not a hypothesis. MEASURED in round 58 by restoring this
 * exact shape in AgentPresetRegistryTest::testLoadThrowsOnInvalidYaml with the
 * subject call `$registry->load('bad-yaml')` DELETED: the file ran green, rc 0.
 * With the repaired shape and the same deletion it goes red.
 *
 * THE STRUCTURAL RULE THIS GUARD ENFORCES, and why it is structural rather than
 * textual (a prose-keyed exemption is bought with a sentence, and the comment
 * explaining a fix is enough to buy it):
 *
 *   - A catch type that WOULD catch an ExpectationFailedException, but is not
 *     itself an assertion-failure type, is FORBIDDEN around an asserting try.
 *     That is Throwable, Exception, RuntimeException, PHPUnit\Framework\Exception,
 *     and anything else in the tree that happens to sit on that chain.
 *   - A catch type that IS an assertion-failure type — AssertionFailedError or
 *     a subclass such as ExpectationFailedException — is ALLOWED, because
 *     naming it is an explicit statement that the failure is the subject under
 *     test. Four such sites exist and every one of them is correct; see
 *     {@see \SugarCraft\Crush\Tests\Cli\BootstrapSkillSkipsTest} for the
 *     sharpest of them, which catches the NARROWER AssertionFailedError
 *     precisely so that its own `fail()` still escapes.
 *
 * The classification is taken from the live class hierarchy via `is_a()`, not
 * from a hand-written list of names, so a type this file has never heard of —
 * Symfony's `ParseException`, say, which is-a `\RuntimeException` and therefore
 * swallows — is classified correctly the first time it appears.
 *
 * WHAT THIS GUARD CANNOT SEE. Both were measured in round 57 and neither is
 * closed here; a guard that hides its blind spots is the defect it is named for:
 *
 *   1. AN ASSERTION REACHED INDIRECTLY. The try body is judged to "assert" by
 *      the token stream of the body itself. A helper called from inside the try
 *      whose own body asserts — `$this->drainTheChild()` — is invisible, so a
 *      wide catch around it is not reported.
 *   2. SWALLOWING THAT IS NOT A CATCH AT ALL. `set_error_handler()` installed
 *      over an asserting region intercepts the failure without any try/catch,
 *      and nothing here looks for it.
 *
 * A third limit is deliberate rather than accidental: a catch type this file
 * cannot RESOLVE to a real class is reported as unclassified and turns the
 * census red. A guard that quietly ignores what it cannot parse has a hole
 * shaped exactly like the next defect.
 */
final class SwallowingCatchCensusTest extends TestCase
{
    /**
     * The known-positive, its two known-negatives, and the unresolvable case,
     * pushed through the SAME scanner the tree scan uses.
     *
     * An assertion of "no occurrences" is not evidence unless something in the
     * same test proves the scanner still works: mutate `scanSource()` to return
     * `[]` and the tree scan below stays green on its own.
     *
     * The fixture sources are BUILT BY CONCATENATION and never spelled as a
     * literal offender, so a future textual sweep for the pattern cannot eat
     * the file that documents it.
     */
    public function testTheScannerIsAliveInBothPolarities(): void
    {
        $wide = '\\' . 'RuntimeException';
        $narrow = '\\' . 'InvalidArgumentException';
        $deliberate = '\\PHPUnit\\Framework\\' . 'AssertionFailedError';

        $positive = self::fixture('$this->assertSame(1, 1);', $wide);
        $hits = self::scanSource($positive, 'FIXTURE_POSITIVE.php');
        self::assertCount(
            1,
            $hits,
            'the scanner no longer sees a wide catch around an asserting try — the tree scan '
            . 'below is therefore worthless and its empty result means nothing',
        );
        self::assertSame('offender', $hits[0]['verdict']);

        // NEGATIVE 1: the catch type cannot catch an assertion failure at all.
        self::assertSame(
            [],
            self::scanSource(self::fixture('$this->assertSame(1, 1);', $narrow), 'FIXTURE_NEG1.php'),
            'a catch type that cannot catch an ExpectationFailedException was reported anyway',
        );

        // NEGATIVE 2: wide catch, but the try body asserts nothing, so there is
        // no failure standing next to it to be eaten.
        self::assertSame(
            [],
            self::scanSource(self::fixture('doSomething();', $wide), 'FIXTURE_NEG2.php'),
            'a wide catch around a non-asserting try body was reported as an offender',
        );

        // NEGATIVE 3: the deliberate shape. Naming the assertion-failure type is
        // the statement of intent that makes it legitimate.
        self::assertSame(
            [],
            self::scanSource(self::fixture('$this->assertSame(1, 1);', $deliberate), 'FIXTURE_NEG3.php'),
            'catching the assertion-failure type BY NAME is how a test says the failure is its '
            . 'subject; reporting it would make the guard unusable and invite a prose exemption',
        );

        // THE UNPARSEABLE CASE, which must go red rather than be skipped.
        $unknown = self::scanSource(
            self::fixture('$this->assertSame(1, 1);', '\\No\\Such\\Namespace\\NopeException'),
            'FIXTURE_UNRESOLVABLE.php',
        );
        self::assertCount(1, $unknown, 'a catch type that resolves to nothing was silently dropped');
        self::assertSame('unclassified', $unknown[0]['verdict']);
    }

    /**
     * The tree itself. Backed by the fixtures above, which is the only reason
     * an empty result here is worth anything.
     */
    public function testNoTestSwallowsTheFailureStandingNextToIt(): void
    {
        $offenders = [];
        $sites = 0;
        $files = 0;

        foreach (self::testFiles() as $rel => $src) {
            if ($rel === 'SwallowingCatchCensusTest.php') {
                continue; // the file that documents the pattern
            }
            $files++;
            foreach (self::scanSource($src, $rel) as $hit) {
                $sites++;
                $offenders[] = $rel . ':' . $hit['line'] . '  catch(' . $hit['type']
                    . ')  [' . $hit['verdict'] . ']';
            }
        }

        self::assertGreaterThan(
            0,
            $files,
            'the walk found no test files at all, so the empty offender list below is an artefact '
            . 'of a broken directory walk rather than a fact about the tree',
        );

        sort($offenders);
        self::assertSame(
            [],
            $offenders,
            "A try body that ASSERTS is wrapped in a catch clause wide enough to catch PHPUnit's\n"
            . "own ExpectationFailedException. The test above passes whether its subject throws,\n"
            . "returns normally, or is deleted outright.\n\n"
            . "THE FIX IS NOT AN EXEMPTION. Move the `fail()` out of the try and assert after it:\n\n"
            . "    \$caught = null;\n"
            . "    try {\n"
            . "        \$subject->doTheThing();\n"
            . "    } catch (\\RuntimeException \$e) {\n"
            . "        \$caught = \$e;\n"
            . "    }\n"
            . "    \$this->assertNotNull(\$caught, 'doTheThing() should have thrown');\n"
            . "    \$this->assertSame('...', \$caught->getMessage());\n\n"
            . "If the assertion failure genuinely IS the subject under test, catch the\n"
            . "assertion-failure type BY NAME — AssertionFailedError, or the narrower\n"
            . "ExpectationFailedException if your own fail() must still escape — and this census\n"
            . "will accept it on the structure alone.\n\n"
            . "A verdict of [unclassified] means something else: this census could not resolve\n"
            . "that catch type to a real class and refuses to guess. Add the `use` it is missing\n"
            . "or spell the type fully qualified.\n\nOffenders:",
        );
    }

    // -------------------------------------------------------------------------
    // the scanner
    // -------------------------------------------------------------------------

    /** Build a fixture source without ever spelling a whole offender literally. */
    private static function fixture(string $body, string $catchType): string
    {
        return "<?php\nclass F {\n  public function t(): void {\n    try {\n      "
            . $body . "\n    } catch (" . $catchType . " \$e) {\n      \$x = 1;\n    }\n  }\n}\n";
    }

    /**
     * @return array<string, string> repo-relative path => source
     */
    private static function testFiles(): array
    {
        $root = \dirname(__DIR__) . '/tests';
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') {
                continue;
            }
            $out[substr($f->getPathname(), \strlen($root) + 1)] = (string) file_get_contents($f->getPathname());
        }

        return $out;
    }

    /**
     * @return list<array{line:int,type:string,verdict:string}>
     */
    private static function scanSource(string $src, string $rel): array
    {
        $tokens = token_get_all($src);
        $n = \count($tokens);
        $uses = self::useMap($tokens);
        $out = [];

        for ($i = 0; $i < $n; $i++) {
            if (!\is_array($tokens[$i]) || $tokens[$i][0] !== T_TRY) {
                continue;
            }

            $j = $i;
            while ($j < $n && $tokens[$j] !== '{') {
                $j++;
            }
            if ($j >= $n) {
                continue;
            }

            [$body, $j] = self::block($tokens, $j, $n);
            $asserts = self::bodyAsserts($body);

            $k = $j + 1;
            while ($k < $n) {
                while ($k < $n && \is_array($tokens[$k])
                    && \in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $k++;
                }
                if ($k >= $n || !\is_array($tokens[$k]) || $tokens[$k][0] !== T_CATCH) {
                    break;
                }

                $catchLine = $tokens[$k][2];
                while ($k < $n && $tokens[$k] !== '(') {
                    $k++;
                }
                $type = '';
                $paren = 0;
                for (; $k < $n; $k++) {
                    $txt = \is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                    if ($txt === '(') {
                        $paren++;
                        if ($paren === 1) {
                            continue;
                        }
                    }
                    if ($txt === ')') {
                        $paren--;
                        if ($paren === 0) {
                            break;
                        }
                    }
                    $type .= $txt;
                }

                if ($asserts) {
                    // multi-catch: `catch (A|B $e)`. The variable is optional in PHP 8.
                    foreach (explode('|', (string) preg_replace('/\$\w+/', '', $type)) as $one) {
                        $one = trim($one);
                        if ($one === '') {
                            continue;
                        }
                        $verdict = self::classify($one, $uses);
                        if ($verdict !== 'safe') {
                            $out[] = ['line' => $catchLine, 'type' => $one, 'verdict' => $verdict];
                        }
                    }
                }

                while ($k < $n && $tokens[$k] !== '{') {
                    $k++;
                }
                [, $k] = self::block($tokens, $k, $n);
                $k++;
            }
        }

        unset($rel);

        return $out;
    }

    /**
     * @param  list<array{0:int,1:string,2:int}|string> $tokens
     * @return array{0:string,1:int} the brace-balanced text, and the index of its closing brace
     */
    private static function block(array $tokens, int $open, int $n): array
    {
        $depth = 0;
        $text = '';
        for ($k = $open; $k < $n; $k++) {
            $txt = \is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
            if ($txt === '{') {
                $depth++;
            }
            if ($depth > 0) {
                $text .= $txt;
            }
            if ($txt === '}') {
                $depth--;
                if ($depth === 0) {
                    return [$text, $k];
                }
            }
        }

        return [$text, $n - 1];
    }

    /**
     * The alphabet is part of the coverage, so it is widened past the shape the
     * known offenders happened to use: a method call on $this/self/static, a
     * static call on ANY class (`Assert::assertSame(...)`), and the function
     * form PHPUnit 10 exports (`use function PHPUnit\Framework\assertSame`).
     */
    private static function bodyAsserts(string $body): bool
    {
        return (bool) preg_match(
            '/(?:(?:\$this|self|static|[A-Z]\w*)\s*(?:->|::)\s*)?\b(?:assert[A-Z_]\w*|fail|expectException\w*)\s*\(/',
            $body,
        );
    }

    /**
     * @param array<string, string> $uses
     * @return 'safe'|'offender'|'unclassified'
     */
    private static function classify(string $written, array $uses): string
    {
        $fqn = self::resolve($written, $uses);

        if ($fqn === null || !(class_exists($fqn) || interface_exists($fqn))) {
            return 'unclassified';
        }

        // Would this catch clause receive a failing assertion?
        if (!is_a(\PHPUnit\Framework\ExpectationFailedException::class, $fqn, true)) {
            return 'safe';
        }

        // It would — but naming an assertion-failure type is the deliberate,
        // legitimate shape: the failure IS the subject under test.
        if (is_a($fqn, AssertionFailedError::class, true)) {
            return 'safe';
        }

        return 'offender';
    }

    /** @param array<string, string> $uses */
    private static function resolve(string $written, array $uses): ?string
    {
        $t = trim($written);
        if ($t === '') {
            return null;
        }
        if (str_starts_with($t, '\\')) {
            return ltrim($t, '\\');
        }

        $head = explode('\\', $t)[0];
        if (isset($uses[$head])) {
            $rest = substr($t, \strlen($head));

            return $uses[$head] . $rest;
        }

        // No import: PHP resolves an unqualified class name in the current
        // namespace, but every catch type in this tree that is not imported is
        // a global one, so try global and let `classify()` report anything else
        // as unclassified rather than guessing.
        return $t;
    }

    /**
     * @param  list<array{0:int,1:string,2:int}|string> $tokens
     * @return array<string, string> local alias => fully qualified name
     */
    private static function useMap(array $tokens): array
    {
        $n = \count($tokens);
        $map = [];

        for ($i = 0; $i < $n; $i++) {
            if (!\is_array($tokens[$i]) || $tokens[$i][0] !== T_USE) {
                continue;
            }
            // A `use` inside a class body (trait import) or a closure `use (...)`
            // is not an import; both are followed by something other than a
            // plain qualified name at statement level, and the `;` scan below
            // simply yields a name we then fail to resolve rather than a wrong one.
            $stmt = '';
            for ($k = $i + 1; $k < $n; $k++) {
                $txt = \is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                if ($txt === ';' || $txt === '{' || $txt === '(') {
                    break;
                }
                $stmt .= $txt;
            }
            $stmt = trim($stmt);
            if ($stmt === '' || str_starts_with($stmt, 'function ') || str_starts_with($stmt, 'const ')) {
                continue;
            }
            foreach (explode(',', $stmt) as $one) {
                $one = trim($one);
                if ($one === '') {
                    continue;
                }
                if (preg_match('/^(.+?)\s+as\s+(\w+)$/i', $one, $m)) {
                    $map[$m[2]] = ltrim(trim($m[1]), '\\');
                } else {
                    $fq = ltrim($one, '\\');
                    $map[substr($fq, (int) strrpos('\\' . $fq, '\\'))] = $fq;
                }
            }
        }

        return $map;
    }
}
