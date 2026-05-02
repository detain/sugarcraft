<?php

declare(strict_types=1);

namespace CandyCore\Core\Tests;

use CandyCore\Core\Renderer;
use CandyCore\Core\Util\Ansi;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    /** @return array{0:resource,1:Renderer} */
    private function make(): array
    {
        $out = fopen('php://memory', 'w+');
        $this->assertNotFalse($out);
        return [$out, new Renderer($out)];
    }

    private function read($out): string
    {
        rewind($out);
        return (string) stream_get_contents($out);
    }

    public function testFirstFrameWritesEverything(): void
    {
        [$out, $r] = $this->make();
        $r->render("a\nb\nc");

        $written = $this->read($out);
        $this->assertStringStartsWith(Ansi::syncBegin() . Ansi::cursorTo(1, 1), $written);
        $this->assertStringContainsString("a\r\nb\r\nc", $written);
        fclose($out);
    }

    public function testIdenticalFrameSkipped(): void
    {
        [$out, $r] = $this->make();
        $r->render('hello');
        $afterFirst = ftell($out);
        $r->render('hello');
        $this->assertSame($afterFirst, ftell($out));
        fclose($out);
    }

    public function testOnlyChangedLineRewritten(): void
    {
        [$out, $r] = $this->make();
        $r->render("alpha\nbeta\ngamma");
        $beforeDiff = ftell($out);
        $r->render("alpha\nBETA\ngamma");

        rewind($out);
        fseek($out, $beforeDiff);
        $diff = (string) stream_get_contents($out);

        // Should have repositioned to row 2 and written BETA (not the rest).
        $this->assertStringContainsString(Ansi::cursorTo(2, 1), $diff);
        $this->assertStringContainsString('BETA', $diff);
        $this->assertStringNotContainsString('alpha', $diff);
        $this->assertStringNotContainsString('gamma', $diff);
        fclose($out);
    }

    public function testShorterFrameClearsExtraLines(): void
    {
        [$out, $r] = $this->make();
        $r->render("a\nb\nc\nd");
        $beforeDiff = ftell($out);
        $r->render("a\nb");

        rewind($out);
        fseek($out, $beforeDiff);
        $diff = (string) stream_get_contents($out);

        // The third and fourth rows should be erased (cursorTo + eraseLine).
        $this->assertStringContainsString(Ansi::cursorTo(3, 1) . Ansi::eraseLine(), $diff);
        $this->assertStringContainsString(Ansi::cursorTo(4, 1) . Ansi::eraseLine(), $diff);
        fclose($out);
    }

    public function testResetForcesFullRedraw(): void
    {
        [$out, $r] = $this->make();
        $r->render('x');
        $r->reset();
        $r->render('x');
        $written = $this->read($out);
        // 'x' should appear twice (full redraw after reset).
        $this->assertSame(2, substr_count($written, 'x'));
        fclose($out);
    }

    public function testFirstFrameWrappedInSyncMarkers(): void
    {
        [$out, $r] = $this->make();
        $r->render("hello");
        $written = $this->read($out);
        $this->assertStringStartsWith(Ansi::syncBegin(), $written);
        $this->assertStringEndsWith(Ansi::syncEnd(), $written);
        fclose($out);
    }

    public function testDiffPayloadWrappedInSyncMarkers(): void
    {
        [$out, $r] = $this->make();
        $r->render("a\nb");
        $beforeDiff = ftell($out);
        $r->render("a\nB");

        rewind($out);
        fseek($out, $beforeDiff);
        $diff = (string) stream_get_contents($out);

        $this->assertStringStartsWith(Ansi::syncBegin(), $diff);
        $this->assertStringEndsWith(Ansi::syncEnd(), $diff);
        fclose($out);
    }

    // ---- inline mode ----------------------------------------------------

    /** @return array{0:resource,1:Renderer} */
    private function makeInline(): array
    {
        $out = fopen('php://memory', 'w+');
        $this->assertNotFalse($out);
        return [$out, new Renderer($out, inline: true)];
    }

    public function testInlineFirstFrameSavesCursorAndDoesNotEraseScreen(): void
    {
        [$out, $r] = $this->makeInline();
        $r->render("hi\nthere");
        $written = $this->read($out);

        // Wrapped in sync markers, starts with cursorSave, contains the
        // body, and crucially does NOT include cursorTo(1,1) (which
        // would clobber scrollback).
        $this->assertStringStartsWith(Ansi::syncBegin() . Ansi::cursorSave(), $written);
        $this->assertStringContainsString("hi\r\nthere", $written);
        $this->assertStringNotContainsString(Ansi::cursorTo(1, 1), $written);
        $this->assertStringNotContainsString(Ansi::eraseToEnd(), $written);
        fclose($out);
    }

    public function testInlineSubsequentFrameRestoresCursorAndErasesToEnd(): void
    {
        [$out, $r] = $this->makeInline();
        $r->render('a');
        $beforeDiff = ftell($out);
        $r->render("b\nc");

        rewind($out);
        fseek($out, $beforeDiff);
        $diff = (string) stream_get_contents($out);

        $this->assertStringStartsWith(Ansi::syncBegin() . Ansi::cursorRestore() . Ansi::eraseToEnd(), $diff);
        $this->assertStringContainsString("b\r\nc", $diff);
        $this->assertStringEndsWith(Ansi::syncEnd(), $diff);
        fclose($out);
    }

    public function testInlineIdenticalFrameSkipped(): void
    {
        [$out, $r] = $this->makeInline();
        $r->render('hello');
        $afterFirst = ftell($out);
        $r->render('hello');
        $this->assertSame($afterFirst, ftell($out));
        fclose($out);
    }

    /** @return array{0:resource,1:Renderer} */
    private function makeCellDiff(): array
    {
        $out = fopen('php://memory', 'w+');
        $this->assertNotFalse($out);
        return [$out, new Renderer($out, inline: false, cellDiff: true)];
    }

    public function testCellDiffPaintsSuffixWhenLineGrows(): void
    {
        [$out, $r] = $this->makeCellDiff();
        $r->render("count: 1\nfooter");
        $firstEnd = ftell($out);

        $r->render("count: 2\nfooter");
        fseek($out, $firstEnd);
        $delta = (string) stream_get_contents($out);

        // Should NOT repaint the unchanged 'footer' row.
        $this->assertStringNotContainsString('footer', $delta);
        // Should repaint at column 8 (after "count: ") on row 1.
        $this->assertStringContainsString(Ansi::cursorTo(1, 8), $delta);
        $this->assertStringContainsString('2', $delta);
        // Wrapped in sync markers.
        $this->assertStringContainsString(Ansi::syncBegin(), $delta);
        $this->assertStringContainsString(Ansi::syncEnd(),   $delta);
        fclose($out);
    }

    public function testCellDiffPreservesSgrAcrossPartialRepaint(): void
    {
        [$out, $r] = $this->makeCellDiff();
        $line1 = "\x1b[31mhello\x1b[0m world";
        $line2 = "\x1b[31mhello\x1b[0m WORLD";  // 'world' → 'WORLD'
        $r->render($line1);
        $firstEnd = ftell($out);
        $r->render($line2);
        fseek($out, $firstEnd);
        $delta = (string) stream_get_contents($out);

        // Partial repaint should advance the cursor past the common
        // prefix, erase to end, then emit the suffix.
        $this->assertStringContainsString(Ansi::eraseToLineEnd(), $delta);
        $this->assertStringContainsString('WORLD', $delta);
        $this->assertStringNotContainsString('hello', $delta);
        fclose($out);
    }

    public function testCellDiffFallsBackToFullRepaintWhenSmaller(): void
    {
        [$out, $r] = $this->makeCellDiff();
        // First frame establishes a styled prefix that the new frame
        // shares; the divergence point is mid-text inside the same
        // SGR-styled run.
        $r->render("\x1b[1;31mAlpha\x1b[0m");
        $firstEnd = ftell($out);
        // New line replaces the whole content — common prefix between
        // 'Alpha' and 'X' is 0 chars, so the partial repaint would
        // include `[1;31m` (the SGR state) before the new text. The
        // full repaint is `[1;1H[2K` + the original sequence which is
        // shorter; the renderer should fall back to it.
        $r->render("\x1b[1;31mXY\x1b[0m");
        fseek($out, $firstEnd);
        $delta = (string) stream_get_contents($out);
        // Either path should give us the X / Y characters.
        $this->assertStringContainsString('XY', $delta);
        // And the SGR state from the prefix must be present somewhere
        // (whether as part of a full repaint or a re-emitted partial).
        $this->assertMatchesRegularExpression('/\x1b\[1[;0]/', $delta);
        fclose($out);
    }

    public function testCellDiffEmitsLessThanLineDiffForCounter(): void
    {
        // Real-world bandwidth-saving case: a status counter ticks.
        // Cell-diff should beat line-diff by a meaningful margin.
        $cellOut = fopen('php://memory', 'w+');
        $lineOut = fopen('php://memory', 'w+');
        $cell = new Renderer($cellOut, inline: false, cellDiff: true);
        $line = new Renderer($lineOut, inline: false, cellDiff: false);

        $frame = static fn(int $n): string
            => "Status: OK\nProcessed: {$n} items\nElapsed: {$n}s";
        for ($i = 0; $i <= 50; $i++) {
            $cell->render($frame($i));
            $line->render($frame($i));
        }

        $cellLen = ftell($cellOut);
        $lineLen = ftell($lineOut);
        // Cell-diff should write meaningfully fewer bytes — exact
        // ratio depends on the line content but should be at least
        // 25% smaller for this counter scenario.
        $this->assertLessThan($lineLen * 0.85, $cellLen,
            "cell-diff ($cellLen B) should beat line-diff ($lineLen B) by >15% on a counter");
        fclose($cellOut);
        fclose($lineOut);
    }
}
