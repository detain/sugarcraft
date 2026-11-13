<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\CodeTime\Dashboard;
use SugarCraft\CodeTime\Heartbeat;
use SugarCraft\CodeTime\Renderer;
use SugarCraft\CodeTime\Stats;
use SugarCraft\CodeTime\Store;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;

/**
 * The dashboard's only contract with a terminal is that it fits the terminal.
 * RendererTest pins the CONTENT of a frame; nothing pinned its GEOMETRY, which
 * is how a timeline that measured two cells wider than the window, a
 * side-by-side join that fired six cells too early, and a weekday row that
 * shared no grid with its bars all shipped green.
 */
final class RendererGeometryTest extends TestCase
{
    private const END = '2024-06-07';

    /**
     * Three projects, two of them on the last day: enough to fill both ranking
     * panes with rows whose durations differ in width.
     *
     * @return list<Heartbeat>
     */
    private static function busy(): array
    {
        $end = new \DateTimeImmutable(self::END);
        return [
            new Heartbeat($end->getTimestamp(), 'demo-project-name-very-long', 'php', '/home/user/src/a.php', 600),
            new Heartbeat($end->getTimestamp(), 'second', 'typescript', '/home/user/src/b.ts', 3600),
            new Heartbeat($end->modify('-1 day')->getTimestamp(), 'alpha', 'php', '/home/user/src/c.php', 120),
        ];
    }

    /** @return list<Heartbeat> */
    private static function everyDay(): array
    {
        $beats = [];
        for ($i = 0; $i < 7; $i++) {
            $day = (new \DateTimeImmutable(self::END))->modify("-{$i} days");
            $beats[] = new Heartbeat($day->getTimestamp(), 'proj', 'php', 'a.php', 600 * ($i + 1));
        }
        return $beats;
    }

    /** @return list<Heartbeat> */
    private static function quiet(): array
    {
        $end = new \DateTimeImmutable(self::END);
        // Under a minute each, so every day rounds to zero recorded minutes.
        return [
            new Heartbeat($end->getTimestamp(), 'p', 'php', 'a.php', 30),
            new Heartbeat($end->modify('-3 days')->getTimestamp(), 'p', 'php', 'a.php', 20),
        ];
    }

    private static function frame(int $width, array $beats, int $days = 7): string
    {
        $end = new \DateTimeImmutable(self::END);
        $stats = Stats::compute($beats, $end->modify('-' . ($days - 1) . ' days'), $end);
        return Renderer::render(new Dashboard(new Store(sys_get_temp_dir()), $end, $days, $stats, $width));
    }

    /** @param list<Heartbeat> $beats @param list<\SugarCraft\CodeTime\Milestone> $milestones */
    private static function milestoneFrame(int $width, array $beats, array $milestones, int $days = 7): string
    {
        $end = new \DateTimeImmutable(self::END);
        $stats = Stats::compute($beats, $end->modify('-' . ($days - 1) . ' days'), $end, $milestones);
        return Renderer::render(new Dashboard(new Store(sys_get_temp_dir()), $end, $days, $stats, $width));
    }

    /** @return list<string> */
    private static function plain(string $frame): array
    {
        return array_map(static fn(string $line): string => Ansi::strip($line), preg_split('/\r?\n/', $frame) ?: []);
    }

    /**
     * The inside of a frame row, with the border and its padding column cut.
     * The bars/label rows are built to be exactly this wide. Splitting on the
     * border rather than slicing by bytes keeps the box-drawing character from
     * being cut in half (it is three bytes, not one cell).
     */
    private static function interior(string $row): string
    {
        $parts = preg_split('/│/u', $row);
        $body = $parts === false || count($parts) < 2 ? '' : $parts[1];
        // One padding cell on each side, both plain spaces.
        return strlen($body) >= 2 ? substr($body, 1, -1) : '';
    }

    /** @return array<string,array{0:list<Heartbeat>,1:int}> */
    public static function terminalSizes(): array
    {
        $rows = [];
        foreach ([10, 20, 28, 40, 52, 54, 56, 62, 80, 120, 160] as $width) {
            $rows['busy at ' . $width] = [self::busy(), $width];
            $rows['every day at ' . $width] = [self::everyDay(), $width];
            $rows['quiet at ' . $width] = [self::quiet(), $width];
        }
        return $rows;
    }

    #[DataProvider('terminalSizes')]
    public function testNoRowEverOutgrowsTheTerminal(array $beats, int $width): void
    {
        foreach (self::plain(self::frame($width, $beats)) as $index => $row) {
            $this->assertLessThanOrEqual(
                $width,
                Width::string($row),
                sprintf('row %d outgrew a %d-cell terminal: [%s]', $index, $width, $row),
            );
        }
    }

    public function testTheWidestFrameFillsTheTerminalExactly(): void
    {
        // The ranking panes are allowed to be narrower than the window; the
        // timeline is not, because it is sized FROM the window. A border row
        // wider than the terminal is the defect this pins: the frame used to be
        // two cells too wide at every size, so its right border was clipped off
        // and the box stopped being a box.
        foreach ([28, 40, 54, 62, 100] as $width) {
            $widest = 0;
            $borders = 0;
            foreach (self::plain(self::frame($width, self::busy())) as $row) {
                if (!str_starts_with($row, '╭') && !str_starts_with($row, '╰')) {
                    continue;
                }
                $borders++;
                $widest = max($widest, Width::string($row));
            }
            $this->assertGreaterThan(0, $borders, 'no frame border reached the test');
            $this->assertSame(
                $width,
                $widest,
                sprintf('widest frame border was %d cells in a %d-cell terminal', $widest, $width),
            );
        }
    }

    public function testBothRankingDurationsSurviveTheSideBySideJoin(): void
    {
        // The old row padded the name to 14 and then added a name-length-derived
        // gap, so the second pane's durations fell past the join and vanished.
        $out = Ansi::strip(self::frame(62, self::busy()));
        $this->assertStringContainsString('1h 00m', $out);
        $this->assertStringContainsString('0h 12m', $out, 'the right-hand pane lost its durations');
        $this->assertStringContainsString('0h 02m', $out);
    }

    public function testSideBySideStartsWhereTwoFramesActuallyFit(): void
    {
        $joined = static fn(int $width): bool => (bool) preg_grep(
            '/╮\s+╭/',
            self::plain(self::frame($width, self::busy())),
        );
        $this->assertFalse($joined(60), 'two 30-cell frames plus a gutter cannot fit 60 cells');
        $this->assertTrue($joined(62), 'the layout refuses to join a terminal wide enough to hold it');
    }

    public function testWeekdayLabelsShareTheBarsGrid(): void
    {
        [$bars, $marks] = self::timelineRows(self::frame(54, self::everyDay()));

        $this->assertSame(
            Width::string($bars),
            Width::string($marks),
            'the label row and the bar row are not one grid',
        );
        $this->assertSame(50, Width::string($bars), 'the timeline body should fill width - border - padding');

        // Seven days of activity have to start in the first day's own column.
        // The sparkline used to slide a 50-cell window over 7 points, leaving 43
        // blanks before any bar appeared, so the 'Mon' under a bar was luck.
        $this->assertSame(
            0,
            strspn($bars, ' '),
            'the first bar did not start in the first day column',
        );
    }

    public function testQuietWeekRendersNoSteadyBars(): void
    {
        [$bars] = self::timelineRows(self::frame(54, self::quiet()));

        // Sparkline maps a zero-range series onto its middle glyph, which read
        // as a flat week of steady work on an account that recorded nothing.
        $this->assertSame(
            '',
            trim($bars),
            'a week with no recorded minutes must render blank, not mid-height',
        );
    }

    public function testEarlierDaysAreDisclosedWhenTheyCannotBeDrawn(): void
    {
        // A 44-day window in a 40-cell terminal cannot give every day a column;
        // the days that fell off the left have to be counted, not skipped in
        // silence. The heading gives up '(minutes)' to make room for the count.
        $end = new \DateTimeImmutable(self::END);
        $out = Ansi::strip(self::frame(40, [
            new Heartbeat($end->getTimestamp(), 'proj', 'php', 'a.php', 600),
        ], 44));
        $this->assertMatchesRegularExpression('/\+8 earlier/', $out);
    }

    /**
     * Step 10 (M-3): CR and LF used to survive the clean() sweep, so a stored
     * name could forge a frame row or shift the rounded border. With the
     * extended sweep the evil-name frame keeps the clean-name row budget and
     * exactly one bottom border, and every row still fits the terminal.
     */
    public function testControlByteNamesForgeNoRowsAndShiftNoBorder(): void
    {
        $end = new \DateTimeImmutable(self::END);
        $evil = [
            new Heartbeat($end->getTimestamp(), "evil\nFAKE-ROW", 'php', 'a.php', 600),
            new Heartbeat($end->getTimestamp(), "cr\rinjected", 'php', 'b.php', 700),
        ];
        $clean = [
            new Heartbeat($end->getTimestamp(), 'evilFAKE-ROW', 'php', 'a.php', 600),
            new Heartbeat($end->getTimestamp(), 'crinjected', 'php', 'b.php', 700),
        ];

        $frame = self::frame(80, $evil);
        $this->assertSame(
            count(self::plain(self::frame(80, $clean))),
            count(self::plain($frame)),
            'an injected LF must not grow the frame',
        );
        $this->assertSame(
            substr_count(self::frame(80, $clean), '╰'),
            substr_count($frame, '╰'),
            'the set of rounded borders is untouched by name bytes',
        );
        foreach (self::plain($frame) as $line) {
            $this->assertLessThanOrEqual(80, Width::of($line), 'no row outgrows the terminal');
        }
    }

    /**
     * @return array{0:string,1:string} the bars row and the weekday row
     */
    public function testTheFrameHeightIsStructurallyBounded(): void
    {
        // The M18 no-reproduce was a measurement, not a pin. Both ranking
        // panes cap at six rows plus a more-line, so frame height saturates:
        // more projects cannot grow it. Measured truth: the side-by-side
        // layout fits a 24-row screen everywhere; the worst stacked layout
        // (40 cols) needs 27 — locked here so a future change that lets the
        // panes grow with row count goes red instead of scrolling.
        $end = new \DateTimeImmutable(self::END);
        $many = [];
        for ($i = 0; $i < 25; $i++) {
            $many[] = new Heartbeat(
                $end->getTimestamp(),
                'project-' . $i,
                'lang' . $i,
                '/home/user/p' . $i . '.php',
                600 * ($i + 1),
            );
        }
        $rowsAt = static function (int $width, array $beats): int {
            return count(explode("\n", rtrim(self::frame($width, $beats), "\n")));
        };
        $this->assertSame(
            $rowsAt(40, $many),
            $rowsAt(40, array_slice($many, 0, 9)),
            'row count must saturate, not grow with projects',
        );
        $this->assertLessThanOrEqual(27, $rowsAt(40, $many));
        $this->assertLessThanOrEqual(24, $rowsAt(62, $many), 'side-by-side must fit 24 rows');
        $this->assertLessThanOrEqual(24, $rowsAt(80, $many));
    }

    public function testTheFrameFitsTheScreenItWasSizedFor(): void
    {
        // M18: sugar-core paints the frame top-down, so a stacked frame
        // taller than the viewport scrolls its own header away. The height
        // budget from WindowSizeMsg must trim ranking entries until the
        // frame fits, and clip as the last resort.
        $render = static function (int $width, ?int $height, array $beats): string {
            $end = new \DateTimeImmutable(self::END);
            $stats = Stats::compute($beats, $end->modify('-6 days'), $end);
            return Renderer::render(new Dashboard(
                new Store(sys_get_temp_dir()), $end, 7, $stats, $width, null, $height
            ));
        };

        $packed = explode("\n", rtrim($render(40, 24, self::busy()), "\n"));
        $this->assertLessThanOrEqual(24, count($packed), 'a 24-row screen gets a 24-row frame');
        $this->assertStringContainsString('Top projects', implode("\n", $packed), 'the header survives a packed window');

        $tiny = explode("\n", rtrim($render(40, 10, self::busy()), "\n"));
        $this->assertLessThanOrEqual(10, count($tiny), 'an absurdly short screen is clipped, not scrolled');
        $this->assertStringStartsWith('CandyCodetime', ltrim(\SugarCraft\Core\Util\Ansi::strip($tiny[0])), 'the header keeps row 1 — the whole point of the ladder');

        // The busy() fixture fits a 24-row screen even with the ladder
        // neutered; only a SATURATED window (more projects than any cap)
        // forces the trim path. Without these legs, a clip-only renderer
        // that amputates the right pane passed every pin (R3 MAJOR-1).
        $deep = [];
        $t0 = (new \DateTimeImmutable(self::END))->getTimestamp();
        foreach (range(1, 25) as $i) {
            $deep[] = new Heartbeat($t0 + $i * 60, 'project-' . $i, 'lang-' . $i, '/a.php', 60);
        }
        $saturated = explode("\n", rtrim($render(40, 20, $deep), "\n"));
        $this->assertLessThanOrEqual(20, count($saturated), 'twenty-five projects and languages must fit a 20-row screen');
        foreach ($saturated as $row) {
            $plain = Ansi::strip($row);
            if (preg_match('/^[\x{256D}\x{2570}\x{2502}]/u', $plain) === 1) {
                $this->assertMatchesRegularExpression('/[\x{256E}\x{256F}\x{2502}]$/u', rtrim($plain),
                    'the ladder trims entries; it never amputates a pane mid-border');
            }
        }

        // A starved pane discloses what it hid — it never calls a busy
        // account idle (R3 NIT-1). The saturated frame stands 27 rows by
        // nature (15-row stacked floor + two rows per entry at MAX_ENTRIES
        // = 6): 19-20 rows affords two entries, 16 rows starves the ladder
        // to zero - only the honest +25-more disclosure survives - and a
        // 10-row budget falls through even the starved frame into the
        // side-by-side fallback.
        foreach ([16, 10] as $h) {
            $starved = Ansi::strip($render(40, $h, $deep));
            $this->assertStringNotContainsString('(no activity)', $starved, "a busy account is never labelled idle (h=$h)");
            $this->assertMatchesRegularExpression('/\+2\d more/u', $starved, "hidden rows are counted honestly (h=$h)");
        }
    }

    /**
     * CT-S01 (finding candy-codetime-001): the C1-sweep bypass and the
     * fail-open-by-erasure fallback. A U+009B name used to sail through the
     * `/u` C0-only class and run on the terminal as a CSI; one invalid byte
     * anywhere used to blank the ENTIRE name via `?? ''`. Both doors now ride
     * Sanitize::untrusted(): the C1 codepoint is gone and the invalid name
     * keeps its clean bytes.
     */
    public function testTheHeaderBadgesTheStoreItActuallyReads(): void
    {
        // CT-S07: with two stores and a per-invocation flag, "which one am
        // I looking at" is a first-class fact of the frame - never a guess.
        $end = new \DateTimeImmutable(self::END);
        $render = static function (?string $label) use ($end): string {
            return Renderer::render(new Dashboard(
                new Store(sys_get_temp_dir()), $end, 7, new Stats([], []), 62, null, null, $label,
            ));
        };
        $this->assertStringContainsString('[sqlite]', $render('sqlite'));
        $this->assertStringContainsString('[file]', $render('file'));
        // No label (library callers, examples) paints no badge at all.
        $this->assertStringNotContainsString('[sqlite]', $render(null));
    }

    public function testTheHeaderPaintsTheSelectedWindowSpan(): void
    {
        // CT-S06: the range is derived from the Model's days, not a baked-in
        // week — a 30-day window has to say so at the top of the frame,
        // because a viewer who cannot see the rows must still see the range.
        $end = new \DateTimeImmutable(self::END);
        $monthAgo = $end->modify('-29 days')->format('M j');
        $thirty = self::frame(62, self::busy(), 30);
        $this->assertStringContainsString($monthAgo, $thirty);
        $this->assertStringContainsString($end->format('M j'), $thirty);

        $week = self::frame(62, self::busy(), 7);
        $this->assertStringNotContainsString($monthAgo, $week);
    }

    public function testC1NamesAreSweptAndInvalidUtf8NamesAreNotBlanked(): void
    {
        $end = new \DateTimeImmutable(self::END);

        $c1 = self::frame(80, [
            new Heartbeat($end->getTimestamp(), "pr\u{9B}oj", 'php', 'a.php', 600),
        ]);
        $this->assertStringNotContainsString("\u{9B}", $c1, 'a stored CSI must not reach the terminal');
        $this->assertStringNotContainsString("\xC2\x9B", $c1, 'the UTF-8 encoding of U+009B must not survive');
        $this->assertStringContainsString('proj', $c1, 'the clean bytes around the C1 are kept');

        $invalid = self::frame(80, [
            new Heartbeat($end->getTimestamp(), "bad\xffname", 'php', 'b.php', 700),
        ]);
        $this->assertStringContainsString('bad', $invalid, 'one invalid byte must not blank the name');
        $this->assertStringContainsString('name', $invalid);
        $cleanTwin = self::frame(80, [
            new Heartbeat($end->getTimestamp(), 'badname', 'php', 'b.php', 700),
        ]);
        $this->assertCount(
            count(self::plain($cleanTwin)),
            self::plain($invalid),
            'an invalid byte must not grow or shrink the frame against its clean twin',
        );
    }

    /**
     * CT-S09 (finding candy-codetime-008): the Trojan Source door. A zero-width
     * space makes two different names look identical on screen, and U+202E
     * repaints the rest of the row reversed — neither may survive into the
     * frame invisible. clean() now rides Sanitize::untrustedForDisplay(), so
     * both land as visible `<U+XXXX>` markers and visually-cloned names paint
     * as different bytes.
     */
    public function testInvisibleFormattingIsMarkedNotPainted(): void
    {
        $end = new \DateTimeImmutable(self::END);

        $spoof = self::frame(80, [
            new Heartbeat($end->getTimestamp(), "proj\u{200B}B", 'php', 'a.php', 600),
        ]);
        $plainTwin = self::frame(80, [
            new Heartbeat($end->getTimestamp(), 'projB', 'php', 'a.php', 600),
        ]);
        $this->assertStringContainsString('<U+200B>', $spoof, 'a zero-width space must paint as a marker');
        $this->assertNotSame($plainTwin, $spoof, 'visually-cloned names must not land byte-identical');

        $rtl = self::frame(80, [
            new Heartbeat($end->getTimestamp(), "repo\u{202E}eviler", 'php', 'b.php', 700),
        ]);
        $this->assertStringNotContainsString("\u{202E}", $rtl, 'a stored RTL override must never reach the terminal');
        $this->assertStringContainsString('<U+202E>', $rtl, 'the override paints as its marker');
    }

    private static function timelineRows(string $frame): array
    {
        $rows = self::plain($frame);
        foreach ($rows as $index => $row) {
            if (str_contains($row, 'Daily activity')) {
                return [
                    self::interior($rows[$index + 1] ?? ''),
                    self::interior($rows[$index + 2] ?? ''),
                ];
            }
        }
        $this->fail('no timeline heading row in the frame');
    }

    /**
     * CT-S13 acceptance: under a German locale every chrome label is the
     * translation, none is the English original, and the translated rows
     * (German is the longest of the 16 catalogues) still obey the one rule
     * the whole file exists for - no row outgrows the terminal.
     */
    /**
     * CT-S08: a milestone inside the window earns a '*' row under the weekday
     * labels - one day slot wide like the bars it sits under - and a frame
     * without milestones keeps the old three-row shape byte for byte.
     */
    public function testMilestoneInsideTheWindowGainsAMarkRowAndFramesWithoutOneAreUnchanged(): void
    {
        $end = new \DateTimeImmutable(self::END);
        $with = self::milestoneFrame(62, self::busy(), [new \SugarCraft\CodeTime\Milestone('shipped', $end->getTimestamp())]);
        $without = self::frame(62, self::busy());
        self::assertStringContainsString('*', $with);
        self::assertStringNotContainsString('*', $without);
        self::assertSame(count(explode("\n", rtrim($without, "\n"))) + 1, count(explode("\n", rtrim($with, "\n"))), 'mark row is exactly one row');
        // The mark lands under its own day (the newest, rightmost slot).
        $rows = self::plain($with);
        $markRow = (string) end($rows);
        // trim trailing padding of the frame border away: find '*' inside the last inner row
        self::assertMatchesRegularExpression('/\* +│$/m', Ansi::strip($with), 'mark sits at its own day slot, right of newest label');
        // Milestones outside the window must not add a row at all.
        $old = self::milestoneFrame(62, self::busy(), [new \SugarCraft\CodeTime\Milestone('ancient', $end->modify('-400 days')->getTimestamp())]);
        self::assertSame($without, $old, 'out-of-window milestone stays silent');
        foreach (self::plain($with) as $line) {
            self::assertLessThanOrEqual(62, Width::string($line));
        }
    }

    public function testGermanLocalePaintsTranslatedPanesInsideTheFrame(): void
    {
        $previous = T::locale();
        T::setLocale('de');
        try {
            $frame = self::frame(62, self::busy());
            foreach (['Top-Projekte', 'Nach Sprache', 'Tägliche Aktivität', 'gesamt:'] as $needle) {
                self::assertStringContainsString($needle, $frame, 'German pane missing: ' . $needle);
            }
            foreach (['Top projects', 'By language', 'Daily activity', 'total:'] as $english) {
                self::assertStringNotContainsString($english, $frame, 'English chrome survived: ' . $english);
            }
            // The German help row needs 67 cells; at 62 the frame trades it
            // for the key-only variant instead of clipping words away. A
            // window wide enough gets the translated full row back.
            self::assertStringContainsString('q/Esc  quit', $frame, 'compact help fallback missing at 62');
            $wide = self::frame(80, self::busy());
            self::assertStringContainsString('beenden', $wide, 'full translated help row missing at 80');
            foreach (self::plain($frame) as $line) {
                self::assertLessThanOrEqual(62, Width::string($line), 'row overflows under de: ' . $line);
            }
        } finally {
            T::setLocale($previous);
        }
    }

    /** @param list<\SugarCraft\CodeTime\Heartbeat> $beats */
    private static function tagFrame(int $width, array $beats, bool $byTags, int $days = 7): string
    {
        $end = new \DateTimeImmutable(self::END);
        $stats = Stats::compute($beats, $end->modify('-' . ($days - 1) . ' days'), $end);
        return Renderer::render(new Dashboard(
            new Store(sys_get_temp_dir()), $end, $days, $stats, $width,
            null, null, null, 0, 0, null, $byTags
        ));
    }

    public function testTheTagToggleSwapsTheLowerRankingPane(): void
    {
        $beats = [
            Heartbeat::fromArray(['time' => strtotime('2024-06-06 10:00 UTC'), 'project' => 'alpha', 'language' => 'php', 'file' => '/x/a.php', 'duration' => 3600, 'tags' => ['work']]),
            Heartbeat::fromArray(['time' => strtotime('2024-06-06 12:00 UTC'), 'project' => 'alpha', 'language' => 'js', 'file' => '/x/b.js', 'duration' => 1800, 'tags' => ['side project']]),
        ];

        $language = self::plain(self::tagFrame(62, $beats, false));
        self::assertStringContainsString('By language', implode("\n", $language));
        self::assertStringNotContainsString('By tag', implode("\n", $language));

        $tagged = self::plain(self::tagFrame(62, $beats, true));
        $joined = implode("\n", $tagged);
        self::assertStringContainsString('By tag', $joined);
        self::assertStringNotContainsString('By language', $joined);
        // The fold rows themselves: both labels ranked, languages gone.
        self::assertStringContainsString('work', $joined);
        self::assertStringContainsString('side project', $joined);
        self::assertStringNotContainsString('php', $joined);

        // The frame budget survives the toggle at a width that forces stacking.
        foreach (self::plain(self::tagFrame(40, $beats, true)) as $line) {
            self::assertLessThanOrEqual(40, Width::string($line));
        }
        // Untagged history with the toggle on answers with the empty note, not
        // a broken frame.
        $empty = self::plain(self::tagFrame(62, [], true));
        self::assertStringContainsString('(no activity)', implode("\n", $empty));
    }

    /** @param list<Heartbeat> $beats */
    private static function goalFrame(int $width, array $beats, ?int $goal, string $end = self::END, int $days = 7, bool $byTags = false): string
    {
        $endDay = new \DateTimeImmutable($end);
        $stats = Stats::compute($beats, $endDay->modify('-' . ($days - 1) . ' days'), $endDay);
        return Renderer::render(new Dashboard(
            new Store(sys_get_temp_dir()), $endDay, $days, $stats, $width,
            null, null, null, 0, 0, null, $byTags, $goal
        ));
    }

    public function testTheGoalCellRidesTheHeaderAndReachedDaysFlagTheBand(): void
    {
        $now = new \DateTimeImmutable('today 09:00');
        $beats = [
            new Heartbeat($now->getTimestamp(), 'proj', 'php', 'a.php', 7290),
            new Heartbeat($now->modify('-1 day')->getTimestamp(), 'proj', 'php', 'a.php', 1800),
        ];
        $plain = self::plain(self::goalFrame(80, $beats, null, 'today'));
        $with = self::plain(self::goalFrame(80, $beats, 3600, 'today'));

        $plainText = implode("\n", $plain);
        self::assertStringNotContainsString(' / 1h00', $plainText);
        self::assertStringNotContainsString(chr(226) . chr(150) . chr(147), $plainText);

        $withText = implode("\n", $with);
        self::assertStringContainsString('today 2h01 / 1h00', $withText);
        self::assertStringContainsString(chr(226) . chr(150) . chr(147), $withText);

        // Presentation rides the existing buckets: no row is added, and only
        // the header cell and the one reached day's glyph move.
        self::assertCount(count($plain), $with);
        $diffs = [];
        foreach ($plain as $index => $row) {
            if ($row !== $with[$index]) {
                $diffs[] = $index;
            }
        }
        self::assertSame(2, count($diffs), 'goal must only move the header and the bars row, saw ' . json_encode($diffs));
        self::assertSame(0, $diffs[0]);

        // The band flag is width-independent: even the minimum frame shows
        // which days reached the goal, whatever the header can afford.
        $narrow = implode("\n", self::plain(self::goalFrame(62, $beats, 3600, 'today')));
        self::assertStringContainsString(chr(226) . chr(150) . chr(147), $narrow);
        foreach (self::plain(self::goalFrame(62, $beats, 3600, 'today')) as $line) {
            self::assertLessThanOrEqual(62, Width::string($line));
        }
    }

    public function testAGoalWindowThatExcludesTodayPaintsTheDashNotAFabricatedZero(): void
    {
        $joined = implode("\n", self::plain(self::goalFrame(80, self::busy(), 3600)));
        self::assertStringContainsString('today ' . chr(226) . chr(128) . chr(148) . ' / 1h00', $joined);
        self::assertStringNotContainsString('today 0h00', $joined);
    }

    public function testTheSideBySideJoinKeepsTheChosenPane(): void
    {
        $end = new \DateTimeImmutable(self::END);
        $stats = Stats::compute(self::everyDay(), $end->modify('-6 days'), $end);
        // Height 6 forces the side-by-side join over a full seven-day
        // timeline; the rebuild must carry the `t` flip (CT-S31 rider).
        $joined = implode("\n", self::plain(Renderer::render(new Dashboard(
            new Store(sys_get_temp_dir()), $end, 7, $stats, 62, null, 6, null, 0, 0, null, true, null
        ))));
        self::assertStringContainsString('By tag', $joined);
        self::assertStringNotContainsString('By language', $joined);
    }

    /**
     * CT-S34 acceptance: a named palette repaints the SAME frame. Colour
     * sequences move; geometry does not — strip every SGR and the light and
     * default renders are byte-identical.
     */
    public function testANamedPaletteRepaintsWithoutMovingAFrameByte(): void
    {
        $endDay = new \DateTimeImmutable(self::END);
        $stats = \SugarCraft\CodeTime\Stats::compute(self::busy(), $endDay->modify('-6 days'), $endDay);
        $render = static function (bool $light) use ($endDay, $stats): string {
            return \SugarCraft\CodeTime\Renderer::render(new \SugarCraft\CodeTime\Dashboard(
                new \SugarCraft\CodeTime\Store(sys_get_temp_dir()), $endDay, 7, $stats, 80,
                \SugarCraft\Core\Util\ColorProfile::TrueColor, null, 'file', 0, 0, null, false, null,
                $light ? \SugarCraft\CodeTime\Theme::named('light') : \SugarCraft\CodeTime\Theme::default(),
            ));
        };

        $default = $render(false);
        $light = $render(true);

        // #c2185b title belongs to light alone.
        $this->assertStringContainsString("\x1b[38;2;194;24;91m", $light);
        $this->assertStringNotContainsString("\x1b[38;2;194;24;91m", $default);
        $strip = static fn (string $view): string => (string) preg_replace('/\x1b\[[0-9;]*m/', '', $view);
        $this->assertSame($strip($default), $strip($light));
    }
}
