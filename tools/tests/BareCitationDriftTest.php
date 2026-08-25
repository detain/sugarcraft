<?php

declare(strict_types=1);

namespace SugarCraft\Tools\Tests;

require_once __DIR__ . '/_bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * A doc-block that cites one of its own test methods must cite one that exists.
 *
 * WHAT THIS IS, IN ONE LINE. E583 measured that the count of unresolvable bare
 * `@see` citations "depends entirely on the alphabet you ask with" — 16 raw /
 * 13 real under one alphabet, and a much smaller number under the tree's own
 * guard, which counts a citation only when the member is spelled as a TEST
 * method. Neither figure is wrong; they answer different questions. E583 filed
 * the disagreement and left the widening to "a round where one lane owns
 * `tests/`". THIS GUARD DOES NOT WIDEN THE ALPHABET. It takes the narrow
 * alphabet — the one whose answer is not a judgement call — and applies it to
 * the population nobody was applying it to at all.
 *
 * THE POPULATION IS THE FINDING (rule 48). `sugar-crush`'s own
 * `SymbolCitationDriftTest` walks `sugar-crush/tests` and nothing else, so
 * every `.php` file under `tools/` and under `candy-pty/` was outside every
 * citation guard in the repo. Building this over the whole of both trees rather
 * than over the two files a census happened to name found that both offenders
 * were real, and that ONE OF THEM WAS NOT A TYPO: `SharedLoopResidueTest`'s
 * class doc-block cited a method that has never existed in this repo, in a
 * sentence asserting a mechanism that the nearest real method's own doc-block
 * explicitly refutes. A bare citation naming nothing is the visible symptom;
 * a mechanism claim standing with nothing under it is the defect.
 *
 * WHY ONLY TEST-SHAPED NAMES, AND IT IS MEASURED RATHER THAN ASSUMED (rule 16).
 * A citation naming a method of the CLASS UNDER TEST is correct and common, and
 * a file-local scan cannot tell it from a broken one. Two live examples in this
 * very population, both correct, both of which a wider alphabet would red:
 * `HangWatchdogTest` cites `budgetFromEnv()`, which is a real
 * `public static function` on `tests/Support/HangWatchdog.php`; and
 * `src/PtyPool.php` cites `spl_object_id()`, which is a PHP built-in. A
 * TEST-shaped name has no such reading — a test method belongs to the file that
 * declares it, and no test file has cause to cite another file's test method by
 * bare name — so for that shape, and only that shape, "declared in this file"
 * is the whole of the question. {@see citationsNamingNothing()} states what the
 * alphabet therefore cannot express.
 *
 * THE CONTINUATION WRAP IS LOAD-BEARING, NOT A DETAIL (rule 17). A doc-block
 * wraps at 80 columns with a ` * ` on every continuation, so a citation is
 * never those bytes in a row: MEASURED on PHP 8.3.6, a single `T_DOC_COMMENT`
 * token can carry the tag and its argument on two lines with the marker
 * between them. A scanner that matches the raw token text reports zero for it —
 * an absence indistinguishable from a clean tree. The marker is flattened
 * before matching, and {@see citationFixtures()} pins that with a wrapped
 * fixture whose answer is known.
 *
 * WHY THE GUARD IS HERE AND NOT IN `candy-pty`, WHICH IS PUBLISHED. A guard
 * under `tools/` does not ship with `sugarcraft/candy-pty` and does not run for
 * anyone who clones the split repo. That is the accepted trade rather than an
 * oversight: the reverse direction is the dependency inversion
 * `StackedDocCommentTest`'s doc-block measures and refuses — a published
 * package may never read `tools/`, which does not exist in its own repo — and
 * every commit reaches the split repos by being made in this monorepo first, so
 * a citation cannot drift downstream without passing this job on the way. The
 * scanner is NOT copied into the package for the same reason it would then need
 * a drift pin of its own; this is prose about `@see` tags, not a runtime
 * property of the library.
 *
 * @internal
 */
final class BareCitationDriftTest extends TestCase
{
    /**
     * The two trees this lane owns, relative to the monorepo root.
     *
     * @var list<string>
     */
    private const ROOTS = ['/tools', '/candy-pty'];

    // ── the scanner, against answers already known (first) ───────────────

    /**
     * Sources whose answer is known, including the shapes that are NOT hits.
     *
     * EVERY FIXTURE IS BUILT BY CONCATENATION (rule 26). This file is inside
     * its own population, so a fixture that spelled a tag literally would be a
     * fixture the guard reports on itself — and the round-48 sweep that ate its
     * own guard's known-positive is what that discipline exists for. The pieces
     * are joined at run time, so the bytes exist for the scanner under test and
     * never in this source. It is belt-and-braces on top of a structural
     * exemption: {@see citationsNamingNothing()} reads `T_DOC_COMMENT` tokens
     * only, so a heredoc is outside the scan by construction (rule 40).
     *
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function citationFixtures(): iterable
    {
        $tag = '{@' . 'see ';

        // THE KNOWN POSITIVE. Without this, every `[]` below is also what a
        // scanner that has stopped matching returns (rule 15/25).
        yield 'a test citation naming nothing is reported' => [
            "<?php\n/**\n * " . $tag . "testAbsent()}\n */\nclass A { public function testPresent(): void {} }",
            ['testAbsent'],
        ];

        // THE RULE 17 ARM, and it is the one a raw-text scanner fails.
        yield 'a citation wrapped across a continuation marker is reported' => [
            "<?php\n/**\n * " . $tag . "\n * testWrappedAbsent()}\n */\nclass B {}",
            ['testWrappedAbsent'],
        ];

        yield 'a test citation that resolves in the same file is not' => [
            "<?php\n/**\n * " . $tag . "testPresent()}\n */\nclass C { public function testPresent(): void {} }",
            [],
        ];

        // THE TWO CORRECT SHAPES A WIDER ALPHABET WOULD RED, as fixtures rather
        // than as a promise: both are live in this population today.
        yield 'a bare method of the class under test is out of the alphabet' => [
            "<?php\n/**\n * " . $tag . "budgetFromEnv()}\n */\nclass D {}",
            [],
        ];
        yield 'a bare built-in function is out of the alphabet' => [
            "<?php\n/**\n * " . $tag . "spl_object_id()}\n */\nclass E {}",
            [],
        ];

        // AND THE NEGATIVES THAT STOP IT REPORTING THE WORLD.
        yield 'a qualified citation is another guard\'s business' => [
            "<?php\n/**\n * " . $tag . "Other::testAbsent()}\n */\nclass F {}",
            [],
        ];
        yield 'a citation in a line comment is not a doc-block tag' => [
            "<?php\n// " . $tag . "testAbsent()}\nclass G {}",
            [],
        ];
        yield 'a citation inside a string literal is not a doc-block tag' => [
            "<?php\n\$s = '" . $tag . "testAbsent()}';\nclass H {}",
            [],
        ];
        yield 'a declaration found only inside a string does not resolve a citation' => [
            "<?php\n/**\n * " . $tag . "testAbsent()}\n */\n\$s = 'function testAbsent() {}';",
            ['testAbsent'],
        ];
    }

    /**
     * @param list<string> $expected
     *
     * @dataProvider citationFixtures
     */
    public function testTheScannerReadsEveryShapeItClaimsTo(string $source, array $expected): void
    {
        $found = [];
        foreach (self::citationsNamingNothing($source) as $row) {
            $found[] = $row['name'];
        }
        \sort($found);
        \sort($expected);

        $this->assertSame($expected, $found);
    }

    /**
     * The reported line is the line the tag OPENS on, wrap or no wrap.
     *
     * NOT COSMETIC, AND IT WAS WRONG. This guard's only output is a list of
     * `file:line — name()` rows a human is expected to go and read, so a line
     * number that drifts under exactly the condition rule 17 exists for makes
     * the report actively misleading rather than merely terse. Both arms run:
     * the unwrapped one would still pass with the offset arithmetic deleted
     * entirely, so it is the wrapped one that carries the claim.
     */
    public function testTheReportedLineSurvivesAContinuationWrap(): void
    {
        $tag = '{@' . 'see ';

        $unwrapped = "<?php\n\n/**\n * padding\n * " . $tag . "testAbsentOne()}\n */\nclass A {}";
        $this->assertSame(
            [['name' => 'testAbsentOne', 'line' => 5]],
            self::citationsNamingNothing($unwrapped),
            'the reported line is wrong for a citation that does not wrap at all',
        );

        $wrapped = "<?php\n\n/**\n * padding\n * " . $tag . "\n * testAbsentTwo()}\n */\nclass B {}";
        $this->assertSame(
            [['name' => 'testAbsentTwo', 'line' => 5]],
            self::citationsNamingNothing($wrapped),
            'the reported line drifts once the citation wraps across a continuation marker — '
            . 'which is the one case rule 17 exists for, so it is the case the number has to '
            . 'be right in. Flattening the marker must not consume the newline with it',
        );
    }

    // ── the roster ───────────────────────────────────────────────────────

    /**
     * No file this lane owns cites a test method that is not there.
     */
    public function testNoDocBlockCitesATestMethodThatIsNotThere(): void
    {
        $root = \dirname(__DIR__, 2);
        $files = self::population($root);

        // RULE 15: the roster is the other half of the assertion below. An
        // empty population produces "nothing cites nothing" exactly as
        // convincingly as a clean tree does, and both trees are named rather
        // than counted because a cardinality here is stale the moment anyone
        // adds a file (rule 18).
        $this->assertContains(__FILE__, $files, 'the walk cannot see its own file');
        $this->assertContains(
            $root . '/candy-pty/tests/Support/SharedLoopResidueTest.php',
            $files,
            'the walk no longer reaches candy-pty/tests/, which is where both of the offenders '
            . 'this guard was written for lived',
        );
        $this->assertContains(
            $root . '/candy-pty/src/PtyPool.php',
            $files,
            'the walk no longer reaches candy-pty/src/, so the published half of the package is '
            . 'covered by no citation guard at all',
        );

        $broken = [];
        foreach ($files as $file) {
            foreach (self::citationsNamingNothing((string) \file_get_contents($file)) as $row) {
                $broken[] = \substr($file, \strlen($root) + 1) . ':' . $row['line'] . ' — ' . $row['name'] . '()';
            }
        }
        \sort($broken);

        $this->assertSame(
            [],
            $broken,
            'These doc-blocks cite a TEST method by bare name and no method of that name is '
            . 'declared in the same file — so the citation resolves to nothing and the sentence '
            . 'around it is a claim with no referent. That is usually a rename leaving its '
            . 'prose behind, and it is worth more than a typo: the one this guard was written '
            . 'for left a mechanism claim standing that the nearest real method refuted. Point '
            . 'the citation at the method that exists, or rewrite the sentence (rule 7: say '
            . 'what it said, what is true now, and why it still earns its place). A citation of '
            . 'a method on the CLASS UNDER TEST, or of a built-in, is deliberately outside this '
            . "guard's alphabet — spell it `Class::method()` if that is what you meant.",
        );
    }

    // ── the instruments ──────────────────────────────────────────────────

    /**
     * Every `.php` file under the roots above, absolute and sorted.
     *
     * `vendor/` is excluded because it is not ours and is not committed.
     *
     * @return list<string>
     */
    private static function population(string $root): array
    {
        $files = [];
        foreach (self::ROOTS as $rel) {
            $dir = $root . $rel;
            if (!\is_dir($dir)) {
                continue;
            }
            $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                $dir,
                \FilesystemIterator::SKIP_DOTS,
            ));
            foreach ($walk as $entry) {
                if (!$entry->isFile() || $entry->getExtension() !== 'php') {
                    continue;
                }
                $path = $entry->getPathname();
                if (\str_contains($path, '/vendor/')) {
                    continue;
                }
                $files[] = $path;
            }
        }
        \sort($files);

        return \array_values($files);
    }

    /**
     * Bare TEST-method citations in one source that the same file does not
     * declare.
     *
     * WHAT THIS ALPHABET CANNOT EXPRESS, stated rather than left to be found
     * (rule 11). A citation of a method on the class under test, of a built-in,
     * or of anything else not spelled `test<Upper>` is out of scope by design
     * and measured to be correct where it occurs here. A citation written
     * `Class::method()` is another guard's business. A citation assembled by
     * concatenation inside a doc-block cannot exist — a doc-block is one token.
     *
     * BOTH HALVES ARE STRUCTURAL (rule 40). Citations are read from
     * `T_DOC_COMMENT` tokens, so a line comment, a string literal and a heredoc
     * are outside the scan by construction rather than by a text exclusion.
     * Declarations are read from `T_FUNCTION` followed by a name, so the word
     * `function` inside a string cannot resolve a citation — which is its own
     * fixture above, because a scanner that let it would fail OPEN.
     *
     * @return list<array{name: string, line: int}>
     */
    private static function citationsNamingNothing(string $source): array
    {
        $tokens = \token_get_all($source);
        $count = \count($tokens);

        $declared = [];
        for ($i = 0; $i < $count; $i++) {
            if (!\is_array($tokens[$i]) || $tokens[$i][0] !== \T_FUNCTION) {
                continue;
            }
            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];
                if (\is_array($next) && \in_array($next[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                    continue;
                }
                if (\is_array($next) && $next[0] === \T_STRING) {
                    $declared[\strtolower($next[1])] = true;
                }

                break;
            }
        }

        $found = [];
        foreach ($tokens as $token) {
            if (!\is_array($token) || $token[0] !== \T_DOC_COMMENT) {
                continue;
            }

            // RULE 17. Strip the ` * ` continuation markers first: a doc-block
            // wraps at 80 columns, so the tag and its argument are never those
            // bytes in a row.
            //
            // THE MARKER IS REPLACED BY A NEWLINE, NOT BY A SPACE, AND THE
            // FIRST DRAFT OF THIS COMMENT CLAIMED THE OPPOSITE. WHAT IT SAID:
            // "line offsets within the block are preserved ... which is why the
            // marker is replaced by a space rather than removed." WHAT IS TRUE:
            // that substitution consumes the newline along with the marker, so
            // every reported line after a wrap was too LOW — MEASURED on PHP
            // 8.3.6 at 3 for a tag opening on line 5. Keeping the newline
            // costs nothing, because `\s+` in the pattern below matches it
            // just as happily as a space, and it makes the offset arithmetic
            // exact instead of merely plausible. A guard whose entire job is
            // pointing a human at a file has to point at the right line;
            // {@see testTheReportedLineSurvivesAContinuationWrap()} pins it.
            $flat = \preg_replace('/\n[ \t]*\*[ \t]?/', "\n", $token[1]);
            $flat = \is_string($flat) ? $flat : $token[1];

            if (\preg_match_all(
                '/\{@see\s+([A-Za-z_][A-Za-z0-9_]*)\(\)\s*\}/',
                $flat,
                $hits,
                \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE,
            ) === 0) {
                continue;
            }

            foreach ($hits as $hit) {
                $name = $hit[1][0];
                if (\preg_match('/^test[A-Z0-9]/', $name) !== 1) {
                    continue;
                }
                if (isset($declared[\strtolower($name)])) {
                    continue;
                }
                $found[] = [
                    'name' => $name,
                    'line' => $token[2] + \substr_count(\substr($flat, 0, (int) $hit[0][1]), "\n"),
                ];
            }
        }

        return $found;
    }
}
