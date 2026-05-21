<?php

declare(strict_types=1);

namespace SugarCraft\Spark\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Spark\C0C1;

final class C0C1Test extends TestCase
{
    public function testC0Names(): void
    {
        $this->assertSame('NUL (null)', C0C1::c0Name(0x00));
        $this->assertSame('SOH (start of heading)', C0C1::c0Name(0x01));
        $this->assertSame('STX (start of text)', C0C1::c0Name(0x02));
        $this->assertSame('ETX (end of text)', C0C1::c0Name(0x03));
        $this->assertSame('EOT (end of transmission)', C0C1::c0Name(0x04));
        $this->assertSame('ENQ (enquiry)', C0C1::c0Name(0x05));
        $this->assertSame('ACK (acknowledge)', C0C1::c0Name(0x06));
        $this->assertSame('BEL (bell)', C0C1::c0Name(0x07));
        $this->assertSame('BS (backspace)', C0C1::c0Name(0x08));
        $this->assertSame('HT (horizontal tab)', C0C1::c0Name(0x09));
        $this->assertSame('LF (line feed)', C0C1::c0Name(0x0A));
        $this->assertSame('VT (vertical tab)', C0C1::c0Name(0x0B));
        $this->assertSame('FF (form feed)', C0C1::c0Name(0x0C));
        $this->assertSame('CR (carriage return)', C0C1::c0Name(0x0D));
        $this->assertSame('SO (shift out)', C0C1::c0Name(0x0E));
        $this->assertSame('SI (shift in)', C0C1::c0Name(0x0F));
        $this->assertSame('DLE (data link escape)', C0C1::c0Name(0x10));
        $this->assertSame('DC1 (device control 1)', C0C1::c0Name(0x11));
        $this->assertSame('DC2 (device control 2)', C0C1::c0Name(0x12));
        $this->assertSame('DC3 (device control 3)', C0C1::c0Name(0x13));
        $this->assertSame('DC4 (device control 4)', C0C1::c0Name(0x14));
        $this->assertSame('NAK (negative acknowledge)', C0C1::c0Name(0x15));
        $this->assertSame('SYN (synchronous idle)', C0C1::c0Name(0x16));
        $this->assertSame('ETB (end of transmission block)', C0C1::c0Name(0x17));
        $this->assertSame('CAN (cancel)', C0C1::c0Name(0x18));
        $this->assertSame('EM (end of medium)', C0C1::c0Name(0x19));
        $this->assertSame('SUB (substitute)', C0C1::c0Name(0x1A));
        $this->assertSame('ESC (escape)', C0C1::c0Name(0x1B));
        $this->assertSame('FS (file separator)', C0C1::c0Name(0x1C));
        $this->assertSame('GS (group separator)', C0C1::c0Name(0x1D));
        $this->assertSame('RS (record separator)', C0C1::c0Name(0x1E));
        $this->assertSame('US (unit separator)', C0C1::c0Name(0x1F));
    }

    public function testC0UnknownReturnsFormattedString(): void
    {
        $this->assertSame('C0 0x20', C0C1::c0Name(0x20));
    }

    public function testC1Names(): void
    {
        $this->assertSame('PAD (padding character)', C0C1::c1Name(0x80));
        $this->assertSame('HOP (high octet preset)', C0C1::c1Name(0x81));
        $this->assertSame('BPH (break permitted here)', C0C1::c1Name(0x82));
        $this->assertSame('NBH (no break here)', C0C1::c1Name(0x83));
        $this->assertSame('IND (index)', C0C1::c1Name(0x84));
        $this->assertSame('NEL (next line)', C0C1::c1Name(0x85));
        $this->assertSame('SSA (start of selected area)', C0C1::c1Name(0x86));
        $this->assertSame('ESA (end of selected area)', C0C1::c1Name(0x87));
        $this->assertSame('HTS (character tabulation set)', C0C1::c1Name(0x88));
        $this->assertSame('HTJ (character tabulation with justification)', C0C1::c1Name(0x89));
        $this->assertSame('VTS (line tabulation set)', C0C1::c1Name(0x8A));
        $this->assertSame('PLD (partial line forward)', C0C1::c1Name(0x8B));
        $this->assertSame('PLU (partial line backward)', C0C1::c1Name(0x8C));
        $this->assertSame('RI (reverse index)', C0C1::c1Name(0x8D));
        $this->assertSame('SS2 (single shift 2)', C0C1::c1Name(0x8E));
        $this->assertSame('SS3 (single shift 3)', C0C1::c1Name(0x8F));
        $this->assertSame('DCS (device control string)', C0C1::c1Name(0x90));
        $this->assertSame('PU1 (private use 1)', C0C1::c1Name(0x91));
        $this->assertSame('PU2 (private use 2)', C0C1::c1Name(0x92));
        $this->assertSame('STS (set transmit state)', C0C1::c1Name(0x93));
        $this->assertSame('CCH (cancel character)', C0C1::c1Name(0x94));
        $this->assertSame('MW (message waiting)', C0C1::c1Name(0x95));
        $this->assertSame('SPA (start of guarded area)', C0C1::c1Name(0x96));
        $this->assertSame('EPA (end of guarded area)', C0C1::c1Name(0x97));
        $this->assertSame('SOS (start of string)', C0C1::c1Name(0x98));
        $this->assertSame('SGCI (single graphic character introducer)', C0C1::c1Name(0x99));
        $this->assertSame('SCI (single character introducer)', C0C1::c1Name(0x9A));
        $this->assertSame('CSI (control sequence introducer)', C0C1::c1Name(0x9B));
        $this->assertSame('ST (string terminator)', C0C1::c1Name(0x9C));
        $this->assertSame('OSC (operating system command)', C0C1::c1Name(0x9D));
        $this->assertSame('PM (privacy message)', C0C1::c1Name(0x9E));
        $this->assertSame('APC (application program command)', C0C1::c1Name(0x9F));
    }

    public function testC1UnknownReturnsFormattedString(): void
    {
        $this->assertSame('C1 0x7F', C0C1::c1Name(0x7F));
    }
}