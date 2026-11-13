<?php

declare(strict_types=1);

namespace SugarCraft\DatePicker\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\DatePicker\DatePicker;
use SugarCraft\Vterm\Cell;
use SugarCraft\Vterm\Terminal\Terminal;

/**
 * Structural cell-grid proof (sugar-datepicker-041): the picker's byte
 * stream is fed through the INDEPENDENT sugar-vterm terminal emulator and
 * the resulting SCREEN cells are asserted — cursor reverse-video, faint
 * out-of-bounds composition (dp-01), today/selected renditions.
 *
 * Byte goldens (GoldenRenderTest) prove nothing changed; this test proves
 * the emitted SGR MEANS what the styling section claims, as interpreted by
 * a second, unrelated parser lineage rather than the picker's own
 * cellbuffer sibling.
 *
 * The golden frames are skipped when sugar-vterm is not autoloadable on
 * the runner (same require-dev doctrine as sugar-pty's vim smoke).
 */
final class CellGridTest extends TestCase
{
    /** vterm renders with descending-LF; the picker emits bare \n rows. */
    private static function feedFrame(DatePicker $dp): Terminal
    {
        $terminal = new Terminal(21, 9);
        $terminal->feed(str_replace("\n", "\r\n", $dp->view()));
        $terminal->flush();

        return $terminal;
    }

    /** May 2026: firstDow Friday (offset 5) — day N sits at grid index N+4. */
    private static function mayFrame(): DatePicker
    {
        return DatePicker::new(new \DateTimeImmutable('2026-05-15'))
            ->withToday(new \DateTimeImmutable('2026-05-05'))
            ->withMinDate(new \DateTimeImmutable('2026-05-10'))
            ->selectDate();
    }

    /** Grid column where the day number starts inside its 3-char cell. */
    private static function dayCol(int $day): int
    {
        $idx = $day + 4;

        return ($idx % 7) * 3 + ($day < 10 ? 1 : 0);
    }

    /** The leading cell of May $day's number (space for 1-digit, tens for 2-digit). */
    private static function cell(Terminal $terminal, int $day): Cell
    {
        $idx = $day + 4;

        return $terminal->screen()->cell(3 + intdiv($idx, 7), self::dayCol($day));
    }

    /** Both glyph columns of the day, trimmed — reads as the day number. */
    private static function dayText(Terminal $terminal, int $day): string
    {
        $idx = $day + 4;
        $row = 3 + intdiv($idx, 7);
        $col = ($idx % 7) * 3;
        $screen = $terminal->screen();

        return trim($screen->cell($row, $col)->char . $screen->cell($row, $col + 1)->char);
    }

    public function testTerminalOracleIsAvailableOnThisRunner(): void
    {
        if (!class_exists(Terminal::class)) {
            $this->markTestSkipped('SugarCraft\\Vterm\\Terminal\\Terminal not autoloadable (sugar-vterm require-dev missing on this runner).');
        }
        $this->assertTrue(true);
    }

    public function testCalendarLandsOnTheExpectedCells(): void
    {
        if (!class_exists(Terminal::class)) {
            $this->markTestSkipped('sugar-vterm missing');
        }
        $screen = self::feedFrame(self::mayFrame())->screen();

        $this->assertSame('May 2026', trim($screen->cell(0, 0)->char . $screen->cell(0, 1)->char . $screen->cell(0, 2)->char . $screen->cell(0, 3)->char . $screen->cell(0, 4)->char . $screen->cell(0, 5)->char . $screen->cell(0, 6)->char . $screen->cell(0, 7)->char));
        $frame = self::feedFrame(self::mayFrame());
        $this->assertSame('15', self::dayText($frame, 15));
        $this->assertSame('5', self::dayText($frame, 5));
        // Lead-in blanks of the first row carry no day digit.
        $this->assertSame(' ', $screen->cell(3, 0)->char);
    }

    public function testCursorCellCarriesReverseVideo(): void
    {
        if (!class_exists(Terminal::class)) {
            $this->markTestSkipped('sugar-vterm missing');
        }
        // The cursor day sits at its grid cell with SGR 7 interpreted as the
        // emulator's reverse flag — attribute semantics, not byte presence.
        $cell = self::cell(self::feedFrame(self::mayFrame()), 15);

        $this->assertNotNull($cell->sgr);
        $this->assertTrue($cell->sgr->reverse, 'cursor cell must be reverse-video in the emulated grid');
        $this->assertTrue($cell->sgr->bold, 'cursor cell also carries the selected bold');
        $this->assertSame(3, $cell->sgr->foreground?->kind, 'truecolour foreground from the selected style');
        $this->assertSame(0x00CDCD, $cell->sgr->foreground?->value, 'rgb(0,205,205) = 0x00CDCD resolved by the emulator');
    }

    public function testOutOfBoundsDaysAreFaintInTheGrid(): void
    {
        if (!class_exists(Terminal::class)) {
            $this->markTestSkipped('sugar-vterm missing');
        }
        // dp-01 composition, structurally: a plain out-of-bounds day carries
        // the dim (SGR 2) rendition and no colour…
        $plain = self::cell(self::feedFrame(self::mayFrame()), 3);
        $this->assertNotNull($plain->sgr);
        $this->assertTrue($plain->sgr->dim, 'out-of-bounds day must read faint on the grid');
        $this->assertNull($plain->sgr->foreground, 'no colour beyond the faint flag');

        // …while out-of-bounds TODAY composes faint ON TOP of the today cue
        // (dual signal), exactly as dp-01 promises.
        $today = self::cell(self::feedFrame(self::mayFrame()), 5);
        $this->assertNotNull($today->sgr);
        $this->assertTrue($today->sgr->dim && $today->sgr->bold, 'disabled today keeps bold AND gains faint');
        $this->assertNotNull($today->sgr->foreground, 'the green today channel survives composition');
        $this->assertSame(0x00CD00, $today->sgr->foreground?->value, 'rgb(0,205,0)');
    }

    public function testInBoundsPlainDaysCarryNoRendition(): void
    {
        if (!class_exists(Terminal::class)) {
            $this->markTestSkipped('sugar-vterm missing');
        }
        // Negative control: a selectable, unstyled day must NOT be dim —
        // guards against a renderer that faints everything.
        $cell = self::cell(self::feedFrame(self::mayFrame()), 12);
        $this->assertFalse(($cell->sgr?->dim ?? false), 'in-bounds plain day is not faint');
    }
}
