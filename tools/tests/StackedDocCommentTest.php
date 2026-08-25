<?php

declare(strict_types=1);

namespace SugarCraft\Tools\Tests;

require_once __DIR__ . '/_bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * `tools/` carries no stacked doc-comments either.
 *
 * PHP attaches only the LAST of a run of doc-comment blocks to the declaration
 * beneath it, so every earlier block in the run documents nothing: its `@return`
 * tag is off the method and its reasoning is orphaned above an unrelated one.
 * `sugar-crush` has guarded that shape across its own `src/` and `tests/` for
 * several rounds. `tools/` was outside every doc-drift roster in the tree.
 *
 * WHY THE OBVIOUS ONE-LINE FIX IS WRONG, MEASURED RATHER THAN ARGUED. The
 * roster that guard walks is rooted at the `sugar-crush` package, and adding
 * `../../tools` to it would red in the split-repo clone that CI publishes to,
 * where `tools/` does not exist at all — or need an `is_dir()` skip, which
 * makes the guard score zero in precisely the environment the published package
 * lives in. `tools/` is monorepo-only and the dependency has to run that way
 * round: a guard HERE may read `sugar-crush`, and never the reverse.
 *
 * WHY THE SCANNER IS A COPY, WHICH IS NORMALLY THE WRONG ANSWER. The zero-copy
 * shape was tried first and MEASURED not to work. The job that runs these files
 * — `tools-guards` in `.github/workflows/ci.yml`, which THIS VERY PIN is the
 * reason for, since a red here used to arrive under a job named "Path-repo
 * policy" and misdescribe itself (E589) — does NO `composer install` at all.
 * Nothing under `tools/` is a composer package, so there is no per-lib
 * `vendor/bin/phpunit` to borrow and the job installs a PHPUnit PHAR. There
 * is therefore no autoloader for `SugarCraft\Crush\Tests\*` in that job, and
 * `require`-ing the canonical guard to reach its scanner by reflection dies
 * before PHPUnit reports anything: VERIFIED on this box, PHP 8.3.6, it fatals
 * with `Trait "SugarCraft\Crush\Tests\Support\DiscardsErrorLogTrait" not found`.
 *
 * SO THE COPY IS PINNED INSTEAD OF WISHED AWAY.
 * {@see testTheScannerHasNotDriftedFromTheOneItWasCopiedFrom()} reads the
 * canonical implementation as TEXT — which needs no autoloader — and compares
 * the two token streams. Improve one and the other reds, naming the file to
 * port the change into. That is the difference between a copy and a fork, and
 * `DuplicatedTestHelperDriftTest` cannot make it — but not for the reason
 * first written here, and the reason is the half a reader checks.
 *
 * WHAT THIS SAID: "MEASURED, its roster is `sugar-crush/src` alone, so it would
 * never have seen this file." WHAT IS TRUE NOW: that named the wrong roster.
 * That guard walks `sugar-crush/tests/`. Its population comes from
 * `TestFileWalkTrait::everyTestFile()`, which it `use`s and which roots at
 * `\dirname(__DIR__)` from `tests/Support/`; every one of its censuses
 * iterates that, and its own failure text says so — "no private test helper was
 * found anywhere under tests/ — the walk is dead". The one direct
 * `RecursiveDirectoryIterator` over `src/` inside it belongs to
 * `declaredTypeNames()`, an auxiliary lookup answering "is this name a class in
 * this package", not the population it compares. WHY THIS STILL EARNS ITS
 * PLACE: the conclusion is unchanged and now rests on a fact that holds.
 * `tools/` is outside `sugar-crush/tests/` exactly as it is outside
 * `sugar-crush/src/`, so under either roster that guard never sees this file,
 * and the drift between this scanner and its canonical has to be pinned here.
 *
 * @internal
 */
final class StackedDocCommentTest extends TestCase
{
    /**
     * The canonical implementation this file's scanner is a copy of.
     */
    private const CANONICAL = '/sugar-crush/tests/Diagnostics/RuntimeNoticeSinkDeliveryTest.php';

    /**
     * No file under `tools/` carries a stacked doc-comment pair.
     */
    public function testNoToolsFileCarriesStackedDocComments(): void
    {
        $root = \dirname(__DIR__, 2);
        $files = self::toolsRoster($root);

        // THE ROSTER IS THE OTHER HALF OF THE FIXTURES BELOW (rule 15). A scan
        // of an EMPTY roster also yields [], so a fixture alone cannot tell a
        // working instrument from one pointed at nothing. These are named
        // because they exist, not counted — a cardinality here is stale the
        // moment anyone adds a script.
        self::assertContains($root . '/tools/gen-docs.php', $files);
        self::assertContains($root . '/tools/check-path-repos.php', $files);
        self::assertContains(__FILE__, $files);
        // AND THE HELPER THAT DECLARES NO TEST, which a `*Test.php` roster
        // would silently drop — the walk has to be by extension, not by suffix.
        self::assertContains($root . '/tools/tests/_bootstrap.php', $files);

        $stacked = [];
        foreach ($files as $file) {
            $source = file_get_contents($file);
            // RULE 14: a roster entry this cannot read must go RED, not
            // contribute a silent zero. MEASURED on this box, PHP 8.3.6,
            // `file_get_contents()` on a DIRECTORY returns the EMPTY STRING
            // rather than `false`, so `is_string()` alone is not the check.
            self::assertIsString($source, $file . ' is unreadable, so the scan over it is void');
            self::assertNotSame('', $source, $file . ' read as empty, so the scan over it is void');
            foreach (self::stackedDocCommentLines($source) as $line) {
                $stacked[] = substr($file, \strlen($root) + 1) . ':' . $line;
            }
        }

        self::assertSame(
            [],
            $stacked,
            'These files have doc-comments stacked immediately on top of each other at the '
            . 'file:line pairs listed. PHP attaches only the last of a run, so every earlier '
            . 'one documents nothing: its @return tag is off the method and its reasoning is '
            . 'orphaned. Merge them into one block, or move the stranded one down to the '
            . 'declaration it describes.',
        );

        // KNOWN-POSITIVE THROUGH THE SAME SCANNER IN THE SAME TEST (rule 15).
        // The assertion above is green with the scanner mutated to never match;
        // these are what say the instrument is alive.
        self::assertSame([2], self::stackedDocCommentLines(<<<'PHP'
            <?php
            /** First, which PHP will drop on the floor. */
            /** Second, which wins. */
            function f(): void {}
            PHP));

        self::assertSame([], self::stackedDocCommentLines(<<<'PHP'
            <?php
            /** One block. */
            function f(): void {}
            /** Another block, with a declaration between them. */
            function g(): void {}
            PHP));

        // AND ACROSS AN ATTRIBUTE, which an adjacency walk loses. PHP still
        // attaches only the LAST block, so the first is still stranded. The
        // nested array in the argument is deliberate: it kills both ways the
        // skip can be wrong — not skipping at all leaves `T_ATTRIBUTE` between
        // the blocks, and skipping to the FIRST `]` leaves the array's own
        // closing bracket there.
        self::assertSame([2], self::stackedDocCommentLines(<<<'PHP'
            <?php
            /** First, stranded across the attribute. */
            #[\Deprecated(['a' => [1, 2]])]
            /** Second, which wins. */
            function f(): void {}
            PHP));

        self::assertSame([], self::stackedDocCommentLines(<<<'PHP'
            <?php
            /** One attributed block. */
            #[\Deprecated(['a' => [1, 2]])]
            function f(): void {}
            /** Another block. */
            function g(): void {}
            PHP));
    }

    /**
     * The copy still matches the implementation it was copied from.
     *
     * A COPY IS ONLY DEFENSIBLE IF DIVERGENCE IS LOUD. The canonical scanner has
     * been improved at least once already — the attribute skip above was an
     * alphabet fix that changed what it finds, not a tidy-up — and a copy that
     * missed such a change would keep reporting a clean `tools/` using a scanner
     * that no longer knows what the shape is.
     *
     * COMPARED AS TOKENS, NOT TEXT, so reformatting and comment edits on either
     * side are free and only a change in what the code DOES reds. Read as text
     * rather than by reflection because the job that runs this file has no
     * autoloader — see this class's own doc-block.
     */
    public function testTheScannerHasNotDriftedFromTheOneItWasCopiedFrom(): void
    {
        $canonical = \dirname(__DIR__, 2) . self::CANONICAL;

        self::assertFileExists(
            $canonical,
            'the canonical stacked-doc-comment scanner has moved or been deleted. This file '
            . 'carries a COPY of it that is only defensible while divergence is loud, so '
            . 'update ' . basename(__FILE__) . '::CANONICAL to wherever it lives now — do not '
            . 'delete this guard, which would leave the copy unwatched',
        );

        $theirs = self::functionBodyTokens((string) file_get_contents($canonical), 'stackedDocCommentLines');
        $mine = self::functionBodyTokens((string) file_get_contents(__FILE__), 'stackedDocCommentLines');

        // RULE 14 IN BOTH DIRECTIONS: an extractor that found nothing would
        // answer [] === [] and pass. Neither side may be empty.
        self::assertNotSame([], $theirs, 'no stackedDocCommentLines() body was found in ' . self::CANONICAL);
        self::assertNotSame([], $mine, 'no stackedDocCommentLines() body was found in this file');

        self::assertSame(
            $theirs,
            $mine,
            'this file\'s copy of stackedDocCommentLines() has drifted from the canonical one '
            . 'in ' . self::CANONICAL . '. Port the change across rather than letting the two '
            . 'diverge: `tools/` would otherwise be scanned by an implementation that no '
            . 'longer agrees with the tree\'s about what the shape is',
        );
    }

    /**
     * The body extractor answers a source whose answer is already known.
     *
     * RULE 25. The drift pin above asserts two extractions are EQUAL, and an
     * extractor that always returned the same wrong thing — the empty list, or
     * the first function in the file whatever its name — satisfies that
     * perfectly. This is what makes the equality mean something.
     */
    public function testTheBodyExtractorReadsTheFunctionItIsAskedFor(): void
    {
        $source = <<<'PHP'
            <?php
            function decoy(): int { return 1; }
            function wanted(string $s): array { return [$s, 2]; }
            function after(): void {}
            PHP;

        self::assertSame(
            ['return', '[', '$s', ',', '2', ']', ';'],
            self::functionBodyTokens($source, 'wanted'),
            'the extractor did not return the body of the function it was asked for',
        );

        self::assertSame(
            ['return', '1', ';'],
            self::functionBodyTokens($source, 'decoy'),
            'the extractor returns the same body whatever name it is given',
        );

        self::assertSame(
            [],
            self::functionBodyTokens($source, 'absent'),
            'the extractor invented a body for a function that is not there',
        );

        // AND A NESTED BRACE MUST NOT CLOSE THE BODY EARLY, which is the one
        // way a brace-matching extractor silently truncates and still compares
        // equal to another truncated copy.
        self::assertSame(
            ['if', '(', 'true', ')', '{', 'return', '1', ';', '}', 'return', '2', ';'],
            self::functionBodyTokens(
                "<?php\nfunction wanted(): int { if (true) { return 1; } return 2; }\n",
                'wanted',
            ),
            'a nested brace closed the body early',
        );
    }

    /**
     * Every `.php` file under `tools/`, absolute and sorted.
     *
     * BY EXTENSION RATHER THAN BY `*Test.php`, deliberately: the scripts under
     * guard are `tools/gen-docs.php` and `tools/check-path-repos.php`, and
     * `_bootstrap.php` carries more prose than either test file does.
     *
     * @return list<string>
     */
    private static function toolsRoster(string $root): array
    {
        $files = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $root . '/tools',
            \FilesystemIterator::SKIP_DOTS,
        ));
        foreach ($walk as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * The significant tokens of one named function's body, or `[]`.
     *
     * Whitespace and comments are dropped so the comparison is about behaviour;
     * braces are matched so a nested block cannot end the body early.
     *
     * @return list<string>
     */
    private static function functionBodyTokens(string $source, string $name): array
    {
        $tokens = token_get_all($source);
        $count = \count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!\is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            $named = null;
            for ($j = $i + 1; $j < $count; $j++) {
                if (\is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    continue;
                }
                $named = $j;

                break;
            }
            if ($named === null || !\is_array($tokens[$named]) || $tokens[$named][1] !== $name) {
                continue;
            }

            $depth = 0;
            $body = [];
            for ($j = $named; $j < $count; $j++) {
                $token = $tokens[$j];
                if ($token === '{') {
                    $depth++;
                    if ($depth === 1) {
                        continue;
                    }
                } elseif ($token === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return $body;
                    }
                }
                if ($depth === 0) {
                    continue;
                }
                if (\is_array($token) && \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $body[] = \is_array($token) ? $token[1] : $token;
            }
        }

        return [];
    }

    /**
     * The 1-indexed lines of every doc-comment immediately followed by another
     * doc-comment, with nothing significant between them.
     *
     * A VERBATIM COPY. Change it only by porting a change made to the canonical
     * one; {@see testTheScannerHasNotDriftedFromTheOneItWasCopiedFrom()} reds
     * on any divergence, in either direction.
     *
     * @return list<int>
     */
    private static function stackedDocCommentLines(string $source): array
    {
        $significant = [];
        $attributeDepth = 0;
        foreach (token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_WHITESPACE, T_COMMENT], true)) {
                continue;
            }
            if ($attributeDepth > 0) {
                if ($token === '[') {
                    $attributeDepth++;
                } elseif ($token === ']') {
                    $attributeDepth--;
                }

                continue;
            }
            // `T_ATTRIBUTE` IS the opening `#[`, so the depth starts at one and
            // the matching `]` closes it.
            if (\is_array($token) && $token[0] === T_ATTRIBUTE) {
                $attributeDepth = 1;

                continue;
            }
            $significant[] = $token;
        }

        $lines = [];
        foreach ($significant as $i => $token) {
            if (!\is_array($token) || $token[0] !== T_DOC_COMMENT) {
                continue;
            }
            $next = $significant[$i + 1] ?? null;
            if (\is_array($next) && $next[0] === T_DOC_COMMENT) {
                $lines[] = $token[2];
            }
        }

        return $lines;
    }
}
