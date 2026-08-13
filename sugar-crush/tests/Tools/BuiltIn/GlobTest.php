<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Tools\BuiltIn\Glob;

/**
 * @see Glob
 *
 * The defect these cover: Glob delegated to PHP's native glob(), which has no
 * globstar support at all — it treats a doubled star as an ordinary
 * single-segment wildcard. A `**` pattern therefore returned ONLY the files
 * exactly one directory below the base, silently missing both the base
 * directory itself and everything deeper, with no error and no warning. The
 * tool's own inputSchema advertises that pattern shape as the example.
 */
final class GlobTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = realpath(sys_get_temp_dir()) . '/crush-glob-' . uniqid('', true);
        mkdir($this->root . '/a/b/c', 0o777, true);

        // depth 0, 1, 2 and 3 — one .php at each level, plus non-matching
        // siblings so an over-broad pattern shows up as a failure.
        file_put_contents($this->root . '/top.php', '<?php');
        file_put_contents($this->root . '/top.txt', 'text');
        file_put_contents($this->root . '/a/mid.php', '<?php');
        file_put_contents($this->root . '/a/note.txt', 'text');
        file_put_contents($this->root . '/a/b/deep.php', '<?php');
        file_put_contents($this->root . '/a/b/c/deeper.php', '<?php');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    // =========================================================================
    // Recursive `**` matching
    // =========================================================================

    public function testGlobstarMatchesTheBaseDirectoryItself(): void
    {
        $matched = $this->matchedPaths('**/*.php');

        // The zero-directory case: native glob() never returned this file.
        $this->assertContains($this->root . '/top.php', $matched);
    }

    public function testGlobstarMatchesOneLevelDown(): void
    {
        $this->assertContains($this->root . '/a/mid.php', $this->matchedPaths('**/*.php'));
    }

    public function testGlobstarMatchesThreeLevelsDown(): void
    {
        $this->assertContains($this->root . '/a/b/c/deeper.php', $this->matchedPaths('**/*.php'));
    }

    public function testGlobstarReturnsEveryDepthAndNothingElse(): void
    {
        $this->assertSame(
            [
                $this->root . '/a/b/c/deeper.php',
                $this->root . '/a/b/deep.php',
                $this->root . '/a/mid.php',
                $this->root . '/top.php',
            ],
            $this->matchedPaths('**/*.php'),
            'every .php at every depth, and no .txt',
        );
    }

    public function testGlobstarCanBeAnchoredToAnIntermediateDirectory(): void
    {
        $this->assertSame(
            [$this->root . '/a/b/c/deeper.php'],
            $this->matchedPaths('**/c/*.php'),
        );
    }

    /**
     * glob() never matches a leading dot with a wildcard; the recursive walk
     * must not either, or `**` would drag .git/ into every result set.
     */
    public function testGlobstarSkipsHiddenDirectories(): void
    {
        mkdir($this->root . '/.hidden');
        file_put_contents($this->root . '/.hidden/secret.php', '<?php');

        $this->assertNotContains($this->root . '/.hidden/secret.php', $this->matchedPaths('**/*.php'));
    }

    // =========================================================================
    // The non-recursive fast path stays byte-identical to glob()
    // =========================================================================

    public function testPlainStarPatternStaysSingleLevel(): void
    {
        $this->assertSame(
            [$this->root . '/top.php'],
            $this->matchedPaths('*.php'),
            'a pattern without ** must not start recursing',
        );
    }

    public function testExplicitSubdirectoryPatternIsUnaffected(): void
    {
        $this->assertSame([$this->root . '/a/mid.php'], $this->matchedPaths('a/*.php'));
    }

    public function testNonMatchingPatternReturnsEmptyContent(): void
    {
        $result = (new Glob())->execute([
            'pattern' => '*.rs',
            'path' => $this->root,
            'description' => 'find rust files',
        ]);

        $this->assertFalse($result->isError());
        $this->assertSame('', $result->content());
    }

    public function testEmptyPatternIsRejected(): void
    {
        $result = (new Glob())->execute(['pattern' => '', 'path' => $this->root]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('pattern cannot be empty', $result->content());
    }

    public function testMissingDirectoryIsRejectedWhenUnjailed(): void
    {
        $result = (new Glob())->execute([
            'pattern' => '**/*.php',
            'path' => $this->root . '/does-not-exist',
            'description' => 'find php files',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('directory not found', $result->content());
    }

    // =========================================================================
    // PathJail
    // =========================================================================

    public function testJailedGlobRejectsAPathOutsideTheWorkspaceRoot(): void
    {
        // Jail on the nested dir, then ask for its parent — the classic escape.
        $tool = new Glob(root: $this->root . '/a');

        $result = $tool->execute([
            'pattern' => '**/*.php',
            'path' => $this->root,
            'description' => 'escape the jail',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('outside workspace root', $result->content());
    }

    public function testJailedGlobRejectsATraversalPath(): void
    {
        $tool = new Glob(root: $this->root . '/a');

        $result = $tool->execute([
            'pattern' => '*.php',
            'path' => '../',
            'description' => 'escape the jail',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('outside workspace root', $result->content());
    }

    public function testJailedGlobStillRecursesInsideTheRoot(): void
    {
        $tool = new Glob(root: $this->root);

        $result = $tool->execute([
            'pattern' => '**/*.php',
            'path' => 'a',
            'description' => 'find php files under a',
        ]);

        $this->assertFalse($result->isError());
        $this->assertSame(
            [
                $this->root . '/a/b/c/deeper.php',
                $this->root . '/a/b/deep.php',
                $this->root . '/a/mid.php',
            ],
            $this->linesOf($result->content()),
        );
    }

    // =========================================================================
    // MAJOR-2 — the pattern is jailed too, not only the path
    // =========================================================================

    /**
     * PathJail::resolveDir() validates `path`; the non-recursive branch then
     * concatenated the RAW `pattern` onto the result, so `path: "."` plus
     * `pattern: "../*.php"` read the jail's parent while the jail reported
     * success.
     */
    public function testTraversalInThePatternEscapedTheJailAndIsNowRejected(): void
    {
        file_put_contents($this->root . '/OUTSIDE-SECRET.php', '<?php');
        $tool = new Glob(root: $this->root . '/a');

        $result = $tool->execute([
            'pattern' => '../*.php',
            'path' => '.',
            'description' => 'escape the jail through the pattern',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('may not contain a ".." segment', $result->content());
        $this->assertStringNotContainsString('OUTSIDE-SECRET', $result->content());
    }

    public function testTraversalIsRejectedInADeeperPatternSegmentToo(): void
    {
        $result = (new Glob(root: $this->root . '/a'))->execute([
            'pattern' => 'b/../../*.php',
            'path' => '.',
            'description' => 'escape the jail through the pattern',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('may not contain a ".."', $result->content());
    }

    /** A dot-dot inside a NAME is not a traversal — `a..b.php` is a filename. */
    public function testADoubleDotInsideAFilenameIsNotTreatedAsTraversal(): void
    {
        file_put_contents($this->root . '/a..b.php', '<?php');

        $result = (new Glob(root: $this->root))->execute([
            'pattern' => 'a..b.php',
            'path' => '.',
            'description' => 'a legitimate filename',
        ]);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame([$this->root . '/a..b.php'], $this->linesOf($result->content()));
    }

    /**
     * Second half of the jail: a symlink pointing out of the workspace needs
     * no `..` anywhere in the request.
     */
    public function testASymlinkedResultOutsideTheRootIsFilteredOut(): void
    {
        $outside = $this->root . '-outside';
        mkdir($outside);
        file_put_contents($outside . '/OUTSIDE-SECRET.php', '<?php');
        symlink($outside . '/OUTSIDE-SECRET.php', $this->root . '/a/escape.php');

        try {
            $result = (new Glob(root: $this->root))->execute([
                'pattern' => 'a/*.php',
                'path' => '.',
                'description' => 'follow the symlink out',
            ]);

            $this->assertFalse($result->isError(), $result->content());
            $this->assertStringNotContainsString('escape.php', $result->content());
            $this->assertSame([$this->root . '/a/mid.php'], $this->linesOf($result->content()));
        } finally {
            $this->removeTree($outside);
        }
    }

    public function testAnUnjailedGlobStillSeesItsOwnSymlinks(): void
    {
        // No root => no jail => nothing to filter against. Proves the
        // realpath() filter is jail enforcement, not a blanket symlink ban.
        symlink($this->root . '/top.php', $this->root . '/a/linked.php');

        $this->assertContains($this->root . '/a/linked.php', $this->matchedPaths('a/*.php'));
    }

    // =========================================================================
    // MAJOR-1 — output caps
    // =========================================================================

    public function testTheMatchCountIsCappedAndTheCapIsAnnounced(): void
    {
        for ($i = 0; $i < 20; $i++) {
            file_put_contents($this->root . '/a/b/c/bulk' . $i . '.php', '<?php');
        }

        $result = (new Glob(maxMatches: 5))->execute([
            'pattern' => '**/*.php',
            'path' => $this->root,
            'description' => 'find php files',
        ]);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertCount(5, array_filter(
            $this->linesOf($result->content()),
            static fn (string $l): bool => str_ends_with($l, '.php'),
        ));
        $this->assertStringContainsString('stopped after 5 matches', $result->content());
        $this->assertStringContainsString('PARTIAL', $result->content());
    }

    /** The non-recursive fast path is unbounded too — glob() has no ceiling. */
    public function testTheFastPathIsCappedAsWell(): void
    {
        for ($i = 0; $i < 20; $i++) {
            file_put_contents($this->root . '/bulk' . $i . '.php', '<?php');
        }

        $result = (new Glob(maxMatches: 4))->execute([
            'pattern' => '*.php',
            'path' => $this->root,
            'description' => 'find php files',
        ]);

        $this->assertCount(4, array_filter(
            $this->linesOf($result->content()),
            static fn (string $l): bool => str_ends_with($l, '.php'),
        ));
        $this->assertStringContainsString('stopped after 4 matches', $result->content());
    }

    public function testTheByteCapClipsTheListAndNamesWhatItDropped(): void
    {
        for ($i = 0; $i < 40; $i++) {
            file_put_contents($this->root . '/a/b/pad' . $i . '.php', '<?php');
        }

        $result = (new Glob(maxOutputBytes: 300))->execute([
            'pattern' => '**/*.php',
            'path' => $this->root,
            'description' => 'find php files',
        ]);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertLessThan(600, strlen($result->content()));
        $this->assertStringContainsString('truncated:', $result->content());
        $this->assertStringContainsString('bytes omitted', $result->content());
    }

    public function testAZeroCapDisablesTruncationForACallerThatOptsOut(): void
    {
        $result = (new Glob(maxOutputBytes: 0, maxMatches: 0))->execute([
            'pattern' => '**/*.php',
            'path' => $this->root,
            'description' => 'find php files',
        ]);

        $this->assertStringNotContainsString('truncated', $result->content());
        $this->assertCount(4, $this->linesOf($result->content()));
    }

    // =========================================================================
    // MAJOR-1 / NIT-2 — pruning
    // =========================================================================

    /** @return array<string, array{0: string}> */
    public static function prunedDirectoryProvider(): array
    {
        return [
            'git metadata' => ['.git'],
            'composer deps' => ['vendor'],
            'npm deps' => ['node_modules'],
            'phpunit cache' => ['.phpunit.cache'],
        ];
    }

    /**
     * @dataProvider prunedDirectoryProvider
     */
    public function testMachineGeneratedTreesArePrunedFromTheWalk(string $dir): void
    {
        mkdir($this->root . '/' . $dir . '/deep', 0o777, true);
        file_put_contents($this->root . '/' . $dir . '/deep/noise.php', '<?php');

        $this->assertNotContains(
            $this->root . '/' . $dir . '/deep/noise.php',
            $this->matchedPaths('**/*.php'),
        );
    }

    /**
     * @dataProvider prunedDirectoryProvider
     */
    public function testNamingAPrunedDirectoryInThePatternOptsBackIn(string $dir): void
    {
        mkdir($this->root . '/' . $dir . '/deep', 0o777, true);
        file_put_contents($this->root . '/' . $dir . '/deep/wanted.php', '<?php');

        $this->assertContains(
            $this->root . '/' . $dir . '/deep/wanted.php',
            $this->matchedPaths($dir . '/**/*.php'),
        );
    }

    public function testSearchingFromInsideAPrunedDirectoryOptsBackIn(): void
    {
        mkdir($this->root . '/vendor/pkg/src', 0o777, true);
        file_put_contents($this->root . '/vendor/pkg/src/Lib.php', '<?php');

        $result = (new Glob())->execute([
            'pattern' => '**/*.php',
            'path' => $this->root . '/vendor/pkg',
            'description' => 'search inside vendor deliberately',
        ]);

        $this->assertSame(
            [$this->root . '/vendor/pkg/src/Lib.php'],
            $this->linesOf($result->content()),
        );
    }

    // =========================================================================
    // Pruning has to ANNOUNCE itself
    // =========================================================================

    /**
     * The match cap and the byte cap both say so; the prune said nothing at
     * all. The failure that exposes it is a project whose first-party source
     * legitimately lives under vendor/ (Magento 2, or any app vendoring its
     * own modules): `**\/Controller.php` came back content='' isError=false —
     * a confident "that file does not exist" about a file that does.
     *
     * @dataProvider prunedDirectoryProvider
     */
    public function testASkippedDirectoryIsNamedInTheResult(string $dir): void
    {
        mkdir($this->root . '/' . $dir . '/deep', 0o777, true);
        file_put_contents($this->root . '/' . $dir . '/deep/noise.php', '<?php');

        $result = (new Glob())->execute([
            'pattern' => '**/*.php',
            'path' => $this->root,
            'description' => 'find php files',
        ]);

        $this->assertStringContainsString('pruned:', $result->content());
        $this->assertStringContainsString($dir, $result->content());
    }

    /** The note has to carry the escape hatch, not just the bad news. */
    public function testThePruneNoteExplainsHowToOptBackIn(): void
    {
        mkdir($this->root . '/vendor/acme', 0o777, true);
        file_put_contents($this->root . '/vendor/acme/Dep.php', '<?php');

        $result = (new Glob())->execute([
            'pattern' => '**/*.php',
            'path' => $this->root,
            'description' => 'find php files',
        ]);

        $this->assertStringContainsString('vendor/**/*.php', $result->content());
        $this->assertStringContainsString('point path inside it', $result->content());
    }

    /**
     * The exact production repro: a real file, found by nothing, reported as
     * an empty non-error result.
     */
    public function testAnEmptyResultCausedByPruningSaysSoInsteadOfLookingDefinitive(): void
    {
        mkdir($this->root . '/vendor/acme/mod', 0o777, true);
        file_put_contents($this->root . '/vendor/acme/mod/Controller.php', '<?php');

        $result = (new Glob())->execute([
            'pattern' => '**/Controller.php',
            'path' => $this->root,
            'description' => 'find the controller',
        ]);

        $this->assertSame([], $this->linesOf($result->content()), 'still pruned, as designed');
        $this->assertStringContainsString('pruned:', $result->content(), 'but no longer silent about it');
        $this->assertStringContainsString('vendor', $result->content());
    }

    /** No note when the walk did not actually skip anything — the annotation
     *  has to mean something when it appears. */
    public function testNothingIsAnnouncedWhenNothingWasPruned(): void
    {
        $this->assertStringNotContainsString(
            'pruned:',
            (new Glob())->execute([
                'pattern' => '**/*.php',
                'path' => $this->root,
                'description' => 'find php files',
            ])->content(),
        );
    }

    /** Opting back in means the directory was walked, so there is nothing to
     *  announce. */
    public function testNoPruneNoteWhenThePatternNamedTheDirectory(): void
    {
        mkdir($this->root . '/vendor/acme', 0o777, true);
        file_put_contents($this->root . '/vendor/acme/Dep.php', '<?php');

        $result = (new Glob())->execute([
            'pattern' => 'vendor/**/*.php',
            'path' => $this->root,
            'description' => 'search vendor deliberately',
        ]);

        $this->assertContains($this->root . '/vendor/acme/Dep.php', $this->linesOf($result->content()));
        $this->assertStringNotContainsString('pruned:', $result->content());
    }

    /** The prune note is the shortest and most actionable part of the result,
     *  so the byte cap must not be what eats it. */
    public function testThePruneNoteSurvivesAByteCapThatClipsTheList(): void
    {
        mkdir($this->root . '/vendor', 0o777, true);
        file_put_contents($this->root . '/vendor/dep.php', '<?php');
        for ($i = 0; $i < 60; $i++) {
            file_put_contents($this->root . '/a/b/padded-name-' . $i . '.php', '<?php');
        }

        $result = (new Glob(maxOutputBytes: 400))->execute([
            'pattern' => '**/*.php',
            'path' => $this->root,
            'description' => 'find php files',
        ]);

        $this->assertStringContainsString('truncated:', $result->content());
        $this->assertStringContainsString('pruned:', $result->content());
    }

    public function testAnEmptyPruneListWalksEverything(): void
    {
        mkdir($this->root . '/vendor');
        file_put_contents($this->root . '/vendor/dep.php', '<?php');

        $result = (new Glob(prunedDirs: []))->execute([
            'pattern' => '**/*.php',
            'path' => $this->root,
            'description' => 'walk everything',
        ]);

        $this->assertContains($this->root . '/vendor/dep.php', $this->linesOf($result->content()));
    }

    /**
     * NIT-2: `$wantsHidden` was a whole-pattern flag, so a pattern merely
     * STARTING with a dot re-enabled hidden traversal everywhere and the walk
     * descended into .git/ in full. The decision is per-segment now.
     */
    public function testNamingOneHiddenDirectoryDoesNotOpenAllOfThem(): void
    {
        mkdir($this->root . '/.github/workflows', 0o777, true);
        file_put_contents($this->root . '/.github/workflows/ci.yml', 'on: push');
        mkdir($this->root . '/.hidden/nested', 0o777, true);
        file_put_contents($this->root . '/.hidden/nested/other.yml', 'x');

        $matched = $this->matchedPaths('.github/**/*.yml');

        $this->assertContains($this->root . '/.github/workflows/ci.yml', $matched);
        $this->assertNotContains($this->root . '/.hidden/nested/other.yml', $matched);
    }

    public function testAWildcardHiddenSegmentStillOpensEveryHiddenDirectory(): void
    {
        mkdir($this->root . '/.one', 0o777, true);
        mkdir($this->root . '/.two', 0o777, true);
        file_put_contents($this->root . '/.one/x.yml', 'x');
        file_put_contents($this->root . '/.two/y.yml', 'y');

        $matched = $this->matchedPaths('.*/**/*.yml');

        $this->assertContains($this->root . '/.one/x.yml', $matched);
        $this->assertContains($this->root . '/.two/y.yml', $matched);
    }

    // =========================================================================
    // MINOR-1 — a malformed bracket expression is one error, not a warning storm
    // =========================================================================

    /**
     * `[z-a]` compiles to an invalid PCRE. The old code compiled the regex
     * inside the walk loop, so it emitted one `preg_match(): Compilation
     * failed` warning PER WALKED ENTRY — on a large tree, six figures of them
     * painted over the TUI frame — and then returned isError=false with empty
     * content, i.e. a confident "nothing matches" for a pattern that never
     * compiled.
     */
    public function testAnUncompilablePatternIsOneCleanErrorNotASilentEmptyResult(): void
    {
        $warnings = [];
        set_error_handler(static function (int $no, string $str) use (&$warnings): bool {
            $warnings[] = $str;

            return true;
        });

        try {
            $result = (new Glob())->execute([
                'pattern' => '**/[z-a].php',
                'path' => $this->root,
                'description' => 'a reversed range',
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($result->isError(), 'a pattern that cannot compile is a bad request');
        $this->assertStringContainsString('not a valid glob', $result->content());
        $this->assertSame([], $warnings, 'not one preg_match() warning may escape');
    }

    /**
     * `[]]` is the POSIX idiom for a literal `]`, so the terminator search has
     * to start past a leading `]`. Reading it as the terminator produced the
     * empty class `[]` — invalid PCRE, and the source of the storm above.
     */
    public function testAPosixLiteralCloseBracketClassCompilesAndMatches(): void
    {
        file_put_contents($this->root . '/a/].php', '<?php');

        $matched = $this->matchedPaths('**/[]].php');

        $this->assertContains($this->root . '/a/].php', $matched);
        $this->assertNotContains($this->root . '/a/mid.php', $matched);
    }

    public function testANegatedPosixLiteralCloseBracketClassCompiles(): void
    {
        file_put_contents($this->root . '/a/].php', '<?php');
        file_put_contents($this->root . '/a/q.php', '<?php');

        $matched = $this->matchedPaths('**/[!]].php');

        $this->assertContains($this->root . '/a/q.php', $matched);
        $this->assertNotContains($this->root . '/a/].php', $matched);
    }

    /** @return array<string, array{0: string}> */
    public static function malformedBracketProvider(): array
    {
        return [
            'empty class'          => ['**/[]x.php'],
            'negation only'        => ['**/[!]'],
            'unterminated class'   => ['**/[abc.php'],
            'unterminated negated' => ['**/[!abc.php'],
        ];
    }

    /**
     * An unterminated `[` is a literal `[` to glob(), so it stays a literal
     * here: a valid PCRE that simply matches nothing, with no warning.
     *
     * @dataProvider malformedBracketProvider
     */
    public function testAnUnterminatedBracketDegradesToALiteralWithoutWarning(string $pattern): void
    {
        $warnings = [];
        set_error_handler(static function (int $no, string $str) use (&$warnings): bool {
            $warnings[] = $str;

            return true;
        });

        try {
            $result = (new Glob())->execute([
                'pattern' => $pattern,
                'path' => $this->root,
                'description' => 'a malformed bracket expression',
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame('', $result->content());
        $this->assertSame([], $warnings);
    }

    public function testAnUnterminatedBracketMatchesTheLiteralBracketFile(): void
    {
        file_put_contents($this->root . '/a/[abc.php', '<?php');

        $this->assertContains($this->root . '/a/[abc.php', $this->matchedPaths('**/[abc.php'));
    }

    // =========================================================================
    // NIT-4 — the rest of patternToRegex()'s branches
    // =========================================================================

    public function testQuestionMarkMatchesExactlyOneCharacter(): void
    {
        file_put_contents($this->root . '/a/xid.php', '<?php');

        $matched = $this->matchedPaths('**/?id.php');

        $this->assertContains($this->root . '/a/mid.php', $matched);
        $this->assertContains($this->root . '/a/xid.php', $matched);
        $this->assertNotContains($this->root . '/a/b/deep.php', $matched);
    }

    public function testQuestionMarkDoesNotCrossASeparator(): void
    {
        // `a?mid.php` must not match `a/mid.php`.
        $this->assertSame([], $this->matchedPaths('**/a?mid.php'));
    }

    public function testCharacterClassMatchesARange(): void
    {
        file_put_contents($this->root . '/a/Zed.php', '<?php');

        $matched = $this->matchedPaths('**/[a-z]*.php');

        $this->assertContains($this->root . '/a/mid.php', $matched);
        $this->assertNotContains($this->root . '/a/Zed.php', $matched);
    }

    public function testNegatedCharacterClassExcludesTheRange(): void
    {
        file_put_contents($this->root . '/a/Zed.php', '<?php');

        $matched = $this->matchedPaths('**/[!a-z]*.php');

        $this->assertContains($this->root . '/a/Zed.php', $matched);
        $this->assertNotContains($this->root . '/a/mid.php', $matched);
    }

    public function testGlobstarInTheMiddleSpansZeroSegments(): void
    {
        mkdir($this->root . '/x/y', 0o777, true);
        file_put_contents($this->root . '/x/target.php', '<?php');
        file_put_contents($this->root . '/x/y/target.php', '<?php');

        $matched = $this->matchedPaths('x/**/target.php');

        $this->assertContains($this->root . '/x/target.php', $matched, 'zero intervening segments');
        $this->assertContains($this->root . '/x/y/target.php', $matched, 'one intervening segment');
    }

    /**
     * Documented deviation (i) from bash globstar: a `**` NOT followed by `/`
     * compiles to `.*`, which CROSSES `/`. bash demotes it to a single-segment
     * `*`. Pinned so the deviation cannot drift unnoticed.
     */
    public function testGlobstarNotFollowedBySlashDeliberatelyCrossesSeparators(): void
    {
        $matched = $this->matchedPaths('**.php');

        $this->assertContains($this->root . '/top.php', $matched);
        $this->assertContains($this->root . '/a/b/c/deeper.php', $matched, 'bash would not match this');
    }

    /**
     * Documented deviation (ii): `a/**` does not match `a` itself, where bash
     * globstar includes the directory.
     */
    public function testTrailingGlobstarDoesNotMatchTheNamedDirectoryItself(): void
    {
        $matched = $this->matchedPaths('a/**');

        $this->assertNotContains($this->root . '/a', $matched);
        $this->assertContains($this->root . '/a/mid.php', $matched);
    }

    /** SELF_FIRST means a pattern can match a directory, exactly as glob() does. */
    public function testADirectoryCanMatchUnderSelfFirst(): void
    {
        $this->assertContains($this->root . '/a/b/c', $this->matchedPaths('**/c'));
    }

    /**
     * RecursiveDirectoryIterator::hasChildren() defaults to $allowLinks=false,
     * so the walk does not descend a symlinked directory. Pinned because the
     * alternative is an infinite loop on a self-referential link.
     */
    public function testTheWalkDoesNotDescendIntoASymlinkedDirectory(): void
    {
        symlink($this->root . '/a', $this->root . '/link');

        $matched = $this->matchedPaths('**/*.php');

        $this->assertContains($this->root . '/a/mid.php', $matched);
        $this->assertNotContains($this->root . '/link/mid.php', $matched);
    }

    // =========================================================================
    // Collaborators the recursive rewrite must not have dropped
    // =========================================================================

    public function testNestedInstructionFileContentIsStillPrependedPerMatch(): void
    {
        file_put_contents($this->root . '/a/AGENTS.md', 'NESTED INSTRUCTION MARKER');

        $result = (new Glob(
            root: null,
            instructionLoader: new InstructionFileLoader($this->root),
        ))->execute([
            'pattern' => '**/*.php',
            'path' => $this->root,
            'description' => 'find php files',
        ]);

        $this->assertStringContainsString('NESTED INSTRUCTION MARKER', $result->content());
    }

    // =========================================================================
    // Base-directory edge cases
    // =========================================================================

    /**
     * `rtrim($path, '/')` turned "/" into "", and an empty string is a
     * \ValueError out of RecursiveDirectoryIterator — an \Error, so the
     * \UnexpectedValueException catch never saw it. Runtime containment now
     * stops it killing the turn, but the model still got a raw internal PHP
     * message for a plausible input.
     */
    public function testTheRootDirectoryIsAValidBaseNotAValueError(): void
    {
        $result = (new Glob(maxMatches: 1))->execute([
            'pattern' => '*-a-name-nothing-on-this-filesystem-has',
            'path' => '/',
            'description' => 'probe the root directory',
        ]);

        $this->assertStringNotContainsString('ValueError', $result->content());
        $this->assertStringNotContainsString('cannot be empty', $result->content());
        $this->assertSame([], $this->linesOf($result->content()));
    }

    /**
     * The RECURSIVE branch at "/", which the \ValueError test above never
     * reached: its pattern carries no `**`, so $regex stayed null and the call
     * took the glob() fast path.
     *
     * RecursiveDirectoryIterator spells every pathname as the string it was
     * HANDED plus "/" plus the filename, so seeded with "/" it emits
     * "//admin". Measuring the prefix to strip from the rtrim'd base instead
     * left a leading "/" on each relative path, the anchored regex matched
     * none of them, and the call answered "no such files" with isError=false.
     * Nothing matching also meant the match cap never engaged, so the walk ran
     * the entire filesystem to exhaustion before returning that wrong answer.
     */
    public function testARecursiveGlobAtTheRootDirectoryStillMatches(): void
    {
        // A small cap keeps this hermetic: SELF_FIRST reaches it within the
        // first handful of top-level entries, so the test never depends on the
        // size or shape of the machine's filesystem.
        $result = (new Glob(maxMatches: 5))->execute([
            'pattern' => '**/*',
            'path' => '/',
            'description' => 'walk the filesystem root',
        ]);

        $this->assertFalse($result->isError(), $result->content());

        $paths = $this->linesOf($result->content());
        $this->assertNotSame([], $paths, 'a recursive walk of "/" cannot legitimately come back empty');

        $this->assertNotSame(
            [],
            array_filter($paths, static fn (string $p): bool => preg_match('#^/[^/]+$#', $p) === 1),
            'the cap is hit on top-level entries first, so at least one direct child of "/" must be here',
        );
    }

    /**
     * A path handed back to the model is a path it feeds to Read or Bash next,
     * so the iterator's "//admin" spelling must not survive into the result.
     */
    public function testARecursiveGlobAtTheRootDirectoryEmitsSingleSlashPaths(): void
    {
        $result = (new Glob(maxMatches: 5))->execute([
            'pattern' => '**/*',
            'path' => '/',
            'description' => 'walk the filesystem root',
        ]);

        $paths = $this->linesOf($result->content());
        $this->assertNotSame([], $paths);

        foreach ($paths as $path) {
            $this->assertStringStartsWith('/', $path);
            $this->assertStringNotContainsString('//', $path);
        }
    }

    /** With matching repaired, the cap has to fire — that is what stops the
     *  walk instead of letting it grind through the whole filesystem. */
    public function testARecursiveGlobAtTheRootDirectoryStopsAtTheMatchCap(): void
    {
        $result = (new Glob(maxMatches: 5))->execute([
            'pattern' => '**/*',
            'path' => '/',
            'description' => 'walk the filesystem root',
        ]);

        $this->assertCount(5, $this->linesOf($result->content()));
        $this->assertStringContainsString('stopped after 5 matches', $result->content());
    }

    /** A trailing slash on an ordinary path is equally harmless, and must not
     *  shift the relative-path arithmetic the pattern matches against. */
    public function testATrailingSlashOnTheBaseDirectoryChangesNothing(): void
    {
        $this->assertSame(
            $this->matchedPaths('**/*.php'),
            $this->linesOf((new Glob())->execute([
                'pattern' => '**/*.php',
                'path' => $this->root . '/',
                'description' => 'find php files',
            ])->content()),
        );
    }

    /**
     * A permission problem is not "no matches". Reported as content=''
     * isError=false it is the same doctrine violation as a silent prune: a
     * definitive-sounding empty answer to a question nobody was allowed to
     * ask.
     */
    public function testAnUnreadableBaseDirectoryIsAnErrorNotAnEmptyAnswer(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores directory permissions, so there is nothing to deny');
        }

        $locked = $this->root . '/locked';
        mkdir($locked . '/sub', 0o777, true);
        file_put_contents($locked . '/sub/hidden-from-us.php', '<?php');
        chmod($locked, 0o000);

        try {
            $result = (new Glob())->execute([
                'pattern' => '**/*.php',
                'path' => $locked,
                'description' => 'search a locked directory',
            ]);

            $this->assertTrue($result->isError(), 'a permission failure must not read as "no matches"');
            $this->assertStringContainsString($locked, $result->content(), 'and it has to name the directory');
        } finally {
            // Restored so tearDown can remove the tree.
            chmod($locked, 0o755);
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @return list<string> */
    private function matchedPaths(string $pattern): array
    {
        $result = (new Glob())->execute([
            'pattern' => $pattern,
            'path' => $this->root,
            'description' => 'find matching files',
        ]);

        $this->assertFalse($result->isError(), $result->content());

        return $this->linesOf($result->content());
    }

    /**
     * The PATH lines of a result.
     *
     * `... [` prefixed lines are the tool's own annotations — the match cap,
     * the byte cap, the prune note — and folding them in would make every
     * path assertion depend on which annotations happened to fire.
     *
     * @return list<string>
     */
    private function linesOf(string $content): array
    {
        $lines = array_values(array_filter(
            explode("\n", $content),
            static fn (string $l): bool => $l !== '' && !str_starts_with($l, '... ['),
        ));
        sort($lines);

        return $lines;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            // is_link() FIRST: is_dir() follows a symlink, so recursing would
            // empty the link's target instead of removing the link.
            if (is_link($path) || !is_dir($path)) {
                unlink($path);
                continue;
            }
            $this->removeTree($path);
        }

        rmdir($dir);
    }
}
