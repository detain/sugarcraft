<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Handler\SgrHandler;
use SugarCraft\Vt\Sgr\Sgr;
use SugarCraft\Vt\Sgr\UnderlineStyle;

/**
 * Tests for SGR underline styles 4:N — single, double, curly, dotted, dashed.
 *
 * Mirrors charmbracelet/x/vt SGR underline-style handling.
 */
final class SgrUnderlineStylesTest extends TestCase
{
    private function apply(array $params, ?Sgr $start = null): Sgr
    {
        return (new SgrHandler())->apply($params, $start ?? Sgr::empty());
    }

    public function testPlain4MeansSingleUnderline(): void
    {
        // CSI 4 (no subparam) → single underline
        $sgr = $this->apply([4]);
        $this->assertTrue($sgr->underline);
        $this->assertSame(UnderlineStyle::Single, $sgr->underlineStyle);
    }

    public function testUnderlineStyleNone(): void
    {
        // CSI 4:0 → underline none
        $sgr = $this->apply([4, 0]);
        $this->assertFalse($sgr->underline);
        $this->assertSame(UnderlineStyle::None, $sgr->underlineStyle);
    }

    public function testUnderlineStyleSingle(): void
    {
        // CSI 4:1 → single underline
        $sgr = $this->apply([4, 1]);
        $this->assertTrue($sgr->underline);
        $this->assertSame(UnderlineStyle::Single, $sgr->underlineStyle);
    }

    public function testUnderlineStyleDouble(): void
    {
        // CSI 4:2 → double underline
        $sgr = $this->apply([4, 2]);
        $this->assertTrue($sgr->underline);
        $this->assertSame(UnderlineStyle::Double, $sgr->underlineStyle);
    }

    public function testUnderlineStyleCurly(): void
    {
        // CSI 4:3 → curly underline
        $sgr = $this->apply([4, 3]);
        $this->assertTrue($sgr->underline);
        $this->assertSame(UnderlineStyle::Curly, $sgr->underlineStyle);
    }

    public function testUnderlineStyleDotted(): void
    {
        // CSI 4:4 → dotted underline
        $sgr = $this->apply([4, 4]);
        $this->assertTrue($sgr->underline);
        $this->assertSame(UnderlineStyle::Dotted, $sgr->underlineStyle);
    }

    public function testUnderlineStyleDashed(): void
    {
        // CSI 4:5 → dashed underline
        $sgr = $this->apply([4, 5]);
        $this->assertTrue($sgr->underline);
        $this->assertSame(UnderlineStyle::Dashed, $sgr->underlineStyle);
    }

    public function testCsi24ClearsUnderlineStyle(): void
    {
        // CSI 24 → underline off (any style resets to none)
        $sgr = $this->apply([4, 2]); // start with double
        $this->assertSame(UnderlineStyle::Double, $sgr->underlineStyle);
        $sgr = $this->apply([24], $sgr);
        $this->assertFalse($sgr->underline);
        $this->assertSame(UnderlineStyle::None, $sgr->underlineStyle);
    }

    public function testCsi0ResetsUnderlineStyle(): void
    {
        // CSI 0 → full reset
        $sgr = $this->apply([4, 5]); // start with dashed
        $this->assertSame(UnderlineStyle::Dashed, $sgr->underlineStyle);
        $sgr = $this->apply([0], $sgr);
        $this->assertFalse($sgr->underline);
        $this->assertSame(UnderlineStyle::None, $sgr->underlineStyle);
    }

    public function testUnknownSubparamFallsBackToSingle(): void
    {
        // Unknown subparam (e.g. 4:6) → treat as single underline
        $sgr = $this->apply([4, 99]);
        $this->assertTrue($sgr->underline);
        $this->assertSame(UnderlineStyle::Single, $sgr->underlineStyle);
    }

    public function testStyleSurvivesBoldToggle(): void
    {
        // Setting underline style, then toggling bold — style preserved
        $sgr = $this->apply([4, 2]); // double underline
        $this->assertSame(UnderlineStyle::Double, $sgr->underlineStyle);
        $sgr = $this->apply([1], $sgr); // bold on
        $this->assertSame(UnderlineStyle::Double, $sgr->underlineStyle);
        $this->assertTrue($sgr->bold);
    }

    public function testDefaultParamTreatedAsZero(): void
    {
        // CSI -1 treated as 0 (reset) per SGR semantics
        $sgr = $this->apply([-1], Sgr::empty()->withUnderlineStyle(UnderlineStyle::Double));
        $this->assertFalse($sgr->underline);
        $this->assertSame(UnderlineStyle::None, $sgr->underlineStyle);
    }

    public function testColonsSubparamForm(): void
    {
        // CSI 4:2 (colon separator) produces same result as 4;2 (semicolon)
        $colon = $this->apply([4, 2]);
        $sgr = Sgr::empty();
        $colonViaHandler = (new SgrHandler())->apply([4, 2], $sgr);
        $this->assertTrue($colonViaHandler->underline);
        $this->assertSame(UnderlineStyle::Double, $colonViaHandler->underlineStyle);
    }

    public function testWithUnderlineStyleMethod(): void
    {
        $sgr = Sgr::empty()->withUnderlineStyle(UnderlineStyle::Dashed);
        $this->assertTrue($sgr->underline);
        $this->assertSame(UnderlineStyle::Dashed, $sgr->underlineStyle);
    }

    public function testUnderlineStyleEnumValues(): void
    {
        $this->assertSame(0, UnderlineStyle::None->value);
        $this->assertSame(1, UnderlineStyle::Single->value);
        $this->assertSame(2, UnderlineStyle::Double->value);
        $this->assertSame(3, UnderlineStyle::Curly->value);
        $this->assertSame(4, UnderlineStyle::Dotted->value);
        $this->assertSame(5, UnderlineStyle::Dashed->value);
    }

    public function testSgrEqualsConsidersUnderlineStyle(): void
    {
        $a = Sgr::empty()->withUnderlineStyle(UnderlineStyle::Double);
        $b = Sgr::empty()->withUnderlineStyle(UnderlineStyle::Double);
        $c = Sgr::empty()->withUnderlineStyle(UnderlineStyle::Single);
        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function testRoundTripThroughHandler(): void
    {
        // Double underline → 24 (off) → 4:2 (double again)
        $sgr = $this->apply([4, 2]);
        $this->assertSame(UnderlineStyle::Double, $sgr->underlineStyle);
        $sgr = $this->apply([24], $sgr);
        $this->assertSame(UnderlineStyle::None, $sgr->underlineStyle);
        $sgr = $this->apply([4, 2], $sgr);
        $this->assertSame(UnderlineStyle::Double, $sgr->underlineStyle);
    }

    public function testSgrUnderlineBooleanTracksUnderlineStyle(): void
    {
        // The underline boolean should be true whenever style is not None
        $none = Sgr::empty()->withUnderlineStyle(UnderlineStyle::None);
        $this->assertFalse($none->underline);

        $single = Sgr::empty()->withUnderlineStyle(UnderlineStyle::Single);
        $this->assertTrue($single->underline);

        $double = Sgr::empty()->withUnderlineStyle(UnderlineStyle::Double);
        $this->assertTrue($double->underline);
    }
}
