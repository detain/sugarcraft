<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Skills\Skill;

/**
 * The frontmatter fence now comes from sugar-markdown's Frontmatter::split()
 * at every call site that used to carry the `/^---\s*\n(.*?)\n---\s*\n/s`
 * copy. The two grammars agree on every document with a non-empty block
 * closed by a line break, and differ in three named places. These tests pin
 * the agreed cases and the three differences, read through Skill::parse()
 * (one of the migrated readers, with the same fence in both of its paths).
 */
final class FrontmatterFenceGrammarTest extends TestCase
{
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
}
