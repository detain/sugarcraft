<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Skills\Skill;

/**
 * The frontmatter fence now comes from sugar-markdown's Frontmatter::split()
 * at every call site that used to carry the `/^---\s*\n(.*?)\n---\s*\n/s`
 * copy. The two grammars agree on every document with a non-empty block
 * closed by a line break, and differ in three named places. The agreed cases
 * and the three differences are read through Skill::parse() (one of the
 * migrated readers, with the same fence in both of its paths). The two
 * differences that change a command file's load outcome are pinned through
 * CommandSpec::fromFile() at the end of this class.
 */
final class FrontmatterFenceGrammarTest extends TestCase
{
    private string $dir = '';

    public function testCrlfBlockReadsTheSameAsLf(): void
    {
        $lf = Skill::parse("---\nname: a\ndescription: Does X\n---\nbody line\n", 'x');
        $crlf = Skill::parse("---\r\nname: a\r\ndescription: Does X\r\n---\r\nbody line\r\n", 'x');

        $this->assertSame('Does X', $lf->description);
        $this->assertSame($lf->description, $crlf->description);
        $this->assertSame($lf->content, $crlf->content);
        $this->assertSame('body line', $crlf->content);
    }

    public function testDocumentWithoutAFenceIsAllBody(): void
    {
        $skill = Skill::parse("plain body\n", 'x');

        $this->assertSame('plain body', $skill->content);
    }

    public function testUnclosedBlockIsAllBody(): void
    {
        $skill = Skill::parse("---\ndescription: Does X\nbody without a closer\n", 'x');

        $this->assertSame("---\ndescription: Does X\nbody without a closer", $skill->content);
    }

    /**
     * Named divergence: the regex needed a line break after the closing
     * fence, so a block closed on the last line of the file was not read.
     * Split reads it, so the metadata is kept and the body is empty.
     */
    public function testBlockClosedOnTheLastLineIsNowRead(): void
    {
        $skill = Skill::parse("---\nname: a\ndescription: Does X\n---", 'x');

        $this->assertSame('Does X', $skill->description);
        $this->assertSame('', $skill->content);
    }

    /**
     * Named divergence: two fences back to back are an empty block, which
     * Split reads as an empty meta. The regex saw no block and kept both
     * fence lines in the body.
     */
    public function testAdjacentFencesAreAnEmptyBlockAndNoLongerBody(): void
    {
        $skill = Skill::parse("---\n---\nbody\n", 'x');

        $this->assertSame('body', $skill->content);
    }

    /**
     * Named divergence: the regex's `\s` also matched a vertical tab (and a
     * form feed) on the fence line, so `---<VT>` opened a block. Split's
     * fence is `---` with spaces or tabs only, so such a line is body text.
     */
    public function testVerticalTabOnTheFenceLineIsNoLongerAFence(): void
    {
        $skill = Skill::parse("---\x0B\ndescription: Does X\n---\nbody\n", 'x');

        $this->assertNotSame('Does X', $skill->description);
        $this->assertSame("---\x0B\ndescription: Does X\n---\nbody", $skill->content);
    }

    /**
     * The closing-side half of the same divergence: the regex's `\s` also
     * matched a vertical tab after the closing `---`, so `---<VT>` closed the
     * block. Split's fence does not, so this block never closes and the whole
     * document is body.
     */
    public function testVerticalTabOnTheClosingFenceLineDoesNotCloseTheBlock(): void
    {
        $skill = Skill::parse("---\nname: a\ndescription: Does X\n---\x0B\nbody\n", 'x');

        $this->assertNotSame('Does X', $skill->description);
        $this->assertSame("---\nname: a\ndescription: Does X\n---\x0B\nbody", $skill->content);
    }

    /*
     * The two named differences change what CommandSpec::fromFile() does, not
     * only what the body looks like. These pin the new outcomes so a later
     * change cannot move them silently. Both were loadable before the
     * migration, so each one is an owner-disclosure item, not a regression
     * fixed here: the file is now refused with the outcome named below.
     */

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/fence-grammar-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    /**
     * Before the migration the regex saw no block here, so the file loaded
     * with both fence lines as its template. Split reads an empty block, whose
     * meta parses to null, so the file is refused as a non-mapping.
     */
    public function testCommandFileWithAdjacentFencesIsRefusedAsNotAMapping(): void
    {
        $path = $this->dir . '/adjacent.md';
        file_put_contents($path, "---\n---\nRun the tests now\n");

        $this->expectException(\InvalidArgumentException::class);
        CommandSpec::fromFile($path, 'adjacent', 'project');
    }

    /**
     * Before the migration the regex needed a line break after the closing
     * fence, so this file loaded with its fence text as the template. Split
     * reads the block, leaving an empty body, which the loader refuses.
     */
    public function testCommandFileClosedOnTheLastLineWithNoBodyIsRefused(): void
    {
        $path = $this->dir . '/meta-only.md';
        file_put_contents($path, "---\ndescription: x\n---");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('empty template body');
        CommandSpec::fromFile($path, 'meta-only', 'project');
    }
}
