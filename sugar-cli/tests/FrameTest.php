<?php

declare(strict_types=1);

namespace SugarCraft\Cli\Tests;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Cli\Frame;
use SugarCraft\Style\Style;
use PHPUnit\Framework\TestCase;

/**
 * @see Frame
 */
final class FrameTest extends TestCase
{
    /**
     * The frame must fill the terminal EXACTLY: every line is `$cols` display
     * cells and the whole frame is `$rows` lines. This is the load-bearing
     * frame-diff invariant.
     *
     * @dataProvider sizes
     */
    public function testFillsTerminalExactly(int $cols, int $rows): void
    {
        $out = Frame::new()
            ->withTitle('SugarSQL')
            ->withStatus('q:quit')
            ->render("line one\nline two", $cols, $rows);

        $lines = explode("\n", $out);
        self::assertCount($rows, $lines, "frame must be exactly $rows lines");
        foreach ($lines as $i => $line) {
            self::assertSame($cols, Width::string($line), "line $i must be exactly $cols cells");
        }
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function sizes(): iterable
    {
        yield '80x24'  => [80, 24];
        yield '100x48' => [100, 48];
        yield '120x40' => [120, 40];
    }

    /**
     * Fix wave: widths under the 4-cell floor (two border caps + one content
     * cell minimum) used to OVERFLOW — a 1-col frame emitted a 2-cell rule,
     * a 2-col frame a 3-cell title row — forcing terminal wraps that desync
     * the diff renderer. render() now clamps $cols up to 4, so every emitted
     * line is exactly 4 cells for any cols in 1..4 (below the floor the rows
     * wrap — terminals autowrap and nothing stays one line at 1-3 columns;
     * the clamp keeps the wrap uniform).
     *
     * @dataProvider tinyCols
     */
    public function testTinyColsClampToFourCellFloor(int $cols): void
    {
        $out = Frame::new()
            ->withTitle('T')
            ->withStatus('S')
            ->render('body', $cols, 8);

        $lines = explode("\n", $out);
        self::assertCount(8, $lines, 'row contract holds unchanged under the clamp');
        foreach ($lines as $i => $line) {
            self::assertSame(
                4,
                Width::string($line),
                "cols={$cols}: line $i must be exactly the clamped 4-cell floor",
            );
        }
    }

    /** @return iterable<string, array{int}> */
    public static function tinyCols(): iterable
    {
        yield 'cols=1' => [1];
        yield 'cols=2' => [2];
        yield 'cols=3' => [3];
        yield 'cols=4' => [4];
    }

    /**
     * Line count stays constant (= $rows) regardless of how much body content
     * is supplied — empty, a few lines, or far more than fits.
     *
     * @dataProvider bodies
     */
    public function testConstantLineCount(string $body): void
    {
        $out = Frame::new()->render($body, 80, 24);
        self::assertCount(24, explode("\n", $out));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bodies(): iterable
    {
        yield 'empty'     => [''];
        yield 'one line'  => ['just one'];
        yield 'few lines' => ["a\nb\nc"];
        yield 'over-tall' => [implode("\n", array_fill(0, 100, 'row'))];
    }

    /** A body taller than the content area is hard-truncated, never overflows. */
    public function testOverTallBodyHardTruncates(): void
    {
        $body = implode("\n", array_map(static fn (int $i): string => "row$i", range(0, 99)));
        $out = Frame::new()->render($body, 80, 24);
        $lines = explode("\n", $out);

        self::assertCount(24, $lines);
        // Untitled frame (SC-F3/049): chrome is 5 rows, so 19 content rows —
        // rows 0..18 fit (18 with the drop-signal ellipsis), row19+ dropped.
        self::assertStringContainsString('row18', $out);
        self::assertStringContainsString('row17', $out);
        self::assertStringNotContainsString('row19', $out);
        self::assertStringNotContainsString('row99', $out);
    }

    /** The bounded explode keeps multi-KB bodies correct, not just cheap. */
    public function testManyKilobyteBodyStillTruncatesToTheFrame(): void
    {
        $body = implode("\n", array_map(static fn (int $i): string => "row$i", range(0, 4999)));
        $out  = Frame::new()->render($body, 80, 24);

        self::assertCount(24, explode("\n", $out));
        self::assertStringContainsString('row18', $out);
        self::assertStringNotContainsString('row19', $out);
        self::assertStringNotContainsString('row4999', $out);
    }

    /** A body line wider than the terminal is truncated with an ellipsis. */
    public function testOverWideLineTruncatesWithEllipsis(): void
    {
        $out = Frame::new()->render(str_repeat('x', 500), 80, 24);

        self::assertStringContainsString('…', $out);
        foreach (explode("\n", $out) as $line) {
            self::assertSame(80, Width::string($line));
        }
    }

    /** A styled (SGR-laden) body line still pads to exactly the terminal width. */
    public function testStyledLinePadsByDisplayWidth(): void
    {
        $styled = Style::new()->bold()->foreground(Color::hex('#ff0000'))->render('hello');
        $out = Frame::new()->render($styled, 80, 24);

        self::assertStringContainsString('hello', $out);
        foreach (explode("\n", $out) as $line) {
            self::assertSame(80, Width::string($line));
        }
    }

    /** Wide CJK glyphs (2 cells each) in the centred title still fill exactly. */
    public function testWideCjkTitlePadsByDisplayWidth(): void
    {
        $out = Frame::new()->withTitle('数据库管理器')->render('body', 80, 24);

        self::assertStringContainsString('数据库管理器', $out);
        foreach (explode("\n", $out) as $line) {
            self::assertSame(80, Width::string($line));
        }
    }

    /** The diff renderer owns the screen — Frame must never emit a clear. */
    public function testEmitsNoScreenClear(): void
    {
        $out = Frame::new()->withTitle('t')->withStatus('s')->render("a\nb", 80, 24);
        self::assertStringNotContainsString("\x1b[2J", $out);
    }

    public function testDrawsDoubleLineBox(): void
    {
        $out = Frame::new()->render('body', 40, 10);
        foreach (['╔', '╗', '╚', '╝', '╠', '╣', '║', '═'] as $glyph) {
            self::assertStringContainsString($glyph, $out, "box should contain $glyph");
        }
    }

    public function testTitleAndStatusAppear(): void
    {
        $out = Frame::new()->withTitle('MY-TITLE')->withStatus('MY-STATUS')->render('body', 80, 24);
        self::assertStringContainsString('MY-TITLE', $out);
        self::assertStringContainsString('MY-STATUS', $out);
    }

    public function testWithersAreImmutable(): void
    {
        $base = Frame::new();
        self::assertNotSame($base, $base->withTitle('TITLEZ'));
        self::assertNotSame($base, $base->withStatus('STATUSZ'));
        self::assertNotSame($base, $base->withBorderStyle(Style::new()->bold()));

        // The base instance is unchanged: its render carries neither title nor status.
        $bare = $base->render('---', 80, 24);
        self::assertStringNotContainsString('TITLEZ', $bare);
        self::assertStringNotContainsString('STATUSZ', $bare);
    }

    public function testDefaultBorderIsSlate(): void
    {
        $out = Frame::new()->render('body', 40, 10);
        // rgb(100,116,139) truecolor SGR on the box-drawing chars.
        self::assertStringContainsString('38;2;100;116;139', $out);
    }

    public function testBorderStyleIsOverridable(): void
    {
        $out = Frame::new()
            ->withBorderStyle(Style::new()->foreground(Color::hex('#ff0000')))
            ->render('body', 40, 10);
        self::assertStringContainsString('38;2;255;0;0', $out);
        self::assertStringNotContainsString('38;2;100;116;139', $out);
    }

    /**
     * A frame shorter than its chrome cannot honour the "exactly $rows lines"
     * contract: with $rows < OVERHEAD (6) there is no room for the border /
     * title / two dividers / status, yet the old code emitted all 6 chrome
     * lines regardless — overflowing the terminal and desyncing the diff
     * renderer. render() must reject it instead.
     *
     * @dataProvider tooShortRows
     */
    public function testRowsBelowOverheadThrow(int $rows): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Frame::new()->render('body', 80, $rows);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function tooShortRows(): iterable
    {
        yield 'zero'  => [0];
        yield 'one'   => [1];
        yield 'five'  => [5];
    }

    /** At exactly OVERHEAD rows the frame renders (all chrome, zero content). */
    public function testExactlyOverheadRowsRenders(): void
    {
        $out = Frame::new()->withTitle('t')->withStatus('s')->render('body', 40, 6);
        $lines = explode("\n", $out);
        self::assertCount(6, $lines, 'a 6-row frame is exactly 6 lines');
        foreach ($lines as $i => $line) {
            self::assertSame(40, Width::string($line), "line $i must be exactly 40 cells");
        }
    }

    /** Truncating an over-wide styled line must preserve its foreground colour. */
    public function testStyledOverWideLineKeepsColor(): void
    {
        $styled = Style::new()->foreground(Color::hex('#ff0000'))->render(str_repeat('x', 500));
        $out = Frame::new()->render($styled, 80, 24);

        // The red SGR introducer (38;2;255;0;0) must survive the truncation.
        self::assertStringContainsString('38;2;255;0;0', $out);
        // Every line must still be exactly $cols cells (Width::string ignores SGR).
        foreach (explode("\n", $out) as $line) {
            self::assertSame(80, Width::string($line), 'truncated styled line must fill terminal exactly');
        }
    }

    // -------------------------------------------------- ingress sanitization

    /**
     * A newline in the title used to add a row to the emitted frame, breaking
     * the "exactly $rows lines" contract this class exists to guarantee (the
     * diff renderer paints once per row; a row-count change corrupts remote
     * ssh/tmux sessions). The title is now sanitized to a single line.
     */
    public function testNewlineInTitleCannotBreakTheRowCount(): void
    {
        $out = Frame::new()->withTitle("evil\ntitle")->render('body', 80, 8);
        self::assertCount(8, explode("\n", $out), 'a rows-invariant despite the embedded newline');
        self::assertStringContainsString('evil', $out);
        self::assertStringContainsString('title', $out);

        $status = Frame::new()->withStatus("st\natus")->render('body', 80, 8);
        self::assertCount(8, explode("\n", $status), 'the status bar cannot add rows either');
    }

    /**
     * Terminal-escape injection through title/status/body: clears, OSC
     * payloads, and set-mode sequences must never reach the output.
     */
    public function testControlInjectionIsStrippedFromAllIngresses(): void
    {
        $out = Frame::new()
            ->withTitle("t\x1b[2J\x1b]52;c;pwned\x07x")
            ->withStatus("s\x1b[?1049h\x1b[1;1Hr")
            ->render("body\x1b[2J\x1b]0;evil\x07line", 80, 24);

        self::assertStringNotContainsString("\x1b[2J", $out);
        self::assertStringNotContainsString("\x1b]52;c;", $out);
        self::assertStringNotContainsString("\x1b]0;evil", $out);
        self::assertStringNotContainsString("\x1b[?1049h", $out);
        self::assertStringNotContainsString("\x1b[1;1H", $out);
        self::assertStringNotContainsString("\x07", $out, 'BEL must not ride along after the payload died');
        self::assertStringContainsString('body', $out);
    }

    /**
     * The other half of the contract: a host app's LEGITIMATE styling (what
     * sugar-style's Style::render produces) must survive ingress untouched —
     * candy-sql passes exactly such rendered titles.
     */
    public function testSgrStylingSurvivesTitleStatusAndBody(): void
    {
        $title = Style::new()->bold()->foreground(Color::hex('#ff5fd2'))->render('Welcome');
        $status = Style::new()->italic()->render(' ready ');
        $body = Style::new()->foreground(Color::hex('#00ff00'))->render('green row');

        $out = Frame::new()->withTitle($title)->withStatus($status)->render($body, 80, 24);

        self::assertStringContainsString($title, $out, 'the styled title must appear verbatim');
        self::assertStringContainsString($status, $out, 'the styled status must appear verbatim');
        self::assertStringContainsString($body, $out, 'the styled body line must appear verbatim');
    }

    /** withTitleText() routes through withTitle(): its Style output survives. */
    public function testWithTitleTextStylingSurvivesSanitization(): void
    {
        $out = Frame::new()
            ->withTitleText('Welcome', Style::new()->bold())
            ->render('body', 50, 10);

        self::assertStringContainsString("\x1b[1m", $out);
        self::assertStringContainsString('Welcome', $out);
    }

    /**
     * Every remaining ESC byte in a rendered frame belongs to an SGR run:
     * structural (non-`m`-terminated) introducers are gone by construction,
     * so Width::string's ANSI-aware measure can only ever see styling.
     */
    public function testEveryEscapeInOutputStartsAnSgrSequence(): void
    {
        $out = Frame::new()
            ->withTitle("t\x1b[2J\x1b]0;x\x07")
            ->withStatus("s\x1b[?7l")
            ->render("b\x1b(Bc\x1b]8;;http://evil\x07d", 60, 8);

        $seen = 0;
        for ($pos = strpos($out, "\x1b"); $pos !== false; $pos = strpos($out, "\x1b", $pos + 1)) {
            $seen++;
            self::assertMatchesRegularExpression(
                '/^\x1b\[[\x30-\x3b]*m/',
                substr($out, $pos),
                'an ESC byte survived that does not head a complete SGR',
            );
        }

        self::assertGreaterThan(0, $seen, 'the slate border styling should still be present');
        // ESC dies (no sequence can form without it); the non-CSI charset
        // fragment `(B` and the body's letters survive as inert visible text,
        // while the OSC-8 hyperlink payload (including its URL) is stripped.
        self::assertStringContainsString('b(Bcd', $out, 'visible body text survives its escorts');
        self::assertStringNotContainsString('http://evil', $out);
    }
    /** Finding 048: dropped rows leave a visible signal on the last row. */
    public function testDroppedRowsSignalWithEllipsisOnLastVisibleRow(): void
    {
        $body  = implode("\n", array_map(static fn (int $i): string => "row$i", range(0, 99)));
        $out   = Frame::new()->withTitle('T')->render($body, 80, 24);
        $lines = explode("\n", $out);

        self::assertCount(24, $lines);
        // Titled chrome is 6 rows: 18 content rows, last visible is row17 —
        // now carrying the drop-signal ellipsis.
        self::assertStringContainsString('row17…', $lines[20]);
        self::assertStringNotContainsString('row19', $out);

        // No drops: no signal anywhere.
        $exact = implode("\n", array_map(static fn (int $i): string => "row$i", range(0, 17)));
        self::assertStringNotContainsString('row17…', Frame::new()->withTitle('T')->render($exact, 80, 24));
    }

    /**
     * Finding 049: an empty sanitized title folds the title slot away —
     * chrome 5, one more content row, line count still exactly $rows.
     */
    public function testUntitledFrameFoldsTheTitleRowIntoContent(): void
    {
        $five = "r0\nr1\nr2\nr3\nr4";
        $un   = explode("\n", Frame::new()->render($five, 40, 10));
        $ti   = explode("\n", Frame::new()->withTitle('T')->render($five, 40, 10));

        self::assertCount(10, $un, 'line count is constant per frame');
        self::assertCount(10, $ti);
        // Untitled: top rule, divider, then straight into 5 content rows…
        self::assertSame($ti[0], $un[0], 'the plain top rule is identical chrome');
        self::assertSame($ti[2], $un[1], 'the header divider follows immediately');
        self::assertStringContainsString('r0', $un[2]);
        // …so five rows fit untitled (no signal) but drop one titled (signal).
        self::assertStringNotContainsString('…', implode("\n", $un));
        self::assertStringContainsString('r3…', $ti[6], 'titled frame drops r4 and signals');
        // A title that sanitizes to empty takes the same door.
        self::assertSame($un, explode("\n", Frame::new()->withTitle("\x07")->render($five, 40, 10)));
    }

    /** Finding 052: an unclosed SGR run is reset before padding is laid. */
    public function testUnclosedSgrRunIsClosedBeforePadding(): void
    {
        $line = explode("\n", Frame::new()->withTitle('T')->render("x\x1b[31m", 20, 10))[3];
        self::assertStringContainsString("\x1b[31m\x1b[0m", $line, 'the guard closes the run');
        self::assertSame(20, Width::string($line), 'cell geometry is unchanged');

        // A closed run gains nothing (no double reset at the seam).
        $closed = explode("\n", Frame::new()->withTitle('T')->render("x\x1b[31my\x1b[0m", 20, 10))[3];
        self::assertStringNotContainsString("\x1b[0m\x1b[0m", $closed);

        // Chrome rows are untouched either way.
        $body = "a\x1b[31m\nb";
        $out  = explode("\n", Frame::new()->withTitle('T')->render($body, 20, 10));
        self::assertCount(10, $out);
        self::assertStringNotContainsString("\x1b[31m", $out[0], 'the unclosed run never paints the top chrome');
        self::assertSame(
            explode("\n", Frame::new()->withTitle('T')->render('plain', 20, 10))[0],
            $out[0],
            'chrome bytes are identical between closed and open body runs'
        );
    }
}