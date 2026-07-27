<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Foundation;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Foundation\Segment;
use SugarCraft\Core\Util\Color;

final class SegmentTest extends TestCase
{
    public function testNewCreatesDefaultSegment(): void
    {
        $segment = Segment::new('12');

        $this->assertInstanceOf(Segment::class, $segment);
    }

    public function testRenderReturnsString(): void
    {
        $segment = Segment::new('8');
        $rendered = $segment->render();

        $this->assertIsString($rendered);
        $this->assertNotEmpty($rendered);
    }

    public function testRenderContainsNewlines(): void
    {
        $segment = Segment::new('0');
        $rendered = $segment->render();

        $this->assertStringContainsString("\n", $rendered);
    }

    public function testRenderDigitCount(): void
    {
        $segment = Segment::new('123');
        $lines = explode("\n", $segment->render());

        $this->assertCount(5, $lines);
    }

    public function testRenderDigit0(): void
    {
        $segment = Segment::new('0');
        $rendered = $segment->render();

        // Should have 5 lines
        $lines = explode("\n", $rendered);
        $this->assertCount(5, $lines);
    }

    public function testRenderDigit1(): void
    {
        $segment = Segment::new('1');
        $rendered = $segment->render();

        $lines = explode("\n", $rendered);
        $this->assertCount(5, $lines);
    }

    public function testRenderHexLetters(): void
    {
        $segment = Segment::new('ABCDEF');
        $rendered = $segment->render();

        $this->assertIsString($rendered);
        $this->assertNotEmpty($rendered);
    }

    public function testRenderSpace(): void
    {
        $segment = Segment::new(' ');
        $rendered = $segment->render();

        $this->assertIsString($rendered);
    }

    public function testRenderMinus(): void
    {
        $segment = Segment::new('-');
        $rendered = $segment->render();

        $this->assertIsString($rendered);
    }

    public function testRenderDegreeSymbol(): void
    {
        $segment = Segment::new('°');
        $rendered = $segment->render();

        $this->assertIsString($rendered);
    }

    public function testGetInnerSizeSingleDigit(): void
    {
        $segment = Segment::new('5');
        [$width, $height] = $segment->getInnerSize();

        $this->assertSame(3, $width);
        $this->assertSame(5, $height);
    }

    public function testGetInnerSizeMultipleDigits(): void
    {
        $segment = Segment::new('12:34');
        [$width, $height] = $segment->getInnerSize();

        // 5 digits + 2 colons = 5*3 + 2 = 17
        $this->assertSame(17, $width);
        $this->assertSame(5, $height);
    }

    public function testWithDigitWidthReturnsNewInstance(): void
    {
        $original = Segment::new('8');
        $modified = $original->withDigitWidth(4);

        $this->assertNotSame($original, $modified);
    }

    public function testWithDigitWidthClampsToMinimumTwo(): void
    {
        $segment = Segment::new('8')->withDigitWidth(1);
        $reflector = new \ReflectionClass($segment);
        $prop = $reflector->getProperty('digitWidth');
        $prop->setAccessible(true);

        $this->assertSame(2, $prop->getValue($segment));
    }

    public function testWithShowColon(): void
    {
        $withColon = Segment::new('12');
        $withoutColon = $withColon->withShowColon(false);

        $this->assertNotSame($withColon, $withoutColon);
    }

    public function testWithOnColor(): void
    {
        $segment = Segment::new('8');
        $colored = $segment->withOnColor(Color::hex('#FF0000'));

        $this->assertNotSame($segment, $colored);
    }

    public function testWithOffColor(): void
    {
        $segment = Segment::new('8');
        $colored = $segment->withOffColor(Color::hex('#333333'));

        $this->assertNotSame($segment, $colored);
    }

    public function testWithContent(): void
    {
        $segment = Segment::new('8');
        $newContent = $segment->withContent('42');

        $this->assertNotSame($segment, $newContent);
    }

    public function testSetSizeReturnsSizer(): void
    {
        $segment = Segment::new('8');
        $sized = $segment->setSize(20, 10);

        $this->assertInstanceOf(\SugarCraft\Dash\Foundation\Sizer::class, $sized);
    }

    public function testImmutabilityWithMultipleWithers(): void
    {
        $original = Segment::new('8');

        $s1 = $original->withDigitWidth(4);
        $s2 = $s1->withShowColon(false);
        $s3 = $s2->withOnColor(Color::hex('#FF0000'));

        $this->assertNotSame($original, $s1);
        $this->assertNotSame($s1, $s2);
        $this->assertNotSame($s2, $s3);
    }
}
