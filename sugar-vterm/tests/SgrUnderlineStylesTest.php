<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vterm\Handler\SgrHandler;
use SugarCraft\Vterm\Sgr\Sgr;
use SugarCraft\Vterm\Sgr\UnderlineStyle;

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

    /**
     * Colon form `4:N`: the style rides as a SUB-PARAMETER of 4, which only
     * the parser's continuation flags can express. Null flags now mean the
     * unambiguous xterm semicolon reading (every slot independent), so the
     * style table is pinned with explicit flags — as the wire delivers them.
     *
     * @param list<int> $params
     */
    private function applyColon(array $params, ?Sgr $start = null): Sgr
    {
        $flags = array_fill(0, count($params), false);
        $flags[0] = true;

        return (new SgrHandler())->apply($params, $start ?? Sgr::empty(), $flags);
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
        $sgr = $this->applyColon([4, 0]);
        $this->assertFalse($sgr->underline);
        $this->assertSame(UnderlineStyle::None, $sgr->underlineStyle);
    }

    public function testUnderlineStyleSingle(): void
    {
        // CSI 4:1 → single underline
        $sgr = $this->applyColon([4, 1]);
        $this->assertTrue($sgr->underline);
        $this->assertSame(UnderlineStyle::Single, $sgr->underlineStyle);
        // The `1` is a colon sub-parameter of `4:1`, not a standalone SGR —
        // consuming it here is what keeps `4:1` from replaying as bold+xterm
        // ctlseqs lists single-underline among the underline styles.
        $this->assertFalse($sgr->bold);
    }

    public function testUnderlineStyleDouble(): void
    {
        // CSI 4:2 → double underline
        $sgr = $this->applyColon([4, 2]);
        $this->assertTrue($sgr->underline);
        $this->assertSame(UnderlineStyle::Double, $sgr->underlineStyle);
    }

    public function testUnderlineStyleCurly(): void
    {
        // CSI 4:3 → curly underline
        $sgr = $this->applyColon([4, 3]);
        $this->assertTrue($sgr->underline);
        $this->assertSame(UnderlineStyle::Curly, $sgr->underlineStyle);
    }

    public function testUnderlineStyleDotted(): void
    {
        // CSI 4:4 → dotted underline
        $sgr = $this->applyColon([4, 4]);
        $this->assertTrue($sgr->underline);
        $this->assertSame(UnderlineStyle::Dotted, $sgr->underlineStyle);
    }

    public function testUnderlineStyleDashed(): void
    {
        // CSI 4:5 → dashed underline
        $sgr = $this->applyColon([4, 5]);
        $this->assertTrue($sgr->underline);
        $this->assertSame(UnderlineStyle::Dashed, $sgr->underlineStyle);
    }

    public function testCsi24ClearsUnderlineStyle(): void
    {
        // CSI 24 → underline off (any style resets to none)
        $sgr = $this->applyColon([4, 2]); // start with double
        $this->assertSame(UnderlineStyle::Double, $sgr->underlineStyle);
        $sgr = $this->apply([24], $sgr);
        $this->assertFalse($sgr->underline);
        $this->assertSame(UnderlineStyle::None, $sgr->underlineStyle);
    }

    public function testCsi0ResetsUnderlineStyle(): void
    {
        // CSI 0 → full reset
        $sgr = $this->applyColon([4, 5]); // start with dashed
        $this->assertSame(UnderlineStyle::Dashed, $sgr->underlineStyle);
        $sgr = $this->apply([0], $sgr);
        $this->assertFalse($sgr->underline);
        $this->assertSame(UnderlineStyle::None, $sgr->underlineStyle);
    }

    public function testUnknownSubparamFallsBackToSingle(): void
    {
        // Unknown subparam (e.g. 4:6) → treat as single underline
        $sgr = $this->applyColon([4, 99]);
        $this->assertTrue($sgr->underline);
        $this->assertSame(UnderlineStyle::Single, $sgr->underlineStyle);
    }

    public function testStyleSurvivesBoldToggle(): void
    {
        // Setting underline style, then toggling bold — style preserved
        $sgr = $this->applyColon([4, 2]); // double underline
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
        // `4:2` (colon, one parameter) is Double; `4;2` (semicolon, two
        // independent SGRs) is underline + dim — the readings are NOT equal,
        // and only the continuation flags tell them apart. vterm-16: the old
        // null-flags default guessed colon and turned every direct `4;N`
        // call into a style, swallowing N.
        $colon = $this->applyColon([4, 2]);
        $this->assertTrue($colon->underline);
        $this->assertSame(UnderlineStyle::Double, $colon->underlineStyle);
        $this->assertFalse($colon->dim, 'the 2 was a sub-parameter, not SGR 2');

        $semicolon = $this->apply([4, 2]);
        $this->assertTrue($semicolon->underline);
        $this->assertSame(UnderlineStyle::Single, $semicolon->underlineStyle, '4;2 = plain underline + independent 2');
        $this->assertTrue($semicolon->dim, '2 executed as its own SGR');
        $this->assertFalse($semicolon->bold);
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
        $sgr = $this->applyColon([4, 2]);
        $this->assertSame(UnderlineStyle::Double, $sgr->underlineStyle);
        $sgr = $this->apply([24], $sgr);
        $this->assertSame(UnderlineStyle::None, $sgr->underlineStyle);
        $sgr = $this->applyColon([4, 2], $sgr);
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
