<?php

declare(strict_types=1);

namespace SugarCraft\MdView\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\MdView\FrontMatter;

/**
 * Behaviour pins for the front-matter detector (finding 007).
 *
 * The detector's contract is asymmetric on purpose: a false negative keeps
 * the user's bytes visible, a false positive silently deletes prose. Every
 * guard branch (very-first-line, same-delimiter close, non-empty block,
 * body-after-close) gets its own can-fail pin here.
 *
 * @covers \SugarCraft\MdView\FrontMatter
 */
final class FrontMatterTest extends TestCase
{
    public function testCanonicalYamlBlockIsSplitIntoMatterAndBody(): void
    {
        $raw    = "---\ntitle: My Doc\ntags: [a, b]\n---\n\n# Head\nbody\n";
        [$body, $matter] = FrontMatter::split($raw);

        self::assertSame("\n# Head\nbody\n", $body);
        self::assertNotNull($matter);
        self::assertSame("---\ntitle: My Doc\ntags: [a, b]\n---\n", $matter);
    }

    public function testDotDelimiterVariantIsStripped(): void
    {
        $raw    = "...\ntitle: My Doc\n...\nbody\n";
        [$body, $matter] = FrontMatter::split($raw);

        self::assertSame("body\n", $body);
        self::assertSame("...\ntitle: My Doc\n...\n", $matter);
    }

    public function testClosingAtEofIsReadAsSetextUnderlineNotMetadata(): void
    {
        // `---\ntext\n---` is a thematic break plus a setext H2. Deleting
        // the whole document to "hide the metadata" is the one failure this
        // detector must never have.
        $raw = "---\ntext\n---";
        [$body, $matter] = FrontMatter::split($raw);

        self::assertSame($raw, $body);
        self::assertNull($matter);
    }

    public function testClosingLineAsFinalLineWithNewlineStillRefusesToStrip(): void
    {
        $raw    = "---\ntext\n---\n";
        [$body, $matter] = FrontMatter::split($raw);

        self::assertSame($raw, $body);
        self::assertNull($matter);
    }

    public function testMismatchedDelimitersDoNotCloseTheFence(): void
    {
        $raw    = "---\ntitle: My Doc\n...\nbody\n";
        [$body, $matter] = FrontMatter::split($raw);

        self::assertSame($raw, $body);
        self::assertNull($matter);
    }

    public function testEmptyBlockIsNotMetadata(): void
    {
        $raw    = "---\n---\nbody\n";
        [$body, $matter] = FrontMatter::split($raw);

        self::assertSame($raw, $body);
        self::assertNull($matter);
    }

    public function testBlankLinesOnlyInsideTheBlockIsNotMetadata(): void
    {
        $raw    = "---\n\n\n---\nbody\n";
        [$body, $matter] = FrontMatter::split($raw);

        self::assertSame($raw, $body);
        self::assertNull($matter);
    }

    public function testOpeningMustBeTheVeryFirstThing(): void
    {
        $raw    = "\n---\ntitle: My Doc\n---\nbody\n";
        [$body, $matter] = FrontMatter::split($raw);

        self::assertSame($raw, $body);
        self::assertNull($matter);
    }

    public function testNoDelimiterAtAllPassesThrough(): void
    {
        $raw = "# Head\n\n---\n\nbody\n";
        [$body, $matter] = FrontMatter::split($raw);

        self::assertSame($raw, $body);
        self::assertNull($matter);
    }

    public function testCarriageReturnsAreToleratedOnBothFences(): void
    {
        $raw    = "---\r\ntitle: My Doc\r\n---\r\nbody\r\n";
        [$body, $matter] = FrontMatter::split($raw);

        self::assertSame("body\r\n", $body);
        self::assertSame("---\r\ntitle: My Doc\r\n---\r\n", $matter);
    }

    public function testIndentedDashLinesAreNotClosingFences(): void
    {
        // A list item like `  ---` under the opening fence must not close it;
        // the real close further down does.
        $raw    = "---\nnotes:\n  ---\ntitle: x\n---\nbody\n";
        [$body, $matter] = FrontMatter::split($raw);

        self::assertSame("body\n", $body);
        self::assertNotNull($matter);
    }

    public function testUnclosedFencePassesThrough(): void
    {
        $raw = "---\ntitle: My Doc\n\n# Head\nbody\n";
        [$body, $matter] = FrontMatter::split($raw);

        self::assertSame($raw, $body);
        self::assertNull($matter);
    }

    public function testStripAndHasConvenienceWrapSplit(): void
    {
        $raw = "---\ntitle: My Doc\n---\nbody\n";

        self::assertTrue(FrontMatter::has($raw));
        self::assertSame('body', rtrim(FrontMatter::strip($raw)));
        self::assertFalse(FrontMatter::has('# Head'));
        self::assertSame('# Head', FrontMatter::strip('# Head'));
    }
}
