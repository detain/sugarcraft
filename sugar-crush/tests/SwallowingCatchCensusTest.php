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

        // AND AN INTERPOLATED BRACE OPENER IN THE TRY BODY, which is a token
        // whose TEXT is not `{`. Before every opener was named, the `${`
        // spelling decremented a level it never incremented, the body walk
        // ended early and the catch clause after it was never reached — so a
        // real offender read as a clean file. The row after this one is the
        // control: the SAME body with no interpolation must give the same
        // answer, or this fixture is pinning the wrong thing.
        $interpolated = self::fixture('$this->assertSame(1, "$' . '{y}");', $wide);
        self::assertCount(
            1,
            self::scanSource($interpolated, 'FIXTURE_INTERPOLATED.php'),
            'an interpolated brace opener ends the try-body walk early, so every catch clause '
            . 'after the first such string in a file is invisible to this census',
        );

        // AND THE MIRROR OF IT: A TOKEN WHOSE TEXT IS A BRACE BUT WHICH IS NOT
        // ONE. A double-quoted string holding a simple variable next to a brace
        // arrives as a single T_ENCAPSED_AND_WHITESPACE token whose text is
        // EXACTLY that brace. A walk deciding on extracted text counts it, the
        // depth goes wrong, and the catch clause after it is never reached —
        // the same failure as the row above, arrived at from the opposite side.
        // Both spellings are pinned, because the close brace unbalances the
        // walk downwards and the open brace unbalances it upwards, and only one
        // of the two is fixed by gating either comparison alone.
        //
        // The bodies are assembled by concatenation so this file never spells a
        // whole offender literally; see the doc-block on this method.
        $closeBraceInString = self::fixture('$this->assertSame(1, "$' . 'x}");', $wide);
        self::assertCount(
            1,
            self::scanSource($closeBraceInString, 'FIXTURE_CLOSE_BRACE_IN_STRING.php'),
            'a close brace inside a string literal is being counted as a real brace, so the '
            . 'try-body walk under-counts its depth, ends early, and every catch clause after '
            . 'the first such string in a file is invisible to this census',
        );

        $openBraceInString = self::fixture('$this->assertSame(1, "$' . 'x{");', $wide);
        self::assertCount(
            1,
            self::scanSource($openBraceInString, 'FIXTURE_OPEN_BRACE_IN_STRING.php'),
            'an open brace inside a string literal is being counted as a real brace, so the '
            . 'try-body walk over-counts its depth and never returns to level zero at the end '
            . 'of the try body',
        );

        // THE TWO RESOLUTION SHAPES THAT MAKE THE CLASSIFIER, NOT THE CODE, THE
        // DEFECT. Both report a file that named its catch type CORRECTLY, so
        // the failure they produce is a false alarm, and a false alarm on an
        // emptiness guard is answered with an exemption row — which is a
        // licence, and exactly where the next real offender hides.
        //
        // MEASURED at this commit: neither shape occurs in sugar-crush/tests
        // (463 files: zero group-use catch types, zero bare-unimported ones).
        // Nor is either shape prevented. `.php-cs-fixer.dist.php` enables
        // `@PSR12`, which DOES carry `single_import_per_statement` -- but
        // configured `['group_to_single_imports' => false]` (php-cs-fixer
        // v3.95.21), i.e. the fixer deliberately leaves a group import alone.
        // So these are latent rather than live, and they are pinned on fixtures
        // rather than on the tree for that reason.
        $groupPrelude = "namespace Demo;\n"
            . 'use PHPUnit\\Framework\\' . '{' . 'AssertionFailedError, Exception as WideOne};' . "\n";

        // A group import of the DELIBERATE type. Before the group form was
        // parsed, neither name in the braces was mapped at all, so this correct
        // file was reported as [unclassified] and reddened the census.
        self::assertSame(
            [],
            self::scanSource(
                self::fixture('$this->assertSame(1, 1);', 'AssertionFailedError', $groupPrelude),
                'FIXTURE_GROUP_USE_SAFE.php',
            ),
            'a catch type imported through a group `use` is not being resolved, so a file that '
            . 'names the assertion-failure type correctly is reported as unclassified',
        );

        // And the positive half of the same prelude: an aliased WIDE type from
        // inside the braces must still be caught as an offender, which is what
        // separates "the group form is parsed" from "the group form is ignored
        // and both names happen to fall through to something harmless".
        $grouped = self::scanSource(
            self::fixture('$this->assertSame(1, 1);', 'WideOne', $groupPrelude),
            'FIXTURE_GROUP_USE_OFFENDER.php',
        );
        self::assertCount(1, $grouped, 'an aliased wide type inside a group import was not reported');
        self::assertSame('offender', $grouped[0]['verdict']);

        // An unqualified, UNIMPORTED type declared in the file's own namespace.
        // PHP resolves this against the current namespace and does not fall
        // back to global; resolving it as global made a real class look like a
        // class that does not exist.
        self::assertSame(
            [],
            self::scanSource(
                self::fixture(
                    '$this->assertSame(1, 1);',
                    'AssertionFailedError',
                    "namespace PHPUnit\\Framework;\n",
                ),
                'FIXTURE_SAME_NAMESPACE.php',
            ),
            'an unqualified catch type declared in the file\'s own namespace is being resolved '
            . 'as a global name, so correct code is reported as unclassified',
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

        // NO SELF-EXCLUSION. This file used to skip itself as "the file that
        // documents the pattern", and MEASURED by mutation the skip bought
        // nothing: deleting it leaves this file green, because the fixtures are
        // assembled by concatenation and no whole offender is ever spelled here.
        // An exemption that buys nothing is not free — it is the one path by
        // which a real offender in this very file would go unreported, and
        // nothing would have pinned that the file stayed clean.
        foreach (self::testFiles() as $rel => $src) {
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
            . "that catch type to a real class and refuses to guess. THAT IS NOT AUTOMATICALLY\n"
            . "YOUR CODE'S FAULT, and if the catch type is spelled correctly then THIS CENSUS is\n"
            . "the thing to fix, not your file -- do NOT answer it with an exemption. Check, in\n"
            . "this order: (1) is the type genuinely misspelled or genuinely missing an import?\n"
            . "Then fix the file. (2) Is it imported or declared in a way resolve()/useMap()\n"
            . "cannot follow -- a conditional class_alias, a name built at runtime? Then teach\n"
            . "the classifier and pin BOTH polarities with a fixture, the way the group-import\n"
            . "and same-namespace shapes are pinned in the test above.\n\nOffenders:",
        );
    }

    // -------------------------------------------------------------------------
    // the scanner
    // -------------------------------------------------------------------------

    /**
     * Build a fixture source without ever spelling a whole offender literally.
     *
     * `$prelude` carries whatever has to sit between the open tag and the class
     * — a `namespace` line, an import — so the RESOLUTION cases can be driven
     * through the same builder as the classification ones.
     */
    private static function fixture(string $body, string $catchType, string $prelude = ''): string
    {
        return "<?php\n" . $prelude . "class F {\n  public function t(): void {\n    try {\n      "
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
        $namespace = self::namespaceOf($tokens);
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
                        $verdict = self::classify($one, $uses, $namespace);
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
     * BRACE DEPTH IS A FACT ABOUT TOKENS, NOT ABOUT TEXT — IN BOTH DIRECTIONS.
     *
     * WHAT THIS SAID BEFORE: "every token the running PHP uses to open a brace,
     * not just the character" — a completeness claim about OPENERS only.
     *
     * WHAT IS TRUE NOW: that heading named half the family. A walk over
     * `token_get_all()` that decides on extracted TEXT can be wrong two ways,
     * and this method was wrong both:
     *
     *   1. A TOKEN THAT OPENS A BRACE WITHOUT SPELLING `{`. `"${y}"` arrives as
     *      `T_DOLLAR_OPEN_CURLY_BRACES`, spelled `${`, while its closing `}` is
     *      an ordinary character token. Counting only the character decremented
     *      a level that was never incremented, the walk left this method early,
     *      and the scan silently stopped matching after the first such string —
     *      a clean bill of health for a file the scanner had stopped reading.
     *      `"{$x}"` arrives as `T_CURLY_OPEN`, whose text IS `{`, so it happened
     *      to work; it is named anyway, because relying on a token's text
     *      matching its role is what produced the bug.
     *   2. A TOKEN WHOSE TEXT IS A BRACE BUT WHICH IS NOT A BRACE. MEASURED on
     *      PHP 8.3.6: `token_get_all()` on a double-quoted string holding a
     *      simple variable followed by a close brace yields a
     *      `T_ENCAPSED_AND_WHITESPACE` whose text is EXACTLY `}`; the open-brace
     *      spelling yields one whose text is exactly `{`. Extracting text first
     *      therefore counted braces that live inside a string literal. MEASURED
     *      through this scanner before the gate below existed: an offender whose
     *      try body carried either spelling was reported ZERO times — the same
     *      defect class as (1), one token short of the family.
     *
     * So both comparisons are now gated on the token being a bare character
     * token, and the two interpolation openers are named explicitly. Every other
     * brace walk in this tree compares the RAW token rather than its text and is
     * immune to (2) already; this method was the only one extracting text first,
     * which is why it was the only one wrong in both directions.
     *
     * WHY THIS STILL EARNS ITS PLACE: at this commit, on PHP 8.3.6, `tests/`
     * holds no token whose text is a lone brace and which is not the brace
     * character, so neither defect changed any census answer — both removed a
     * hole that opens the first time somebody writes one inside a try body.
     * That reachability is prose and is deliberately asserted nowhere; the
     * MECHANISM is pinned by the fixtures in
     * {@see self::testTheScannerIsAliveInBothPolarities()}, which do not depend
     * on what the tree happens to contain this week.
     *
     * @param  list<array{0:int,1:string,2:int}|string> $tokens
     * @return array{0:string,1:int} the brace-balanced text, and the index of its closing brace
     */
    private static function block(array $tokens, int $open, int $n): array
    {
        $depth = 0;
        $text = '';
        for ($k = $open; $k < $n; $k++) {
            $token = $tokens[$k];
            $txt = \is_array($token) ? $token[1] : $token;
            // `is_string()` is the whole point: a bare character token IS the
            // brace, while an array token whose text merely reads `{` or `}` is
            // a fragment of a string literal.
            $isPunctuation = \is_string($token);
            $opensABrace = ($isPunctuation && $txt === '{')
                || (\is_array($token) && \in_array($token[0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true));
            if ($opensABrace) {
                $depth++;
            }
            if ($depth > 0) {
                $text .= $txt;
            }
            if ($isPunctuation && $txt === '}') {
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
    private static function classify(string $written, array $uses, string $namespace = ''): string
    {
        $fqn = self::resolve($written, $uses, $namespace);

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

    /**
     * WHAT THIS SAID BEFORE: "every catch type in this tree that is not
     * imported is a global one, so try global and let `classify()` report
     * anything else as unclassified rather than guessing."
     *
     * WHAT IS TRUE NOW: the first half is still MEASURED true — a walk over
     * `sugar-crush/tests` at this commit finds no catch type that is neither
     * fully qualified nor imported — but the conclusion did not follow. PHP
     * resolves an unqualified class name against the CURRENT namespace and does
     * NOT fall back to global, so a file that catches a type declared beside it
     * was resolved to a global name that does not exist, and the census reported
     * correct code as `[unclassified]` and went red on it. That is the shape
     * where the classifier, not the code, is the defect.
     *
     * WHY THIS STILL EARNS ITS PLACE: the ordering below is deliberately more
     * permissive than PHP. The current namespace is tried FIRST, because that is
     * what the language does; global is kept as a fallback because this tree's
     * convention is to spell a global type with a leading `\` and a file that
     * forgets the slash means the global one. Being permissive here can only
     * turn an `[unclassified]` into a real verdict — it can never turn an
     * offender into `safe`, because `classify()` still asks the live hierarchy
     * about whatever class it ends up with.
     *
     * @param array<string, string> $uses
     */
    private static function resolve(string $written, array $uses, string $namespace = ''): ?string
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

        if ($namespace !== '' && (class_exists($namespace . '\\' . $t) || interface_exists($namespace . '\\' . $t))) {
            return $namespace . '\\' . $t;
        }

        return $t;
    }

    /**
     * The file's own namespace, which is where PHP resolves an unqualified
     * catch type that carries no import.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function namespaceOf(array $tokens): string
    {
        $n = \count($tokens);

        for ($i = 0; $i < $n; $i++) {
            if (!\is_array($tokens[$i]) || $tokens[$i][0] !== T_NAMESPACE) {
                continue;
            }
            $name = '';
            for ($k = $i + 1; $k < $n; $k++) {
                $txt = \is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                if ($txt === ';' || $txt === '{') {
                    break;
                }
                $name .= $txt;
            }

            return trim($name, " \t\n\\");
        }

        return '';
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
            // A group import — `use A\\B\\{C, D as E};` — is a `{` at statement
            // level, and stopping there used to leave every name in the group
            // unmapped while recording a bogus empty-string alias for the
            // prefix. It is picked up here by carrying the prefix into the
            // braces. A closure's `use (...)` still stops at the `(`, and a
            // trait import inside a class body reaches a `{` with no `\` in the
            // prefix, which the guard below rejects.
            $stmt = '';
            $prefix = '';
            for ($k = $i + 1; $k < $n; $k++) {
                $txt = \is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                if ($txt === '(') {
                    break;
                }
                if ($txt === '{') {
                    $candidate = trim($stmt);
                    if (!str_contains($candidate, '\\')) {
                        break; // a trait import, not a group import
                    }
                    $prefix = ltrim($candidate, '\\');
                    $stmt = '';

                    continue;
                }
                if ($txt === ';' || $txt === '}') {
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
                    $map[$m[2]] = $prefix . ltrim(trim($m[1]), '\\');
                } else {
                    $fq = $prefix . ltrim($one, '\\');
                    $map[substr($fq, (int) strrpos('\\' . $fq, '\\'))] = $fq;
                }
            }
        }

        return $map;
    }
}
