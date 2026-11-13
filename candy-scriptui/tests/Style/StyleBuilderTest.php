<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Style;

use SugarCraft\ScriptUi\Runtime\ColourGate;
use SugarCraft\ScriptUi\Style\StyleBuilder;
use PHPUnit\Framework\TestCase;

final class StyleBuilderTest extends TestCase
{
    public function testBoldFlag(): void
    {
        $s = StyleBuilder::fromFlags(['bold' => true]);
        $this->assertSame("\x1b[1mhi\x1b[0m", $s->render('hi'));
    }

    public function testForegroundHexAndBold(): void
    {
        $s = StyleBuilder::fromFlags([
            'foreground' => '#ff0000',
            'bold'       => true,
        ]);
        $this->assertSame("\x1b[1m\x1b[38;2;255;0;0mhi\x1b[0m", $s->render('hi'));
    }

    public function testForegroundAnsiIndex(): void
    {
        $s = StyleBuilder::fromFlags(['foreground' => '4']);
        // ANSI index 4 = blue (code 34).
        $this->assertSame("\x1b[34mhi\x1b[0m", $s->render('hi'));
    }

    public function testForegroundAnsi256Index(): void
    {
        $s = StyleBuilder::fromFlags(['foreground' => '202']);
        $this->assertSame("\x1b[38;5;202mhi\x1b[0m", $s->render('hi'));
    }

    public function testHexWithoutHash(): void
    {
        $s = StyleBuilder::fromFlags(['foreground' => 'ff8000']);
        $this->assertSame("\x1b[38;2;255;128;0mhi\x1b[0m", $s->render('hi'));
    }

    public function testBadColorRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StyleBuilder::parseColor('not-a-color');
    }

    public function testParseSidesShorthand(): void
    {
        $this->assertSame([1],          StyleBuilder::parseSides('1'));
        $this->assertSame([1, 2],       StyleBuilder::parseSides('1 2'));
        $this->assertSame([1, 2, 3, 4], StyleBuilder::parseSides('1,2,3,4'));
    }

    public function testParseSidesRejectsThree(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StyleBuilder::parseSides('1 2 3');
    }

    public function testParseSidesRejectsNonNumericToken(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StyleBuilder::parseSides('foo');
    }

    public function testParseSidesRejectsMixedToken(): void
    {
        // `(int) '1bar'` would silently coerce to 1; must throw instead.
        $this->expectException(\InvalidArgumentException::class);
        StyleBuilder::parseSides('1,bar');
    }

    public function testParseSidesAcceptsNegativeNumbers(): void
    {
        // The Style layer rejects negatives, but the parser itself
        // should treat `-1` as a valid integer token (and let the higher
        // layer's validation surface a clearer error).
        $this->expectException(\InvalidArgumentException::class);
        StyleBuilder::parseSides('-1,bogus');
    }

    public function testPaddingApplied(): void
    {
        $s = StyleBuilder::fromFlags(['padding' => '0 2']);
        $this->assertSame('  hi  ', $s->render('hi'));
    }

    public function testWidthApplied(): void
    {
        $s = StyleBuilder::fromFlags(['width' => '5']);
        $this->assertSame('hi   ', $s->render('hi'));
    }

    public function testAlignRight(): void
    {
        $s = StyleBuilder::fromFlags(['width' => '5', 'align' => 'right']);
        $this->assertSame('   hi', $s->render('hi'));
    }

    public function testAllAttributes(): void
    {
        $s = StyleBuilder::fromFlags([
            'bold'          => true,
            'italic'        => true,
            'underline'     => true,
            'strikethrough' => true,
            'faint'         => true,
        ]);
        $this->assertStringContainsString("\x1b[", $s->render('x'));
    }

    public function testBorderFlag(): void
    {
        $s = StyleBuilder::fromFlags(['border' => 'rounded']);
        $out = $s->render('hi');
        $this->assertStringContainsString('╭', $out);
        $this->assertStringContainsString('╯', $out);
    }

    public function testBorderForegroundColor(): void
    {
        $s = StyleBuilder::fromFlags([
            'border'             => 'normal',
            'border-foreground'  => '4',
        ]);
        $out = $s->render('hi');
        // ANSI 4 = 34
        $this->assertStringContainsString("\x1b[34m", $out);
    }

    public function testHeightFlag(): void
    {
        $s = StyleBuilder::fromFlags(['height' => '3']);
        $out = $s->render('x');
        $this->assertCount(3, explode("\n", $out));
    }

    public function testParseHexColorExpandsThreeDigitShorthand(): void
    {
        $this->assertEquals(
            StyleBuilder::parseHexColor('#aabbcc'),
            StyleBuilder::parseHexColor('#abc'),
        );
    }

    public function testThreeDigitShorthandRendersSameBytesAsSixDigit(): void
    {
        $short = StyleBuilder::fromFlags(['foreground' => '#f80']);
        $long  = StyleBuilder::fromFlags(['foreground' => '#ff8800']);
        $this->assertSame($long->render('hi'), $short->render('hi'));
    }

    public function testBareThreeDigitHexStillAccepted(): void
    {
        $s = StyleBuilder::fromFlags(['foreground' => 'f80']);
        $this->assertSame("\x1b[38;2;255;136;0mhi\x1b[0m", $s->render('hi'));
    }

    public function testParseHexColorRejectsFourDigitsNamingValue(): void
    {
        try {
            StyleBuilder::parseHexColor('#1234');
            $this->fail('Expected InvalidArgumentException for 4-digit hex');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('#1234', $e->getMessage());
            $this->assertStringContainsString('3 or 6', $e->getMessage());
        }
    }

    /** Bare hex digits with a wrong count get the specific hex error too. */
    public function testBareHexWithWrongDigitCountRejected(): void
    {
        try {
            StyleBuilder::parseColor('abcde');
            $this->fail('Expected InvalidArgumentException for 5-digit hex');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('abcde', $e->getMessage());
        }
    }

    public function testParseHexColorRejectsNonHexDigits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StyleBuilder::parseHexColor('#zzz');
    }

    /**
     * scriptui-25 (candy-scriptui-018): the border colour legs used to apply
     * OUTSIDE the single ColourGate clamp; every colour leg now feeds one
     * profile computation that is clamped once, after collection.
     */
    public function testBorderColoursNeverReWidenPastTheGate(): void
    {
        putenv('NO_COLOR=1');
        try {
            ColourGate::install();
            $s = StyleBuilder::fromFlags([
                'foreground'        => '#ff0000',
                'border'            => 'normal',
                'border-foreground' => '#0000ff',
                'border-background' => '4',
            ]);
            $out = $s->render('hi');
            $this->assertStringNotContainsString('38;2', $out, 'NO_COLOR must not leak truecolour');
            $this->assertStringNotContainsString('38;5', $out);
            $this->assertStringNotContainsString("\x1b[34m", $out);
            $this->assertStringNotContainsString("\x1b[31m", $out);
        } finally {
            ColourGate::resetAmbient();
            putenv('NO_COLOR');
        }
    }

    public function testBorderColourStillRendersWhenAllowed(): void
    {
        $s = StyleBuilder::fromFlags([
            'border'            => 'normal',
            'border-foreground' => '4',
        ]);
        // Palette index advertises its own SGR: ANSI 4 = 34.
        $this->assertStringContainsString("\x1b[34m", $s->render('hi'));
    }

    /** scriptui-25 (candy-scriptui-043): advertised props must reach the Style. */
    public function testBlinkAndReverseReachTheRender(): void
    {
        $this->assertSame("\x1b[5mx\x1b[0m", StyleBuilder::fromFlags(['blink' => true])->render('x'));
        $this->assertSame("\x1b[7mx\x1b[0m", StyleBuilder::fromFlags(['reverse' => true])->render('x'));
    }

    /** scriptui-25 (candy-scriptui-044): bad enums refuse with the allowed list. */
    public function testUnknownAlignThrowsNamingAllowedValues(): void
    {
        try {
            StyleBuilder::fromFlags(['align' => 'middle']);
            $this->fail('align=middle must throw');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('middle', $e->getMessage());
            $this->assertStringContainsString('left|center|right', $e->getMessage());
        }
    }

    public function testUnknownBorderThrowsNamingAllowedValues(): void
    {
        try {
            StyleBuilder::fromFlags(['border' => 'round']);
            $this->fail('border=round must throw');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('round', $e->getMessage());
            $this->assertStringContainsString('none|normal|rounded|thick|double|block|ascii|hidden', $e->getMessage());
        }
    }

    public function testHiddenAndNoneBorderStaySilentNoOps(): void
    {
        foreach (['hidden', 'none', '0'] as $spelling) {
            $out = StyleBuilder::fromFlags(['border' => $spelling])->render('hi');
            $this->assertStringNotContainsString('│', $out);
        }
    }

    public function testAsciiBorderPresetApplies(): void
    {
        $out = StyleBuilder::fromFlags(['border' => 'ascii'])->render('hi');
        $this->assertStringContainsString('+', $out);
        $this->assertStringContainsString('-', $out);
    }
}
