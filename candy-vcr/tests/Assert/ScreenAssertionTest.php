<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Assert;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Assert\Assertion;
use SugarCraft\Vcr\Assert\ScreenAssertion;

final class ScreenAssertionTest extends TestCase
{
    public function testImplementsAssertion(): void
    {
        $this->assertInstanceOf(Assertion::class, new ScreenAssertion());
    }

    public function testIdenticalBytesPass(): void
    {
        $r = (new ScreenAssertion())->compare("hello", "hello");
        $this->assertTrue($r['ok']);
        $this->assertSame('', $r['diff']);
    }

    public function testEquivalentCursorMovesPass(): void
    {
        // Both produce "AB" on the first line, but the actual takes a
        // longer route via explicit CSI-H. Byte-strict comparison would
        // fail; cell-grid comparison succeeds.
        $expected = 'AB';
        $actual = "\x1b[1;1HA\x1b[1;2HB";
        $r = (new ScreenAssertion())->compare($expected, $actual);
        $this->assertTrue($r['ok'], $r['diff']);
    }

    public function testRedundantSgrReemissionPasses(): void
    {
        // Both write a bold "X" at the same cell; the actual emits a
        // redundant bold-on / bold-off / bold-on sequence in between.
        $expected = "\x1b[1mX";
        $actual = "\x1b[1m\x1b[22m\x1b[1mX";
        $r = (new ScreenAssertion())->compare($expected, $actual);
        $this->assertTrue($r['ok'], $r['diff']);
    }

    public function testDifferentGraphemesFail(): void
    {
        $r = (new ScreenAssertion())->compare("hello", "hellp");
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('cell-grid mismatch', $r['diff']);
        $this->assertStringContainsString('1 cell(s) differ', $r['diff']);
        $this->assertStringContainsString("'o'", $r['diff']);
        $this->assertStringContainsString("'p'", $r['diff']);
    }

    public function testMultipleCellDifferencesFail(): void
    {
        $r = (new ScreenAssertion())->compare("hello world", "HELLO world");
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('5 cell(s) differ', $r['diff']);
    }

    public function testManyCellsTruncatesDiff(): void
    {
        $expected = str_repeat('a', 80);
        $actual = str_repeat('b', 80);
        $r = (new ScreenAssertion())->compare($expected, $actual);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('80 cell(s) differ', $r['diff']);
        $this->assertStringContainsString('and 75 more', $r['diff']);
    }

    public function testCustomDimensions(): void
    {
        $a = new ScreenAssertion(cols: 40, rows: 12);
        $this->assertSame(40, $a->cols);
        $this->assertSame(12, $a->rows);
    }

    public function testRejectsBadDimensions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ScreenAssertion(cols: 0, rows: 24);
    }

    public function testEmptyByteStreamsPass(): void
    {
        $r = (new ScreenAssertion())->compare('', '');
        $this->assertTrue($r['ok']);
    }
}
