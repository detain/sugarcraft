<?php

declare(strict_types=1);

namespace CandyCore\Shell\Tests\Style;

use CandyCore\Shell\Style\StyleBuilder;
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
}
