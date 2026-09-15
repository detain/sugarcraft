<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Terminal\Terminal;

/**
 * Query→reply channel: DA1, DA2, DSR/CPR, DECRQM and XTWINOPS answers
 * produced from emulator state, delivered either through the optional
 * `feed($bytes, $respond)` callback or the `replies()` drain.
 *
 * Reply byte formats follow xterm (DA1/DA2/DECRPM/CPR/XTWINOPS) and the
 * same attribute lists charmbracelet/x/vt emits. Semantics are pinned to
 * what candy-mosaic/src/Detect.php probes: DA1 without `;4` (no sixel),
 * 16t `ESC [ 6 ; h ; w t` and 18t `ESC [ 8 ; rows ; cols t`.
 *
 * @see https://invisible-island.net/xterm/ctlseqs/ctlseqs.html
 * @see charmbracelet/x/ansi ctrl.go / status.go / winop.go / mode.go
 */
final class QueryReplyTest extends TestCase
{
    // ─── Backwards compatibility ───────────────────────────────────────────

    public function testFeedStillAcceptsSingleArgumentAndReturnsVoid(): void
    {
        // A single-argument call must still compile and run unchanged — the
        // optional $respond parameter is additive. (PHP cannot assert void at
        // runtime; the signature is the contract.)
        $t = Terminal::new(10, 3);
        $t->feed('abc');
        $this->assertSame('a', $t->screen()->cell(0, 0)->grapheme);
        $this->assertSame([], $t->replies(), 'plain text produces no replies');
    }

    public function testRepliesDrainOnce(): void
    {
        $t = Terminal::new(10, 3);
        $t->feed("\x1b[c");
        $this->assertSame(["\x1b[?62;1;6;22c"], $t->replies());
        $this->assertSame([], $t->replies());
    }

    public function testQueueSurvivesUntilDrainedAcrossFeeds(): void
    {
        $t = Terminal::new(10, 3);
        $t->feed("\x1b[5n");
        $t->feed("\x1b[6n");
        $this->assertSame(["\x1b[0n", "\x1b[1;1R"], $t->replies());
    }

    public function testRespondCallbackDeliversInRequestOrder(): void
    {
        $t = Terminal::new(10, 3);
        $seen = [];
        $t->feed("\x1b[c\x1b[>c\x1b[1;2H\x1b[6n", function (string $reply) use (&$seen): void {
            $seen[] = $reply;
        });
        $this->assertSame(["\x1b[?62;1;6;22c", "\x1b[>1;10;0c", "\x1b[1;2R"], $seen);
        // Everything went through the callback — queue is left empty.
        $this->assertSame([], $t->replies());
    }

    // ─── DA1 / DA2 ──────────────────────────────────────────────────────────

    public function testDa1AnswersVt220AttributeListWithoutSixel(): void
    {
        $t = Terminal::new(10, 3);
        $t->feed("\x1b[c");
        $replies = $t->replies();
        $this->assertSame(["\x1b[?62;1;6;22c"], $replies);

        // candy-mosaic Detect::parseDa1Reply sixel heuristic — the ACTUAL
        // produced reply must NOT match any of its three sixel patterns.
        $reply = $replies[0];
        $this->assertFalse(str_contains($reply, ';4;'));
        $this->assertFalse(str_contains($reply, ';4c'));
        $this->assertFalse(str_contains($reply, '?4c'));
    }

    public function testDa1AlsoAnswersCsiZeroC(): void
    {
        $t = Terminal::new(10, 3);
        $t->feed("\x1b[0c");
        $this->assertSame(["\x1b[?62;1;6;22c"], $t->replies());
    }

    public function testDa1VendorVariantsStaySilent(): void
    {
        // `CSI = c` (DA3) and `CSI 61 c` (DECVDI) must not be answered
        // with a DA1 report (mirrors upstream's first-param guard).
        $t = Terminal::new(10, 3);
        $t->feed("\x1b[=c");
        $t->feed("\x1b[61c");
        $this->assertSame([], $t->replies());
    }

    public function testDa2AnswersSecondaryAttributes(): void
    {
        $t = Terminal::new(10, 3);
        $t->feed("\x1b[>c");
        $this->assertSame(["\x1b[>1;10;0c"], $t->replies());
    }

    // ─── DSR / CPR ──────────────────────────────────────────────────────────

    public function testCprIsOneBasedAndTracksCursor(): void
    {
        $t = Terminal::new(10, 3);
        $t->feed("\x1b[3;7H\x1b[6n");
        $this->assertSame(["\x1b[3;7R"], $t->replies());
    }

    public function testDsrFiveReportsTerminalReady(): void
    {
        $t = Terminal::new(10, 3);
        $t->feed("\x1b[5n");
        $this->assertSame(["\x1b[0n"], $t->replies());
    }

    public function testUnknownDsrParamIsSilent(): void
    {
        $t = Terminal::new(10, 3);
        $t->feed("\x1b[7n");
        $this->assertSame([], $t->replies());
    }

    // ─── DECRQM ─────────────────────────────────────────────────────────────

    public function testDecrqmReportsSetAndResetStates(): void
    {
        $t = Terminal::new(10, 3);
        $t->feed("\x1b[?7\$p");
        $t->feed("\x1b[?7l\x1b[?7\$p");
        $t->feed("\x1b[?7h\x1b[?25l\x1b[?25\$p");
        $this->assertSame(["\x1b[?7;1\$y", "\x1b[?7;2\$y", "\x1b[?25;2\$y"], $t->replies());
    }

    public function testDecrqmUnknownModeReportsNotRecognized(): void
    {
        $t = Terminal::new(10, 3);
        $t->feed("\x1b[?1\$p");
        $this->assertSame(["\x1b[?1;0\$y"], $t->replies());
    }

    public function testDecrqmAnsiModeReportsNotRecognized(): void
    {
        // This emulator routes only DEC private modes; ANSI modes are
        // honestly reported as unrecognized (DECRQM without '?').
        $t = Terminal::new(10, 3);
        $t->feed("\x1b[4\$p");
        $this->assertSame(["\x1b[4;0\$y"], $t->replies());
    }

    public function testDecrqmAltScreenVariantsAndMouseModes(): void
    {
        $t = Terminal::new(10, 3);
        $t->feed("\x1b[?1049h\x1b[?1049\$p\x1b[?1000h\x1b[?1000\$p\x1b[?1049l");
        $this->assertSame(["\x1b[?1049;1\$y", "\x1b[?1000;1\$y"], $t->replies());
    }

    // ─── XTWINOPS ───────────────────────────────────────────────────────────

    public function testXtwinops18ReportsCellGeometry(): void
    {
        $t = Terminal::new(40, 12);
        $t->feed("\x1b[18t");
        $this->assertSame(["\x1b[8;12;40t"], $t->replies());
    }

    public function testXtwinops16ReportsCellPixels(): void
    {
        $t = Terminal::new(80, 24);
        $t->feed("\x1b[16t");
        $this->assertSame(["\x1b[6;16;8t"], $t->replies());

        $custom = Terminal::new(80, 24)->withCellPixels(9, 18);
        $custom->feed("\x1b[16t");
        $this->assertSame(["\x1b[6;18;9t"], $custom->replies());
    }

    public function testXtwinops14ReportsWindowPixels(): void
    {
        $t = Terminal::new(10, 4);
        $t->feed("\x1b[14t");
        $this->assertSame(["\x1b[4;64;80t"], $t->replies());
    }

    public function testXtwinopsUnknownOpIsSilent(): void
    {
        $t = Terminal::new(10, 4);
        $t->feed("\x1b[13t");
        $this->assertSame([], $t->replies());
    }

    /**
     * Field-order lock against candy-mosaic/src/Detect::parseXtwinoReply():
     * it maps reply parts [id, v1, v2] to CellSize(v2, v1), so 16t must
     * be `6;height;width` and 18t `8;rows;cols` — swapped fields would
     * silently give mosaic transposed cell geometry.
     */
    public function testXtwinopsFieldOrderMatchesMosaicParser(): void
    {
        $t = Terminal::new(132, 43)->withCellPixels(10, 20);
        $t->feed("\x1b[16t\x1b[18t");
        $replies = $t->replies();
        $this->assertCount(2, $replies);

        preg_match('/\x1b\[6;(\d+);(\d+)t/', $replies[0], $m16);
        $this->assertSame([20, 10], [(int) ($m16[1] ?? 0), (int) ($m16[2] ?? 0)], '16t = 6;height;width');

        preg_match('/\x1b\[8;(\d+);(\d+)t/', $replies[1], $m18);
        $this->assertSame([43, 132], [(int) ($m18[1] ?? 0), (int) ($m18[2] ?? 0)], '18t = 8;rows;cols');
    }

    public function testBareWindowOpStaysSilent(): void
    {
        // xterm defaults a missing Ps for `CSI t` to 1 (de-iconify, no
        // reply) — an unsolicited 4t could be mis-ingested mid-read.
        $t = Terminal::new(10, 3);
        $t->feed("\x1b[t");
        $this->assertSame([], $t->replies());
    }

    public function testReplyQueueIsBoundedAgainstQueryStorms(): void
    {
        // Hostile program output spamming CSI 6n at a terminal nobody
        // drains must not grow memory without bound (candy-pty renders
        // exactly such untrusted streams). Drop-OLDEST at MAX_REPLIES:
        // each query is issued from a rotating row so head and tail
        // differ, pinning which end the ring sheds.
        $t = Terminal::new(10, 3);
        $storm = '';
        for ($i = 0; $i < 1100; $i++) {
            $storm .= "\x1b[" . ($i % 3 + 1) . ";1H\x1b[6n";
        }
        $t->feed($storm);
        $replies = $t->replies();
        $this->assertCount(1024, $replies, 'queue capped, not unbounded');
        // Kept window is pushes 76..1099: head answers i=76 (row 76%3+1=2).
        $this->assertSame("\x1b[2;1R", $replies[0], 'oldest replies were shed');
        $this->assertSame("\x1b[" . (1099 % 3 + 1) . ";1R", $replies[1023], 'newest reply kept');
    }
}
