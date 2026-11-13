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
        // delegating call site (candy-code balanceSgr) predates id tracking.
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

    // ---- sc-18: ITU T.416 colon sub-parameter dialect ------------------

    public function testColonDialectTrueColourMatchesSemicolonDialect(): void
    {
        $colon = SgrState::initial();
        foreach ((new Parser())->parse("\x1b[38:2:255:128:0m") as $t) {
            $colon->applyCsi($t);
        }
        $semi = SgrState::initial();
        foreach ((new Parser())->parse("\x1b[38;2;255;128;0m") as $t) {
            $semi->applyCsi($t);
        }
        // One canonical surface regardless of dialect. The pre-fix flat
        // ';' parser read the whole `38:2:255:128:0` group as a lone 38
        // (intval truncation) and then reached PAST the group boundary
        // for its mode — a trailing colour was silently dropped, and
        // when a following group existed, it was swallowed as the mode.
        $this->assertSame($semi->toPrefix(), $colon->toPrefix());
        $this->assertStringContainsString("\x1b[38;2;255;128;0m", $colon->toPrefix());
    }

    public function testColonDialectBackgroundAndUnderlineColour(): void
    {
        $s = SgrState::initial();
        foreach ((new Parser())->parse("\x1b[48:5:196m\x1b[58:2:10:20:30m") as $t) {
            $s->applyCsi($t);
        }
        $this->assertSame("\x1b[48;5;196m", $this->readField($s, 'bg'));
        $this->assertSame("\x1b[58;2;10;20;30m", $s->underlineColor());
    }

    public function testColonColourSpaceSlotIsDropped(): void
    {
        // `38:2::r:g:b` carries a colour-space ID slot (empty = the
        // common default). The state round-trips raw sequences, it does
        // not colour-manage: the slot drops and the triple survives.
        $s = SgrState::initial();
        foreach ((new Parser())->parse("\x1b[38:2::1:2:3m") as $t) {
            $s->applyCsi($t);
        }
        $this->assertStringContainsString("\x1b[38;2;1;2;3m", $s->toPrefix());
    }

    public function testDecorativeColonSubAppliesCodeWithoutResetting(): void
    {
        // `1;4:0` — bold, then underline with sub 0. Sub-parameters on
        // codes this state does not model are recognised-but-dropped:
        // exactly what the old parser did via intval truncation. The
        // critical half: a sub must NOT resurface as a separate `0`
        // group (a full reset) — the semicolon form `4;0` DOES reset,
        // the dialects are not interchangeable mid-sequence.
        $s = SgrState::initial();
        foreach ((new Parser())->parse("\x1b[1;4:0m") as $t) {
            $s->applyCsi($t);
        }
        $prefix = $s->toPrefix();
        $this->assertStringContainsString("\x1b[0;1;4m", $prefix);

        $r = SgrState::initial();
        foreach ((new Parser())->parse("\x1b[1;4;0m") as $t) {
            $r->applyCsi($t);
        }
        $this->assertTrue($r->isDefault(), 'the semicolon form keeps its reset semantics');
    }

    public function testIncompleteColonGroupSkipsWithoutSwallowingTheNext(): void
    {
        // `38:2` alone is incomplete: skip the code (pre-fix shape —
        // no consumption, no state change) and DO NOT read the following
        // group as colour data; `;1` still lands as bold.
        $s = SgrState::initial();
        foreach ((new Parser())->parse("\x1b[38:2;1m") as $t) {
            $s->applyCsi($t);
        }
        $prefix = $s->toPrefix();
        $this->assertStringContainsString("\x1b[0;1m", $prefix);
        $this->assertStringNotContainsString('38', $prefix);
    }

    public function testIsDefaultAgreesWithPrefixEmptinessEverywhere(): void
    {
        // isDefault() moved from `toPrefix() === ''` to a per-field
        // conjunction; the contract is that the two readings never
        // diverge — pinned across the states that matter (underline
        // colour and links ride the row-boundary bytes, NOT the
        // default-ness test).
        foreach (["\x1b[1m", "\x1b[58;5:9m", "\x1b]8;;u\x1b\\", "\x1b[38;5;4m\x1b[49m", ''] as $feed) {
            $s = SgrState::initial();
            foreach ((new Parser())->parse($feed) as $t) {
                $s->apply($t);
            }
            $this->assertSame($s->toPrefix() === '', $s->isDefault(), "divergence for {$feed}");
        }
        // underline-colour-only stays default (old byte contract held)
        $s = SgrState::initial();
        foreach ((new Parser())->parse("\x1b[58;5;9m") as $t) {
            $s->apply($t);
        }
        $this->assertTrue($s->isDefault());
    }

    /** Reflection peek at the private bg field for exactness. */
    private function readField(SgrState $s, string $field): string
    {
        $r = new \ReflectionProperty(SgrState::class, $field);
        $r->setAccessible(true);
        return $r->getValue($s);
    }
}
