<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\IgnoreRules;

/**
 * @see IgnoreRules
 *
 * The defect these cover (crush_code.md Phase 8 item 7): `Glob` and `Grep`
 * walked into `vendor/`, `node_modules/`, build output and anything else the
 * project had told git to ignore — burning the turn's context on generated
 * files, and surfacing secrets out of ignored local config.
 */
final class IgnoreRulesTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = realpath(sys_get_temp_dir()) . '/crush-ignore-' . uniqid('', true);
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    // =========================================================================
    // The default-exclude list — in force with no .gitignore at all
    // =========================================================================

    /** @return array<string, array{0: string}> */
    public static function defaultExcludedProvider(): array
    {
        return [
            'git metadata' => ['.git'],
            'composer deps' => ['vendor'],
            'npm deps' => ['node_modules'],
            'phpunit cache' => ['.phpunit.cache'],
        ];
    }

    /** @dataProvider defaultExcludedProvider */
    public function testTheDefaultListAppliesWithoutAnyGitignore(string $dir): void
    {
        $rules = IgnoreRules::new($this->root);

        $this->assertTrue($rules->excludesDirectoryNamed($dir));
        $this->assertSame($dir, $rules->excludedDirectoryIn($this->root . '/' . $dir . '/deep/x.php'));
    }

    public function testAPathOutsideTheRootHasNoVerdict(): void
    {
        $rules = IgnoreRules::new($this->root);

        $this->assertNull($rules->excludedDirectoryIn('/somewhere/else/vendor/x.php'));
        $this->assertFalse($rules->ignores('/somewhere/else/x.php', false));
    }

    public function testNamingAnExcludedDirectoryDropsItFromTheList(): void
    {
        $rules = IgnoreRules::new($this->root)->withoutExcludedDirs(['vendor']);

        $this->assertFalse($rules->excludesDirectoryNamed('vendor'));
        $this->assertTrue($rules->excludesDirectoryNamed('node_modules'), 'only the named one');
    }

    public function testTheExcludeListReachesGrepAsExcludeDirFlags(): void
    {
        $flags = IgnoreRules::new($this->root)->grepExcludeFlags();

        foreach (IgnoreRules::DEFAULT_EXCLUDED_DIRS as $dir) {
            $this->assertStringContainsString('--exclude-dir=' . escapeshellarg($dir), $flags);
        }
    }

    /**
     * grep has no re-include flag, so a gitignore rule pushed onto its command
     * line could never be taken back by a `!` negation. The flags therefore
     * carry ONLY the default list.
     */
    public function testGitignorePatternsAreNeverPushedOntoGrepsCommandLine(): void
    {
        $this->write('.gitignore', "secrets.env\n");

        $this->assertStringNotContainsString('secrets.env', IgnoreRules::new($this->root)->grepExcludeFlags());
    }

    // =========================================================================
    // .gitignore syntax
    // =========================================================================

    public function testCommentsAndBlankLinesAreNotPatterns(): void
    {
        $this->write('.gitignore', "# build\n\n   \nreal.log\n");
        $rules = IgnoreRules::new($this->root);

        $this->assertTrue($rules->ignores($this->root . '/real.log', false));
        $this->assertFalse($rules->ignores($this->root . '/build', true), 'the comment is not a pattern');
    }

    public function testAnEscapedHashIsALiteralName(): void
    {
        $this->write('.gitignore', "\\#notes\n");

        $this->assertTrue(IgnoreRules::new($this->root)->ignores($this->root . '/#notes', false));
    }

    public function testTrailingWhitespaceIsStrippedUnlessEscaped(): void
    {
        $this->write('.gitignore', "loose.txt   \ntight\\ \n");
        $rules = IgnoreRules::new($this->root);

        $this->assertTrue($rules->ignores($this->root . '/loose.txt', false));
        $this->assertTrue($rules->ignores($this->root . '/tight ', false), 'the escaped space is part of the name');
        $this->assertFalse($rules->ignores($this->root . '/tight', false));
    }

    public function testABarePatternMatchesAtAnyDepth(): void
    {
        $this->write('.gitignore', "notes.md\n");
        $rules = IgnoreRules::new($this->root);

        $this->assertTrue($rules->ignores($this->root . '/notes.md', false));
        $this->assertTrue($rules->ignores($this->root . '/a/b/notes.md', false));
    }

    public function testALeadingSlashAnchorsThePatternToTheRoot(): void
    {
        $this->write('.gitignore', "/notes.md\n");
        $rules = IgnoreRules::new($this->root);

        $this->assertTrue($rules->ignores($this->root . '/notes.md', false));
        $this->assertFalse($rules->ignores($this->root . '/a/notes.md', false), 'anchored means top level only');
    }

    public function testASlashInTheMiddleAnchorsThePatternToo(): void
    {
        $this->write('.gitignore', "doc/notes.md\n");
        $rules = IgnoreRules::new($this->root);

        $this->assertTrue($rules->ignores($this->root . '/doc/notes.md', false));
        $this->assertFalse($rules->ignores($this->root . '/a/doc/notes.md', false));
    }

    public function testATrailingSlashMatchesOnlyDirectories(): void
    {
        $this->write('.gitignore', "cache/\n");
        $rules = IgnoreRules::new($this->root);

        $this->assertTrue($rules->ignores($this->root . '/cache', true));
        $this->assertFalse($rules->ignores($this->root . '/cache', false), 'a FILE named cache is not ignored');
    }

    /** A directory-only pattern still hides everything beneath it. */
    public function testADirectoryOnlyPatternAlsoHidesItsContents(): void
    {
        $this->write('.gitignore', "cache/\n");

        $this->assertTrue(IgnoreRules::new($this->root)->ignores($this->root . '/cache/deep/x.php', false));
    }

    public function testWildcardsDoNotCrossASeparator(): void
    {
        $this->write('.gitignore', "/a/*.log\n");
        $rules = IgnoreRules::new($this->root);

        $this->assertTrue($rules->ignores($this->root . '/a/one.log', false));
        $this->assertFalse($rules->ignores($this->root . '/a/b/one.log', false));
    }

    public function testQuestionMarkMatchesExactlyOneCharacter(): void
    {
        $this->write('.gitignore', "/log?.txt\n");
        $rules = IgnoreRules::new($this->root);

        $this->assertTrue($rules->ignores($this->root . '/log1.txt', false));
        $this->assertFalse($rules->ignores($this->root . '/log12.txt', false));
    }

    public function testCharacterClassesAreHonoured(): void
    {
        $this->write('.gitignore', "/log[0-9].txt\n!/log[!0-9].txt\n");
        $rules = IgnoreRules::new($this->root);

        $this->assertTrue($rules->ignores($this->root . '/log7.txt', false));
        $this->assertFalse($rules->ignores($this->root . '/logX.txt', false));
    }

    public function testALeadingGlobstarMatchesAtAnyDepth(): void
    {
        $this->write('.gitignore', "**/tmp.txt\n");
        $rules = IgnoreRules::new($this->root);

        $this->assertTrue($rules->ignores($this->root . '/tmp.txt', false));
        $this->assertTrue($rules->ignores($this->root . '/a/b/tmp.txt', false));
    }

    public function testATrailingGlobstarMatchesEverythingBelow(): void
    {
        $this->write('.gitignore', "/out/**\n");
        $rules = IgnoreRules::new($this->root);

        $this->assertTrue($rules->ignores($this->root . '/out/a/b.txt', false));
        $this->assertFalse($rules->ignores($this->root . '/outside.txt', false));
    }

    public function testAMiddleGlobstarSpansZeroOrMoreDirectories(): void
    {
        $this->write('.gitignore', "/a/**/t.txt\n");
        $rules = IgnoreRules::new($this->root);

        $this->assertTrue($rules->ignores($this->root . '/a/t.txt', false), 'zero intervening directories');
        $this->assertTrue($rules->ignores($this->root . '/a/b/c/t.txt', false));
    }

    public function testAMalformedPatternIsSkippedRatherThanFailingTheSearch(): void
    {
        $this->write('.gitignore', "/[z-a].txt\n/real.txt\n");
        $rules = IgnoreRules::new($this->root);

        $this->assertTrue($rules->ignores($this->root . '/real.txt', false), 'the good rule still applies');
        $this->assertFalse($rules->ignores($this->root . '/anything.txt', false));
    }

    // =========================================================================
    // Negation
    // =========================================================================

    public function testALaterNegationWins(): void
    {
        $this->write('.gitignore', "*.log\n!keep.log\n");
        $rules = IgnoreRules::new($this->root);

        $this->assertTrue($rules->ignores($this->root . '/drop.log', false));
        $this->assertFalse($rules->ignores($this->root . '/keep.log', false));
    }

    public function testAReIgnoreAfterANegationWinsAgain(): void
    {
        $this->write('.gitignore', "*.log\n!keep.log\nkeep.log\n");

        $this->assertTrue(IgnoreRules::new($this->root)->ignores($this->root . '/keep.log', false));
    }

    /**
     * Git's documented limitation, reproduced deliberately: "it is not
     * possible to re-include a file if a parent directory of that file is
     * excluded". Answering otherwise would show paths `git status` never will.
     */
    public function testANegationCannotResurrectAFileUnderAnExcludedDirectory(): void
    {
        $this->write('.gitignore', "out/\n!out/keep.txt\n");

        $this->assertTrue(IgnoreRules::new($this->root)->ignores($this->root . '/out/keep.txt', false));
    }

    public function testAnEscapedBangIsALiteralName(): void
    {
        $this->write('.gitignore', "\\!important\n");

        $this->assertTrue(IgnoreRules::new($this->root)->ignores($this->root . '/!important', false));
    }

    // =========================================================================
    // Nested .gitignore files
    // =========================================================================

    public function testANestedGitignoreAppliesToItsOwnSubtree(): void
    {
        mkdir($this->root . '/pkg', 0o777, true);
        $this->write('pkg/.gitignore', "local.php\n");
        $rules = IgnoreRules::new($this->root);

        $this->assertTrue($rules->ignores($this->root . '/pkg/local.php', false));
        $this->assertFalse($rules->ignores($this->root . '/local.php', false), 'and not to its siblings');
    }

    public function testANestedGitignoreOverridesTheRootOne(): void
    {
        mkdir($this->root . '/pkg', 0o777, true);
        $this->write('.gitignore', "*.log\n");
        $this->write('pkg/.gitignore', "!*.log\n");
        $rules = IgnoreRules::new($this->root);

        $this->assertTrue($rules->ignores($this->root . '/top.log', false));
        $this->assertFalse($rules->ignores($this->root . '/pkg/deep.log', false), 'the deeper file wins');
    }

    public function testANestedGitignoreAnchorsToItsOwnDirectory(): void
    {
        mkdir($this->root . '/pkg/sub', 0o777, true);
        $this->write('pkg/.gitignore', "/local.php\n");
        $rules = IgnoreRules::new($this->root);

        $this->assertTrue($rules->ignores($this->root . '/pkg/local.php', false));
        $this->assertFalse($rules->ignores($this->root . '/pkg/sub/local.php', false));
    }

    public function testGitInfoExcludeIsHonouredButRanksBelowGitignore(): void
    {
        mkdir($this->root . '/.git/info', 0o777, true);
        $this->write('.git/info/exclude', "scratch.txt\nnotes.txt\n");
        $this->write('.gitignore', "!notes.txt\n");
        $rules = IgnoreRules::new($this->root);

        $this->assertTrue($rules->ignores($this->root . '/scratch.txt', false));
        $this->assertFalse($rules->ignores($this->root . '/notes.txt', false), '.gitignore outranks info/exclude');
    }

    // =========================================================================
    // Overrides
    // =========================================================================

    public function testGitignoreCanBeTurnedOffEntirely(): void
    {
        $this->write('.gitignore', "secret.env\n");

        $this->assertFalse(
            IgnoreRules::new($this->root)->withGitignore(false)->ignores($this->root . '/secret.env', false),
        );
    }

    public function testWithersReturnNewInstances(): void
    {
        $rules = IgnoreRules::new($this->root);

        $this->assertNotSame($rules, $rules->withGitignore(false));
        $this->assertNotSame($rules, $rules->withExcludedDirs([]));
        $this->assertNotSame($rules, $rules->withFollowSymlinks(true));
        $this->assertTrue($rules->honoursGitignore, 'the original is untouched');
        $this->assertSame(IgnoreRules::DEFAULT_EXCLUDED_DIRS, $rules->excludedDirs);
        $this->assertFalse($rules->followsSymlinks);
    }

    // =========================================================================
    // The symlink hard stop
    // =========================================================================

    public function testASymlinkedDirectoryIsAHardStop(): void
    {
        mkdir($this->root . '/real', 0o777, true);
        symlink($this->root . '/real', $this->root . '/link');

        $this->assertTrue(IgnoreRules::new($this->root)->halts($this->root . '/link'));
    }

    public function testAnOrdinaryDirectoryAndASymlinkedFileAreNotStops(): void
    {
        mkdir($this->root . '/real', 0o777, true);
        $this->write('target.txt', 'x');
        symlink($this->root . '/target.txt', $this->root . '/linked.txt');
        $rules = IgnoreRules::new($this->root);

        $this->assertFalse($rules->halts($this->root . '/real'));
        $this->assertFalse($rules->halts($this->root . '/linked.txt'), 'a link to a FILE cannot cycle');
    }

    public function testTheStopIsLiftableForACallerThatAcceptsTheRisk(): void
    {
        mkdir($this->root . '/real', 0o777, true);
        symlink($this->root . '/real', $this->root . '/link');

        $this->assertFalse(
            IgnoreRules::new($this->root)->withFollowSymlinks(true)->halts($this->root . '/link'),
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function write(string $relative, string $contents): void
    {
        file_put_contents($this->root . '/' . $relative, $contents);
    }

    private static function removeTree(string $dir): void
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
            self::removeTree($path);
        }

        rmdir($dir);
    }
}
