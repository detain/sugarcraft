<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Rule;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Style;
use SugarCraft\Widgets\Rule\Orientation;
use SugarCraft\Widgets\Rule\Rule;

final class RuleTest extends TestCase
{
    public function testPlainHorizontalGolden(): void
    {
        self::assertSame('──────', Rule::new(6)->view());
        self::assertSame('──────', (string) Rule::new(6));
    }

    public function testZeroLengthIsAuto(): void
    {
        self::assertSame(Rule::AUTO_LENGTH, Rule::new()->cells());
        self::assertSame(Rule::AUTO_LENGTH, Width::string(Rule::new(0)->view()));
        self::assertSame(7, Rule::new()->withLength(7)->cells());
    }

    public function testNegativeLengthThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Rule::new(-1);
    }

    public function testTitleGolden(): void
    {
        self::assertSame('── Title ───', Rule::new(12)->withTitle('Title')->view());
    }

    public function testTitleClipsToBudgetAtEveryLength(): void
    {
        for ($n = 1; $n <= 20; $n++) {
            $v = Rule::new($n)->withTitle('A long title here')->view();
            self::assertSame($n, Width::string($v), "length {$n}: {$v}");
        }
    }

    public function testWideTitleStaysWithinBudget(): void
    {
        $v = Rule::new(11)->withTitle('日本語のタイトル')->view();
        self::assertSame(11, Width::string($v));
    }

    public function testVerticalGolden(): void
    {
        self::assertSame("│\n│\n│", Rule::vertical(3)->view());
        self::assertSame(Orientation::Vertical, Rule::vertical(3)->orientation);
        self::assertSame(
            Rule::vertical(3)->view(),
            Rule::new(3)->withOrientation(Orientation::Vertical)->withTitle('ignored')->view(),
        );
    }

    public function testCustomRuneAndWidthLaw(): void
    {
        self::assertSame('=====', Rule::new(5)->withRune('=')->view());
        $this->expectException(\InvalidArgumentException::class);
        Rule::new(5)->withRune('日');
    }

    public function testEmptyRuneRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Rule::new(5)->withRune('');
    }

    public function testDefaultOutputCarriesNoSgrSoNoColorSafe(): void
    {
        self::assertStringNotContainsString("\x1b", Rule::new(10)->withTitle('t')->view());
        self::assertStringNotContainsString("\x1b", Rule::vertical(3)->view());
    }

    public function testStyleWrapsAndClearsWithStyle(): void
    {
        $r = Rule::new(4)->withStyle(Style::new()->bold());
        self::assertSame("\x1b[1m────\x1b[0m", $r->view());
        self::assertSame('────', $r->withStyle(null)->view());
    }

    public function testImmutable(): void
    {
        $r = Rule::new(4);
        $r->withTitle('x');
        $r->withRune('=');
        self::assertSame('────', $r->view());
    }

    public function testControlBytesStrippedFromTitle(): void
    {
        $v = Rule::new(20)->withTitle("a\x1b[31mb\x07")->view();
        self::assertStringNotContainsString("\x1b", $v);
        self::assertStringNotContainsString("\x07", $v);
    }
}
