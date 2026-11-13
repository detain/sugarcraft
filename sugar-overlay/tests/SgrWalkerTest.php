<?php

declare(strict_types=1);

/*
 * SO-06 (sugar-overlay-004/005/063/071) — the ONE SGR parser.
 *
 * The pen merge and the splice neutralizer used to be two hand-rolled
 * explode(';') walks that drifted on the same rules. These pins hold the
 * rules ONCE (colon sub-parameters, semicolon operand arity, the no-op
 * shape of malformed extended legs) and then hold the two consumers in
 * lockstep: a corpus runs through BOTH penFromSgr and isolation, and the
 * verdicts must agree byte-for-byte.
 */

namespace SugarCraft\Overlay\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\CellBuffer\Style;
use SugarCraft\Overlay\MarkedFrame;
use SugarCraft\Overlay\SgrWalker;
use SugarCraft\Overlay\Veil;

final class SgrWalkerTest extends TestCase
{
    /** @param array{fg:?int,bg:?int,attrs:int} $pen */
    private static function fold(string $params, ?array $pen = null): array
    {
        return SgrWalker::fold($params, $pen ?? ['fg' => null, 'bg' => null, 'attrs' => 0]);
    }

    public function testColonFormKeepsBothTheColorAndTheTrailingBold(): void
    {
        // sugar-overlay-005's primary: the old else-arm's `$i = $count`
        // dropped the bold, and the color along with it.
        $pen = self::fold('38:2:1:2:3;1');
        $this->assertSame(0x010203, $pen['fg']);
        $this->assertSame(Style::ATTR_BOLD, $pen['attrs']);
    }

    public function testColonColorSpaceFieldIsSkippedForBothEmptyAndNamedId(): void
    {
        // T.416: 38:2:<cs>:r:g:b. The empty-cs spelling is the common one.
        $this->assertSame(0x010203, self::fold('38:2::1:2:3')['fg']);
        $this->assertSame(0x020304, self::fold('48:2:1:2:3:4')['bg']);
        $this->assertSame(SgrWalker::xterm256ToHex(42), self::fold('38:5::42')['fg'], 'an empty cs field before a palette index is skipped');
    }

    public function testSemicolonPaletteGathersExactlyItsOperandAndLeavesTheRestLive(): void
    {
        // "38;5;n" is a TWO-leg sequence: the old walk's blind `$i += 4`
        // shape (and an over-gathering new one alike) must not eat ";7".
        $pen = self::fold('38;5;42;7');
        $this->assertSame(SgrWalker::xterm256ToHex(42), $pen['fg']);
        $this->assertSame(Style::ATTR_REVERSE, $pen['attrs']);
    }

    public function testSemicolonTruecolorGathersThreeChannelsAndLeavesTheRestLive(): void
    {
        $pen = self::fold('38;2;10;20;30;1');
        $this->assertSame(0x0A141E, $pen['fg']);
        $this->assertSame(Style::ATTR_BOLD, $pen['attrs']);
    }

    public function testUnknownExtendedModeIsANoOpAndDoesNotSwallowFollowingLegs(): void
    {
        // "38;7;1": mode 7 is no colour space — the leg no-ops, and 1 lands
        // as bold. (The old pen walk's `$i = $count` dropped the bold too;
        // "keep scanning" is the rule one parser can promise both callers.)
        $pen = self::fold('38;7;1');
        $this->assertNull($pen['fg']);
        $this->assertSame(Style::ATTR_BOLD, $pen['attrs']);
    }

    public function testBareTrailing38KeepsThePriorPen(): void
    {
        // sugar-overlay-071: an incomplete extended leg changes NOTHING.
        $carried = ['fg' => 0x112233, 'bg' => 0x445566, 'attrs' => Style::ATTR_BOLD];
        $this->assertSame($carried, self::fold('38', $carried));
        $this->assertSame($carried, self::fold('48', $carried));
        $this->assertSame($carried, self::fold('38;2;1;2', $carried), 'four operands needed, three given');
    }

    public function testEmptyParameterStringIsTheResetItAlwaysWas(): void
    {
        // ECMA-48 equates \e[m with SGR 0 — both old walkers reached that
        // via intval('') = 0; the fold keeps it explicitly.
        $pen = self::fold('', ['fg' => 0x010203, 'bg' => 0x040506, 'attrs' => 7]);
        $this->assertSame(['fg' => null, 'bg' => null, 'attrs' => 0], $pen);
    }

    public function testChannelsSaturateNeverWrap(): void
    {
        $this->assertSame(0xFF0007, self::fold('38;2;300;-5;7')['fg']);
        $this->assertSame(SgrWalker::xterm256ToHex(255), SgrWalker::xterm256ToHex(999), 'palette indices clamp at 255');
    }

    // ─── the two consumers must never disagree ───────────────────────────────

    public function testIsolationResetsExactlyWhenThePenEndsOnANonDefaultColor(): void
    {
        // The differential acceptance pin (sugar-overlay-004): one SGR
        // stream through BOTH consumers — the compositor's pen merge and
        // the splice neutralizer — verdicts byte-identical.
        $corpus = [
            '', '0', '1', '31', '90', '39', '49',
            '38;5;42', '38;2;1;2;3', '48;5;9;1', '38:2:1:2:3;1', '38:2::1:2:3',
            '48:2:1:2:3:4', '38;7;1', '38', '48', '38;2;1;2', '38;5;42;39;7',
            '31;39', '31;;1', '38;2;300;-5;7;1', '1;38:5:8', '38:5:',
        ];

        $penFromSgr = new ReflectionMethod(Veil::class, 'penFromSgr');

        foreach ($corpus as $params) {
            $style = $penFromSgr->invoke(null, $params, null);
            $armed = $style !== null && ($style->fg() !== null || $style->bg() !== null);

            $emitted = MarkedFrame::isolation("\e[{$params}m" . 'x');
            $this->assertSame(
                $armed ? "\e[39m\e[49m" : '',
                $emitted,
                sprintf('isolation of \\e[%sm must agree with the pen verdict', $params)
            );
        }
    }

    public function testNoPhantomResetForAColorThePenNeverArmed(): void
    {
        // sugar-overlay-071's line-ending case, named at the consumer: a
        // row that just drops `\e[38m` at the end must NOT gain a 39/49.
        $this->assertSame('', MarkedFrame::isolation("plain \e[38m"));
        $this->assertSame('', MarkedFrame::isolation("plain \e[48m"));
        $this->assertSame('', MarkedFrame::isolation("plain \e[38;9m"), 'unknown mode is no-op too');
    }

    public function testAttributesDeliberatelyBleedAcrossSeams(): void
    {
        // sugar-overlay-063, the documented rule: the neutralizer resets
        // COLOUR only. Bold/underline/reverse ride across the splice seam —
        // widening it to SGR 0 would clobber link pens and chrome alike.
        $neutralizer = MarkedFrame::isolation("\e[1;4;7;31mX");
        $this->assertSame("\e[39m\e[49m", $neutralizer);
        foreach ([22, 23, 24, 27, 0] as $attrOff) {
            $this->assertStringNotContainsString("\e[{$attrOff}m", $neutralizer);
        }
    }
}
