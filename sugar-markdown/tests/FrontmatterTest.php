<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Markdown\Frontmatter;
use SugarCraft\Markdown\Frontmatter\Split;

/**
 * Pins the `---` fence grammar. The cases mirror the edge cases the seven
 * candy-code frontmatter readers handled (six share one regex, MemoryStore
 * keeps a variant), plus the empty block and CRLF documents they got wrong
 * or left unstated.
 */
final class FrontmatterTest extends TestCase
{
    public function testSplitsMetaFromBody(): void
    {
        $split = Frontmatter::split("---\nname: demo\n---\nbody text\n");

        $this->assertInstanceOf(Split::class, $split);
        $this->assertSame('name: demo', $split->meta);
        $this->assertSame("body text\n", $split->body);
        $this->assertSame(strlen("---\nname: demo\n---\n"), $split->bodyOffset);
    }

    public function testBodyOffsetAlwaysPointsAtTheBody(): void
    {
        $documents = [
            "---\na: 1\n---\nbody",
            "---\r\na: 1\r\n---\r\nbody",
            "---\n---\nbody",
            "---\n\n\na: 1\n---\n\n\n  indented",
            "---\na: 1\n---",
            "---\na\n---\nbody\n---\nmore",
        ];
        foreach ($documents as $document) {
            $split = Frontmatter::split($document);
            $this->assertNotNull($split, $document);
            $this->assertSame($split->body, substr($document, $split->bodyOffset), $document);
        }
    }

    public function testNoOpeningFenceMeansNoBlock(): void
    {
        $this->assertNull(Frontmatter::split("# Heading\n---\na: 1\n---\n"));
        $this->assertNull(Frontmatter::split(''));
    }

    public function testTheOpeningLineMustBeNothingButTheFence(): void
    {
        $this->assertNull(Frontmatter::split("--- x\na: 1\n---\nbody"));
        $this->assertNull(Frontmatter::split("----\na: 1\n---\nbody"));
    }

    public function testOpeningFenceAtEndOfInputIsNotABlock(): void
    {
        $this->assertNull(Frontmatter::split('---'));
        $this->assertNull(Frontmatter::split("---\n"));
    }

    public function testUnclosedBlockIsNotABlock(): void
    {
        $this->assertNull(Frontmatter::split("---\na: 1\nbody"));
        $this->assertNull(Frontmatter::split("---\na: 1\n----\nbody"));
    }

    public function testClosingFenceAtEndOfInputNeedsNoLineBreak(): void
    {
        $split = Frontmatter::split("---\na: 1\n---");

        $this->assertNotNull($split);
        $this->assertSame('a: 1', $split->meta);
        $this->assertSame('', $split->body);
        $this->assertSame(strlen("---\na: 1\n---"), $split->bodyOffset);
    }

    public function testEmptyBlockIsAValidBlockWithEmptyMeta(): void
    {
        $split = Frontmatter::split("---\n---\nbody");

        $this->assertNotNull($split);
        $this->assertSame('', $split->meta);
        $this->assertSame('body', $split->body);
        $this->assertSame(strlen("---\n---\n"), $split->bodyOffset);
    }

    public function testEmptyBlockAtEndOfInput(): void
    {
        $split = Frontmatter::split("---\n---");

        $this->assertNotNull($split);
        $this->assertSame('', $split->meta);
        $this->assertSame('', $split->body);
    }

    public function testFenceMayCarryTrailingSpacesAndTabs(): void
    {
        $split = Frontmatter::split("---  \na: 1\n---\t \nbody");

        $this->assertNotNull($split);
        $this->assertSame('a: 1', $split->meta);
        $this->assertSame('body', $split->body);
    }

    public function testCrlfDocumentsLoseTheLineBreakBeforeTheFence(): void
    {
        $split = Frontmatter::split("---\r\nname: demo\r\nkeys: 2\r\n---\r\nbody\r\n");

        $this->assertNotNull($split);
        $this->assertSame("name: demo\r\nkeys: 2", $split->meta);
        $this->assertSame("body\r\n", $split->body);
    }

    public function testBlankLinesBeforeTheMetaAreDroppedAndIndentationIsKept(): void
    {
        $split = Frontmatter::split("---\n\n   \n  key: v\n---\nbody");

        $this->assertNotNull($split);
        $this->assertSame('  key: v', $split->meta);
    }

    public function testInteriorBlankLinesOfTheMetaAreKept(): void
    {
        $split = Frontmatter::split("---\na: 1\n\nb: 2\n---\nbody");

        $this->assertNotNull($split);
        $this->assertSame("a: 1\n\nb: 2", $split->meta);
    }

    public function testBlankLinesAfterTheFenceAreDroppedFromTheBody(): void
    {
        $split = Frontmatter::split("---\na: 1\n---\n\n \t\n    code line\nnext");

        $this->assertNotNull($split);
        $this->assertSame("    code line\nnext", $split->body);
    }

    public function testBodyKeepsItsOwnFencesAndBlankLinesAfterTheFirstContentLine(): void
    {
        $split = Frontmatter::split("---\na\n---\nbody\n\n---\nmore");

        $this->assertNotNull($split);
        $this->assertSame('a', $split->meta);
        $this->assertSame("body\n\n---\nmore", $split->body);
    }

    public function testBodyOfTrailingWhitespaceWithoutLineBreakIsKeptAsIs(): void
    {
        $split = Frontmatter::split("---\na\n---\n   ");

        $this->assertNotNull($split);
        $this->assertSame('   ', $split->body);
    }

    public function testByteOrderMarkMeansPlainBody(): void
    {
        // Deliberate: a BOM-prefixed file is body text, not a block. The
        // caller that wants BOM tolerance strips it before splitting.
        $this->assertNull(Frontmatter::split("\xEF\xBB\xBF---\na\n---\nbody"));
    }

    public function testSplitIsReadonly(): void
    {
        $split = Frontmatter::split("---\na\n---\nbody");
        $this->assertNotNull($split);

        $reflection = new \ReflectionClass(Split::class);
        foreach (['meta', 'body', 'bodyOffset'] as $property) {
            $this->assertTrue($reflection->getProperty($property)->isReadOnly(), $property);
        }
    }
}
