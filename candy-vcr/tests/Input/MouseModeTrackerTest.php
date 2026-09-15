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
}
