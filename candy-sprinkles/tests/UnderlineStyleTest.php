<?php

declare(strict_types=1);

namespace CandyCore\Sprinkles\Tests;

use CandyCore\Sprinkles\Style;
use CandyCore\Sprinkles\UnderlineStyle;
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

    public function testWithoutUnderlineFlagNoSubStyleEmitted(): void
    {
        // Sub-style without the underline flag should emit nothing
        // related to underlining.
        $s = Style::new()->underlineStyle(UnderlineStyle::Curly);
        $rendered = $s->render('hello');
        $this->assertStringNotContainsString("\x1b[4:", $rendered);
        $this->assertStringNotContainsString("\x1b[4m", $rendered);
    }
}
