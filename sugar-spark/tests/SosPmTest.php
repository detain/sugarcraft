<?php

declare(strict_types=1);

namespace SugarCraft\Spark\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Spark\C0C1;
use SugarCraft\Spark\Inspector;
use SugarCraft\Spark\SequenceSegment;
use SugarCraft\Spark\TextSegment;

/**
 * SOS / PM / ST and the C1 controls in the inspector (step 10.16).
 *
 * C1 controls arrive in the stream under two spellings, and this file pins
 * both:
 *
 *  - Raw 8-bit C1 (a single byte 0x80-0x9F). PHP strings are byte strings,
 *    so these are detected without ambiguity: the VT500 anywhere table sends
 *    the executable members (0x80-0x8F, 0x91-0x97, 0x99, 0x9A, 0x9C) to
 *    AnsiHandler::execute(), which reports them as "C1 <name>", while the
 *    introducers (0x90/0x98/0x9B/0x9D/0x9E/0x9F) open their family's state.
 *    A dispatched family replays in its 7-bit spelling — README deviation
 *    #9, pinned in ByteFidelityTest, not here.
 *  - 7-bit form (ESC + a C6 final byte): `ESC X` is SOS, `ESC ^` is PM,
 *    `ESC \` is ST. Inspector::describeEsc() names these directly.
 *
 * The first revision of this file claimed raw C1 bytes were "not detected
 * because PHP 8.3 UTF-8 turns them into multi-byte sequences". That is
 * backwards: chr(0x98) is one byte 0x98. What IS two bytes is the UTF-8
 * encoding of U+0098 (`0xC2 0x98`) — and that is legitimately a text rune,
 * pinned as one below so nobody "fixes" it into a control.
 *
 * @see https://www.ecma-international.org/publications-and-standards/standards/ecma-48/
 */
final class SosPmTest extends TestCase
{
    public function testC0C1TableSosEntry(): void
    {
        $this->assertSame('SOS (start of string)', C0C1::c1Name(0x98));
    }

    public function testC0C1TablePmEntry(): void
    {
        $this->assertSame('PM (privacy message)', C0C1::c1Name(0x9E));
    }

    // --- 7-bit spellings ---------------------------------------------------

    public function testBareEscXIsNamedStartOfString(): void
    {
        // ESC X opens a SOS string that never receives a payload byte; the
        // empty dispatch reports the introducer itself, by name.
        $segs = Inspector::parse("\x1bX");
        $this->assertCount(1, $segs);
        $this->assertSame("\x1bX", $segs[0]->raw());
        $this->assertStringContainsString('start of string (SOS)', $segs[0]->describe());
    }

    public function testBareEscCaretIsNamedPrivacyMessage(): void
    {
        $segs = Inspector::parse("\x1b^");
        $this->assertCount(1, $segs);
        $this->assertSame("\x1b^", $segs[0]->raw());
        $this->assertStringContainsString('privacy message (PM)', $segs[0]->describe());
    }

    public function testBareEscBackslashIsNamedStringTerminator(): void
    {
        // An ST with no open string to close is still an ST — naming it beats
        // the old generic "ESC \" label.
        $segs = Inspector::parse("\x1b\\");
        $this->assertCount(1, $segs);
        $this->assertSame("\x1b\\", $segs[0]->raw());
        $this->assertStringContainsString('string terminator (ST)', $segs[0]->describe());
    }

    public function testSosPayloadIsCountedAndNamedApartFromPm(): void
    {
        $sos = Inspector::parse("\x1bXabc\x1b\\")[0];
        $this->assertStringContainsString('SOS string (3 bytes)', $sos->describe());

        $pm = Inspector::parse("\x1b^priv\x1b\\")[0];
        $this->assertStringContainsString('PM string (4 bytes)', $pm->describe());
        $this->assertStringNotContainsString('SOS', $pm->describe());
    }

    public function testSosInTextContextSwallowsToTheTerminator(): void
    {
        // ECMA-48 §5.6: everything up to the ST belongs to the string — even
        // printable bytes. "before" is text; "after" never reaches the text
        // channel.
        $segs = Inspector::parse("before\x1bXafter");
        $this->assertCount(2, $segs);
        $this->assertInstanceOf(TextSegment::class, $segs[0]);
        $this->assertSame('before', $segs[0]->describe());
        $this->assertStringContainsString('SOS string (5 bytes)', $segs[1]->describe());
    }

    // --- raw 8-bit C1 --------------------------------------------------------

    public function testRawC1ExecuteBytesAreNamed(): void
    {
        // One byte each; the anywhere table routes them to execute(), where
        // the C1 branch names them from the shared C0C1 table.
        $codes = [
            0x84 => 'C1 IND (index)',
            0x85 => 'C1 NEL (next line)',
            0x88 => 'C1 HTS (character tabulation set)',
            0x8D => 'C1 RI (reverse index)',
            0x8E => 'C1 SS2 (single shift 2)',
            0x9C => 'C1 ST (string terminator)',
        ];
        foreach ($codes as $byte => $expected) {
            $segs = Inspector::parse(chr($byte));
            $this->assertCount(1, $segs, sprintf('raw 0x%02X should produce one segment', $byte));
            $this->assertSame(chr($byte), $segs[0]->raw(), sprintf('raw 0x%02X keeps its own byte', $byte));
            $this->assertStringContainsString($expected, $segs[0]->describe());
        }
    }

    public function testRawEightBitSosOpensTheSosFamily(): void
    {
        // 0x98 is the 8-bit SOS introducer. It opens the string state and
        // flushes empty at end of stream; the replay carries the 7-bit
        // spelling (deviation #9) while the label names the family.
        $segs = Inspector::parse(chr(0x98));
        $this->assertCount(1, $segs);
        $this->assertInstanceOf(SequenceSegment::class, $segs[0]);
        $this->assertStringContainsString('start of string (SOS)', $segs[0]->describe());
    }

    public function testSevenBitC1AliasesKeepTheirEscapeNames(): void
    {
        // ESC D / ESC E / ESC M are the 7-bit forms of IND / NEL / RI and are
        // reported by their ECMA-48 function names, not generic escapes.
        $this->assertStringContainsString('index (move cursor down)', Inspector::parse("\x1bD")[0]->describe());
        $this->assertStringContainsString('next line', Inspector::parse("\x1bE")[0]->describe());
        $this->assertStringContainsString('reverse index', Inspector::parse("\x1bM")[0]->describe());
    }

    public function testEscHHasNoSevenBitC1Alias(): void
    {
        // 0x88 HTS has no `ESC H` 7-bit spelling (ESC H is not ECMA-48
        // standard); the generic label is the honest answer. The raw C1 byte
        // IS detected — see testRawC1ExecuteBytesAreNamed.
        $this->assertStringContainsString('ESC H', Inspector::parse("\x1bH")[0]->describe());
    }

    public function testSs2AndSs3SevenBitForms(): void
    {
        // ESC N is not the SS2 introducer in ECMA-48's 7-bit set, so it stays
        // generic; ESC O opens SS3 and a final byte completes it.
        $this->assertStringContainsString('ESC N', Inspector::parse("\x1bN")[0]->describe());
        $seg = Inspector::parse("\x1bOP")[0];
        $this->assertStringContainsString('F1', $seg->describe());
        $this->assertSame("\x1bOP", $seg->raw());
    }

    public function testOscAndApcSevenBitForms(): void
    {
        $this->assertStringContainsString('OSC', Inspector::parse("\x1b]")[0]->describe());
        $this->assertStringContainsString('APC', Inspector::parse("\x1b_")[0]->describe());
    }

    // --- the UTF-8 encoding of a C1 codepoint is text ------------------------

    public function testUtf8EncodedC1IsATextRuneNotAControl(): void
    {
        // 0xC2 0x98 encodes U+0098 (STRING TERMINATOR). It is two bytes, is
        // not a control introducer to the parser, and must not be "detected"
        // as C1 — this is the true residue of the false premise the first
        // revision of this file was written around.
        $segs = Inspector::parse("\xc2\x98");
        $this->assertCount(1, $segs);
        $this->assertInstanceOf(TextSegment::class, $segs[0]);
        $this->assertSame("\xc2\x98", $segs[0]->raw());
    }
}
