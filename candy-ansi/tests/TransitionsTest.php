<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Tests;

use SugarCraft\Ansi\Parser\Action;
use SugarCraft\Ansi\Parser\State;
use SugarCraft\Ansi\Parser\Transitions;
use PHPUnit\Framework\TestCase;

final class TransitionsTest extends TestCase
{
    public function testGroundStatePrintableBytes(): void
    {
        $entry = Transitions::get(State::Ground->value, ord('A'));

        $this->assertSame(Action::Print->value, Transitions::action($entry));
        $this->assertSame(State::Ground->value, Transitions::nextState($entry));
    }

    public function testGroundStateC0ControlsExecute(): void
    {
        $entry = Transitions::get(State::Ground->value, 0x00);

        $this->assertSame(Action::Execute->value, Transitions::action($entry));
        $this->assertSame(State::Ground->value, Transitions::nextState($entry));
    }

    public function testGroundStateEscTransition(): void
    {
        $entry = Transitions::get(State::Ground->value, 0x1B);

        $this->assertSame(Action::Clear->value, Transitions::action($entry));
        $this->assertSame(State::Escape->value, Transitions::nextState($entry));
    }

    public function testGroundStateCsiEntryViaEscape(): void
    {
        $entry = Transitions::get(State::Escape->value, ord('['));

        $this->assertSame(Action::Clear->value, Transitions::action($entry));
        $this->assertSame(State::CsiEntry->value, Transitions::nextState($entry));
    }

    public function testGroundStateOscStringViaEscape(): void
    {
        $entry = Transitions::get(State::Escape->value, ord(']'));

        $this->assertSame(Action::Start->value, Transitions::action($entry));
        $this->assertSame(State::OscString->value, Transitions::nextState($entry));
    }

    public function testCsiEntryDispatchOnFinalByte(): void
    {
        $entry = Transitions::get(State::CsiEntry->value, ord('m'));

        $this->assertSame(Action::Dispatch->value, Transitions::action($entry));
        $this->assertSame(State::Ground->value, Transitions::nextState($entry));
    }

    public function testCsiEntryParamTransition(): void
    {
        $entry = Transitions::get(State::CsiEntry->value, ord('3'));

        $this->assertSame(Action::Param->value, Transitions::action($entry));
        $this->assertSame(State::CsiParam->value, Transitions::nextState($entry));
    }

    public function testCsiEntryPrivateMarkerPrefix(): void
    {
        $entry = Transitions::get(State::CsiEntry->value, ord('?'));

        $this->assertSame(Action::Prefix->value, Transitions::action($entry));
        $this->assertSame(State::CsiParam->value, Transitions::nextState($entry));
    }

    public function testCsiEntryIntermediateCollect(): void
    {
        $entry = Transitions::get(State::CsiEntry->value, ord(' '));

        $this->assertSame(Action::Collect->value, Transitions::action($entry));
        $this->assertSame(State::CsiIntermediate->value, Transitions::nextState($entry));
    }

    public function testCsiParamDispatchOnFinalByte(): void
    {
        $entry = Transitions::get(State::CsiParam->value, ord('m'));

        $this->assertSame(Action::Dispatch->value, Transitions::action($entry));
        $this->assertSame(State::Ground->value, Transitions::nextState($entry));
    }

    public function testCsiParamCollectOnIntermediate(): void
    {
        $entry = Transitions::get(State::CsiParam->value, ord(' '));

        $this->assertSame(Action::Collect->value, Transitions::action($entry));
        $this->assertSame(State::CsiIntermediate->value, Transitions::nextState($entry));
    }

    public function testCsiIntermediateDispatchOnFinalByte(): void
    {
        $entry = Transitions::get(State::CsiIntermediate->value, ord('m'));

        $this->assertSame(Action::Dispatch->value, Transitions::action($entry));
        $this->assertSame(State::Ground->value, Transitions::nextState($entry));
    }

    public function testCsiIntermediateIgnoreOtherParams(): void
    {
        $entry = Transitions::get(State::CsiIntermediate->value, ord('3'));

        $this->assertSame(Action::None->value, Transitions::action($entry));
        $this->assertSame(State::Ground->value, Transitions::nextState($entry));
    }

    public function testOscStringPutForPrintable(): void
    {
        $entry = Transitions::get(State::OscString->value, ord('A'));

        $this->assertSame(Action::Put->value, Transitions::action($entry));
        $this->assertSame(State::OscString->value, Transitions::nextState($entry));
    }

    public function testOscStringDispatchOnBel(): void
    {
        $entry = Transitions::get(State::OscString->value, 0x07);

        $this->assertSame(Action::Dispatch->value, Transitions::action($entry));
        $this->assertSame(State::Ground->value, Transitions::nextState($entry));
    }

    public function testOscStringDispatchOnEscape(): void
    {
        $entry = Transitions::get(State::OscString->value, 0x1B);

        $this->assertSame(Action::Dispatch->value, Transitions::action($entry));
        $this->assertSame(State::Escape->value, Transitions::nextState($entry));
    }

    public function testOscStringIgnoreControlChars(): void
    {
        $entry = Transitions::get(State::OscString->value, 0x00);

        $this->assertSame(Action::None->value, Transitions::action($entry));
        $this->assertSame(State::OscString->value, Transitions::nextState($entry));
    }

    public function testEscapeIntermediateDispatch(): void
    {
        $entry = Transitions::get(State::EscapeIntermediate->value, ord('D'));

        $this->assertSame(Action::Dispatch->value, Transitions::action($entry));
        $this->assertSame(State::Ground->value, Transitions::nextState($entry));
    }

    public function testEscapeDispatch(): void
    {
        $entry = Transitions::get(State::Escape->value, ord('D'));

        $this->assertSame(Action::Dispatch->value, Transitions::action($entry));
        $this->assertSame(State::Ground->value, Transitions::nextState($entry));
    }

    public function testDcsEntryStart(): void
    {
        $entry = Transitions::get(State::DcsEntry->value, ord('@'));

        $this->assertSame(Action::Start->value, Transitions::action($entry));
        $this->assertSame(State::DcsString->value, Transitions::nextState($entry));
    }

    public function testDcsEntryCollectIntermediate(): void
    {
        $entry = Transitions::get(State::DcsEntry->value, ord(' '));

        $this->assertSame(Action::Collect->value, Transitions::action($entry));
        $this->assertSame(State::DcsIntermediate->value, Transitions::nextState($entry));
    }

    public function testDcsEntryPrefixPrivate(): void
    {
        $entry = Transitions::get(State::DcsEntry->value, ord('<'));

        $this->assertSame(Action::Prefix->value, Transitions::action($entry));
        $this->assertSame(State::DcsParam->value, Transitions::nextState($entry));
    }

    public function testDcsEntryParamByte(): void
    {
        $entry = Transitions::get(State::DcsEntry->value, ord('3'));

        $this->assertSame(Action::Param->value, Transitions::action($entry));
        $this->assertSame(State::DcsParam->value, Transitions::nextState($entry));
    }

    public function testUtf8LeadByte(): void
    {
        $entry = Transitions::get(State::Ground->value, 0xC3);

        $this->assertSame(Action::Collect->value, Transitions::action($entry));
        $this->assertSame(State::Utf8->value, Transitions::nextState($entry));
    }

    public function testUtf8ContinuationByte(): void
    {
        $entry = Transitions::get(State::Utf8->value, 0xA9);

        $this->assertSame(Action::None->value, Transitions::action($entry));
        $this->assertSame(State::Ground->value, Transitions::nextState($entry));
    }

    public function testUtf8NonContinuationInUtf8State(): void
    {
        $entry = Transitions::get(State::Utf8->value, ord('A'));

        $this->assertSame(Action::None->value, Transitions::action($entry));
        $this->assertSame(State::Ground->value, Transitions::nextState($entry));
    }

    public function testAnywhereC1CsiEntry9B(): void
    {
        $entry = Transitions::get(State::Ground->value, 0x9B);

        $this->assertSame(Action::Clear->value, Transitions::action($entry));
        $this->assertSame(State::CsiEntry->value, Transitions::nextState($entry));
    }

    public function testAnywhereC1DcsEntry90(): void
    {
        $entry = Transitions::get(State::Ground->value, 0x90);

        $this->assertSame(Action::Clear->value, Transitions::action($entry));
        $this->assertSame(State::DcsEntry->value, Transitions::nextState($entry));
    }

    public function testAnywhereC1OscString9D(): void
    {
        $entry = Transitions::get(State::Ground->value, 0x9D);

        $this->assertSame(Action::Start->value, Transitions::action($entry));
        $this->assertSame(State::OscString->value, Transitions::nextState($entry));
    }

    public function testAnywhereC1SosString98(): void
    {
        $entry = Transitions::get(State::Ground->value, 0x98);

        $this->assertSame(Action::Start->value, Transitions::action($entry));
        $this->assertSame(State::SosString->value, Transitions::nextState($entry));
    }

    public function testAnywhereC1PmString9E(): void
    {
        $entry = Transitions::get(State::Ground->value, 0x9E);

        $this->assertSame(Action::Start->value, Transitions::action($entry));
        $this->assertSame(State::PmString->value, Transitions::nextState($entry));
    }

    public function testAnywhereC1ApcString9F(): void
    {
        $entry = Transitions::get(State::Ground->value, 0x9F);

        $this->assertSame(Action::Start->value, Transitions::action($entry));
        $this->assertSame(State::ApcString->value, Transitions::nextState($entry));
    }

    public function testAnywhereExecuteBytes(): void
    {
        foreach ([0x18, 0x1A, 0x99, 0x9A, 0x9C] as $byte) {
            $entry = Transitions::get(State::Ground->value, $byte);

            $this->assertSame(Action::Execute->value, Transitions::action($entry), sprintf('Byte 0x%02X should execute', $byte));
            $this->assertSame(State::Ground->value, Transitions::nextState($entry));
        }
    }

    public function testAnywhereExecuteRange80To8F(): void
    {
        $entry = Transitions::get(State::Ground->value, 0x80);

        $this->assertSame(Action::Execute->value, Transitions::action($entry));
        $this->assertSame(State::Ground->value, Transitions::nextState($entry));
    }

    public function testAnywhereDcsEntryFor0x90(): void
    {
        // 0x90 is DCS entry (handled at :87), not Execute
        $entry = Transitions::get(State::Ground->value, 0x90);

        $this->assertSame(Action::Clear->value, Transitions::action($entry));
        $this->assertSame(State::DcsEntry->value, Transitions::nextState($entry));
    }

    public function testAnywhereExecute0x91To0x97(): void
    {
        // 0x91-0x97 are Execute/Ground (0x90 was removed from this range)
        foreach (range(0x91, 0x97) as $byte) {
            $entry = Transitions::get(State::Ground->value, $byte);
            $this->assertSame(Action::Execute->value, Transitions::action($entry), sprintf('Byte 0x%02X should be Execute', $byte));
            $this->assertSame(State::Ground->value, Transitions::nextState($entry), sprintf('Byte 0x%02X should transition to Ground', $byte));
        }
    }

    public function testSosStringState(): void
    {
        $entry = Transitions::get(State::SosString->value, ord('A'));

        $this->assertSame(Action::Put->value, Transitions::action($entry));
        $this->assertSame(State::SosString->value, Transitions::nextState($entry));
    }

    public function testSosStringDispatchOnEscape(): void
    {
        $entry = Transitions::get(State::SosString->value, 0x1B);

        $this->assertSame(Action::Dispatch->value, Transitions::action($entry));
        $this->assertSame(State::Escape->value, Transitions::nextState($entry));
    }

    public function testPmStringState(): void
    {
        $entry = Transitions::get(State::PmString->value, ord('A'));

        $this->assertSame(Action::Put->value, Transitions::action($entry));
        $this->assertSame(State::PmString->value, Transitions::nextState($entry));
    }

    public function testApcStringState(): void
    {
        $entry = Transitions::get(State::ApcString->value, ord('A'));

        $this->assertSame(Action::Put->value, Transitions::action($entry));
        $this->assertSame(State::ApcString->value, Transitions::nextState($entry));
    }

    public function testDcsStringPassthrough(): void
    {
        $entry = Transitions::get(State::DcsString->value, ord('A'));

        $this->assertSame(Action::Put->value, Transitions::action($entry));
        $this->assertSame(State::DcsString->value, Transitions::nextState($entry));
    }

    public function testDcsStringDispatchOnEscape(): void
    {
        $entry = Transitions::get(State::DcsString->value, 0x1B);

        $this->assertSame(Action::Dispatch->value, Transitions::action($entry));
        $this->assertSame(State::Escape->value, Transitions::nextState($entry));
    }

    public function testGroundStateDeleteExecutes(): void
    {
        $entry = Transitions::get(State::Ground->value, 0x7F);

        $this->assertSame(Action::Execute->value, Transitions::action($entry));
        $this->assertSame(State::Ground->value, Transitions::nextState($entry));
    }

    public function testAllUtf8LeadByteRanges(): void
    {
        $twoByte = Transitions::get(State::Ground->value, 0xC2);
        $this->assertSame(State::Utf8->value, Transitions::nextState($twoByte));

        $threeByte = Transitions::get(State::Ground->value, 0xE0);
        $this->assertSame(State::Utf8->value, Transitions::nextState($threeByte));

        $fourByte = Transitions::get(State::Ground->value, 0xF0);
        $this->assertSame(State::Utf8->value, Transitions::nextState($fourByte));

        $fourByteUpper = Transitions::get(State::Ground->value, 0xF4);
        $this->assertSame(State::Utf8->value, Transitions::nextState($fourByteUpper));
    }
}
