<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;

/**
 * crush_libs.md candy-core #2: `wrapParagraph()` split words with a `/u`
 * regex and read its `false` (malformed UTF-8) through `?: []` as "no words",
 * so `Width::wrap()` returned '' for any paragraph holding one bad byte —
 * the text simply vanished, while truncate()/string() pass the same bytes
 * through. Every input byte must survive the wrap.
 *
 * Probing that turned up the root of the disagreement one level down:
 * `nextCluster()` trusted `grapheme_extract()`, which on malformed input
 * returns the NEXT cluster (skipping the stray byte) or a substituted U+FFFD,
 * so every cluster walk duplicated one cluster and dropped the bad byte —
 * `truncate("aaa\xffb", 10)` was `"aaabb"`. The walkers must reproduce
 * their input byte-for-byte when it fits.
 */
final class WidthInvalidUtf8Test extends TestCase
{
    /** @return iterable<string, array{string, int, string}> */
    public static function cases(): iterable
    {
        yield 'leading garbage' => ["\xff\xfe garbage here", 20, "\xff\xfe garbage here"];
        yield 'garbage mid-line' => ["hello \xff world", 20, "hello \xff world"];
        yield 'garbage forces wraps' => ["hello \xff world again", 8, "hello \xff\nworld\nagain"];
        // 0xA0 is a UTF-8 continuation byte (`à` is C3 A0): the byte-level
        // fallback must not treat it as whitespace and cut the glyph in half.
        yield 'valid multibyte beside garbage' => ["caf\u{e0} \xff x", 5, "caf\u{e0} \xff\nx"];
        yield 'only the bad paragraph falls back' => ["ok line\n\xff bad", 20, "ok line\n\xff bad"];
    }

    #[DataProvider('cases')]
    public function testWrapKeepsEveryByteOfAnInvalidUtf8Paragraph(string $in, int $max, string $expected): void
    {
        $this->assertSame($expected, Width::wrap($in, $max));
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'stray lead byte' => ["aaa\xffb"];
        yield 'leading stray bytes' => ["\xff\xfeab"];
        yield 'truncated tail' => ["ab\xc3"];
        yield 'broken sequence before ascii' => ["\xe2AB"];
        // 0xA5, not 0x80-0x9F: Ansi::strip() (which the non-ANSI walkers run
        // first) deliberately removes lone C1-range bytes as 8-bit controls.
        yield 'lone continuation byte' => ["x\xa5y"];
    }

    #[DataProvider('malformed')]
    public function testClusterWalkersReproduceMalformedInputThatFits(string $in): void
    {
        $this->assertSame($in, Width::truncate($in, 50));
        $this->assertSame($in, Width::truncateAnsi($in, 50));
        $this->assertSame($in, Width::takeAnsi($in, 50));
        $this->assertSame($in, Width::wrapAnsi($in, 50));
        $this->assertSame($in, Width::wrap($in, 50));
    }

    public function testBrokenSequenceDoesNotSwallowFollowingAscii(): void
    {
        // `\xe2` claims 3 bytes, but `A` and `B` are not continuations: they
        // must still be measured as the two visible cells they are.
        $this->assertSame(2, Width::string("\xe2AB"));
    }

    public function testWrapAgreesWithTruncateOnInvalidBytes(): void
    {
        $in = "aaa\xffb";
        // Both measures treat the stray byte as a zero-width cluster.
        $this->assertSame(Width::truncate($in, 10), Width::wrap($in, 10));
    }
}
