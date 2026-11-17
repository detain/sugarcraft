<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Markdown\Frontmatter;
use SugarCraft\Markdown\Frontmatter\Split;

/**
 * Frontmatter::split() is the one fence grammar behind the seven candy-code
 * copies of `/^---\s*\n(.*?)\n---\s*\n/s` and the MemoryStore variant
 * `/^---\s*\n(.*?)\n---[ \t]*(?:\r?\n|$)(.*)$/s`. The parity cases pin every
 * document those copies agree on, and the widening (a closing fence that ends
 * the document) is pinned on its own.
 */
final class FrontmatterTest extends TestCase
{
    /** The seven candy-code copies that share the main grammar. */
    private const LEGACY_MAIN = '/^---\s*\n(.*?)\n---\s*\n/s';

    /** candy-code Memory/MemoryStore.php, the one variant that accepts an end-of-file closing fence. */
    private const LEGACY_MEMORY = '/^---\s*\n(.*?)\n---[ \t]*(?:\r?\n|$)(.*)$/s';

    public function testSplitsMetaFromBodyVerbatim(): void
    {
        $doc = "---\ntitle: Review\nmodel: fast\n---\nBody line\n";
        $split = Frontmatter::split($doc);

        $this->assertInstanceOf(Split::class, $split);
        $this->assertSame("title: Review\nmodel: fast", $split->meta);
        $this->assertSame("Body line\n", $split->body);
        $this->assertSame(strlen("---\ntitle: Review\nmodel: fast\n---\n"), $split->bodyOffset);
        $this->assertSame($split->body, substr($doc, $split->bodyOffset));
    }

    public function testBodyIsNotTrimmed(): void
    {
        $split = Frontmatter::split("---\na: 1\n---\n\n  indented first line\n");

        $this->assertNotNull($split);
        $this->assertSame("  indented first line\n", $split->body);
    }

    public function testBlankLinesAfterTheClosingFenceAreConsumed(): void
    {
        $split = Frontmatter::split("---\na: 1\n---\n\n\nbody");

        $this->assertNotNull($split);
        $this->assertSame('body', $split->body);
    }

    public function testNoFrontmatterWhenTheDocumentDoesNotOpenWithAFence(): void
    {
        $this->assertNull(Frontmatter::split(''));
        $this->assertNull(Frontmatter::split("plain body\n---\na: 1\n---\n"));
        $this->assertNull(Frontmatter::split("\n---\na: 1\n---\nbody"));
        $this->assertNull(Frontmatter::split(" ---\na: 1\n---\nbody"));
        $this->assertNull(Frontmatter::split("---a\na: 1\n---\nbody"));
    }

    public function testAFourDashRuleDoesNotCloseTheBlock(): void
    {
        $this->assertNull(Frontmatter::split("---\na: 1\n----\nbody"));
        $this->assertNull(Frontmatter::split("---\na: 1\n---x\nbody"));
    }

    public function testAnUnclosedBlockIsNotFrontmatter(): void
    {
        $this->assertNull(Frontmatter::split("---\na: 1\nbody with no close\n"));
    }

    public function testEmptyMetaNeedsABlankLineBetweenTheFences(): void
    {
        // Matches the seven copies: `---\n---\n` has no room for the
        // mandatory newline the grammar puts before the closing fence.
        $this->assertNull(Frontmatter::split("---\n---\nbody"));

        $split = Frontmatter::split("---\n\n---\nbody");
        $this->assertNotNull($split);
        $this->assertSame('', $split->meta);
        $this->assertSame('body', $split->body);
    }

    public function testCarriageReturnLineEndingsKeepTheCarriageReturnInMeta(): void
    {
        $split = Frontmatter::split("---\r\ntitle: x\r\n---\r\nbody\r\n");

        $this->assertNotNull($split);
        $this->assertSame("title: x\r", $split->meta);
        $this->assertSame("body\r\n", $split->body);
    }

    public function testClosingFenceMayCarryTrailingSpacesAndTabs(): void
    {
        $split = Frontmatter::split("---\na: 1\n---  \t\nbody");

        $this->assertNotNull($split);
        $this->assertSame('a: 1', $split->meta);
        $this->assertSame('body', $split->body);
    }

    public function testAFenceInsideAValueDoesNotSplitTheBlock(): void
    {
        $split = Frontmatter::split("---\ntags: a---b\n---\nbody");

        $this->assertNotNull($split);
        $this->assertSame('tags: a---b', $split->meta);
    }

    public function testTheFirstClosingFenceWinsAndLaterRulesStayInTheBody(): void
    {
        $split = Frontmatter::split("---\na: 1\n---\nbody\n\n---\n\nmore");

        $this->assertNotNull($split);
        $this->assertSame('a: 1', $split->meta);
        $this->assertSame("body\n\n---\n\nmore", $split->body);
    }

    public function testAClosingFenceMayEndTheDocumentWithoutANewline(): void
    {
        // The widening the MemoryStore variant needed for hand-written notes
        // with no body. The seven main copies returned no frontmatter here;
        // this is the one input where the shared grammar is wider than them.
        $doc = "---\nname: note\n---";
        $split = Frontmatter::split($doc);

        $this->assertNotNull($split);
        $this->assertSame('name: note', $split->meta);
        $this->assertSame('', $split->body);
        $this->assertSame(strlen($doc), $split->bodyOffset);
    }

    public function testMainGrammarParityOnEveryDocumentItAccepts(): void
    {
        foreach ($this->parityDocuments() as $name => $doc) {
            if (preg_match(self::LEGACY_MAIN, $doc, $m) !== 1) {
                continue;
            }
            $split = Frontmatter::split($doc);
            $this->assertNotNull($split, $name);
            $this->assertSame($m[1], $split->meta, $name);
            $this->assertSame(substr($doc, strlen($m[0])), $split->body, $name);
        }
    }

    /**
     * MemoryStore keeps the blank lines between its closing fence and the
     * text; the shared split drops them, because the main copies do. The
     * divergence is deliberate and pinned here: the body is the memory body
     * with its leading blank lines removed, nothing else changed.
     */
    public function testMemoryVariantParityOnEveryDocumentItAccepts(): void
    {
        foreach ($this->parityDocuments() as $name => $doc) {
            if (preg_match(self::LEGACY_MEMORY, $doc, $m) !== 1) {
                continue;
            }
            $split = Frontmatter::split($doc);
            $this->assertNotNull($split, $name);
            $this->assertSame($m[1], $split->meta, $name);
            $this->assertSame(
                preg_replace('/\A(?:[ \t]*\r?\n)*/', '', $m[2]),
                $split->body,
                "{$name}: body differs from MemoryStore beyond its leading blank lines",
            );
            $this->assertSame(strlen($doc) - strlen($split->body), $split->bodyOffset, $name);
        }
    }

    public function testNoDocumentTheSevenCopiesRejectIsAcceptedUnlessItEndsAtTheFence(): void
    {
        foreach ($this->parityDocuments() as $name => $doc) {
            if (preg_match(self::LEGACY_MAIN, $doc) === 1) {
                continue;
            }
            $split = Frontmatter::split($doc);
            if ($split !== null) {
                $this->assertSame('', $split->body, "{$name} widened beyond an end-of-document close");
            }
        }
    }

    /** @return array<string, string> */
    private function parityDocuments(): array
    {
        return [
            'basic' => "---\ntitle: x\n---\nbody\n",
            'rule' => "---\ntitle: x\n---\n\n---\n\nbody\n",
            'crlf' => "---\r\ntitle: x\r\n---\r\nbody\r\n",
            'trailing-ws-fences' => "---  \ntitle: x\n---\t\nbody",
            'blank-lines-before-body' => "---\ntitle: x\n---\n\n\n  indented\n",
            'empty-meta' => "---\n\n---\nbody",
            'empty-meta-no-body' => "---\n\n---\n",
            'eof-close' => "---\ntitle: x\n---",
            'eof-close-newline' => "---\ntitle: x\n---\n",
            'fence-in-value' => "---\ntags: a---b\n---\nbody",
            'no-open' => "body\n---\ntitle: x\n---\n",
            'four-dash' => "---\ntitle: x\n----\nbody",
            'dash-suffix' => "---\ntitle: x\n---x\nbody",
            'unclosed' => "---\ntitle: x\nbody\n",
            'only-fence' => "---",
            'empty' => '',
        ];
    }
}
