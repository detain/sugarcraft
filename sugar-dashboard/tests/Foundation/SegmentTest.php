<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Foundation;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Foundation\Segment;
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
        [$width, $height] = $segment->innerSize();

        $this->assertSame(3, $width);
        $this->assertSame(5, $height);
    }

    public function testGetInnerSizeMultipleDigits(): void
    {
        $segment = Segment::new('12:34');
        [$width, $height] = $segment->innerSize();

        // D-50 DISCLOSED flip (finding 040): the old 17 came from counting
        // the literal ':' as a full digit column PLUS a phantom floor(n/2)
        // separator roster render() never matched. Honest model: 4 digit
        // cells x 3 + one 1-cell colon column = 13, and every rendered
        // line is exactly that wide.
        $this->assertSame(13, $width);
        $this->assertSame(5, $height);
        $this->assertEveryLineIsThePromisedWidth($segment, 13);
    }

    /**
     * Escape-stripped line width census: render() and innerSize() must
     * agree on every row (the old mixed-width colon rows desynced them).
     */
    private function assertEveryLineIsThePromisedWidth(Segment $segment, int $expect): void
    {
        foreach (explode("\n", $segment->render()) as $line) {
            $this->assertSame($expect, mb_strlen(\SugarCraft\Core\Util\Ansi::strip($line), 'UTF-8'));
        }
    }

    public function testTwoDigitNumberGrowsNoSpuriousColon(): void
    {
        // The finding headline: '95' rendered 9-blank-5 under the old
        // every-pair auto-insert.
        $segment = Segment::new('95');
        $this->assertStringNotContainsString('●', $segment->render());
        $this->assertSame([6, 5], $segment->innerSize());
        $this->assertEveryLineIsThePromisedWidth($segment, 6);
    }

    public function testClockShapesRenderTheirColons(): void
    {
        $nine = Segment::new('9:5');
        $this->assertSame(2, substr_count($nine->render(), '●'));
        $this->assertSame([7, 5], $nine->innerSize());
        $this->assertEveryLineIsThePromisedWidth($nine, 7);

        $hour = Segment::new('12:34');
        $this->assertSame(2, substr_count($hour->render(), '●'));
        $this->assertEveryLineIsThePromisedWidth($hour, 13);

        $full = Segment::new('12:34:56');
        $this->assertSame(4, substr_count($full->render(), '●'));
        $this->assertSame([6 * 3 + 2, 5], $full->innerSize());
        $this->assertEveryLineIsThePromisedWidth($full, 20);
    }

    public function testColonlessDigitStreamGetsClockPairGrouping(): void
    {
        // The honest auto-insert: a six-digit stream groups as 12:34:56
        // (two auto colon columns, each drawn as a two-dot pair).
        $segment = Segment::new('123456');
        $this->assertSame(4, substr_count($segment->render(), '●'));
        $this->assertSame([20, 5], $segment->innerSize());
        $this->assertEveryLineIsThePromisedWidth($segment, 20);

        // ...and the opt-out suppresses the grouping only.
        $plain = $segment->withShowColon(false);
        $this->assertStringNotContainsString('●', $plain->render());
        $this->assertSame([18, 5], $plain->innerSize());
        $this->assertEveryLineIsThePromisedWidth($plain, 18);
    }

    public function testLiteralColonsAreNeverDoubledByAutoGrouping(): void
    {
        // Content that spells its own separators keeps them verbatim:
        // '12:3' has one colon column, not one plus an auto-insert.
        $segment = Segment::new('12:3');
        $this->assertSame(2, substr_count($segment->render(), '●'));
        $this->assertSame([3 * 3 + 1, 5], $segment->innerSize());
        $this->assertEveryLineIsThePromisedWidth($segment, 10);
    }

    public function testDotTakesTheUnknownCharBlankFallbackWithoutADeadMapEntry(): void
    {
        // D-50 removed the all-off '.' entry (and the unreachable 'C2'
        // key). A '.' consumes one digit column rendered all-dark, exactly
        // as it did before — the roster no longer advertises a decimal
        // point that was never drawn.
        $segment = Segment::new('9.5');
        $this->assertStringNotContainsString('●', $segment->render());
        $this->assertSame([9, 5], $segment->innerSize());
        $this->assertEveryLineIsThePromisedWidth($segment, 9);
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

        $this->assertInstanceOf(\SugarCraft\Dashboard\Foundation\Sizer::class, $sized);
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
