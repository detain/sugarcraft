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
}
