<?php

declare(strict_types=1);

namespace SugarCraft\Style\Tests;

use SugarCraft\Style\Style;
use SugarCraft\Style\UnderlineStyle;
use PHPUnit\Framework\TestCase;

final class UnderlineStyleTest extends TestCase
{
    public function testEnumValues(): void
    {
        $this->assertSame(0, UnderlineStyle::None->value);
        $this->assertSame(1, UnderlineStyle::Single->value);
        $this->assertSame(2, UnderlineStyle::Double->value);
        $this->assertSame(3, UnderlineStyle::Curly->value);
        $this->assertSame(4, UnderlineStyle::Dotted->value);
        $this->assertSame(5, UnderlineStyle::Dashed->value);
    }

    public function testDefaultIsNone(): void
    {
        $this->assertSame(UnderlineStyle::None, Style::new()->getUnderlineStyle());
    }

    public function testWithStyleSets(): void
    {
        $s = Style::new()->underline()->underlineStyle(UnderlineStyle::Curly);
        $this->assertSame(UnderlineStyle::Curly, $s->getUnderlineStyle());
    }

    public function testEmitsSubStyledSgr(): void
    {
        $s = Style::new()->underline()->underlineStyle(UnderlineStyle::Double);
        $rendered = $s->render('hello');
        // Modern terminals' sub-style escape: SGR 4:2.
        $this->assertStringContainsString("\x1b[4:2m", $rendered);
    }

    public function testNoSubStyleKeepsPlainSgr4(): void
    {
        $s = Style::new()->underline();
        $rendered = $s->render('hello');
        // SGR 4 (plain underline) only, no 4:N sub-style.
        $this->assertStringContainsString("\x1b[4m", $rendered);
        $this->assertStringNotContainsString("\x1b[4:", $rendered);
    }

    public function testStyleNoneEmitsPlainSgr4(): void
    {
        $s = Style::new()->underline()->underlineStyle(UnderlineStyle::None);
        $rendered = $s->render('hello');
        $this->assertStringContainsString("\x1b[4m", $rendered);
        $this->assertStringNotContainsString("\x1b[4:", $rendered);
    }

    public function testSubStyleAloneImpliesUnderlineWith4NWireForm(): void
    {
        $rendered = Style::new()->underlineStyle(UnderlineStyle::Curly)->render('hello');
        $this->assertStringContainsString("\x1b[4:3m", $rendered);
        $this->assertStringNotContainsString("\x1b[4m", $rendered);
    }

    public function testExplicitLaterUnderlineFalseWins(): void
    {
        $rendered = Style::new()->underlineStyle(UnderlineStyle::Curly)->underline(false)->render('hello');
        $this->assertStringNotContainsString("\x1b[4", $rendered);
    }

    public function testNoneStyleLeavesUnderlineAlone(): void
    {
        $rendered = Style::new()->underlineStyle(UnderlineStyle::None)->render('hello');
        $this->assertStringNotContainsString("\x1b[4", $rendered);
    }

    public function testImplicitUnderlineSurvivesInheritAndPatch(): void
    {
        $parent = Style::new()->underlineStyle(UnderlineStyle::Dashed);
        $this->assertStringContainsString("\x1b[4:5m", Style::new()->inherit($parent)->render('x'));
        $this->assertStringContainsString("\x1b[4:5m", Style::new()->patch($parent)->render('x'));
    }
}
