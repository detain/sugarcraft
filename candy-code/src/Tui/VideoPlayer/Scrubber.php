<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\VideoPlayer;

/**
 * The glyph timeline under the video (crush-media W5.4) — a port of the
 * phlix console client's `src/Ui/Scrubber.php` idiom to crush's screen.
 *
 * Pure and deterministic: position glyphs, no animation, no color, so it
 * stays NO_COLOR-safe and byte-testable. The head marker rides a ladder of
 * shade glyphs across the played span and the unfilled track keeps a single
 * light shade, which reads as a progress bar in every font that carries
 * the block elements — the same "shapes before colors" law the meter
 * primitives in this repo follow.
 */
final class Scrubber
{
    /** The unfilled track. */
    public const TRACK = '░';

    /** The played span behind the head. */
    public const PASSED = '▒';

    /** The playhead itself. */
    public const HEAD = '◆';

    /** The track shrinks to nothing below this width; the timecodes hold. */
    public const MIN_WIDTH = 12;

    private function __construct()
    {
    }

    /**
     * Render one chrome row: `0:04 ▒▒▒◆░░░░░░ 0:12` fitted to $width cells.
     *
     * A non-positive or unparseable duration renders an empty track with
     * both timecodes at 0:00 — an unprobeable clip still gets a stable,
     * honest row rather than a division by zero.
     */
    public static function render(float $position, float $duration, int $width): string
    {
        if ($width < self::MIN_WIDTH) {
            return '';
        }

        $elapsed = self::formatTime($position);
        $total = self::formatTime($duration > 0.0 ? $duration : 0.0);

        // 'elapsed' + space + track + space + 'total'
        $track = max(1, $width - strlen($elapsed) - strlen($total) - 2);
        $ratio = $duration > 0.0 ? min(1.0, max(0.0, $position / $duration)) : 0.0;
        $head = min($track - 1, max(0, (int) floor($ratio * ($track - 1))));

        return $elapsed
            . ' '
            . str_repeat(self::PASSED, $head)
            . self::HEAD
            . str_repeat(self::TRACK, $track - 1 - $head)
            . ' '
            . $total;
    }

    /**
     * `m:ss` under an hour, `h:mm:ss` beyond it — truncating, never
     * rounding up, so the row never claims a second the play has not
     * reached. Negative clocks read as zero.
     */
    public static function formatTime(float $seconds): string
    {
        $whole = $seconds > 0.0 ? (int) $seconds : 0;

        if ($whole < 3600) {
            return sprintf('%d:%02d', intdiv($whole, 60), $whole % 60);
        }

        return sprintf('%d:%02d:%02d', intdiv($whole, 3600), intdiv($whole % 3600, 60), $whole % 60);
    }
}
