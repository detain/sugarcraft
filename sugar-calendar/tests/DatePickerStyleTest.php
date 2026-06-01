<?php

declare(strict_types=1);

namespace SugarCraft\Calendar\Tests;

use SugarCraft\Buffer\Buffer;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Style;
use SugarCraft\Calendar\DatePicker;
use PHPUnit\Framework\TestCase;

final class DatePickerStyleTest extends TestCase
{
    private DatePicker $dp;

    protected function setUp(): void
    {
        $this->dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
    }

    public function testWithCursorStyleFluent(): void
    {
        $a = $this->dp;
        $b = $a->WithCursorStyle('7');
        $c = $b->WithCursorStyle('1;31');

        $this->assertNotSame($a, $b);
        $this->assertNotSame($b, $c);
    }

    public function testWithRangeStyleFluent(): void
    {
        $a = $this->dp;
        $b = $a->WithRangeStyle('1;35');
        $c = $b->WithRangeStyle('1;36');

        $this->assertNotSame($a, $b);
        $this->assertNotSame($b, $c);
    }

    public function testSgrToBufferStyleBold(): void
    {
        $style = $this->invokeSgrToBufferStyle('1');
        $this->assertNotNull($style);
        $this->assertTrue($style->hasBold());
    }

    public function testSgrToBufferStyleFaint(): void
    {
        $style = $this->invokeSgrToBufferStyle('2');
        $this->assertNotNull($style);
        $this->assertTrue($style->hasFaint());
    }

    public function testSgrToBufferStyleItalic(): void
    {
        $style = $this->invokeSgrToBufferStyle('3');
        $this->assertNotNull($style);
        $this->assertTrue($style->hasItalic());
    }

    public function testSgrToBufferStyleUnderline(): void
    {
        $style = $this->invokeSgrToBufferStyle('4');
        $this->assertNotNull($style);
        $this->assertTrue($style->hasUnderline());
    }

    public function testSgrToBufferStyleBlink(): void
    {
        $style = $this->invokeSgrToBufferStyle('5');
        $this->assertNotNull($style);
        $this->assertTrue($style->hasBlink());
    }

    public function testSgrToBufferStyleBlink6(): void
    {
        $style = $this->invokeSgrToBufferStyle('6');
        $this->assertNotNull($style);
        $this->assertTrue($style->hasBlink());
    }

    public function testSgrToBufferStyleReverse(): void
    {
        $style = $this->invokeSgrToBufferStyle('7');
        $this->assertNotNull($style);
        $this->assertTrue($style->hasReverse());
    }

    public function testSgrToBufferStyleStrike(): void
    {
        $style = $this->invokeSgrToBufferStyle('9');
        $this->assertNotNull($style);
        $this->assertTrue($style->hasStrike());
    }

    public function testSgrToBufferStyleBrightForeground(): void
    {
        $style = $this->invokeSgrToBufferStyle('91');
        $this->assertNotNull($style);
        $this->assertNotNull($style->fg());
    }

    public function testSgrToBufferStyleEmptyReturnsNull(): void
    {
        $style = $this->invokeSgrToBufferStyle('');
        $this->assertNull($style);
    }

    public function testSgrToBufferStyleCombined(): void
    {
        $style = $this->invokeSgrToBufferStyle('1;31');
        $this->assertNotNull($style);
        $this->assertTrue($style->hasBold());
        $this->assertNotNull($style->fg());
    }

    public function testAnsiColorToRgbStandard(): void
    {
        $rgb = $this->invokeAnsiColorToRgb(0, false);
        $this->assertSame(0x000000, $rgb);

        $rgb = $this->invokeAnsiColorToRgb(7, false);
        $this->assertSame(0xc0c0c0, $rgb);
    }

    public function testAnsiColorToRgbBright(): void
    {
        $rgb = $this->invokeAnsiColorToRgb(0, true);
        $this->assertSame(0x606060, $rgb);

        $rgb = $this->invokeAnsiColorToRgb(7, true);
        $this->assertSame(0xffffff, $rgb);
    }

    public function testAnsiColorToRgbOutOfRange(): void
    {
        $rgb = $this->invokeAnsiColorToRgb(99, false);
        $this->assertSame(0xc0c0c0, $rgb);

        $rgb = $this->invokeAnsiColorToRgb(99, true);
        $this->assertSame(0xffffff, $rgb);
    }

    public function testGraphemeWidthCombiningMark(): void
    {
        $w = $this->invokeGraphemeWidth("\xcc\x80");
        $this->assertSame(0, $w);

        $w = $this->invokeGraphemeWidth("\xcd\x8f");
        $this->assertSame(0, $w);
    }

    public function testGraphemeWidthWideEastAsian(): void
    {
        $w = $this->invokeGraphemeWidth("\xe4\xb8\x80");
        $this->assertSame(2, $w);

        $w = $this->invokeGraphemeWidth("\xe3\x82\xa0");
        $this->assertSame(2, $w);
    }

    public function testGraphemeWidthEmptyReturnsZero(): void
    {
        $w = $this->invokeGraphemeWidth('');
        $this->assertSame(0, $w);
    }

    public function testGraphemeWidthAsciiAlpha(): void
    {
        $w = $this->invokeGraphemeWidth('A');
        $this->assertSame(1, $w);
    }

    public function testPlaceStringAtWideCharTruncatesAtWidth(): void
    {
        $buf = Buffer::new(5, 3);
        $result = $this->invokePlaceStringAt($buf, 0, 0, "\xe4\xb8\x80\xe4\xb8\x80\xe4\xb8\x80", null);

        $this->assertInstanceOf(Buffer::class, $result);
    }

    public function testBuildRangeWithBothDatesInViewMonth(): void
    {
        $dp = $this->dp
            ->withRangeMode(true);

        $firstDow = (int) (new \DateTimeImmutable('2026-05-01'))->format('w');
        for ($i = 0; $i < $firstDow; $i++) {
            $dp = $dp->MoveCursorRight();
        }
        $dp = $dp->handleKey('enter');

        for ($i = 0; $i < 4; $i++) {
            $dp = $dp->MoveCursorRight();
        }
        $dp = $dp->handleKey('enter');

        $this->assertNotNull($dp->rangeStart());
        $this->assertNotNull($dp->rangeEnd());
    }

    public function testBuildRangeWithDatesOutsideViewMonth(): void
    {
        $ref = new \ReflectionClass(DatePicker::class);
        $dp = $this->dp->withRangeMode(true);

        $juneDate = new \DateTimeImmutable('2026-06-15');
        $julyDate = new \DateTimeImmutable('2026-07-01');

        $propStart = $ref->getProperty('rangeStart');
        $propStart->setAccessible(true);
        $propStart->setValue($dp, $juneDate);

        $propEnd = $ref->getProperty('rangeEnd');
        $propEnd->setAccessible(true);
        $propEnd->setValue($dp, $julyDate);

        $meth = $ref->getMethod('buildRange');
        $meth->setAccessible(true);
        $range = $meth->invoke($dp);

        $this->assertNull($range);
    }

    public function testBuildCellsWithRangeHighlight(): void
    {
        $dp = $this->dp
            ->withRangeMode(true);

        $firstDow = (int) (new \DateTimeImmutable('2026-05-01'))->format('w');
        for ($i = 0; $i < $firstDow; $i++) {
            $dp = $dp->MoveCursorRight();
        }
        $dp = $dp->handleKey('enter');

        for ($i = 0; $i < 4; $i++) {
            $dp = $dp->MoveCursorRight();
        }
        $dp = $dp->handleKey('enter');

        $view = $dp->View();
        $this->assertIsString($view);
    }

    private function invokeSgrToBufferStyle(string $sgr): ?Style
    {
        $ref = new \ReflectionClass($this->dp);
        $meth = $ref->getMethod('sgrToBufferStyle');
        $meth->setAccessible(true);
        return $meth->invoke($this->dp, $sgr);
    }

    private function invokeAnsiColorToRgb(int $idx, bool $bright): int
    {
        $ref = new \ReflectionClass($this->dp);
        $meth = $ref->getMethod('ansiColorToRgb');
        $meth->setAccessible(true);
        return $meth->invoke($this->dp, $idx, $bright);
    }

    private function invokeGraphemeWidth(string $g): int
    {
        $ref = new \ReflectionClass($this->dp);
        $meth = $ref->getMethod('graphemeWidth');
        $meth->setAccessible(true);
        return $meth->invoke($this->dp, $g);
    }

    private function invokePlaceStringAt(Buffer $buf, int $col, int $row, string $s, ?Style $style): Buffer
    {
        $ref = new \ReflectionClass($this->dp);
        $meth = $ref->getMethod('placeStringAt');
        $meth->setAccessible(true);
        return $meth->invoke($this->dp, $buf, $col, $row, $s, $style);
    }
}
