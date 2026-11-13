<?php

declare(strict_types=1);

namespace SugarCraft\Ignore\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Ignore\IgnoreRules;

/**
 * gitignore(5) semantics through the injected-contents API. Ported from
 * candy-code's IgnoreRulesTest, with the filesystem replaced by a map.
 */
final class IgnoreRulesTest extends TestCase
{
    private static function rules(string $gitignore): IgnoreRules
    {
        return IgnoreRules::fromPaths(['.gitignore' => $gitignore]);
    }

    public function testNewIsEmptyAndIgnoresNothing(): void
    {
        $rules = IgnoreRules::new();

        $this->assertSame([], $rules->sources);
        $this->assertNull($rules->root);
        $this->assertNull($rules->ignores('anything.txt', false));
        $this->assertFalse($rules->isIgnored('a/b', true));
    }

    public function testCommentsAndBlankLinesAreNotPatterns(): void
    {
        $rules = self::rules("# build\n\n   \nreal.log\n");

        $this->assertNotNull($rules->ignores('real.log', false));
        $this->assertNull($rules->ignores('build', true), 'the comment is not a pattern');
    }

    public function testCrLfLineEndingsAreAccepted(): void
    {
        $rules = self::rules("a.log\r\nb.log\r\n");

        $this->assertTrue($rules->isIgnored('a.log', false));
        $this->assertTrue($rules->isIgnored('b.log', false));
    }

    public function testAnEscapedHashIsALiteralName(): void
    {
        $this->assertTrue(self::rules("\\#notes\n")->isIgnored('#notes', false));
    }

    public function testTrailingWhitespaceIsStrippedUnlessEscaped(): void
    {
        $rules = self::rules("loose.txt   \ntight\\ \n");

        $this->assertTrue($rules->isIgnored('loose.txt', false));
        $this->assertTrue($rules->isIgnored('tight ', false), 'the escaped space is part of the name');
        $this->assertFalse($rules->isIgnored('tight', false));
    }

    public function testABarePatternMatchesAtAnyDepth(): void
    {
        $rules = self::rules("notes.md\n");

        $this->assertTrue($rules->isIgnored('notes.md', false));
        $this->assertTrue($rules->isIgnored('a/b/notes.md', false));
    }

    public function testALeadingSlashAnchorsThePattern(): void
    {
        $rules = self::rules("/notes.md\n");

        $this->assertTrue($rules->isIgnored('notes.md', false));
        $this->assertFalse($rules->isIgnored('a/notes.md', false));
    }

    public function testASlashInTheMiddleAnchorsToo(): void
    {
        $rules = self::rules("doc/notes.md\n");

        $this->assertTrue($rules->isIgnored('doc/notes.md', false));
        $this->assertFalse($rules->isIgnored('a/doc/notes.md', false));
    }

    public function testATrailingSlashMatchesOnlyDirectories(): void
    {
        $rules = self::rules("cache/\n");

        $this->assertTrue($rules->isIgnored('cache', true));
        $this->assertFalse($rules->isIgnored('cache', false), 'a FILE named cache is not ignored');
    }

    public function testADirectoryOnlyPatternAlsoHidesItsContents(): void
    {
        $this->assertTrue(self::rules("cache/\n")->isIgnored('cache/deep/x.php', false));
    }

    public function testWildcardsDoNotCrossASeparator(): void
    {
        $rules = self::rules("/a/*.log\n");

        $this->assertTrue($rules->isIgnored('a/one.log', false));
        $this->assertFalse($rules->isIgnored('a/b/one.log', false));
    }

    public function testQuestionMarkMatchesExactlyOneCharacter(): void
    {
        $rules = self::rules("/log?.txt\n");

        $this->assertTrue($rules->isIgnored('log1.txt', false));
        $this->assertFalse($rules->isIgnored('log12.txt', false));
    }

    public function testCharacterClassesAreHonoured(): void
    {
        $rules = self::rules("/log[0-9].txt\n!/log[!0-9].txt\n");

        $this->assertTrue($rules->isIgnored('log7.txt', false));
        $this->assertFalse($rules->isIgnored('logX.txt', false));
    }

    public function testALeadingGlobstarMatchesAtAnyDepth(): void
    {
        $rules = self::rules("**/tmp.txt\n");

        $this->assertTrue($rules->isIgnored('tmp.txt', false));
        $this->assertTrue($rules->isIgnored('a/b/tmp.txt', false));
    }

    public function testATrailingGlobstarMatchesEverythingBelow(): void
    {
        $rules = self::rules("/out/**\n");

        $this->assertTrue($rules->isIgnored('out/a/b.txt', false));
        $this->assertFalse($rules->isIgnored('outside.txt', false));
    }

    public function testAMiddleGlobstarSpansZeroOrMoreDirectories(): void
    {
        $rules = self::rules("/a/**/t.txt\n");

        $this->assertTrue($rules->isIgnored('a/t.txt', false), 'zero intervening directories');
        $this->assertTrue($rules->isIgnored('a/b/c/t.txt', false));
    }

    public function testAMalformedPatternIsSkippedRatherThanFailing(): void
    {
        $rules = self::rules("/[z-a].txt\n/real.txt\n");

        $this->assertTrue($rules->isIgnored('real.txt', false), 'the good rule still applies');
        $this->assertFalse($rules->isIgnored('anything.txt', false));
    }

    public function testAnUnterminatedBracketIsALiteral(): void
    {
        $rules = self::rules("/a[b\n");

        $this->assertTrue($rules->isIgnored('a[b', false));
        $this->assertFalse($rules->isIgnored('ab', false));
    }

    public function testALaterNegationWins(): void
    {
        $rules = self::rules("*.log\n!keep.log\n");

        $this->assertTrue($rules->isIgnored('drop.log', false));
        $this->assertFalse($rules->isIgnored('keep.log', false));
    }

    public function testAReIgnoreAfterANegationWinsAgain(): void
    {
        $this->assertTrue(self::rules("*.log\n!keep.log\nkeep.log\n")->isIgnored('keep.log', false));
    }

    public function testANegationCannotResurrectAFileUnderAnExcludedDirectory(): void
    {
        $this->assertTrue(self::rules("out/\n!out/keep.txt\n")->isIgnored('out/keep.txt', false));
    }

    public function testAnEscapedBangIsALiteralName(): void
    {
        $this->assertTrue(self::rules("\\!important\n")->isIgnored('!important', false));
    }

    public function testIgnoresNamesTheDecidingRule(): void
    {
        $rules = IgnoreRules::fromPaths(['.gitignore' => "*.log\n", 'pkg/.gitignore' => "local.php\n"]);

        $this->assertSame('.gitignore: *.log', $rules->ignores('a/x.log', false));
        $this->assertSame('pkg/.gitignore: local.php', $rules->ignores('pkg/local.php', false));
        $this->assertSame('.gitignore: *.log', $rules->ignores('x.log/inner.txt', false), 'an ancestor is named');
    }

    public function testANestedFileAppliesToItsOwnSubtree(): void
    {
        $rules = IgnoreRules::fromPaths(['pkg/.gitignore' => "local.php\n"]);

        $this->assertTrue($rules->isIgnored('pkg/local.php', false));
        $this->assertTrue($rules->isIgnored('pkg/sub/local.php', false));
        $this->assertFalse($rules->isIgnored('local.php', false), 'and not to its siblings');
        $this->assertFalse($rules->isIgnored('pkgx/local.php', false), 'a prefix is not a subtree');
    }

    public function testANestedFileOverridesTheRootOne(): void
    {
        $rules = IgnoreRules::fromPaths(['pkg/.gitignore' => "!*.log\n", '.gitignore' => "*.log\n"]);

        $this->assertTrue($rules->isIgnored('top.log', false));
        $this->assertFalse($rules->isIgnored('pkg/deep.log', false), 'the deeper file wins whatever the map order');
    }

    public function testANestedFileAnchorsToItsOwnDirectory(): void
    {
        $rules = IgnoreRules::fromPaths(['pkg/.gitignore' => "/local.php\n"]);

        $this->assertTrue($rules->isIgnored('pkg/local.php', false));
        $this->assertFalse($rules->isIgnored('pkg/sub/local.php', false));
    }

    public function testInfoExcludeIsHonouredButRanksBelowGitignore(): void
    {
        $rules = IgnoreRules::fromPaths([
            '.gitignore' => "!notes.txt\n",
            '.git/info/exclude' => "scratch.txt\nnotes.txt\n",
        ]);

        $this->assertTrue($rules->isIgnored('scratch.txt', false));
        $this->assertFalse($rules->isIgnored('notes.txt', false), '.gitignore outranks info/exclude');
    }

    public function testAnIgnoreFileInsideAnIgnoredDirectoryNeverLoads(): void
    {
        $rules = IgnoreRules::fromPaths(['.gitignore' => "out/\n", 'out/.gitignore' => "!keep.txt\n"]);

        $this->assertTrue($rules->isIgnored('out/keep.txt', false));
    }

    public function testFromPathsStripsAnAbsoluteRoot(): void
    {
        $rules = IgnoreRules::fromPaths(['/repo/.gitignore' => "*.log\n", '/repo/pkg/.gitignore' => "x\n"], '/repo/');

        $this->assertSame('/repo', $rules->root);
        $this->assertSame(['.gitignore', 'pkg/.gitignore'], array_keys($rules->sources));
        $this->assertTrue($rules->isIgnored('/repo/a/b.log', false), 'absolute paths under the root are accepted');
        $this->assertTrue($rules->isIgnored('pkg/x', false));
    }

    public function testAPathOutsideTheRootHasNoVerdict(): void
    {
        $rules = IgnoreRules::fromPaths(['/repo/.gitignore' => "*.log\n"], '/repo');

        $this->assertNull($rules->ignores('/elsewhere/x.log', false));
        $this->assertNull($rules->ignores('/repo', true), 'the root itself is never ignored');
        $this->assertNull($rules->ignores('', true));
    }

    public function testFromPathsDropsIgnoreFilesOutsideTheRoot(): void
    {
        $rules = IgnoreRules::fromPaths(['/other/.gitignore' => "*\n"], '/repo');

        $this->assertSame([], $rules->sources);
        $this->assertFalse($rules->isIgnored('x', false));
    }

    public function testWithFileReturnsANewInstanceAndLeavesTheOriginal(): void
    {
        $base = self::rules("a.log\n");
        $next = $base->withFile('pkg/.gitignore', "b.log\n");

        $this->assertNotSame($base, $next);
        $this->assertFalse($base->isIgnored('pkg/b.log', false));
        $this->assertTrue($next->isIgnored('pkg/b.log', false));
        $this->assertTrue($next->isIgnored('a.log', false), 'earlier files are kept');
    }

    public function testWithFileReplacesAnExistingEntry(): void
    {
        $next = self::rules("a.log\n")->withFile('.gitignore', "b.log\n");

        $this->assertFalse($next->isIgnored('a.log', false));
        $this->assertTrue($next->isIgnored('b.log', false));
    }

    public function testWithFileOutsideTheRootIsANoOp(): void
    {
        $base = IgnoreRules::new('/repo');

        $this->assertSame($base, $base->withFile('/other/.gitignore', "*\n"));
    }

    public function testUndecidableRulesIsEmptyForOrdinaryRules(): void
    {
        $rules = self::rules("*.log\n");
        $rules->ignores('a.log', false);

        $this->assertSame([], $rules->undecidableRules());
        $this->assertSame([], $rules->undecidablePatterns());
    }
}
