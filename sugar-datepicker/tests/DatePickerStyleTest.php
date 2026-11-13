<?php

declare(strict_types=1);

namespace SugarCraft\DatePicker\Tests;

use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\CellBuffer\Style;
use SugarCraft\DatePicker\DatePicker;
use PHPUnit\Framework\TestCase;

final class DatePickerStyleTest extends TestCase
{
    private DatePicker $dp;

    protected function setUp(): void
    {
        $this->dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
    }

    public function testWithCursorStyleFluent(): void
    {
        $a = $this->dp;
        $b = $a->withCursorStyle('7');
        $c = $b->withCursorStyle('1;31');

        $this->assertNotSame($a, $b);
        $this->assertNotSame($b, $c);
    }

    public function testWithRangeStyleFluent(): void
    {
        $a = $this->dp;
        $b = $a->withRangeStyle('1;35');
        $c = $b->withRangeStyle('1;36');

        $this->assertNotSame($a, $b);
        $this->assertNotSame($b, $c);
    }

    public function testSgrToBufferStyleBold(): void
    {
        $style = $this->invokeSgrToBufferStyle('1');
        $this->assertNotNull($style);
        $this->assertTrue($style->hasBold());
    }

    public function testSgrToBufferStyleFaint(): void
    {
        $style = $this->invokeSgrToBufferStyle('2');
        $this->assertNotNull($style);
        $this->assertTrue($style->hasFaint());
    }

    public function testSgrToBufferStyleItalic(): void
    {
        $style = $this->invokeSgrToBufferStyle('3');
        $this->assertNotNull($style);
        $this->assertTrue($style->hasItalic());
    }

    public function testSgrToBufferStyleUnderline(): void
    {
        $style = $this->invokeSgrToBufferStyle('4');
        $this->assertNotNull($style);
        $this->assertTrue($style->hasUnderline());
    }

    public function testSgrToBufferStyleBlink(): void
    {
        $style = $this->invokeSgrToBufferStyle('5');
        $this->assertNotNull($style);
        $this->assertTrue($style->hasBlink());
    }

    public function testSgrToBufferStyleBlink6(): void
    {
        $style = $this->invokeSgrToBufferStyle('6');
        $this->assertNotNull($style);
        $this->assertTrue($style->hasBlink());
    }

    public function testSgrToBufferStyleReverse(): void
    {
        $style = $this->invokeSgrToBufferStyle('7');
        $this->assertNotNull($style);
        $this->assertTrue($style->hasReverse());
    }

    public function testSgrToBufferStyleStrike(): void
    {
        $style = $this->invokeSgrToBufferStyle('9');
        $this->assertNotNull($style);
        $this->assertTrue($style->hasStrike());
    }

    public function testSgrToBufferStyleBrightForeground(): void
    {
        $style = $this->invokeSgrToBufferStyle('91');
        $this->assertNotNull($style);
        $this->assertNotNull($style->fg());
    }

    public function testSgrToBufferStyleEmptyReturnsNull(): void
    {
        $style = $this->invokeSgrToBufferStyle('');
        $this->assertNull($style);
    }

    public function testSgrToBufferStyleCombined(): void
    {
        $style = $this->invokeSgrToBufferStyle('1;31');
        $this->assertNotNull($style);
        $this->assertTrue($style->hasBold());
        $this->assertNotNull($style->fg());
    }

    public function testAnsiColorToRgbStandard(): void
    {
        $rgb = $this->invokeAnsiColorToRgb(0, false);
        $this->assertSame(0x000000, $rgb);

        // Slot 7 = sugar-core ANSI16_RGB[7] = [229,229,229] (xterm white).
        $rgb = $this->invokeAnsiColorToRgb(7, false);
        $this->assertSame(0xe5e5e5, $rgb);
    }

    public function testAnsiColorToRgbBright(): void
    {
        // Slot 8 = ANSI16_RGB[8] = [127,127,127] (xterm bright black).
        $rgb = $this->invokeAnsiColorToRgb(0, true);
        $this->assertSame(0x7f7f7f, $rgb);

        // Slot 15 = ANSI16_RGB[15] = [255,255,255]; equals the old +96-clamped value.
        $rgb = $this->invokeAnsiColorToRgb(7, true);
        $this->assertSame(0xffffff, $rgb);
    }

    public function testAnsiColorToRgbOutOfRange(): void
    {
        // Out-of-range falls back to the canonical white slot 7 = [229,229,229].
        $rgb = $this->invokeAnsiColorToRgb(99, false);
        $this->assertSame(0xe5e5e5, $rgb);

        // …and to slot 15 = [255,255,255] on the bright half, as before.
        $rgb = $this->invokeAnsiColorToRgb(99, true);
        $this->assertSame(0xffffff, $rgb);
    }

    public function testGraphemeWidthCombiningMark(): void
    {
        $w = $this->invokeGraphemeWidth("\xcc\x80");
        $this->assertSame(0, $w);

        $w = $this->invokeGraphemeWidth("\xcd\x8f");
        $this->assertSame(0, $w);
    }

    public function testGraphemeWidthWideEastAsian(): void
    {
        $w = $this->invokeGraphemeWidth("\xe4\xb8\x80");
        $this->assertSame(2, $w);

        $w = $this->invokeGraphemeWidth("\xe3\x82\xa0");
        $this->assertSame(2, $w);
    }

    public function testGraphemeWidthEmptyReturnsZero(): void
    {
        $w = $this->invokeGraphemeWidth('');
        $this->assertSame(0, $w);
    }

    public function testGraphemeWidthAsciiAlpha(): void
    {
        $w = $this->invokeGraphemeWidth('A');
        $this->assertSame(1, $w);
    }

    public function testGraphemeWidthControlChar(): void
    {
        // ASCII control chars 0x00-0x08 and 0x7F return 0
        $w = $this->invokeGraphemeWidth("\x01");
        $this->assertSame(0, $w, 'Control char 0x01 must return width 0');

        $w = $this->invokeGraphemeWidth("\x7f");
        $this->assertSame(0, $w, 'DEL char 0x7F must return width 0');
    }

    public function testPlaceStringAtWideCharTruncatesAtWidth(): void
    {
        $buf = Buffer::new(5, 3);
        $result = $this->invokePlaceStringAt($buf, 0, 0, "\xe4\xb8\x80\xe4\xb8\x80\xe4\xb8\x80", null);

        // Three 2-column glyphs into a 5-wide row: two full pairs, then the
        // edge straddler degrades to a blank (sugar-cellbuffer pair law),
        // never a 2-paint in a 1-column space.
        $row = [0, 1, 2, 3, 4];
        $painted = 0;
        $runes = [];
        $widths = [];
        foreach ($row as $col) {
            $cell = $result->cellAt($col, 0);
            $runes[] = $cell->rune();
            $widths[] = $cell->width();
            $painted += $cell->width();
        }

        $this->assertSame(['一', '', '一', '', ' '], $runes);
        $this->assertSame([2, 0, 2, 0, 1], $widths);
        $this->assertSame($buf->width(), $painted, 'painted columns must equal the declared width');
        $this->assertSame(1, $result->cellAt(4, 0)->width(), 'last column must never hold a 2-wide lead');
    }

    public function testPlaceStringAtDegradesWideClusterStraddlingBufferEdgeMidway(): void
    {
        // Start col 3 of a 5-wide buffer with 'a' + one 2-col glyph: the
        // glyph lands on the final column and degrades, consuming one column.
        $buf = Buffer::new(5, 1);
        $result = $this->invokePlaceStringAt($buf, 3, 0, "a\xe4\xb8\x80", null);

        $this->assertSame('a', $result->cellAt(3, 0)->rune());
        $this->assertSame(' ', $result->cellAt(4, 0)->rune());
        $this->assertSame(1, $result->cellAt(4, 0)->width());
    }

    public function testBuildRangeWithBothDatesInViewMonth(): void
    {
        $dp = $this->dp
            ->withRangeMode(true);

        $firstDow = (int) (new \DateTimeImmutable('2026-05-01'))->format('w');
        for ($i = 0; $i < $firstDow; $i++) {
            $dp = $dp->moveCursorRight();
        }
        $dp = $dp->handleKey('enter');

        for ($i = 0; $i < 4; $i++) {
            $dp = $dp->moveCursorRight();
        }
        $dp = $dp->handleKey('enter');

        $this->assertNotNull($dp->rangeStart());
        $this->assertNotNull($dp->rangeEnd());
    }

    public function testBuildRangeWithDatesOutsideViewMonth(): void
    {
        $ref = new \ReflectionClass(DatePicker::class);
        $dp = $this->dp->withRangeMode(true);

        $juneDate = new \DateTimeImmutable('2026-06-15');
        $julyDate = new \DateTimeImmutable('2026-07-01');

        $propStart = $ref->getProperty('rangeStart');
        $propStart->setAccessible(true);
        $propStart->setValue($dp, $juneDate);

        $propEnd = $ref->getProperty('rangeEnd');
        $propEnd->setAccessible(true);
        $propEnd->setValue($dp, $julyDate);

        $meth = $ref->getMethod('buildRange');
        $meth->setAccessible(true);
        $range = $meth->invoke($dp);

        $this->assertNull($range);
    }

    public function testBuildCellsWithRangeHighlight(): void
    {
        $dp = $this->dp
            ->withRangeMode(true);

        $firstDow = (int) (new \DateTimeImmutable('2026-05-01'))->format('w');
        for ($i = 0; $i < $firstDow; $i++) {
            $dp = $dp->moveCursorRight();
        }
        $dp = $dp->handleKey('enter');

        for ($i = 0; $i < 4; $i++) {
            $dp = $dp->moveCursorRight();
        }
        $dp = $dp->handleKey('enter');

        $view = $dp->view();
        $this->assertIsString($view);
    }

    public function testWithStyleRejectsNonSgrInput(): void
    {
        // Full escape sequences are not valid SGR codes for With*Style() setters
        $this->expectException(\InvalidArgumentException::class);
        $this->dp->withHeaderStyle("\e[1m");
    }

    public function testWithStyleAcceptsValidSgr(): void
    {
        // Valid SGR codes should not throw
        $dp = $this->dp
            ->withHeaderStyle('1;31')
            ->withTodayStyle('7')
            ->withCursorStyle('');

        $this->assertNotSame($this->dp, $dp);
    }

    public function testSgrToBufferStyleBackgroundColors(): void
    {
        // Background colors 40-47 (black, red, green, yellow, blue, magenta, cyan, white)
        $style = $this->invokeSgrToBufferStyle('44'); // blue background
        $this->assertNotNull($style);
        $this->assertNotNull($style->bg());
    }

    public function testSgrToBufferStyleCombinedFgBg(): void
    {
        // Combined foreground (30-37) + background (40-47) + bold (1)
        $style = $this->invokeSgrToBufferStyle('1;31;44');
        $this->assertNotNull($style);
        $this->assertTrue($style->hasBold());
        $this->assertNotNull($style->fg());
        $this->assertNotNull($style->bg());
    }

    private function invokeSgrToBufferStyle(string $sgr): ?Style
    {
        $ref = new \ReflectionClass($this->dp);
        $meth = $ref->getMethod('sgrToBufferStyle');
        $meth->setAccessible(true);
        return $meth->invoke($this->dp, $sgr);
    }

    private function invokeAnsiColorToRgb(int $idx, bool $bright): int
    {
        $ref = new \ReflectionClass($this->dp);
        $meth = $ref->getMethod('ansiColorToRgb');
        $meth->setAccessible(true);
        return $meth->invoke($this->dp, $idx, $bright);
    }

    public function testWithStyleRejectsDanglingSgrShapes(): void
    {
        // docs-fix-1023 E2: sgrToBufferStyle() explode(';')-parses, so
        // dangling/empty parameter shapes are refused (they used to slip
        // through the loose '^[0-9;]*$' guard as silent no-op segments).
        foreach ([';', '1;', ';1', '1;;2'] as $shape) {
            try {
                $this->dp->withHeaderStyle($shape);
                $this->fail("SGR shape '{$shape}' must be rejected");
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function testWithStyleAcceptsCanonicalSgrShapes(): void
    {
        // Positive half of the previous pin: legal shapes stay accepted.
        // Extended-colour introducers (38/48) are NOT in this set — see
        // testWithStyleRejectsExtendedColour below.
        $dp = $this->dp
            ->withHeaderStyle('1;31')
            ->withTodayStyle('105')
            ->withCursorStyle('4;36');
        $this->assertNotSame($this->dp, $dp);
    }

    public function testWithStyleRejectsExtendedColour(): void
    {
        // D11: sgrToBufferStyle() cannot consume the parameters that follow a
        // 38/48 introducer, so accepting '38;5;226' would drop the colour and
        // leave the bare 5 to be mis-decoded as ATTR_BLINK — a silent, wrong
        // render. It is refused loudly instead.
        foreach (['38;5;226', '48;2;10;20;30', '38'] as $shape) {
            try {
                $this->dp->withHeaderStyle($shape);
                $this->fail("extended-colour shape '{$shape}' must be rejected");
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function testAssertSgrEscapesControlBytesInItsMessage(): void
    {
        // MT5: the rejected string is interpolated into the error message. It
        // is addcslashes-escaped so a forged ESC can never reach the terminal
        // through the error path.
        try {
            $this->dp->withHeaderStyle("\e[1m");
            $this->fail('a raw escape must be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString("\e", $e->getMessage(),
                'the message must not carry the raw control byte');
        }

        // N17: the escape range used to cover C0 and DEL only, so a rejected
        // string opening with the one-byte CSI (U+009B) put that raw C1 byte
        // back on the terminal through the exception path.
        try {
            $this->dp->withHeaderStyle("\x9b31m");
            $this->fail('a raw C1 escape introducer must be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString("\x9b", $e->getMessage(),
                'the message must not carry the raw C1 byte');
        }
        try {
            $this->dp->withHeaderStyle("\x01abc");
            $this->fail('a raw C0 byte must be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString("\x01", $e->getMessage(),
                'the C0 escape leg must keep working');
        }
    }

    public function testPlaceStringAtGuardFailsClosedOnMalformedAndC1Clusters(): void
    {
        // M8: the old guard read "mb_ord succeeded AND the codepoint is a
        // control code", so clusters mb_ord could not decode bypassed the drop
        // branch entirely, and the C1 range (which contains the one-byte CSI
        // U+009B) was never named. sugar-cellbuffer emits whatever rune a cell
        // holds, so this guard is the boundary. Pin the guard body: the
        // behavioural table below is non-discriminating on boxes whose splitter
        // zero-scores every probe before the fail-open branch is reached, so
        // the body pins carry the discrimination.
        $method = new \ReflectionMethod(DatePicker::class, 'placeStringAt');
        $lines = \array_slice(\file((string) $method->getFileName()),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1);
        $body = \implode('', $lines);
        $this->assertStringContainsString('$ord === false', $body,
            'the guard must drop clusters mb_ord cannot decode');
        $this->assertStringContainsString('0x9f', $body,
            'the guard must name the top of the C1 range it drops');
        $this->assertStringNotContainsString('$ord !== false &&', $body,
            'the old fail-open guard shape must not return');
    }

    public function testPlaceStringAtDropsControlProbeClusters(): void
    {
        // Output contract locking the post-fix guarantee for future machines
        // whose grapheme splitter surfaces the raw bytes: every hostile cluster
        // collapses to nothing while the surrounding letters stay adjacent.
        //
        // The 'malformed 2-byte' probe deliberately ends in a CONTROL byte.
        // It previously ended in '(' (0xC3 0x28), and the two splitters
        // disagree on that string: mb_str_split (the PHP < 8.4 path, no
        // grapheme_str_split) greedily groups a structurally-valid lead with
        // its declared byte-count, so 0xC3 0x28 arrived as ONE undecodable
        // cluster and the '(' fell with it; grapheme_str_split (PHP 8.4)
        // segments invalid bytes singly, and '(' is a perfectly printable
        // character the fail-closed guard has no licence to swallow — 8.4
        // legs pinned 'A(B'. Neither outcome smuggles a control byte, so the
        // pinned contract is stated where both machines agree: a malformed
        // lead consumes no column AND its control tail is never placed, plus
        // the splitter-independent invariant that no placed rune is ever a
        // control, DEL, C1, or undecodable byte.
        $probes = [
            'C0 tab' => "A\tB",
            'DEL' => "A\x7fB",
            'C1 CSI' => "A\x9bB",
            'C1 NEL' => "A\x85B",
            'malformed 2-byte' => "A\xC3\x07B",
            'malformed ESC tail' => "A\xC2\x1bB",
            'overlong ESC' => "A\xF0\xF0\xF0\x1bB",
            'BOM mid-string' => "A\xEF\xBB\xBFB",
        ];
        foreach ($probes as $name => $probe) {
            $buffer = $this->invokePlaceStringAt(Buffer::new(10, 1), 0, 0, $probe, null);
            $text = '';
            for ($x = 0; $x < 10; $x++) {
                $rune = $buffer->cellAt($x, 0)->rune();
                $ord = \mb_ord($rune, 'UTF-8');
                $this->assertTrue(
                    $ord !== false && !($ord < 0x20 || $ord === 0x7f || ($ord >= 0x80 && $ord <= 0x9f)),
                    $name . ': a placed rune must never be a control, DEL, C1, or undecodable byte',
                );
                $text .= $rune;
            }
            $this->assertSame('AB' . \str_repeat(' ', 8), $text,
                $name . ': control bytes must consume no column');
        }
    }

    public function testAssertSgrLengthCap(): void
    {
        // MT5: digit runs are capped so an unbounded parameter cannot slip in.
        $this->expectException(\InvalidArgumentException::class);
        $this->dp->withHeaderStyle(\str_repeat('1;', 40) . '1');
    }

    public function testWithDayNameAndSelectedTodayStyleSetters(): void
    {
        // MT3: the two previously setterless style channels.
        $a = $this->dp;
        $b = $a->withDayNameStyle('36')->withSelectedTodayStyle('1;33');
        $this->assertNotSame($a, $b);
    }

    public function testCursorCaretIsVisibleOnEmptyLeadCell(): void
    {
        // D3: a cursor resting on an empty lead-in cell must still emit a
        // reverse SGR (the single focus cue), not vanish. May 2026 starts on
        // Friday, so grid index 0 (Sunday) is an empty lead-in cell.
        $empty = $this->dp->moveCursorLeft()->moveCursorLeft()
            ->moveCursorLeft()->moveCursorLeft()->moveCursorLeft();
        $this->assertNull($empty->dateAtCursor(), 'index 0 is the empty lead-in');
        $this->assertStringContainsString("\x1b[0;7m", $empty->view(),
            'the cursor must render on an empty cell');
    }

    public function testSelectedDayDoesNotBleedIntoNextMonth(): void
    {
        // D2: selecting May 15 must highlight it in May only. The old code
        // compared day-of-month, so the "15" cell in June (and May 2027) also
        // wore the selected style. selectedStyle = '1;36' → bold cyan, whose
        // ANSI16 slot-6 fg triple is 0;205;205; no other channel uses it, so
        // its presence in a view means the selection painted there.
        $may = $this->dp->moveToDate(new \DateTimeImmutable('2026-05-15'))
            ->withToday(new \DateTimeImmutable('2026-12-31')) // keep "today" out of May
            ->handleKey('enter');
        $this->assertSame('2026-05-15', $may->selectedDate()?->format('Y-m-d'));
        $this->assertStringContainsString('38;2;0;205;205', $may->view(),
            'the selected day must carry the cyan style in its own month');

        $june = $may->goToNextMonth();
        $this->assertSame(6, $june->viewMonth());
        $this->assertStringNotContainsString('38;2;0;205;205', $june->view(),
            'a day-of-month collision in another month must not be highlighted');

        $nextYear = $may->goToNextYear();
        $this->assertStringNotContainsString('38;2;0;205;205', $nextYear->view(),
            'the same collision across a year boundary must not be highlighted');
    }

    public function testConstructorFloorsPreYearOneDate(): void
    {
        // D6: a zero date (exactly what bare MySQL/hosting rows yield) used to
        // render, then crash on the first Enter through Navigation. The
        // constructor now floors the view to January 0001 so nothing throws.
        $dp = DatePicker::new(\DateTimeImmutable::createFromFormat('Y-m-d', '0000-01-01'));
        $this->assertGreaterThanOrEqual(1, $dp->viewYear());
        $this->assertIsString($dp->view());
        $this->assertIsString($dp->handleKey('enter')->view());
    }

    public function testMaxDateBelowFloorIsRefusedAtTheSetter(): void
    {
        // D6 variant, superseded by dp-32 (sugar-datepicker-056): a maxDate
        // earlier than the calendar floor used to be accepted and floored the
        // view into an all-dim dead month (the floor-applied-LAST defence is
        // still there, now unreachable through the public API). The setter
        // fails fast and names the offending date.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('0000-06-15 lies outside');

        $this->dp->withMaxDate(\DateTimeImmutable::createFromFormat('Y-m-d', '0000-06-15'));
    }

    public function testHomeAndEndLandOnRealDays(): void
    {
        // D21: Home = first day of the viewed month, End = last day; never a
        // raw 0/41 grid position that may be blank.
        $home = $this->dp->handleKey('home');
        $end  = $this->dp->handleKey('end');
        $this->assertSame('2026-05-01', $home->dateAtCursor()?->format('Y-m-d'));
        $this->assertSame('2026-05-31', $end->dateAtCursor()?->format('Y-m-d'));
    }

    public function testMoveToDatePositionsCursorOnExistingValue(): void
    {
        // D9: the edit-existing-value recipe. A date in the viewed month aims
        // the cursor on it; Enter then selects that exact date.
        $dp = $this->dp->moveToDate(new \DateTimeImmutable('2026-05-15'));
        $this->assertSame('2026-05-15', $dp->dateAtCursor()?->format('Y-m-d'));
        $this->assertSame('2026-05-15', $dp->handleKey('enter')->selectedDate()?->format('Y-m-d'));
    }

    public function testMoveToDateOutOfMonthKeepsView(): void
    {
        // A date outside the viewed month leaves the view untouched and keeps
        // the cursor on the first valid day (MoveToDate never re-anchors view).
        $dp = $this->dp->moveToDate(new \DateTimeImmutable('2026-09-03'));
        $this->assertSame(5, $dp->viewMonth());
        $this->assertStringStartsWith('2026-05-', $dp->dateAtCursor()?->format('Y-m-d') ?? '');
    }

    public function testWeekColumnKeepsTotalWidthInsideCap(): void
    {
        // D20: the clamp is applied to the TOTAL width (calendar area + the
        // optional 4-cell week column), floored to the 7×3 natural grid so all
        // seven day columns stay visible, capped at 63. The old code clamped
        // BEFORE adding the week column, so View(63,true) overflowed to 67.
        $maxLineWidth = static function (string $view): int {
            $plain = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $view);
            $widest = 0;
            foreach (explode("\n", $plain) as $line) {
                $widest = max($widest, \SugarCraft\Core\Util\Width::of($line));
            }
            return $widest;
        };

        // Natural floor: even a request of 15 with a week column renders 25.
        $this->assertSame(25, $maxLineWidth($this->dp->view(15, true)));
        // The upper cap: 63, never 67 — and the whole last day column survives.
        $wide = $this->dp->view(63, true);
        $this->assertLessThanOrEqual(63, $maxLineWidth($wide));
        $this->assertStringContainsString('31', $wide,
            'the last day of May must stay visible at the capped width');
    }

    /**
     * dp-08: the accept set is an enumerated truth (SUPPORTED_SGR) joined to
     * the decoder by this matrix — every member admits through a setter AND
     * decodes to a live style, every outsider is refused loudly with the
     * code named. Literals are spelled independently of the const on purpose:
     * dropping a member from SUPPORTED_SGR without dropping the decoder arm
     * (or the other way round) reddens a row here.
     *
     * @return array<string, array{0: string, 1: string}> code => kind
     */
    public static function supportedSgrProvider(): array
    {
        $cases = [];
        foreach ([1, 2, 3, 4, 5, 6, 7, 9] as $n) {
            $cases['attr ' . $n] = [(string) $n, 'attr'];
        }
        foreach ([...range(30, 37), ...range(90, 97)] as $n) {
            $cases['fg ' . $n] = [(string) $n, 'fg'];
        }
        foreach ([...range(40, 47), ...range(100, 107)] as $n) {
            $cases['bg ' . $n] = [(string) $n, 'bg'];
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('supportedSgrProvider')]
    public function testEverySupportedSgrRoundTrips(string $code, string $kind): void
    {
        // The setter admits it...
        $picker = $this->dp->withHeaderStyle($code);
        $this->assertNotSame($this->dp, $picker);

        // ...and the decoder gives it a live style: attributes flip attrs,
        // colours land on the right channel only.
        $style = $this->invokeSgrToBufferStyle($code);
        $this->assertNotNull($style, $code . ' is in the accept set but decodes to nothing');
        match ($kind) {
            'fg' => $this->assertNotNull($style->fg()),
            'bg' => $this->assertNotNull($style->bg()),
            default => $this->assertTrue(
                $style->attrs() !== 0,
                $code . ' must set at least one attribute bit',
            ),
        };
    }

    /**
     * Refused set: nothing here has a decoder arm, so nothing may be
     * silently accepted. `58;5;9` and the 38/48 families mis-decoded before
     * dp-08; the off-codes were no-op lies.
     */
    public static function refusedSgrProvider(): array
    {
        $codes = [
            '0', '8', '21', '22', '23', '24', '25', '26', '27', '28', '29',
            '38', '39', '48', '49', '53', '58', '60', '70', '80', '89',
            '98', '99', '108', '109', '110', '255', '999',
        ];
        $cases = [];
        foreach ($codes as $c) {
            $cases['refuse ' . $c] = [$c];
        }
        foreach (['1;39', '58;5;9', '38;5;226', '48;2;1;2;3', '100;108'] as $combo) {
            $cases['refuse ' . $combo] = [$combo];
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedSgrProvider')]
    public function testUndecodableSgrIsRefusedLoudlyNamingTheCode(string $code): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no decoder arm');

        try {
            $this->dp->withHeaderStyle($code);
        } catch (\InvalidArgumentException $e) {
            // The message names the offending code and quotes the full input,
            // so every part is disclosed for a host to fix.
            foreach (explode(';', $code) as $part) {
                $this->assertStringContainsString($part, $e->getMessage());
            }

            throw $e;
        }
    }

    public function testBrightBackgroundDecodesOnBgChannel(): void
    {
        // 100-107 mirror 90-97 on the background channel (the buffer carries
        // bright-bg colours; dp-08 shipped the arm rather than refuse them).
        $style = $this->invokeSgrToBufferStyle('104');
        $this->assertNotNull($style);
        $this->assertNotNull($style->bg());
        $this->assertNull($style->fg());
    }

    private function invokeGraphemeWidth(string $g): int
    {
        $ref = new \ReflectionClass($this->dp);
        $meth = $ref->getMethod('graphemeWidth');
        $meth->setAccessible(true);
        return $meth->invoke($this->dp, $g);
    }

    private function invokePlaceStringAt(Buffer $buf, int $col, int $row, string $s, ?Style $style): Buffer
    {
        $ref = new \ReflectionClass($this->dp);
        $meth = $ref->getMethod('placeStringAt');
        $meth->setAccessible(true);
        return $meth->invoke($this->dp, $buf, $col, $row, $s, $style);
    }
}
