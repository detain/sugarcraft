<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Assert;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Assert\ByteAssertion;

final class ByteAssertionTest extends TestCase
{
    public function testEqualBytesPass(): void
    {
        $r = (new ByteAssertion())->compare("hello", "hello");
        $this->assertTrue($r['ok']);
        $this->assertSame('', $r['diff']);
    }

    public function testEmptyEqualPass(): void
    {
        $r = (new ByteAssertion())->compare('', '');
        $this->assertTrue($r['ok']);
    }

    public function testDifferentLengthsFail(): void
    {
        $r = (new ByteAssertion())->compare('abc', 'abcd');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('expected 3 bytes, got 4 bytes', $r['diff']);
        $this->assertStringContainsString('first divergence at offset 3', $r['diff']);
    }

    public function testDifferingBytesFailWithFirstDivergenceOffset(): void
    {
        $r = (new ByteAssertion())->compare('hello world', 'hello WORLD');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('first divergence at offset 6', $r['diff']);
    }

    public function testDiffSummaryShowsHexAndPrintableWindow(): void
    {
        $r = (new ByteAssertion())->compare("\x1b[2J", "\x1b[2K");
        $this->assertFalse($r['ok']);
        // Window starts at the first-divergence offset (3), so we see
        // just the differing tail byte: "J" → 4a, "K" → 4b.
        $this->assertStringContainsString('4a (J)', $r['diff']);
        $this->assertStringContainsString('4b (K)', $r['diff']);
    }

    public function testDiffSummaryWidthCoversFollowingBytes(): void
    {
        // Force divergence at offset 0 — the 16-byte window then shows
        // the whole expected/actual prefix in hex.
        $expected = str_repeat('A', 16);
        $actual = str_repeat('B', 16);
        $r = (new ByteAssertion())->compare($expected, $actual);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString(str_repeat('41', 16), $r['diff']);
        $this->assertStringContainsString(str_repeat('42', 16), $r['diff']);
    }

    public function testFirstDivergenceIsExactAcrossTheChunkBoundary(): void
    {
        // VCR-S75/PERF-10: the scan skips 64 KiB slices at C speed before
        // walking the diverging slice byte-by-byte. Offsets straddling the
        // private DIVERGENCE_CHUNK (65536) must report the naive loop's
        // exact answer.
        $chunk = 65536;
        foreach ([$chunk - 1, $chunk, $chunk + 1, 2 * $chunk + 7] as $off) {
            $a = str_repeat('x', $off) . 'A' . 'tail';
            $b = str_repeat('x', $off) . 'B' . 'tail';
            $r = (new ByteAssertion())->compare($a, $b);
            $this->assertFalse($r['ok'], "offset {$off} must diverge");
            $this->assertStringContainsString("first divergence at offset {$off}", $r['diff']);
        }
    }

    public function testLongCommonPrefixDivergesAtMinLenWhenOneSideRunsOut(): void
    {
        // Past the last whole chunk, equal prefixes with a shorter tail
        // report offset = min length — the same answer as the naive walk.
        $a = str_repeat('q', 70000);
        $b = $a . 'z';
        $r = (new ByteAssertion())->compare($a, $b);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('first divergence at offset 70000', $r['diff']);
        $this->assertStringContainsString('expected 70000 bytes, got 70001', $r['diff']);
    }

    public function testEofWindowWhenActualEndsEarly(): void
    {
        $r = (new ByteAssertion())->compare('hello world', 'hello');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('<EOF>', $r['diff']);
    }
}
