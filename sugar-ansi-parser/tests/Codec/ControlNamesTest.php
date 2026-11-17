<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Tests\Codec;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\AnsiParser\Codec\ControlNames;

/**
 * Pins the control-name vocabulary. The long labels are the spellings that
 * candy-ansi-inspect's C0C1Test has always asserted. The mnemonic tests pin
 * the short names that candy-ansi-inspect's Inspector splices into sanitized
 * labels for 46 bytes (see inspectorMnemonics()). The Inspector renders 19
 * further C1 bytes as hex escapes, while mnemonic() names them; that gap is
 * pinned separately and recorded in the README migration map.
 */
final class ControlNamesTest extends TestCase
{
    /**
     * Every label candy-ansi-inspect's C0C1Test has pinned, byte for byte, so
     * retiring C0C1.php cannot change a rendered label.
     */
    public function testEveryLabelMatchesTheInspectorsPinnedSpelling(): void
    {
        $c0 = [
            0x00 => 'NUL (null)',
            0x01 => 'SOH (start of heading)',
            0x02 => 'STX (start of text)',
            0x03 => 'ETX (end of text)',
            0x04 => 'EOT (end of transmission)',
            0x05 => 'ENQ (enquiry)',
            0x06 => 'ACK (acknowledge)',
            0x07 => 'BEL (bell)',
            0x08 => 'BS (backspace)',
            0x09 => 'HT (horizontal tab)',
            0x0A => 'LF (line feed)',
            0x0B => 'VT (vertical tab)',
            0x0C => 'FF (form feed)',
            0x0D => 'CR (carriage return)',
            0x0E => 'SO (shift out)',
            0x0F => 'SI (shift in)',
            0x10 => 'DLE (data link escape)',
            0x11 => 'DC1 (device control 1)',
            0x12 => 'DC2 (device control 2)',
            0x13 => 'DC3 (device control 3)',
            0x14 => 'DC4 (device control 4)',
            0x15 => 'NAK (negative acknowledge)',
            0x16 => 'SYN (synchronous idle)',
            0x17 => 'ETB (end of transmission block)',
            0x18 => 'CAN (cancel)',
            0x19 => 'EM (end of medium)',
            0x1A => 'SUB (substitute)',
            0x1B => 'ESC (escape)',
            0x1C => 'FS (file separator)',
            0x1D => 'GS (group separator)',
            0x1E => 'RS (record separator)',
            0x1F => 'US (unit separator)',
            0x20 => 'C0 0x20',
        ];
        $c1 = [
            0x80 => 'PAD (padding character)',
            0x81 => 'HOP (high octet preset)',
            0x82 => 'BPH (break permitted here)',
            0x83 => 'NBH (no break here)',
            0x84 => 'IND (index)',
            0x85 => 'NEL (next line)',
            0x86 => 'SSA (start of selected area)',
            0x87 => 'ESA (end of selected area)',
            0x88 => 'HTS (character tabulation set)',
            0x89 => 'HTJ (character tabulation with justification)',
            0x8A => 'VTS (line tabulation set)',
            0x8B => 'PLD (partial line forward)',
            0x8C => 'PLU (partial line backward)',
            0x8D => 'RI (reverse index)',
            0x8E => 'SS2 (single shift 2)',
            0x8F => 'SS3 (single shift 3)',
            0x90 => 'DCS (device control string)',
            0x91 => 'PU1 (private use 1)',
            0x92 => 'PU2 (private use 2)',
            0x93 => 'STS (set transmit state)',
            0x94 => 'CCH (cancel character)',
            0x95 => 'MW (message waiting)',
            0x96 => 'SPA (start of guarded area)',
            0x97 => 'EPA (end of guarded area)',
            0x98 => 'SOS (start of string)',
            0x99 => 'SGCI (single graphic character introducer)',
            0x9A => 'SCI (single character introducer)',
            0x9B => 'CSI (control sequence introducer)',
            0x9C => 'ST (string terminator)',
            0x9D => 'OSC (operating system command)',
            0x9E => 'PM (privacy message)',
            0x9F => 'APC (application program command)',
            0x7F => 'C1 0x7F',
        ];
        foreach ($c0 as $byte => $name) {
            $this->assertSame($name, ControlNames::c0Name($byte));
        }
        foreach ($c1 as $byte => $name) {
            $this->assertSame($name, ControlNames::c1Name($byte));
        }
    }

    public function testEveryC0ByteHasALongLabel(): void
    {
        $this->assertCount(32, ControlNames::C0);
        $this->assertSame('NUL (null)', ControlNames::c0Name(0x00));
        $this->assertSame('BEL (bell)', ControlNames::c0Name(0x07));
        $this->assertSame('ESC (escape)', ControlNames::c0Name(0x1B));
        $this->assertSame('US (unit separator)', ControlNames::c0Name(0x1F));
    }

    public function testC0FallbackForNonControlByte(): void
    {
        $this->assertSame('C0 0x20', ControlNames::c0Name(0x20));
    }

    public function testEveryC1ByteHasALongLabel(): void
    {
        $this->assertCount(32, ControlNames::C1);
        $this->assertSame('PAD (padding character)', ControlNames::c1Name(0x80));
        $this->assertSame('NEL (next line)', ControlNames::c1Name(0x85));
        $this->assertSame('CSI (control sequence introducer)', ControlNames::c1Name(0x9B));
        $this->assertSame('APC (application program command)', ControlNames::c1Name(0x9F));
    }

    public function testC1FallbackIsNotAC1Byte(): void
    {
        // DEL sits outside the C1 range, so it has no C1 label.
        $this->assertSame('C1 0x7F', ControlNames::c1Name(0x7F));
    }

    public function testMnemonicIsTheBareAbbreviation(): void
    {
        $this->assertSame('BEL', ControlNames::mnemonic(0x07));
        $this->assertSame('ESC', ControlNames::mnemonic(0x1B));
        $this->assertSame('CSI', ControlNames::mnemonic(0x9B));
        $this->assertSame('SS3', ControlNames::mnemonic(0x8F));
        $this->assertSame('APC', ControlNames::mnemonic(0x9F));
    }

    public function testMnemonicNamesDelAsAControlByte(): void
    {
        $this->assertSame('DEL', ControlNames::mnemonic(0x7F));
    }

    public function testMnemonicIsNullForPrintableAndOutOfRangeBytes(): void
    {
        $this->assertNull(ControlNames::mnemonic(0x20));
        $this->assertNull(ControlNames::mnemonic(0x41));
        $this->assertNull(ControlNames::mnemonic(0xA0));
        $this->assertNull(ControlNames::mnemonic(-1));
    }

    public function testMnemonicMatchesEveryLongLabelsLeadingToken(): void
    {
        // One union with `+`, not array spread: `[...$a, ...$b]` renumbers the
        // int keys, which would shift every C1 byte onto the wrong code point.
        foreach (ControlNames::C0 + ControlNames::C1 as $byte => $name) {
            $this->assertSame(explode(' ', $name, 2)[0], ControlNames::mnemonic($byte));
        }
    }

    /**
     * C1 bytes that candy-ansi-inspect's Inspector::sanitizeLabelBytes() does
     * NOT name: its default arm renders them as `\xNN`. mnemonic() names every
     * C1 byte, so migrating the Inspector onto this table changes the output
     * for exactly these bytes. The migration must either keep the hex form for
     * them or change it deliberately in its own PR.
     *
     * @return iterable<string, array{int, string}>
     */
    public static function inspectorHexFallbackC1Bytes(): iterable
    {
        $pairs = [
            0x80 => 'PAD', 0x81 => 'HOP', 0x82 => 'BPH', 0x83 => 'NBH',
            0x86 => 'SSA', 0x87 => 'ESA', 0x89 => 'HTJ', 0x8A => 'VTS',
            0x8B => 'PLD', 0x8C => 'PLU', 0x91 => 'PU1', 0x92 => 'PU2',
            0x93 => 'STS', 0x94 => 'CCH', 0x95 => 'MW',  0x96 => 'SPA',
            0x97 => 'EPA', 0x99 => 'SGCI', 0x9A => 'SCI',
        ];
        foreach ($pairs as $byte => $short) {
            yield sprintf('0x%02X', $byte) => [$byte, $short];
        }
    }

    #[DataProvider('inspectorHexFallbackC1Bytes')]
    public function testC1BytesTheInspectorLeavesAsHexHaveMnemonicsHere(int $byte, string $short): void
    {
        $this->assertSame($short, ControlNames::mnemonic($byte));
    }

    /**
     * The table candy-ansi-inspect's Inspector::sanitizeLabelBytes() carried
     * before this table existed. Every entry here must come out of
     * mnemonic() unchanged, or migrating the Inspector changes its labels.
     *
     * @return iterable<string, array{int, string}>
     */
    public static function inspectorMnemonics(): iterable
    {
        $pairs = [
            0x1B => 'ESC', 0x00 => 'NUL', 0x01 => 'SOH', 0x02 => 'STX', 0x03 => 'ETX',
            0x04 => 'EOT', 0x05 => 'ENQ', 0x06 => 'ACK', 0x07 => 'BEL', 0x08 => 'BS',
            0x09 => 'HT',  0x0A => 'LF',  0x0B => 'VT',  0x0C => 'FF',  0x0D => 'CR',
            0x0E => 'SO',  0x0F => 'SI',  0x10 => 'DLE', 0x11 => 'DC1', 0x12 => 'DC2',
            0x13 => 'DC3', 0x14 => 'DC4', 0x15 => 'NAK', 0x16 => 'SYN', 0x17 => 'ETB',
            0x18 => 'CAN', 0x19 => 'EM',  0x1A => 'SUB', 0x1C => 'FS',  0x1D => 'GS',
            0x1E => 'RS',  0x1F => 'US',  0x7F => 'DEL',
            0x84 => 'IND', 0x85 => 'NEL', 0x88 => 'HTS', 0x8D => 'RI',  0x8E => 'SS2',
            0x8F => 'SS3', 0x90 => 'DCS', 0x98 => 'SOS', 0x9B => 'CSI', 0x9C => 'ST',
            0x9D => 'OSC', 0x9E => 'PM',  0x9F => 'APC',
        ];
        foreach ($pairs as $byte => $short) {
            yield sprintf('0x%02X', $byte) => [$byte, $short];
        }
    }

    #[DataProvider('inspectorMnemonics')]
    public function testInspectorMnemonicsAreReproduced(int $byte, string $short): void
    {
        $this->assertSame($short, ControlNames::mnemonic($byte));
    }
}
