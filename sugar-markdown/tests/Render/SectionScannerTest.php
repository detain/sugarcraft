<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests\Render;

use PHPUnit\Framework\TestCase;
use SugarCraft\Markdown\Render\SectionScanner;
use SugarCraft\Markdown\Renderer;

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

    public function testAHeadingAfterAListItemIsABoundary(): void
    {
        $scanner = new SectionScanner();

        $this->assertSame(
            ["# A\n\n- one\n- two\n\n"],
            $scanner->push("# A\n\n- one\n- two\n\n# B\n\n1. three\n\n"),
        );
        $this->assertSame(["# B\n\n1. three\n\n"], $scanner->push("## C\n"));
    }

    public function testAHeadingGluedToAListItemIsStillNotABoundary(): void
    {
        $scanner = new SectionScanner();

        $this->assertSame([], $scanner->push("# A\n\n- one\n# B\n"));
    }

    /**
     * SM-S03: a multi-megabyte newline-less push must not grow the scanner
     * past its cap; the overflow leaves as provisional sections whose
     * bytes, re-joined, are exactly the bytes fed.
     */
    public function testANewlinelessBlobFlushesProvisionalSectionsBounded(): void
    {
        $scanner = new SectionScanner();
        $doc = str_repeat('a', 3 * 1024 * 1024 + 17);
        $seen = '';
        $provisional = 0;

        foreach (str_split($doc, 65536) as $chunk) {
            $closed = $scanner->push($chunk);
            foreach ($closed as $section) {
                $provisional++;
                $seen .= $section;
            }
            $live = self::liveBytes($scanner);
            $this->assertLessThanOrEqual(
                SectionScanner::MAX_SECTION_BYTES + 2 * 65536,
                $live,
                'the scanner live buffer must stay at roughly one cap while feeding a newline-less blob',
            );
        }
        $this->assertGreaterThan(2, $provisional, 'overflow must stream out before finish()');

        $rest = $scanner->finish() ?? '';
        $this->assertSame(
            $doc . "\n",
            $seen . $rest,
            'provisional cuts must preserve every byte in order (finish() terminates the last line)',
        );
    }

    public function testStreamOfAMultiMegabyteNewlinelessParagraphIsByteIdenticalToRender(): void
    {
        $renderer = new Renderer();
        $doc = str_repeat('x', 2_500_000);

        $streamed = '';
        $chunks = 0;
        foreach ($renderer->stream(str_split($doc, 65536)) as $body) {
            $streamed .= $body;
            $chunks++;
        }

        $this->assertSame($renderer->render($doc), $streamed);
        $this->assertSame(1, $chunks, 'a boundary-less document still answers exactly one final chunk');
    }

    public function testStreamOfGiantLineBledTextIsByteIdenticalAndProgresses(): void
    {
        $renderer = new Renderer();
        // No blank lines: one paragraph of many complete lines, so every
        // provisional flush lands on a line edge and the parser re-joins it.
        $doc = implode("\n", array_fill(0, 6000, str_repeat('word ', 80) . 'end'));

        $bodies = iterator_to_array($renderer->stream(str_split($doc, 70000)), false);
        $this->assertSame($renderer->render($doc), implode('', $bodies));
    }

    public function testARealBoundaryAfterProvisionalBytesConfirmsAndStreamsEarly(): void
    {
        $renderer = new Renderer();
        $doc = str_repeat('b', 2_000_000) . "\n\n# Head\n\nafter\n";

        $bodies = [];
        foreach ($renderer->stream(str_split($doc, 65536)) as $body) {
            $bodies[] = $body;
            if (count($bodies) > 1) {
                break; // progress observed: a section answered before finish()
            }
        }
        $this->assertGreaterThan(1, count($bodies), 'the blob section must finalise at the real heading, mid-feed');

        $streamed = implode('', $bodies);
        $full = '';
        foreach ($renderer->stream(str_split($doc, 1_048_576)) as $body) {
            $full .= $body;
        }
        $this->assertStringStartsWith($streamed, $full);
        $this->assertSame($renderer->render($doc), $full);
    }

    private static function liveBytes(SectionScanner $scanner): int
    {
        $read = static function (string $property) use ($scanner) {
            $p = new \ReflectionProperty(SectionScanner::class, $property);
            $p->setAccessible(true);

            return $p->getValue($scanner);
        };

        // SM-S15: the open section is a line list now; sectionBytes is its
        // live byte count without materialising it.
        return $read('sectionBytes') + strlen($read('buffer')) - $read('consumed');
    }
}
