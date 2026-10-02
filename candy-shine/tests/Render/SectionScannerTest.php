<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests\Render;

use PHPUnit\Framework\TestCase;
use SugarCraft\Shine\Render\SectionScanner;

/**
 * The line-level boundary proposals of {@see SectionScanner} (audit 15b-31
 * and the fence/HTML tracking the streaming law leans on).
 */
final class SectionScannerTest extends TestCase
{
    public function testFinishKeepsTheSectionAnUnterminatedHeadingCloses(): void
    {
        $scanner = new SectionScanner();

        $this->assertSame([], $scanner->push("Intro\n\n# F"));
        $this->assertSame("Intro\n\n# F\n", $scanner->finish(), 'the closed "Intro" section must not be dropped');
    }

    public function testFinishReturnsNullForBlankRemainder(): void
    {
        $scanner = new SectionScanner();

        $this->assertSame([], $scanner->push("\n\n"));
        $this->assertNull($scanner->finish());
    }

    public function testAHeadingStraightAfterAClosingFenceIsABoundary(): void
    {
        $scanner = new SectionScanner();

        $this->assertSame(["```php\necho 1;\n```\n"], $scanner->push("```php\necho 1;\n```\n## Next\n"));
        $this->assertSame("## Next\n", $scanner->openSection());
    }

    public function testAHeadingInsideAFenceIsNotABoundary(): void
    {
        $scanner = new SectionScanner();

        $this->assertSame([], $scanner->push("```\n# not a heading\n\n# still not\n"));
    }

    public function testABacktickInTheInfoStringMeansNoFence(): void
    {
        // "```a`b" is a paragraph holding a code span, so the heading after
        // the blank line is a real boundary.
        $scanner = new SectionScanner();

        $this->assertSame(["```a`b\n\n"], $scanner->push("```a`b\n\n# H\n"));
    }

    public function testAnHtmlCommentSpanningABlankLineHoldsHeadings(): void
    {
        $scanner = new SectionScanner();

        $this->assertSame([], $scanner->push("<!--\n\n# inside the comment\n-->\n"));
        $this->assertSame(["<!--\n\n# inside the comment\n-->\n\n"], $scanner->push("\n# After\n"));
    }

    public function testAOneLineHtmlCommentDoesNotHold(): void
    {
        $scanner = new SectionScanner();

        $this->assertSame(["<!-- note -->\n\n"], $scanner->push("<!-- note -->\n\n# After\n"));
    }

    public function testOpenSectionStartsWithTheClosingHeading(): void
    {
        $scanner = new SectionScanner();

        $this->assertSame(["# A\n\none\n\n"], $scanner->push("# A\n\none\n\n# B\n\ntw"));
        $this->assertSame("# B\n\n", $scanner->openSection());
    }
}
