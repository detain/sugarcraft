<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Tests;

use SugarCraft\AnsiParser\Parser\Action;
use SugarCraft\AnsiParser\Parser\State;
use SugarCraft\AnsiParser\Parser\Transitions;
use PHPUnit\Framework\TestCase;

final class TransitionsTest extends TestCase
{
    public function testLookupFailsFastOnOutOfRangeByte(): void
    {
        // sap-08 (finding 012): fail-fast replaces the undefined-offset
        // warning route and the wrong-row silent read.
        foreach ([-1, 0x100, PHP_INT_MIN] as $byte) {
            try {
                Transitions::lookup(State::Ground->value, $byte);
                $this->fail('byte ' . $byte . ' must throw');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testLookupFailsFastOnOutOfRangeState(): void
    {
        foreach ([-1, 16, 999] as $state) {
            try {
                Transitions::lookup($state, 0x41);
                $this->fail('state ' . $state . ' must throw');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }


    public function testGroundStatePrintableBytes(): void
    {
        $entry = Transitions::lookup(State::Ground->value, ord('A'));

        $this->assertSame(Action::Print->value, Transitions::action($entry));
        $this->assertSame(State::Ground->value, Transitions::nextState($entry));
    }

    public function testGroundStateC0ControlsExecute(): void
    {
        $entry = Transitions::lookup(State::Ground->value, 0x00);

        $this->assertSame(Action::Execute->value, Transitions::action($entry));
        $this->assertSame(State::Ground->value, Transitions::nextState($entry));
    }

    public function testGroundStateEscTransition(): void
    {
        $entry = Transitions::lookup(State::Ground->value, 0x1B);

        $this->assertSame(Action::Clear->value, Transitions::action($entry));
        $this->assertSame(State::Escape->value, Transitions::nextState($entry));
    }

    public function testGroundStateCsiEntryViaEscape(): void
    {
        $entry = Transitions::lookup(State::Escape->value, ord('['));

        $this->assertSame(Action::Clear->value, Transitions::action($entry));
        $this->assertSame(State::CsiEntry->value, Transitions::nextState($entry));
    }

    public function testGroundStateOscStringViaEscape(): void
    {
        $entry = Transitions::lookup(State::Escape->value, ord(']'));

        $this->assertSame(Action::Start->value, Transitions::action($entry));
        $this->assertSame(State::OscString->value, Transitions::nextState($entry));
    }

    public function testCsiEntryDispatchOnFinalByte(): void
    {
        $entry = Transitions::lookup(State::CsiEntry->value, ord('m'));

        $this->assertSame(Action::Dispatch->value, Transitions::action($entry));
        $this->assertSame(State::Ground->value, Transitions::nextState($entry));
    }

    public function testCsiEntryParamTransition(): void
    {
        $entry = Transitions::lookup(State::CsiEntry->value, ord('3'));

        $this->assertSame(Action::Param->value, Transitions::action($entry));
        $this->assertSame(State::CsiParam->value, Transitions::nextState($entry));
    }

    public function testCsiEntryPrivateMarkerPrefix(): void
    {
        $entry = Transitions::lookup(State::CsiEntry->value, ord('?'));

        $this->assertSame(Action::Prefix->value, Transitions::action($entry));
        $this->assertSame(State::CsiParam->value, Transitions::nextState($entry));
    }

    public function testCsiEntryIntermediateCollect(): void
    {
        $entry = Transitions::lookup(State::CsiEntry->value, ord(' '));

        $this->assertSame(Action::Collect->value, Transitions::action($entry));
        $this->assertSame(State::CsiIntermediate->value, Transitions::nextState($entry));
    }

    public function testCsiParamDispatchOnFinalByte(): void
    {
        $entry = Transitions::lookup(State::CsiParam->value, ord('m'));

        $this->assertSame(Action::Dispatch->value, Transitions::action($entry));
        $this->assertSame(State::Ground->value, Transitions::nextState($entry));
    }

    public function testCsiParamCollectOnIntermediate(): void
    {
        $entry = Transitions::lookup(State::CsiParam->value, ord(' '));

        $this->assertSame(Action::Collect->value, Transitions::action($entry));
        $this->assertSame(State::CsiIntermediate->value, Transitions::nextState($entry));
    }

    public function testCsiIntermediateDispatchOnFinalByte(): void
    {
        $entry = Transitions::lookup(State::CsiIntermediate->value, ord('m'));

        $this->assertSame(Action::Dispatch->value, Transitions::action($entry));
        $this->assertSame(State::Ground->value, Transitions::nextState($entry));
    }

    public function testCsiIntermediateDigitBandDropsSequenceToGround(): void
    {
        // G1 documented arm (finding 003): the row is charmbracelet-identical
        // BY DESIGN — a digit or private byte after intermediates drops the
        // whole CSI to Ground with no dispatch, and the tail bytes then echo
        // as printable text (pinned behaviorally in ParserTest). The
        // Paul-Williams figure's csi_ignore swallows until the final instead;
        // the README's machine section discloses that divergence rather than
        // pretending parity with the figure.
        $entry = Transitions::lookup(State::CsiIntermediate->value, ord('3'));

        $this->assertSame(Action::None->value, Transitions::action($entry));
        $this->assertSame(State::Ground->value, Transitions::nextState($entry));
    }

    public function testDcsIntermediateDigitBandStaysInState(): void
    {
        // sap-04 (finding 014): 0x30-0x3F in DcsIntermediate is ignored
        // IN-STATE. The former Start -> DcsString row forged the
        // non-final byte into the DCS command position.
        foreach ([ord('0'), ord('9'), ord('<'), ord('='), ord('>'), ord('?')] as $byte) {
            $entry = Transitions::lookup(State::DcsIntermediate->value, $byte);
            $this->assertSame(Action::None->value, Transitions::action($entry), 'byte 0x' . dechex($byte));
            $this->assertSame(
                State::DcsIntermediate->value,
                Transitions::nextState($entry),
                'byte 0x' . dechex($byte) . ' must not leave DcsIntermediate',
            );
        }

        // And the legal final-command row (0x40-0x7E) still opens the
        // string — it must never follow the digit band's lead.
        $entry = Transitions::lookup(State::DcsIntermediate->value, ord('q'));
        $this->assertSame(Action::Start->value, Transitions::action($entry));
        $this->assertSame(State::DcsString->value, Transitions::nextState($entry));
    }

    public function testOscStringPutForPrintable(): void
    {
        $entry = Transitions::lookup(State::OscString->value, ord('A'));

        $this->assertSame(Action::Put->value, Transitions::action($entry));
        $this->assertSame(State::OscString->value, Transitions::nextState($entry));
    }

    public function testOscStringDispatchOnBel(): void
    {
        $entry = Transitions::lookup(State::OscString->value, 0x07);

        $this->assertSame(Action::Dispatch->value, Transitions::action($entry));
        $this->assertSame(State::Ground->value, Transitions::nextState($entry));
    }

    public function testOscStringDispatchOnEscape(): void
    {
        $entry = Transitions::lookup(State::OscString->value, 0x1B);

        $this->assertSame(Action::Dispatch->value, Transitions::action($entry));
        $this->assertSame(State::Escape->value, Transitions::nextState($entry));
    }

    public function testOscStringIgnoreControlChars(): void
    {
        $entry = Transitions::lookup(State::OscString->value, 0x00);

        $this->assertSame(Action::None->value, Transitions::action($entry));
        $this->assertSame(State::OscString->value, Transitions::nextState($entry));
    }

    public function testEscapeIntermediateDispatch(): void
    {
        $entry = Transitions::lookup(State::EscapeIntermediate->value, ord('D'));

        $this->assertSame(Action::Dispatch->value, Transitions::action($entry));
        $this->assertSame(State::Ground->value, Transitions::nextState($entry));
    }

    public function testEscapeDispatch(): void
    {
        $entry = Transitions::lookup(State::Escape->value, ord('D'));

        $this->assertSame(Action::Dispatch->value, Transitions::action($entry));
        $this->assertSame(State::Ground->value, Transitions::nextState($entry));
    }

    public function testDcsEntryStart(): void
    {
        $entry = Transitions::lookup(State::DcsEntry->value, ord('@'));

        $this->assertSame(Action::Start->value, Transitions::action($entry));
        $this->assertSame(State::DcsString->value, Transitions::nextState($entry));
    }

    public function testDcsEntryCollectIntermediate(): void
    {
        $entry = Transitions::lookup(State::DcsEntry->value, ord(' '));

        $this->assertSame(Action::Collect->value, Transitions::action($entry));
        $this->assertSame(State::DcsIntermediate->value, Transitions::nextState($entry));
    }

    public function testDcsEntryPrefixPrivate(): void
    {
        $entry = Transitions::lookup(State::DcsEntry->value, ord('<'));

        $this->assertSame(Action::Prefix->value, Transitions::action($entry));
        $this->assertSame(State::DcsParam->value, Transitions::nextState($entry));
    }

    public function testDcsEntryParamByte(): void
    {
        $entry = Transitions::lookup(State::DcsEntry->value, ord('3'));

        $this->assertSame(Action::Param->value, Transitions::action($entry));
        $this->assertSame(State::DcsParam->value, Transitions::nextState($entry));
    }

    public function testUtf8LeadByte(): void
    {
        $entry = Transitions::lookup(State::Ground->value, 0xC3);

        $this->assertSame(Action::Collect->value, Transitions::action($entry));
        $this->assertSame(State::Utf8->value, Transitions::nextState($entry));
    }

    /**
     * Pins the BUILT Utf8 row, not machine behavior: Parser::advance()
     * intercepts the Utf8 state before any table read (see the honesty note
     * at Transitions.php's anywhere-section header), so this row is padding
     * completeness of the table, never a consulted transition. Behavioral
     * UTF-8 coverage lives in tests/Utf8PolicyTest.php. A future row cleanup
     * may move this test; it must not go red for no behavioral reason.
     */
    public function testUtf8RowIsTablePaddingForContinuationBytes(): void
    {
        $entry = Transitions::lookup(State::Utf8->value, 0xA9);

        $this->assertSame(Action::None->value, Transitions::action($entry));
        $this->assertSame(State::Ground->value, Transitions::nextState($entry));
    }

    /**
     * Table-padding pin only — see {@see self::testUtf8RowIsTablePaddingForContinuationBytes()}
     * for why no byte is ever consulted through the Utf8 row, and
     * tests/Utf8PolicyTest.php for the real behavioral home.
     */
    public function testUtf8RowIsTablePaddingForNonContinuationBytes(): void
    {
        $entry = Transitions::lookup(State::Utf8->value, ord('A'));

        $this->assertSame(Action::None->value, Transitions::action($entry));
        $this->assertSame(State::Ground->value, Transitions::nextState($entry));
    }

    public function testAnywhereC1CsiEntry9B(): void
    {
        $entry = Transitions::lookup(State::Ground->value, 0x9B);

        $this->assertSame(Action::Clear->value, Transitions::action($entry));
        $this->assertSame(State::CsiEntry->value, Transitions::nextState($entry));
    }

    public function testAnywhereC1DcsEntry90(): void
    {
        $entry = Transitions::lookup(State::Ground->value, 0x90);

        $this->assertSame(Action::Clear->value, Transitions::action($entry));
        $this->assertSame(State::DcsEntry->value, Transitions::nextState($entry));
    }

    public function testAnywhereC1OscString9D(): void
    {
        $entry = Transitions::lookup(State::Ground->value, 0x9D);

        $this->assertSame(Action::Start->value, Transitions::action($entry));
        $this->assertSame(State::OscString->value, Transitions::nextState($entry));
    }

    public function testAnywhereC1SosString98(): void
    {
        $entry = Transitions::lookup(State::Ground->value, 0x98);

        $this->assertSame(Action::Start->value, Transitions::action($entry));
        $this->assertSame(State::SosString->value, Transitions::nextState($entry));
    }

    public function testAnywhereC1PmString9E(): void
    {
        $entry = Transitions::lookup(State::Ground->value, 0x9E);

        $this->assertSame(Action::Start->value, Transitions::action($entry));
        $this->assertSame(State::PmString->value, Transitions::nextState($entry));
    }

    public function testAnywhereC1ApcString9F(): void
    {
        $entry = Transitions::lookup(State::Ground->value, 0x9F);

        $this->assertSame(Action::Start->value, Transitions::action($entry));
        $this->assertSame(State::ApcString->value, Transitions::nextState($entry));
    }

    public function testAnywhereExecuteBytes(): void
    {
        foreach ([0x18, 0x1A, 0x99, 0x9A, 0x9C] as $byte) {
            $entry = Transitions::lookup(State::Ground->value, $byte);

            $this->assertSame(Action::Execute->value, Transitions::action($entry), sprintf('Byte 0x%02X should execute', $byte));
            $this->assertSame(State::Ground->value, Transitions::nextState($entry));
        }
    }

    public function testAnywhereExecuteRange80To8F(): void
    {
        $entry = Transitions::lookup(State::Ground->value, 0x80);

        $this->assertSame(Action::Execute->value, Transitions::action($entry));
        $this->assertSame(State::Ground->value, Transitions::nextState($entry));
    }

    public function testAnywhereDcsEntryFor0x90(): void
    {
        // 0x90 is DCS entry (handled at :87), not Execute
        $entry = Transitions::lookup(State::Ground->value, 0x90);

        $this->assertSame(Action::Clear->value, Transitions::action($entry));
        $this->assertSame(State::DcsEntry->value, Transitions::nextState($entry));
    }

    public function testAnywhereExecute0x91To0x97(): void
    {
        // 0x91-0x97 are Execute/Ground (0x90 was removed from this range)
        foreach (range(0x91, 0x97) as $byte) {
            $entry = Transitions::lookup(State::Ground->value, $byte);
            $this->assertSame(Action::Execute->value, Transitions::action($entry), sprintf('Byte 0x%02X should be Execute', $byte));
            $this->assertSame(State::Ground->value, Transitions::nextState($entry), sprintf('Byte 0x%02X should transition to Ground', $byte));
        }
    }

    public function testSosStringState(): void
    {
        $entry = Transitions::lookup(State::SosString->value, ord('A'));

        $this->assertSame(Action::Put->value, Transitions::action($entry));
        $this->assertSame(State::SosString->value, Transitions::nextState($entry));
    }

    public function testSosStringDispatchOnEscape(): void
    {
        $entry = Transitions::lookup(State::SosString->value, 0x1B);

        $this->assertSame(Action::Dispatch->value, Transitions::action($entry));
        $this->assertSame(State::Escape->value, Transitions::nextState($entry));
    }

    public function testPmStringState(): void
    {
        $entry = Transitions::lookup(State::PmString->value, ord('A'));

        $this->assertSame(Action::Put->value, Transitions::action($entry));
        $this->assertSame(State::PmString->value, Transitions::nextState($entry));
    }

    public function testApcStringState(): void
    {
        $entry = Transitions::lookup(State::ApcString->value, ord('A'));

        $this->assertSame(Action::Put->value, Transitions::action($entry));
        $this->assertSame(State::ApcString->value, Transitions::nextState($entry));
    }

    public function testDcsStringPassthrough(): void
    {
        $entry = Transitions::lookup(State::DcsString->value, ord('A'));

        $this->assertSame(Action::Put->value, Transitions::action($entry));
        $this->assertSame(State::DcsString->value, Transitions::nextState($entry));
    }

    public function testDcsStringDispatchOnEscape(): void
    {
        $entry = Transitions::lookup(State::DcsString->value, 0x1B);

        $this->assertSame(Action::Dispatch->value, Transitions::action($entry));
        $this->assertSame(State::Escape->value, Transitions::nextState($entry));
    }

    public function testGroundStateDeleteExecutes(): void
    {
        $entry = Transitions::lookup(State::Ground->value, 0x7F);

        $this->assertSame(Action::Execute->value, Transitions::action($entry));
        $this->assertSame(State::Ground->value, Transitions::nextState($entry));
    }

    public function testAllUtf8LeadByteRanges(): void
    {
        $twoByte = Transitions::lookup(State::Ground->value, 0xC2);
        $this->assertSame(State::Utf8->value, Transitions::nextState($twoByte));

        $threeByte = Transitions::lookup(State::Ground->value, 0xE0);
        $this->assertSame(State::Utf8->value, Transitions::nextState($threeByte));

        $fourByte = Transitions::lookup(State::Ground->value, 0xF0);
        $this->assertSame(State::Utf8->value, Transitions::nextState($fourByte));

        $fourByteUpper = Transitions::lookup(State::Ground->value, 0xF4);
        $this->assertSame(State::Utf8->value, Transitions::nextState($fourByteUpper));
    }

    /**
     * sap-02 pin: the sweep terminator sets must match the shipped rows
     * EXACTLY per string state (a missed byte would let the bulk append
     * swallow a dispatch edge; an extra byte only costs one advance()).
     * Derived expected sets, stated as ord-lists so the whole 256-byte
     * space is asserted, not spot-checks:
     * - OscString: everything below 0x20 (C0 none-band + BEL/CAN/SUB/ESC)
     *   plus 0x9C (ST dispatches; the rest of 0x80-0xFF is Put payload).
     * - DcsString: only the four cancel/terminator edges (the whole rest of
     *   the byte space is Put passthrough).
     * - SOS/PM/APC: those four plus the five C1 introducer Start edges
     *   (0x98/0x9D/0x9E/0x9F — same-type re-introduction stays deliberately
     *   byte-at-a-time so start()'s cross-type discard keeps its exact seat).
     */
    public function testSweepTerminatorSetsMatchTheShippedRows(): void
    {
        $ords = static fn (string $set): array => array_map('ord', str_split($set));

        $expectedOsc = array_merge(range(0x00, 0x1F), [0x9C]);
        $this->assertSame($expectedOsc, $ords(Transitions::terminators(State::OscString->value)));

        $expectedDcs = [0x18, 0x1A, 0x1B, 0x9C];
        $this->assertSame($expectedDcs, $ords(Transitions::terminators(State::DcsString->value)));

        $expectedSosPmApc = [0x18, 0x1A, 0x1B, 0x98, 0x9C, 0x9D, 0x9E, 0x9F];
        foreach ([State::SosString, State::PmString, State::ApcString] as $state) {
            $this->assertSame(
                $expectedSosPmApc,
                $ords(Transitions::terminators($state->value)),
                "terminator set drifted for {$state->name}",
            );
        }
    }

    public function testSweepTerminatorsRejectNonStringStates(): void
    {
        // Fail-fast: an empty/garbage set would mean an unbounded bulk append
        // past the sequence boundary, so only the five string states answer.
        $this->expectException(\InvalidArgumentException::class);
        Transitions::terminators(State::Ground->value);
    }
}
