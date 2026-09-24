<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui\Components;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Crush\Tui\Components\PaneFrame;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;

/**
 * The 256-colour side-frame gradient ({@see PaneFrame}). Pins, in the order
 * the feature makes promises: below-256 byte identity, endpoint identity,
 * monotonic interior, the budget formula's clamp edges, the all-or-nothing
 * fallback when the border walk's row shape is not the one we know, and the
 * piped-stdout default that keeps every existing snapshot honest.
 *
 * Glyphs are composed as "\u{25EB}" escapes so no string literal here ever
 * looks glob-shaped to the PathGlob differential corpus.
 */
final class PaneFrameTest extends TestCase
{
    private const TOP = '#ff0000';

    private const SIDE = '#0000ff';

    /**
     * A frame exactly as the pane components build one: rounded border,
     * titled, one column of horizontal padding, fixed outer width.
     */
    private static function frameStyle(Color $borderFg, int $width = 40): Style
    {
        return Style::new()
            ->border(Border::rounded()->withTitle(' ' . "\u{25EB}" . ' files '))
            ->padding(0, 1)
            ->width($width)
            ->borderForeground($borderFg);
    }

    /** @return string body of $rows uniform content rows */
    private static function body(int $rows): string
    {
        return implode("\n", array_fill(0, $rows, 'content'));
    }

    public function testAnsiProfileReturnsTheFrameByteIdentical(): void
    {
        $top = Color::hex(self::TOP);
        $side = Color::hex(self::SIDE);
        $st = self::frameStyle($top);
        $body = self::body(20);

        $gradient = PaneFrame::render($st, $body, $side, ColorProfile::Ansi);

        self::assertSame($st->render($body), $gradient, 'a 16-colour terminal must see today\'s bytes exactly');
    }

    public function testBelowAnsiProfilesReturnTheFrameByteIdentical(): void
    {
        $top = Color::hex(self::TOP);
        $side = Color::hex(self::SIDE);
        $st = self::frameStyle($top);
        $body = self::body(20);

        foreach ([ColorProfile::Ascii, ColorProfile::NoTty] as $profile) {
            self::assertSame(
                $st->render($body),
                PaneFrame::render($st, $body, $side, $profile),
                $profile->name . ' must see today\'s bytes exactly',
            );
        }
    }

    public function testSameTopAndSideColourShortCircuitsToThePlainFrame(): void
    {
        // The unfocused pane paints border everywhere; the fade would
        // interpolate a colour into itself and must cost zero bytes.
        $border = Color::hex(self::SIDE);
        $st = self::frameStyle($border);
        $body = self::body(20);

        self::assertSame(
            $st->render($body),
            PaneFrame::render($st, $body, $border, ColorProfile::TrueColor),
        );
    }

    public function testTrueColorFadeStartsAtTheTopColourAndEndsAtTheSideColour(): void
    {
        $top = Color::hex(self::TOP);
        $side = Color::hex(self::SIDE);
        $st = self::frameStyle($top);
        $body = self::body(20);

        $rows = explode("\n", PaneFrame::render($st, $body, $side, ColorProfile::TrueColor));
        $stops = PaneFrame::gradientRows(count($rows));

        $leftRune = static fn (Color $c): string => $c->toFg(ColorProfile::TrueColor) . "\u{2502}" . Ansi::reset();
        $rightRune = static fn (Color $c): string => $c->toFg(ColorProfile::TrueColor) . "\u{2502}" . Ansi::reset();

        // First body row still carries the top colour verbatim.
        self::assertStringStartsWith($leftRune($top), $rows[1]);
        self::assertStringEndsWith($rightRune($top), $rows[1]);

        // The last gradient row and every row below it carry the side colour.
        $lastFade = 1 + $stops - 1;
        self::assertStringStartsWith($leftRune($side), $rows[$lastFade]);
        self::assertStringStartsWith($leftRune($side), $rows[$lastFade + 1]);
        self::assertStringStartsWith($leftRune($side), $rows[count($rows) - 2]);

        // The fade is real: interior rows carry colours distinct from both
        // endpoints.
        $middle = $rows[1 + intdiv($stops, 2)];
        self::assertStringStartsWith($top->toFg(ColorProfile::TrueColor), $rows[1]);
        self::assertFalse(str_starts_with($middle, $leftRune($top)));
        self::assertFalse(str_starts_with($middle, $leftRune($side)));
    }

    public function testTrueColorInteriorChannelsMoveMonotonically(): void
    {
        $top = Color::hex('#ff0000');
        $side = Color::hex('#0000ff');
        $st = self::frameStyle($top);
        $rows = explode("\n", PaneFrame::render($st, self::body(30), $side, ColorProfile::TrueColor));

        $r = [];
        $g = [];
        $b = [];
        $stops = PaneFrame::gradientRows(count($rows));
        for ($i = 1; $i <= $stops; $i++) {
            // SGRs carry parentheses-free triples; the regex parens also keep
            // this literal out of the glob-shaped corpus.
            self::assertSame(1, preg_match('/38;2;(\d+);(\d+);(\d+)m/', $rows[$i], $m), 'row ' . $i . ' must carry a truecolour SGR');
            $r[] = (int) $m[1];
            $g[] = (int) $m[2];
            $b[] = (int) $m[3];
        }

        self::assertSame(255, $r[0]);                      // starts red
        self::assertSame(0, $r[$stops - 1]);               // ends un-red
        self::assertSame(0, $b[0]);                        // starts un-blue
        self::assertSame(255, $b[$stops - 1]);             // ends blue
        self::assertSame(array_fill(0, $stops, 0), $g);    // green never moves

        for ($i = 1; $i < $stops; $i++) {
            self::assertLessThanOrEqual($r[$i - 1], $r[$i], 'red channel row ' . $i);
            self::assertGreaterThanOrEqual($b[$i - 1], $b[$i], 'blue channel row ' . $i);
        }
    }

    public function testAnsi256FadeUsesTheCubeApproximationAndKeepsEndpointSpellings(): void
    {
        // An ansi-slot side must keep its palette spelling at the endpoints:
        // only the interior rows may invent new colours.
        $top = Color::hex('#ff8700');
        $side = Color::ansi(9);
        $st = self::frameStyle($top);
        $body = self::body(20);

        $rows = explode("\n", PaneFrame::render($st, $body, $side, ColorProfile::Ansi256));
        $stops = PaneFrame::gradientRows(count($rows));

        self::assertStringStartsWith($top->toFg(ColorProfile::Ansi256), $rows[1]);
        self::assertStringStartsWith($side->toFg(ColorProfile::Ansi256), $rows[1 + $stops - 1]);

        // The 256 tier spells colours as SGR 38;5;n or the 16-colour slot
        // codes — never the truecolour triple.
        self::assertStringNotContainsString('38;2;', $rows[1]);
        self::assertStringNotContainsString('38;2;', $rows[1 + $stops - 1]);

        // Interior rows are quantised onto the 256 tier too.
        $middle = $rows[1 + intdiv($stops, 2)];
        self::assertMatchesRegularExpression('/\x1b\[(38;5;\d+|3[0-7]|9[0-7])m/u', $middle);
    }

    public function testTopAndBottomRowsAreUntouched(): void
    {
        $top = Color::hex(self::TOP);
        $side = Color::hex(self::SIDE);
        $st = self::frameStyle($top);
        $body = self::body(20);

        $plain = explode("\n", $st->render($body));
        $faded = explode("\n", PaneFrame::render($st, $body, $side, ColorProfile::TrueColor));

        self::assertSame($plain[0], $faded[0], 'the top edge (and its title) keeps its bytes');
        self::assertSame($plain[count($plain) - 1], $faded[count($faded) - 1], 'the bottom edge keeps its bytes');
    }

    public function testFadeBudgetScalesWithHeightAndClampsAtBothEnds(): void
    {
        // intdiv edges: 7 and below floor at 2, 32 and above cap at 8.
        self::assertSame(2, PaneFrame::gradientRows(1));
        self::assertSame(2, PaneFrame::gradientRows(7));
        self::assertSame(2, PaneFrame::gradientRows(8));
        self::assertSame(3, PaneFrame::gradientRows(12));
        self::assertSame(3, PaneFrame::gradientRows(15));
        self::assertSame(7, PaneFrame::gradientRows(31));
        self::assertSame(8, PaneFrame::gradientRows(32));
        self::assertSame(8, PaneFrame::gradientRows(400));
    }

    public function testFrameWithoutSideRunesFallsBackToTheWholePlainFrame(): void
    {
        // Top/bottom-only border: no body row carries side runes, so the
        // restyler must hand back the frame untouched rather than chop rows.
        $top = Color::hex(self::TOP);
        $side = Color::hex(self::SIDE);
        $st = Style::new()
            ->border(Border::rounded(), true, false)
            ->padding(0, 1)
            ->width(40)
            ->borderForeground($top);
        $body = self::body(20);

        self::assertSame($st->render($body), PaneFrame::render($st, $body, $side, ColorProfile::TrueColor));
    }

    public function testContentBytesBetweenTheSideRunesAreUntouched(): void
    {
        // The restyle must cost the interior nothing: strip the edge runes
        // from both renders and the cores must be byte-identical.
        $top = Color::hex(self::TOP);
        $side = Color::hex(self::SIDE);
        $st = self::frameStyle($top);
        $body = self::body(20);

        $plain = array_slice(explode("\n", $st->render($body)), 1, -1);
        $faded = array_slice(explode("\n", PaneFrame::render($st, $body, $side, ColorProfile::TrueColor)), 1, -1);

        // SGR sequences are the ONLY thing the fade is allowed to add or
        // swap on a body row: strip every one of them from both renders and
        // the surviving bytes must be identical row for row.
        $uncoloured = static fn (string $row): string => preg_replace('/\x1b\[[0-9;]*m/', '', $row);

        self::assertCount(count($plain), $faded);
        foreach ($plain as $i => $row) {
            self::assertSame(
                $uncoloured($row),
                $uncoloured($faded[$i]),
            );
        }
    }

    public function testDetectionRoutesStandardCapabilityVariables(): void
    {
        // The feature must reach these tiers through the existing candy-core
        // path, never a private spelling of its own.
        self::assertSame(ColorProfile::TrueColor, PaneFrame::detectProfile(['COLORTERM' => 'truecolor'], null));
        self::assertSame(ColorProfile::Ansi256, PaneFrame::detectProfile(['TERM' => 'xterm-256color'], null));
        self::assertSame(ColorProfile::Ansi, PaneFrame::detectProfile(['TERM' => 'xterm'], null));
        self::assertSame(ColorProfile::Ascii, PaneFrame::detectProfile(['NO_COLOR' => '1'], null));
    }

    public function testDefaultProfileOnAPipedStdoutKeepsEverySnapshotOnTodaysBytes(): void
    {
        if (stream_isatty(STDOUT)) {
            return; // the law only binds when the suite is piped, as CI runs it
        }

        $envKeys = ['NO_COLOR', 'CLICOLOR_FORCE', 'FORCE_COLOR', 'COLORTERM', 'TERM'];
        $saved = [];
        foreach ($envKeys as $key) {
            $saved[$key] = getenv($key);
            putenv($key);
        }
        PaneFrame::resetProfileCacheForTesting();

        try {
            self::assertSame(ColorProfile::NoTty, PaneFrame::profile());

            $top = Color::hex(self::TOP);
            $side = Color::hex(self::SIDE);
            $st = self::frameStyle($top);
            $body = self::body(20);
            self::assertSame($st->render($body), PaneFrame::render($st, $body, $side));
        } finally {
            foreach ($saved as $key => $value) {
                if ($value === false) {
                    putenv($key);
                } else {
                    putenv($key . '=' . $value);
                }
            }
            PaneFrame::resetProfileCacheForTesting();
        }
    }

    public function testFadeEndpointsArePlainColoursNotARoundTripThroughTheBlend(): void
    {
        // blend(t=0) and blend(t=1) are already exact, but the promise this
        // pins is stronger: the endpoint rows emit the ORIGINAL Color's own
        // toFg spelling, which is what preserves ansi-slot palette codes.
        $top = Color::ansi(4);
        $side = Color::ansi(9);
        $st = self::frameStyle($top);
        $rows = explode("\n", PaneFrame::render($st, self::body(20), $side, ColorProfile::TrueColor));
        $stops = PaneFrame::gradientRows(count($rows));

        self::assertStringStartsWith($top->toFg(ColorProfile::TrueColor), $rows[1]);
        self::assertStringStartsWith($side->toFg(ColorProfile::TrueColor), $rows[1 + $stops - 1]);
        // A slot-4 colour stays a slot-4 spelling even at the TrueColor tier.
        self::assertStringNotContainsString('38;2;', explode(Ansi::reset(), $rows[1])[0]);
    }
}
