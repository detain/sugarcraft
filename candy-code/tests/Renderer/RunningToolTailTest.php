<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Message;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\Theme;
use SugarCraft\Code\ToolCall;
use SugarCraft\Code\Tui\ToolTailSlots;

/**
 * C-TOOL-3.b's paint contract for the live shell tail: the running-tool row
 * shows the newest TAIL_LINES lines the {@see ToolTailSlots} store holds for
 * its call, each dim and on its own row, cells-clipped to the frame, and the
 * overflow announced by a `… N earlier lines` caption above the shown lines.
 *
 * The lines are painted through the renderer's own frame sanitizer before
 * they are styled: no raw control byte, no unmarked bidi override, and no
 * zone sentinel may ride the transcript (audit 15b-28). An absent slot paints
 * nothing at all - the row stays byte-identical to a no-tail render, which is
 * what keeps every frame golden that never arms a tail unaffected.
 */
final class RunningToolTailTest extends TestCase
{
    protected function setUp(): void
    {
        ToolTailSlots::clearAll();
    }

    protected function tearDown(): void
    {
        ToolTailSlots::clearAll();
    }

    public function testACallWithoutASlotPaintsNothing(): void
    {
        self::assertSame('', self::paint('call-none', 80), 'no frame arrived, no row appears');
    }

    public function testTheTailPaintsOneDimRowPerLineUnderTheRunningRow(): void
    {
        ToolTailSlots::set('call-1', "alpha\nbeta", false, 1);

        $out = self::paint('call-1', 80);

        self::assertStringStartsWith("\n", $out, 'the block is appended rows, never inline text');
        self::assertSame(["\n  alpha", "\n  beta"], array_map(
            static fn (string $row): string => "\n" . Ansi::strip($row),
            array_slice(explode("\n", $out), 1),
        ), 'indent, one line per row, plain on screen - dim is the styling');
        self::assertStringContainsString("\x1b[", $out, 'the rows ride the dim style, not bare bytes');
    }

    public function testOnlyTheNewestBudgetLinesSurviveWithAnEarlierCaption(): void
    {
        $lines = array_map(static fn (int $i): string => 'row-' . $i, range(1, 9));
        ToolTailSlots::set('call-1', implode("\n", $lines), false, 1);

        $out = Ansi::strip(self::paint('call-1', 80));

        self::assertStringContainsString('… 3 earlier lines', $out, 'the hidden head of the tail is announced');
        self::assertStringNotContainsString('row-1', $out);
        self::assertStringNotContainsString('row-3', $out);
        self::assertStringContainsString('row-4', $out, 'the newest six lines are the shown window');
        self::assertStringContainsString('row-9', $out);
        self::assertLessThan(
            (int) strpos($out, 'row-4'),
            (int) strpos($out, '… 3 earlier lines'),
            'the caption sits above the shown lines, reading order intact',
        );
    }

    public function testWithinBudgetThereIsNoCaptionAtAll(): void
    {
        ToolTailSlots::set('call-1', "one\ntwo\nthree", false, 1);

        $out = Ansi::strip(self::paint('call-1', 80));

        self::assertStringNotContainsString('earlier', $out, 'nothing hidden, nothing announced');
    }

    public function testControlsSentinelsAndBidiNeverRideTheFrame(): void
    {
        $tail = "\x1b[31mred\x1b[0m bell\x07 dir\u{202E}end zone\u{E000}forged\u{E001}!";
        ToolTailSlots::set('call-1', $tail, false, 1);

        $out = self::paint('call-1', 80);

        self::assertStringNotContainsString('[31m', $out, 'hostile SGR is stripped before styling');
        self::assertStringNotContainsString("\x07", $out, 'no raw control byte survives the sanitizer');
        self::assertStringNotContainsString("\u{202E}", $out, 'bidi override may not stay invisible');
        self::assertStringContainsString('<U+202E>', $out, 'the marked form replaces it');
        self::assertStringNotContainsString("\u{E000}", $out, 'a forged zone sentinel cannot reach the frame');
        self::assertStringNotContainsString("\u{E001}", $out);
        self::assertStringContainsString('red', Ansi::strip($out));
        self::assertStringContainsString('forged', Ansi::strip($out), 'the payload text itself stays readable');
    }

    public function testCarriageReturnsBreakRowsLikeTheFrameSanitizerSays(): void
    {
        ToolTailSlots::set('call-1', "one\r\ntwo\rthree", false, 1);

        $out = Ansi::strip(self::paint('call-1', 80));

        self::assertStringContainsString('one', $out);
        self::assertStringContainsString('two', $out);
        self::assertStringContainsString('three', $out);
        self::assertSame(3, substr_count($out, "\n"), 'every break owns its row - a progress bar cannot overwrite a neighbour');
    }

    public function testAFrameEndingOnANewlineOwnsNoBlankRow(): void
    {
        ToolTailSlots::set('call-1', "done\n", false, 1);

        $out = self::paint('call-1', 80);

        self::assertSame(1, substr_count($out, "\n"), 'the shell has not written the next line yet; no gap is painted');
        self::assertSame('done', trim(Ansi::strip($out)));
    }

    public function testRowsAreCellClippedToTheFrameWidth(): void
    {
        ToolTailSlots::set('call-1', str_repeat('x', 300) . "\nwide\x{4E16}\x{754C}tail", false, 1);

        $out = self::paint('call-1', 40);

        foreach (array_slice(explode("\n", $out), 1) as $row) {
            self::assertLessThanOrEqual(40, Width::of(Ansi::strip($row)), 'one row per line, always');
        }
        self::assertStringEndsWith('…', Ansi::strip((string) explode("\n", $out)[1]), 'the clipped long line says so');
    }

    public function testAZeroWidthFramePaintsNothing(): void
    {
        ToolTailSlots::set('call-1', 'alpha', false, 1);

        self::assertSame('', self::paint('call-1', 0));
    }

    public function testTheRunningRowPaintsTheLiveTailEndToEnd(): void
    {
        ToolTailSlots::set('call-1', 'live-shell-line', false, 1);
        $chat = new Chat(
            history: [
                Message::user('go'),
                Message::toolRunning(new ToolCall('bash', ['command' => 'echo hi'], 'call-1')),
            ],
            backend: new EchoBackend(),
        );

        $frame = Ansi::strip(Renderer::render($chat));

        self::assertStringContainsString('live-shell-line', $frame, 'the dormant wire is live: the pump lands here, the row paints it');

        ToolTailSlots::clear('call-1');

        $without = Ansi::strip(Renderer::render($chat));
        self::assertStringNotContainsString('live-shell-line', $without, 'settling clears the slot and the row goes back to its old bytes');
    }

    /**
     * Paint just the tail block the running row would append, exactly the way
     * {@see Renderer::renderPendingToolCall()} invokes it - the private seam is
     * the contract under test, and going through the whole frame only for it
     * would couple these pins to unrelated layout drift.
     */
    private static function paint(string $callId, int $width): string
    {
        $method = new \ReflectionMethod(Renderer::class, 'tailLines');

        return (string) $method->invoke(null, $callId, Theme::default(), $width);
    }
}
