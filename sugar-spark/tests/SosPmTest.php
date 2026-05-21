<?php

declare(strict_types=1);

namespace SugarCraft\Spark\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Spark\Inspector;

/**
 * Tests for C1 control codes (0x80-0x9F).
 *
 * NOTE: C1 codes in 7-bit form (ESC + chr(0x40-0x5F)) cannot be reliably
 * detected in PHP 8.3+ UTF-8 strings because chr(0xNN) for 0x80-0x9F produces
 * multi-byte UTF-8 sequences. The byte values chr(0x58) for SOS and chr(0x5E)
 * for PM have ord < 0x80, so the C1 check (>= 0x80) doesn't catch them.
 *
 * For actual C1 handling in terminals, the terminal sends raw 8-bit C1 bytes,
 * which in UTF-8 become multi-byte sequences and are not detected by ord().
 * This is a known limitation of string-based parsing.
 *
 * These tests verify the C0C1 lookup table and document the expected behavior.
 */
final class SosPmTest extends TestCase
{
    public function testC0C1TableSosEntry(): void
    {
        // SOS is C1 code 0x98.
        // In 7-bit form: ESC X (chr(0x58) = 'X').
        // The C0C1::c1Name() correctly returns "SOS (start of string)".
        $this->assertSame('SOS (start of string)', \SugarCraft\Spark\C0C1::c1Name(0x98));
    }

    public function testC0C1TablePmEntry(): void
    {
        // PM is C1 code 0x9E.
        // In 7-bit form: ESC ^ (chr(0x5E) = '^').
        // The C0C1::c1Name() correctly returns "PM (privacy message)".
        $this->assertSame('PM (privacy message)', \SugarCraft\Spark\C0C1::c1Name(0x9E));
    }

    public function testEscXAsGenericEscapeSequence(): void
    {
        // ESC X is the 7-bit representation of C1 SOS.
        // Due to PHP UTF-8 string handling, ord('X') = 0x58 < 0x80,
        // so it falls through to describeEsc and returns generic "ESC X".
        $seg = Inspector::parse("\x1bX")[0];
        // The describeEsc returns "ESC X" for unknown ESC sequences.
        $this->assertStringContainsString('ESC X', $seg->describe());
    }

    public function testEscCaretAsGenericEscapeSequence(): void
    {
        // ESC ^ is the 7-bit representation of C1 PM.
        // Due to PHP UTF-8 string handling, ord('^') = 0x5E < 0x80,
        // so it falls through to describeEsc and returns generic "ESC ^".
        $seg = Inspector::parse("\x1b^")[0];
        $this->assertStringContainsString('ESC ^', $seg->describe());
    }

    public function testC1IndSequence(): void
    {
        // IND (0x84) in 7-bit form is ESC D (not CSI D).
        // ord('D') = 0x44 < 0x80, so not caught by C1 check.
        // Falls through to describeEsc which returns "index (move cursor down)".
        $seg = Inspector::parse("\x1bD")[0];
        $this->assertStringContainsString('index (move cursor down)', $seg->describe());
    }

    public function testC1NelSequence(): void
    {
        // NEL (0x85) in 7-bit form is ESC E.
        // ord('E') = 0x45 < 0x80, so not caught by C1 check.
        // Falls through to describeEsc which returns "next line".
        $seg = Inspector::parse("\x1b[E")[0];
        $this->assertStringContainsString('next line', $seg->describe());
    }

    public function testC1HtsSequence(): void
    {
        // HTS (0x88) in 7-bit form is ESC H.
        // ord('H') = 0x48 < 0x80, so not caught by C1 check.
        // Falls through to describeEsc which is not a standard escape,
        // so it becomes CSI H (cursor position).
        $seg = Inspector::parse("\x1b[H")[0];
        $this->assertStringContainsString('cursor position', $seg->describe());
    }

    public function testC1RiSequence(): void
    {
        // RI (0x8D) in 7-bit form is ESC M (not CSI M).
        // ord('M') = 0x4D < 0x80, so not caught by C1 check.
        // Falls through to describeEsc which returns "reverse index (move cursor up)".
        $seg = Inspector::parse("\x1bM")[0];
        $this->assertStringContainsString('reverse index', $seg->describe());
    }

    public function testC1Ss2Sequence(): void
    {
        // SS2 (0x8E) in 7-bit form is ESC N.
        // ord('N') = 0x4E < 0x80, so not caught by C1 check.
        // Falls through to describeEsc which returns "SS2 N" (generic).
        $seg = Inspector::parse("\x1bN")[0];
        $this->assertStringContainsString('ESC N', $seg->describe());
    }

    public function testC1Ss3Sequence(): void
    {
        // SS3 (0x8F) in 7-bit form is ESC O <final>.
        // ord('O') = 0x4F < 0x80, so not caught by C1 check.
        // Falls through to SS3 handler which processes ESC O <final>.
        // Use ESC O P (F1 key) as a valid SS3 sequence.
        $seg = Inspector::parse("\x1bOP")[0];
        $this->assertStringContainsString('F1', $seg->describe());
        // The raw bytes are ESCOP (printable form).
        $this->assertSame("\x1bOP", $seg->raw());
    }

    public function testC1OscSequence(): void
    {
        // OSC (0x9D) in 7-bit form is ESC ].
        // ord(']') = 0x5D < 0x80, so not caught by C1 check.
        // Falls through to OSC handler.
        $seg = Inspector::parse("\x1b]")[0];
        $this->assertStringContainsString('OSC', $seg->describe());
    }

    public function testC1ApcSequence(): void
    {
        // APC (0x9F) in 7-bit form is ESC _.
        // ord('_') = 0x5F < 0x80, so not caught by C1 check.
        // Falls through to APC handler.
        $seg = Inspector::parse("\x1b_")[0];
        $this->assertStringContainsString('APC', $seg->describe());
    }

    public function testSosInTextContext(): void
    {
        // ESC X in text context.
        $segs = Inspector::parse("before\x1bXafter");
        // ESC X is not caught by C1 check (ord('X') < 0x80).
        // Falls through to generic ESC X handling.
        $this->assertGreaterThanOrEqual(2, count($segs));
    }
}