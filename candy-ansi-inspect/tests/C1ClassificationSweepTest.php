<?php

declare(strict_types=1);

namespace SugarCraft\AnsiInspect\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SugarCraft\AnsiInspect\Inspector;
use SugarCraft\AnsiInspect\SequenceSegment;
use PHPUnit\Framework\TestCase;

/**
 * Exhaustive adjudication of the 0x80–0x9F anywhere-table roster (finding 025).
 *
 * The C1 classification was stated three contradictory ways — README
 * deviation 10, the AnsiHandler::execute() docblock, and the transition
 * table itself — with no test to settle them. This sweep IS the closed
 * roster: every C1 byte gets exactly one classification leg, so the prose
 * sources cannot drift from the machine again without a red test.
 *
 * The authority is sugar-ansi-parser `Parser/Transitions.php` (the anywhere
 * table): Execute covers 0x18, 0x1A, 0x99, 0x9A (the CAN/SUB pair plus the
 * introducers), 0x80–0x8F, 0x91–0x97 and 0x9C. The six Entry-state openers
 * (0x90 DCS, 0x98 SOS, 0x9B CSI, 0x9D OSC, 0x9E PM, 0x9F APC) dispatch their
 * own families instead.
 */
final class C1ClassificationSweepTest extends TestCase
{
    /**
     * Every executed C1 byte with the mnemonic C0C1 names it.
     *
     * @return array<int, array{int, string}>
     */
    public static function executedC1Bytes(): array
    {
        return [
            [0x80, 'PAD'], [0x81, 'HOP'], [0x82, 'BPH'], [0x83, 'NBH'],
            [0x84, 'IND'], [0x85, 'NEL'], [0x86, 'SSA'], [0x87, 'ESA'],
            [0x88, 'HTS'], [0x89, 'HTJ'], [0x8A, 'VTS'], [0x8B, 'PLD'],
            [0x8C, 'PLU'], [0x8D, 'RI'],  [0x8E, 'SS2'], [0x8F, 'SS3'],
            [0x91, 'PU1'], [0x92, 'PU2'], [0x93, 'STS'], [0x94, 'CCH'],
            [0x95, 'MW'],  [0x96, 'SPA'], [0x97, 'EPA'],
            [0x99, 'SGCI'], [0x9A, 'SCI'], [0x9C, 'ST'],
        ];
    }

    /**
     * @return array<int, array{int}>
     */
    public static function introducingC1Bytes(): array
    {
        return [[0x90], [0x98], [0x9B], [0x9D], [0x9E], [0x9F]];
    }

    #[DataProvider('executedC1Bytes')]
    public function testExecutedC1BytesArriveAsNamedC1Segments(int $byte): void
    {
        $segments = Inspector::parse(chr($byte));

        $this->assertCount(1, $segments, sprintf('0x%02X', $byte));
        $this->assertInstanceOf(SequenceSegment::class, $segments[0]);
        $this->assertSame(chr($byte), $segments[0]->raw(), sprintf('0x%02X', $byte));
        $this->assertStringContainsString('C1 ', $segments[0]->describe(), sprintf('0x%02X', $byte));
    }

    public function testSweepCoversEveryC1ByteExactlyOnce(): void
    {
        // Anti-vacuous guard for the two providers: executed + introducing
        // must partition 0x80–0x9F completely (32 bytes, no overlap) — a
        // fifth byte sneaking into either list fails the acceptance split.
        $executed = array_column(self::executedC1Bytes(), 0);
        $introducing = array_column(self::introducingC1Bytes(), 0);
        $all = range(0x80, 0x9F);

        $this->assertCount(26, $executed);
        $this->assertCount(6, $introducing);
        $this->assertSame([], array_intersect($executed, $introducing));
        $union = $executed;
        array_push($union, ...$introducing);
        sort($union);
        $this->assertSame($all, $union);
    }

    #[DataProvider('introducingC1Bytes')]
    public function testIntroducingC1BytesOpenTheirFamilyNotAnExecuteSegment(int $byte): void
    {
        $segments = Inspector::parse(chr($byte));

        $this->assertCount(1, $segments, sprintf('0x%02X', $byte));
        $describe = $segments[0]->describe();
        $this->assertStringNotContainsString('C1 ', $describe, sprintf('0x%02X', $byte));
        $this->assertNotEmpty($segments[0]->raw(), sprintf('0x%02X', $byte));
    }

    public function testExecutedBytesCarryTheirRosterMnemonics(): void
    {
        // The prose sources named the roster loosely; pin the exact
        // mnemonics the table implies, byte by byte (0x86 is SSA — the
        // streaming-era docblock here once called it NEL).
        $names = [];
        foreach (self::executedC1Bytes() as [$byte, $mnemonic]) {
            $segments = Inspector::parse(chr($byte));
            $describe = $segments[0]->describe();
            $this->assertStringContainsString(
                'C1 ' . $mnemonic . ' (',
                $describe,
                sprintf('0x%02X', $byte),
            );
            $names[] = $mnemonic;
        }

        $this->assertCount(26, array_unique($names), 'every executed byte names a distinct control');
        $this->assertContains('SSA', $names);
        $this->assertContains('SCI', $names);
        $this->assertContains('SGCI', $names);
    }

    public function testEightBitStringFamiliesCarryTheirPayloadThroughDispatch(): void
    {
        // The payload legs of the introducers (finding 025's unpinned
        // 0x9D/0x9F families): the data survives verbatim through the
        // re-emitted 7-bit template spelling — README deviation 9's
        // disclosed family, pinned rather than assumed.
        $osc = Inspector::parse("\x9d1;t\x07");
        $this->assertCount(1, $osc);
        $this->assertSame("\x1b]1;t\x07", $osc[0]->raw());
        $this->assertStringContainsString('set icon name', $osc[0]->describe());

        $apc = Inspector::parse("\x9fGhello\x9c");
        $this->assertCount(1, $apc);
        $this->assertSame("\x1b_Ghello\x1b\\", $apc[0]->raw());
        $this->assertStringContainsString('kitty graphics', $apc[0]->describe());

        $sos = Inspector::parse("\x98abc\x9c");
        $this->assertCount(1, $sos);
        $this->assertSame("\x1bXabc\x1b\\", $sos[0]->raw());

        $pm = Inspector::parse("\x9eab\x1b\\");
        $this->assertSame("\x1b^ab\x1b\\", $pm[0]->raw());
    }
}
