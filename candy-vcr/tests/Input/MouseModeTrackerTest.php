<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Input;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Input\MouseModeTracker;

final class MouseModeTrackerTest extends TestCase
{
    public function testSetAndResetSingleMode(): void
    {
        $tracker = new MouseModeTracker();

        $tracker->observe("\x1b[?1000h");
        self::assertTrue($tracker->enabled(MouseModeTracker::X10_TRACKING));
        self::assertTrue($tracker->legacyReportingActive());

        $tracker->observe("\x1b[?1000l");
        self::assertFalse($tracker->enabled(MouseModeTracker::X10_TRACKING));
        self::assertFalse($tracker->legacyReportingActive());
    }

    /**
     * The whole point of the fix: a combined DECSET must arm every mode it
     * names, so later mouse bytes can be attributed to the encoding that
     * produced them rather than assumed.
     */
    public function testMultiModeDecsetRecordsEveryMode(): void
    {
        $tracker = new MouseModeTracker();

        $tracker->observe("\x1b[?1000;1002;1006h");

        self::assertTrue($tracker->enabled(MouseModeTracker::X10_TRACKING));
        self::assertTrue($tracker->enabled(MouseModeTracker::CELL_MOTION));
        self::assertTrue($tracker->enabled(MouseModeTracker::SGR_COORDINATES));
        self::assertFalse($tracker->enabled(MouseModeTracker::ANY_MOTION));
        self::assertFalse($tracker->enabled(MouseModeTracker::UTF8_COORDINATES));
    }

    public function testMultiModeDecrstClearsEveryMode(): void
    {
        $tracker = new MouseModeTracker();

        $tracker->observe("\x1b[?1005;1015h");
        $tracker->observe("\x1b[?1005;1015l");

        self::assertFalse($tracker->enabled(MouseModeTracker::UTF8_COORDINATES));
        self::assertFalse($tracker->enabled(MouseModeTracker::URXVT_COORDINATES));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function watchedModeProvider(): array
    {
        return [
            'X10' => [MouseModeTracker::X10_TRACKING],
            'cell motion' => [MouseModeTracker::CELL_MOTION],
            'any motion' => [MouseModeTracker::ANY_MOTION],
            'utf8' => [MouseModeTracker::UTF8_COORDINATES],
            'SGR' => [MouseModeTracker::SGR_COORDINATES],
            'urxvt' => [MouseModeTracker::URXVT_COORDINATES],
            'pixel' => [MouseModeTracker::PIXEL_COORDINATES],
        ];
    }

    #[DataProvider('watchedModeProvider')]
    public function testEveryWatchedModeIsRecorded(int $mode): void
    {
        $tracker = new MouseModeTracker();

        $tracker->observe("\x1b[?{$mode}h");

        self::assertTrue($tracker->enabled($mode));
    }

    public function testAnsiPrefixIsIgnored(): void
    {
        $tracker = new MouseModeTracker();

        // Mode 1000 without the '?' prefix is not a DEC private mouse mode.
        $tracker->observe("\x1b[1000h");

        self::assertFalse($tracker->enabled(MouseModeTracker::X10_TRACKING));
    }

    public function testUnwatchedModesAreIgnored(): void
    {
        $tracker = new MouseModeTracker();

        $tracker->observe("\x1b[?25h\x1b[?1049h");

        self::assertFalse($tracker->enabled(25));
        self::assertFalse($tracker->enabled(1049));
    }

    public function testSequencesCarryAcrossChunkBoundaries(): void
    {
        $tracker = new MouseModeTracker();

        $tracker->observe("\x1b[?1000;100");
        self::assertFalse($tracker->enabled(MouseModeTracker::CELL_MOTION));
        $tracker->observe('2h');

        self::assertTrue($tracker->enabled(MouseModeTracker::X10_TRACKING));
        self::assertTrue($tracker->enabled(MouseModeTracker::CELL_MOTION));
    }

    public function testResetClearsModeStateAndParser(): void
    {
        $tracker = new MouseModeTracker();

        $tracker->observe("\x1b[?1006h");
        $tracker->reset();

        self::assertFalse($tracker->enabled(MouseModeTracker::SGR_COORDINATES));

        // A half-fed sequence must not leak across the reset either.
        $tracker->observe("\x1b[?1000");
        $tracker->reset();
        $tracker->observe("6h");
        self::assertFalse($tracker->enabled(MouseModeTracker::X10_TRACKING));
        self::assertFalse($tracker->enabled(MouseModeTracker::SGR_COORDINATES));
    }

    public function testDefaultsAreOff(): void
    {
        $tracker = new MouseModeTracker();

        self::assertFalse($tracker->legacyReportingActive());
    }

    /**
     * VCR-S28 fast path: repaint frames and print runs contain no DEC
     * private h/l and must never reach the parser. The observe() return
     * value is the spy — true means the slow (parser) path ran.
     */
    public function testInertFramesNeverTouchTheParser(): void
    {
        $tracker = new MouseModeTracker();

        $frames = [
            "\x1b[2J\x1b[H",
            "hello world\r\n",
            "\x1b[38;2;120;200;90m\x1b[1mgreen\x1b[0m",
            "\x1b[1;1H\x1b[K\x1b[?25l",           // cursor hide: private but unwatched
            "\x1b[?2026h\x1b[10;5H\x1b[?2026l",   // synchronized-output brackets
            "\x1b]0;title\x07",                    // OSC 8-less title set
            "\x1b[?1049hbuffered",                 // alt screen
        ];
        foreach ($frames as $frame) {
            self::assertFalse($tracker->observe($frame), "frame: {$frame}");
        }

        self::assertFalse($tracker->legacyReportingActive());
        self::assertFalse($tracker->enabled(MouseModeTracker::SGR_COORDINATES));
    }

    public function testCanonicalDecsetIsHandledByThePatternNotTheParser(): void
    {
        $tracker = new MouseModeTracker();

        self::assertFalse($tracker->observe("\x1b[?1000;1002;1006h"));
        self::assertFalse($tracker->observe("\x1b[?1000l"));

        self::assertFalse($tracker->enabled(MouseModeTracker::X10_TRACKING));
        self::assertTrue($tracker->enabled(MouseModeTracker::CELL_MOTION));
        self::assertTrue($tracker->enabled(MouseModeTracker::SGR_COORDINATES));
    }

    public function testParserSpyCollapsesOnFixtureStream(): void
    {
        $tracker = new MouseModeTracker();
        $slow = 0;

        for ($frame = 0; $frame < 200; $frame++) {
            $bytes = match ($frame) {
                0 => "\x1b[?1000;1006h",
                199 => "\x1b[?1000;1006l",
                default => "\x1b[1;" . ($frame % 40 + 1) . "H\x1b[38;5;" . ($frame % 256) . "mframe{$frame}\x1b[K\x1b[0m\r\n",
            };
            if ($tracker->observe($bytes)) {
                $slow++;
            }
        }

        self::assertSame(0, $slow, 'repaint frames must never reach the parser');
    }

    public function testCarryCompletesAcrossChunksOnTheFastPath(): void
    {
        $tracker = new MouseModeTracker();

        self::assertFalse($tracker->observe("frame\x1b[?100"));
        self::assertFalse($tracker->enabled(MouseModeTracker::SGR_COORDINATES));
        // A frame chunk arriving before the completion must not absorb it:
        self::assertFalse($tracker->observe('6h'));

        self::assertTrue($tracker->enabled(MouseModeTracker::SGR_COORDINATES));
    }

    public function testBareIntroducerCarryAcrossChunks(): void
    {
        $tracker = new MouseModeTracker();

        self::assertFalse($tracker->observe("\x1b["));
        self::assertFalse($tracker->observe('?1002h'));

        self::assertTrue($tracker->enabled(MouseModeTracker::CELL_MOTION));
    }

    public function testRawC1ChunkKeepsTheSlowPath(): void
    {
        $tracker = new MouseModeTracker();

        // 8-bit CSI introducer: the ESC pattern cannot see it, parser must.
        self::assertTrue($tracker->observe("\x9b?1000h"));

        self::assertTrue($tracker->enabled(MouseModeTracker::X10_TRACKING));
    }

    public function testNonCanonicalSplitKeepsTheParserCarrying(): void
    {
        $tracker = new MouseModeTracker();

        // A non-canonical private sequence cut at a chunk boundary: the
        // mid-param tail rides the pattern-side carry until it resolves,
        // then the fallback feeds the parser, which owns the fragment —
        // the ESC-less continuation must still be fed (old code fed all).
        self::assertFalse($tracker->observe("\x1b[? $100"));
        self::assertTrue($tracker->observe('6h'));

        // This parser does not dispatch intermediated params as DECSET.
        self::assertFalse($tracker->enabled(MouseModeTracker::SGR_COORDINATES));

        // Once closed, the fast path resumes.
        self::assertFalse($tracker->observe("\x1b[?2026h"));
        self::assertFalse($tracker->observe("\x1b[?1002h"));
        self::assertTrue($tracker->enabled(MouseModeTracker::CELL_MOTION));
    }

    public function testNonCanonicalPrivateFallsBackToTheParser(): void
    {
        $tracker = new MouseModeTracker();

        // `CSI ? <intermediate> <params> h` is non-canonical: the pattern
        // refuses to guess and hands the chunk to the state machine. This
        // parser does not dispatch it as DECSET (params after an
        // intermediate are dropped), and the fast path inherits exactly
        // that verdict — the assert pins the shared outcome, not a guess.
        self::assertTrue($tracker->observe("\x1b[? $1006h"));

        self::assertFalse($tracker->enabled(MouseModeTracker::SGR_COORDINATES));
    }

    public function testAbandonedPrivateIntroducerDoesNotLeakLaterModes(): void
    {
        $tracker = new MouseModeTracker();

        // CAN aborts the in-flight CSI; the continuation must not arm modes.
        $tracker->observe("\x1b[?100");
        $tracker->observe("\x1806h");

        self::assertFalse($tracker->enabled(MouseModeTracker::SGR_COORDINATES));
    }
}
