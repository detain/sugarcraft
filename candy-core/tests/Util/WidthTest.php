<?php

declare(strict_types=1);

namespace CandyCore\Core\Tests\Util;

use CandyCore\Core\Util\Width;
use PHPUnit\Framework\TestCase;

final class WidthTest extends TestCase
{
    public function testAsciiWidth(): void
    {
        $this->assertSame(11, Width::string('hello world'));
    }

    public function testStripsAnsiBeforeMeasuring(): void
    {
        $this->assertSame(5, Width::string("\x1b[31mhello\x1b[0m"));
    }

    public function testEmpty(): void
    {
        $this->assertSame(0, Width::string(''));
    }

    public function testCjkWideEachCounts2(): void
    {
        $this->assertSame(4, Width::string('日本'));
    }

    public function testEmojiCounts2(): void
    {
        $this->assertSame(2, Width::string('🎉'));
    }

    public function testZeroWidthJoinerInvisible(): void
    {
        $this->assertSame(0, Width::string("\u{200b}"));
    }

    public function testCombiningMarkInvisible(): void
    {
        $this->assertSame(1, Width::string("e\u{0301}"));
    }

    public function testTruncate(): void
    {
        $this->assertSame('hello', Width::truncate('hello world', 5));
    }

    public function testTruncateRespectsWideChars(): void
    {
        $this->assertSame('日', Width::truncate('日本', 3));
    }

    public function testTruncateZero(): void
    {
        $this->assertSame('', Width::truncate('hello', 0));
    }
}
