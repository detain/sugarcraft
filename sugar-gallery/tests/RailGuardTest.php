<?php

declare(strict_types=1);

namespace SugarCraft\Gallery\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\Util\Width;
use SugarCraft\Gallery\PosterCard;
use SugarCraft\Gallery\PosterGrid;
use SugarCraft\Gallery\Rail;

/**
 * The Rail heading's trust boundary + width law (docs-review-1023 M4/m3).
 *
 * Before the fix, {@see Rail::render()} emitted $this->title VERBATIM into
 * the frame while PosterCard's plain-title path ran the identical bytes
 * through AnsiGuard::stripControls() — a title from a server response could
 * move the user's cursor, clear the screen, or smuggle an OSC-8 hyperlink
 * through the rail heading and nowhere else. Now both header lines are
 * stripped with the lib's own scanner and clamped to $railWidth, and the
 * empty-state line is a translation lookup, proving lang/ is live (M2/M3).
 */
final class RailGuardTest extends TestCase
{
    protected function setUp(): void
    {
        // Hermetic: the empty-state line now reads the T registry, so pin the
        // assertion locale instead of inheriting the runner's environment.
        T::setLocale('en');
    }

    protected function tearDown(): void
    {
        T::reset();
    }

    /** @return list<PosterCard> */
    private function cards(int $n): array
    {
        $cards = [];
        for ($i = 0; $i < $n; $i++) {
            $cards[] = new PosterCard((string) $i, 'Card ' . $i);
        }

        return $cards;
    }

    public function testAForgedTitleCannotEscapeTheHeading(): void
    {
        $title = "Am\x1b]8;;http://evil.example\x07LINK\x1b[2J\x1b[10;1H\x1b[31mred?\x9b";

        $frame = (new Rail($title, $this->cards(3)))->render(60, true, 10, 2);

        // Every escape byte, the OSC terminator, and the raw C1 control are gone.
        self::assertStringNotContainsString("\x1b", $frame, 'no ESC may reach the terminal from a title');
        self::assertStringNotContainsString("\x07", $frame, 'no bare BEL may reach the terminal');
        self::assertStringNotContainsString("\x9b", $frame, 'no raw C1 may reach the terminal');
        // The OSC-8 payload dies with its sequence; the escape hatch never opens.
        self::assertStringNotContainsString('evil.example', $frame);
        // The harmless visible text survives (it is text, not a control).
        self::assertStringContainsString('Am', $frame);
        self::assertStringContainsString('LINK', $frame);
        self::assertStringContainsString('red?', $frame);
    }

    public function testUnfocusedHeadingIsGuardedToo(): void
    {
        $frame = (new Rail("T\x1b[2J!", []))->render(40, false, 10, 2);
        self::assertStringNotContainsString("\x1b", $frame);
    }

    public function testAnOverLongTitleIsClampedToRailWidth(): void
    {
        $railWidth = 12;
        $frame = (new Rail(str_repeat('x', 200), $this->cards(3)))->render($railWidth, true, 6, 2);

        $lines = explode("\n", $frame);
        self::assertLessThanOrEqual($railWidth, Width::string($lines[0]), 'heading cannot exceed the rail');
        // Dated flip (2026-11-03 review M3): this used to assert the line
        // ENDS with '…' — which codified the defect. One whole-line clamp
        // killed the position/total counter before the title ever yielded.
        // The counter is now reserved first, so the ellipsis lands mid-line
        // and '(1/3)' survives whole inside the same width budget.
        self::assertStringContainsString('…', $lines[0], 'the title still yields with an ellipsis');
        self::assertStringEndsWith('(1/3)', $lines[0], 'the counter is data, not decoration');

        // The empty-state line obeys the same budget.
        $emptyFrame = (new Rail(str_repeat('x', 200), []))->render($railWidth, true, 6, 2);
        foreach (explode("\n", $emptyFrame) as $line) {
            self::assertLessThanOrEqual($railWidth, Width::string($line));
        }
    }

    public function testThePositionCounterSurvivesANarrowRailAndALyingRestore(): void
    {
        // M3: even a long title at a narrow rail keeps the counter tail.
        $frame = (new Rail('Movies Forever And Ever', $this->cards(3)))->render(14, true, 6, 2);
        $line = explode("\n", $frame)[0];
        self::assertStringEndsWith('(1/3)', $line);
        self::assertLessThanOrEqual(14, Width::string($line));

        // m10 twin: the taught restore path CLAMPS instead of printing lies
        // — a stale (999, 0) pair used to render "(1000/2)" over a phantom
        // empty body row because array_slice walked past the list.
        $stale = (new Rail('R', $this->cards(2), 999, 0))->render(40, true, 10, 2);
        $staleLines = explode("\n", $stale);
        self::assertStringEndsWith('(2/2)', rtrim($staleLines[0]));
        self::assertStringNotContainsString('(1000/2)', $stale);
        self::assertStringContainsString('Card 0', $stale, 'the window lands on real cards, not a phantom row');

        $negative = new Rail('R', $this->cards(3), -5, -3);
        self::assertSame(0, $negative->cursor);
        self::assertSame(0, $negative->scroll);
    }

    public function testWideTitlesCountVisibleCellsNotBytes(): void
    {
        $frame = (new Rail(str_repeat('標', 30), $this->cards(2)))->render(10, true, 6, 2);
        $line = explode("\n", $frame)[0];
        self::assertLessThanOrEqual(10, Width::string($line), 'CJK title clamps by CELLS');
    }

    public function testTheEmptyStateIsLocalized(): void
    {
        T::setLocale('de');
        $frame = (new Rail('R', []))->render(40, true, 10, 2);
        self::assertStringContainsString('(keine Einträge)', $frame, 'rail.empty must resolve through the locale');

        T::setLocale('ja');
        $frame = (new Rail('R', []))->render(40, true, 10, 2);
        self::assertStringContainsString('(項目なし)', $frame);
    }

    public function testTheEnglishEmptyStateKeepsItsTwoSpaceIndent(): void
    {
        $frame = (new Rail('R', []))->render(40, true, 10, 2);
        $lines = explode("\n", $frame);
        self::assertStringStartsWith('  ', $lines[1], 'indent is structural, outside the translated value');
        self::assertStringContainsString('(no items)', $lines[1]);
    }

    public function testTheAmbiguousWidthRosterInTheReadmeMatchesTheRealGlyphs(): void
    {
        // m2: the README once taught `▸` and `░` as Ambiguous (they are
        // neutral in every source) while omitting the two glyphs that
        // genuinely are — the progress fill and the truncation ellipsis.
        // The roster is quoted by exact name here, so editing one side
        // without the other goes red. Each glyph now rides its codepoint,
        // because `▸` U+25B8 (neutral) is confusable with `▶` U+25B6
        // (ambiguous) at a glance.
        $readme = (string) file_get_contents(__DIR__ . '/../README.md');

        self::assertStringContainsString('`●` `U+25CF` and `○` `U+25CB`', $readme);
        self::assertStringContainsString('`▓` `U+2593` (the filled leg of the progress bar) and `…`', $readme);
        self::assertStringContainsString('`U+2026` (truncation ellipsis)', $readme);
        self::assertStringContainsString('the focus marker is `▸`', $readme);
        self::assertStringContainsString('not the ambiguous large `▶`', $readme);
        self::assertStringContainsString('is `░` `U+2591`.)', $readme);
        self::assertStringNotContainsString('`●`, `○`, `▸` and `░` are', $readme);
    }

    public function testEveryLocalisedNoticeResolvesUnderAForcedLocale(): void
    {
        // n18 (rail.empty's test above only covered that one key): the
        // failed-card notice and the empty-grid message must switch with the
        // locale too, or the shipped translations are dead weight.
        T::setLocale('de');
        $failed = explode("\n", (new PosterCard('1', 'X'))->withFailed()->render(false, 30, 1));
        self::assertSame('(nicht verfügbar)', rtrim($failed[0]));
        self::assertSame('(keine Poster)', PosterGrid::emptyMessage());

        T::setLocale('ja');
        self::assertSame('(利用不可)', rtrim(explode("\n", (new PosterCard('1', 'X'))->withFailed()->render(false, 30, 1))[0]));
        self::assertSame('(ポスターなし)', PosterGrid::emptyMessage());
    }
}
