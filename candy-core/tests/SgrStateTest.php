<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use SugarCraft\Core\SgrState;
use SugarCraft\Core\Util\Parser;
use SugarCraft\Core\Util\Token;
use PHPUnit\Framework\TestCase;

final class SgrStateTest extends TestCase
{
    public function testInitialStateEmitsNothing(): void
    {
        $this->assertSame('', SgrState::initial()->toPrefix());
    }

    public function testApplyBoldRedPrefix(): void
    {
        $s = SgrState::initial();
        foreach ((new Parser())->parse("\x1b[1;31m") as $t) {
            $s->applyCsi($t);
        }
        $prefix = $s->toPrefix();
        $this->assertStringContainsString("\x1b[", $prefix);
        $this->assertStringContainsString('1', $prefix);
        $this->assertStringContainsString("\x1b[31m", $prefix);
    }

    public function testResetClearsState(): void
    {
        $s = SgrState::initial();
        $tokens = (new Parser())->parse("\x1b[1;31m\x1b[0m");
        foreach ($tokens as $t) {
            $s->applyCsi($t);
        }
        $this->assertTrue($s->isDefault());
    }

    public function testBackgroundColour(): void
    {
        $s = SgrState::initial();
        foreach ((new Parser())->parse("\x1b[44m") as $t) {
            $s->applyCsi($t);
        }
        $this->assertStringContainsString("\x1b[44m", $s->toPrefix());
    }

    public function testTrueColourFg(): void
    {
        $s = SgrState::initial();
        foreach ((new Parser())->parse("\x1b[38;2;255;128;0m") as $t) {
            $s->applyCsi($t);
        }
        $this->assertStringContainsString("\x1b[38;2;255;128;0m", $s->toPrefix());
    }

    public function testCsi256Fg(): void
    {
        $s = SgrState::initial();
        foreach ((new Parser())->parse("\x1b[38;5;42m") as $t) {
            $s->applyCsi($t);
        }
        $this->assertStringContainsString("\x1b[38;5;42m", $s->toPrefix());
    }

    public function testNonSgrTokenIgnored(): void
    {
        $s = SgrState::initial();
        $tokens = (new Parser())->parse("\x1b[5A"); // cursor up — final 'A', not 'm'
        foreach ($tokens as $t) {
            $s->applyCsi($t);
        }
        $this->assertTrue($s->isDefault());
    }

    // ------------------------------------------------------- E50: SGR 58 + OSC 8

    public function testSgr58IsTrackedButDoesNotEnterThePrefix(): void
    {
        $s = SgrState::initial();
        foreach ((new Parser())->parse("\x1b[58;5;42m") as $t) {
            $s->applyCsi($t);
        }
        $this->assertSame("\x1b[58;5;42m", $s->underlineColor());
        $this->assertSame('', $s->toPrefix(), 'additive round: prefix bytes must not change');
        $this->assertTrue($s->isDefault());
    }

    public function testSgr58TrueColorAnd59Reset(): void
    {
        $s = SgrState::initial();
        foreach ((new Parser())->parse("\x1b[58;2;1;2;3m") as $t) {
            $s->applyCsi($t);
        }
        $this->assertSame("\x1b[58;2;1;2;3m", $s->underlineColor());
        foreach ((new Parser())->parse("\x1b[59m") as $t) {
            $s->applyCsi($t);
        }
        $this->assertSame('', $s->underlineColor());
    }

    public function testResetEndsUnderlineColourAndOpenLink(): void
    {
        $s = SgrState::initial();
        foreach ((new Parser())->parse("\x1b[58;5;3m\x1b]8;;https://example.org\x1b\\") as $t) {
            $s->apply($t);
        }
        $this->assertTrue($s->hasOpenLink());
        foreach ((new Parser())->parse("\x1b[0m") as $t) {
            $s->applyCsi($t);
        }
        $this->assertSame('', $s->underlineColor());
        $this->assertSame('', $s->linkUri());
        $this->assertFalse($s->hasOpenLink());
    }

    public function testApplyRoutesByTokenKindAcrossAMixedStream(): void
    {
        $s = SgrState::initial();
        $stream = "\x1b[1m\x1b]8;id=ref1:other=2;https://a.test/p;q\x1b\\\x1b[58;5;9m";
        foreach ((new Parser())->parse($stream) as $t) {
            $s->apply($t);
        }
        $this->assertSame('https://a.test/p;q', $s->linkUri(), 'limit-3 parse keeps `;` inside the URI');
        $this->assertSame('ref1', $s->linkId());
        $this->assertSame("\x1b[58;5;9m", $s->underlineColor());
        $this->assertStringContainsString("\x1b[0;1m", $s->toPrefix()); // bold — the only prefix contributor
        $this->assertStringNotContainsString('58', $s->toPrefix());
    }

    public function testEmptyUriClosesTheLinkAndForeignOscIsIgnored(): void
    {
        $s = SgrState::initial();
        $s->apply(new Token(Token::OSC, '8;https://solo.test'));  // params field omitted
        $this->assertSame('https://solo.test', $s->linkUri());
        $this->assertSame('', $s->linkId());
        $s->apply(new Token(Token::OSC, '10;rgb:ffff/0000/0000')); // colour query
        $this->assertSame('https://solo.test', $s->linkUri(), 'non-8 OSC must not disturb the link');
        $s->apply(new Token(Token::OSC, '8;;'));                    // canonical close
        $this->assertFalse($s->hasOpenLink());
    }

    // ------------------------------------------------------------------
    // E667: the row-boundary contract (rowOpen / rowClose)
    // ------------------------------------------------------------------

    public function testRowBoundariesOfDefaultStateAreEmpty(): void
    {
        $s = SgrState::initial();
        foreach ((new Parser())->parse("\x1b[31m\x1b[0m") as $t) {
            $s->apply($t);
        }
        $this->assertSame('', $s->rowOpen());
        $this->assertSame('', $s->rowClose());
    }

    public function testRowOpenCarriesPrefixAndReOpensTheLinkWithoutId(): void
    {
        $s = SgrState::initial();
        foreach ((new Parser())->parse("\x1b[1m") as $t) {
            $s->apply($t);
        }
        $s->apply(new Token(Token::OSC, '8;id=2;https://example.com/x'));
        // The id is deliberately NOT re-emitted — the byte contract of the
        // delegating call site (sugar-crush balanceSgr) predates id tracking.
        $this->assertSame("\x1b[0;1m\x1b]8;;https://example.com/x\x1b\\", $s->rowOpen());
    }

    public function testRowCloseResetsStyleAndEndsTheLink(): void
    {
        $s = SgrState::initial();
        foreach ((new Parser())->parse("\x1b[1m") as $t) {
            $s->apply($t);
        }
        $s->apply(new Token(Token::OSC, '8;;https://example.com'));
        $this->assertSame("\x1b[0m\x1b]8;;\x1b\\", $s->rowClose());
    }

    public function testRowBoundaryBytesRoundTripASplitLinkRow(): void
    {
        // A link whose label spans two rows: row 1 opens it, row 2 closes it.
        // Feeding row 1 through the state must make row 2's re-open carry the
        // URI, so the repaintable-alone row is self-contained — and the close
        // must land on any row that ENDS inside the link.
        $row1 = "\x1b]8;;https://example.com/x\x1b\\long ";
        $row2 = "label\x1b]8;;\x1b\\";

        $s = SgrState::initial();
        foreach ((new Parser())->parse($row1) as $t) {
            $s->apply($t);
        }
        $closed1 = $s->rowClose();
        $prefix2 = $s->rowOpen();
        foreach ((new Parser())->parse($row2) as $t) {
            $s->apply($t);
        }

        $this->assertSame("\x1b]8;;\x1b\\", $closed1, 'an unterminated link must be closed at the row end');
        $this->assertSame("\x1b]8;;https://example.com/x\x1b\\", $prefix2, 'the next row must re-open it');
        $this->assertSame('', $s->rowClose(), 'a row that closes its own link leaves nothing');
    }

    public function testOsc8FormatsOpenAndClose(): void
    {
        $this->assertSame("\x1b]8;;https://example.com\x1b\\", SgrState::osc8('https://example.com'));
        $this->assertSame("\x1b]8;;\x1b\\", SgrState::osc8(''));
    }
}
