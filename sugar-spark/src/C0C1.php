<?php

declare(strict_types=1);

namespace SugarCraft\Spark;

/**
 * Lookup tables for C0 (0x00–0x1F) and C1 (0x80–0x9F) control codes.
 * Descriptions follow ANSI/ISO standards and common terminal conventions.
 */
final class C0C1
{
    /** C0 control codes 0x00–0x1F (7-bit). */
    public const C0 = [
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
    ];

    /** C1 control codes 0x80–0x9F (8-bit, two-byte ESC sequence). */
    public const C1 = [
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
    ];

    public static function c0Name(int $byte): string
    {
        return self::C0[$byte] ?? 'C0 0x' . strtoupper(dechex($byte));
    }

    public static function c1Name(int $byte): string
    {
        return self::C1[$byte] ?? 'C1 0x' . strtoupper(dechex($byte));
    }
}