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

    public function testEofWindowWhenActualEndsEarly(): void
    {
        $r = (new ByteAssertion())->compare('hello world', 'hello');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('<EOF>', $r['diff']);
    }
}
