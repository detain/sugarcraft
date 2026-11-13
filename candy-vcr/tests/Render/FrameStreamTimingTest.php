<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Render;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Encode\PhpGifEncoder;
use SugarCraft\Vcr\Encode\TapeToGif;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Player;
use SugarCraft\Vcr\Raster\GdRasterizer;
use SugarCraft\Vcr\Render\FrameStream;
use SugarCraft\Vcr\Render\Renderer;
use SugarCraft\Vcr\Tape\Compiler;
use SugarCraft\Vcr\Tape\Lexer;
use SugarCraft\Vcr\Tape\Parser;
use SugarCraft\Vterm\Terminal;

/**
 * Frame-clock integrity of the render stream (findings 003/052/038/001-cap).
 *
 * The pipeline must show the grid the tape held AT each boundary — not the
 * grid of the NEXT event (two-phase flush), keep the opening frame empty
 * until the tape's first keystroke, extend the final held frame across a
 * trailing `Sleep`, suppress per-boundary duplicate snapshots on idle runs,
 * and abort degenerate clocks loudly at named caps instead of spinning.
 */
final class FrameStreamTimingTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/candy-vcr-timing-' . bin2hex(random_bytes(6));
        if (mkdir($this->tempDir, 0700, true) === false) {
            $this->markTestSkipped("Cannot create temp dir {$this->tempDir}");
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
    }

    private static function header(?float $speed = null): CassetteHeader
    {
        return new CassetteHeader(
            version: 1,
            createdAt: '2026-11-12T00:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'SugarCraft/Vcr timing test',
            playbackSpeed: $speed,
        );
    }

    /** @return list<\SugarCraft\Vterm\Snapshot> */
    private static function collect(FrameStream $stream): array
    {
        $out = [];
        foreach ($stream as $snapshot) {
            $out[] = $snapshot;
        }

        return $out;
    }

    /**
     * Finding 052: a tape that idles for ten seconds must mint the two frames
     * that changed, not ~300 byte-identical snapshots.
     */
    public function testIdleRunEmitsOnlyChangedFrames(): void
    {
        $cassette = new Cassette(self::header(), [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'a']),
            new Event(t: 10.0, kind: EventKind::Output, payload: ['b' => 'b']),
        ]);

        $stream = (new Renderer())->render(new Player($cassette), Terminal::new(80, 24), 30.0);
        $snapshots = self::collect($stream);

        $this->assertLessThan(5, count($snapshots), 'Idle boundaries must ride the held frame, not duplicate snapshots');
        $this->assertEqualsWithDelta(10.0, $snapshots[count($snapshots) - 1]->time - $snapshots[0]->time, 0.05);
    }

    /**
     * Finding 038 (opening half): the frame at t=0 must show the grid BEFORE
     * the tape's first keystroke — an empty terminal.
     */
    public function testOpeningFramePrecedesFirstEvent(): void
    {
        $cassette = new Cassette(self::header(), [
            new Event(t: 1.0, kind: EventKind::Input, payload: ['b' => 'hi']),
        ]);

        $snapshots = self::collect((new Renderer())->render(new Player($cassette), Terminal::new(80, 24), 30.0));

        $this->assertGreaterThanOrEqual(2, count($snapshots));
        $blank = Terminal::new(80, 24)->snapshot(0.0);
        $this->assertTrue(
            $snapshots[0]->equals($blank),
            'The t=0 frame must be the pre-typing grid'
        );
        $this->assertFalse(
            $snapshots[count($snapshots) - 1]->equals($blank),
            'The event must reach a later frame'
        );
    }

    /**
     * Findings 003/038 (trailing half): a compiled tape knows its end past
     * the last event, and the final held frame lives to that end — the GIF
     * shows the pause instead of collapsing it.
     */
    public function testCompiledTrailingSleepExtendsFinalHold(): void
    {
        $tapePath = $this->tempDir . '/trailing.tape';
        file_put_contents($tapePath, implode("\n", [
            'Set Theme "TokyoNight"',
            'Set FontSize 14',
            'Set Width 280',
            'Set Height 120',
            'Type "done"',
            'Sleep 2s',
        ]) . "\n");

        $lexer = new Lexer();
        $compiler = new Compiler();
        $cassette = $compiler->compile((new Parser())->parse($lexer->tokenize((string) file_get_contents($tapePath))), $tapePath);

        $this->assertNotNull($cassette->endTime, 'A compiled cassette carries its true end time');
        $this->assertGreaterThanOrEqual(2.1, $cassette->duration());

        $pipeline = new TapeToGif($lexer, new Parser(), $compiler, new Renderer(), new GdRasterizer(), new PhpGifEncoder());

        $method = new \ReflectionMethod(TapeToGif::class, 'buildFramesWithHolds');
        $stream = new FrameStream(new Player($cassette), Terminal::new(80, 24), 30.0);
        /** @var \Generator<int, array{snapshot: \SugarCraft\Vterm\Snapshot, hold: float}> $frames */
        $frames = $method->invoke($pipeline, $stream, 1.0 / 30.0, $cassette->endTime);

        $holds = [];
        foreach ($frames as $frame) {
            $holds[] = $frame['hold'];
        }

        $this->assertNotEmpty($holds);
        $this->assertGreaterThan(1.9, end($holds), 'The final frame must hold across the trailing Sleep');
        $this->assertGreaterThanOrEqual($cassette->duration() - 0.05, array_sum($holds), 'Total holds must cover the tape duration');
    }

    /**
     * Finding 001 (cap leg): the frame ceiling aborts with a named error —
     * never an endless duplicate stream running until the harness kills it.
     */
    public function testFrameCapAbortsDegenerateStream(): void
    {
        $cassette = new Cassette(self::header(), [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'a']),
            new Event(t: 5.0, kind: EventKind::Output, payload: ['b' => 'b']),
        ]);

        $stream = new FrameStream(new Player($cassette), Terminal::new(80, 24), 60.0, null, maxFrames: 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exceeded the render cap of 1 frames');
        $snapshots = self::collect($stream);
        $this->fail('unreachable: stream should have aborted at the cap, got ' . count($snapshots) . ' frames');
    }

    /**
     * Finding 001 (corrupted clock): a tape whose END rides past the runtime
     * cap — legal per-event timestamps whose trailing time still overflows
     * the ceiling — dies at the runtime cap, never at the harness timeout.
     * (A corrupted per-event `t` is rejected earlier, at the Event door; the
     * format-side edges live in tests/EventTest.php.)
     */
    public function testRuntimeCapRejectsCorruptedTimestamp(): void
    {
        $cassette = new Cassette(self::header(), [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'a']),
        ], endTime: 200_000.0);

        $stream = new FrameStream(new Player($cassette), Terminal::new(80, 24), 30.0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exceeds the render runtime cap');
        $snapshots = self::collect($stream);
        $this->fail('unreachable: stream should have aborted at the runtime cap, got ' . count($snapshots) . ' frames');
    }

    /**
     * Finding 038 (resize half): a mid-tape Resize must preserve the
     * visible grid (clamped to the new geometry) instead of wiping it —
     * the old `Terminal::new` swap turned every resize into a black flash.
     */
    public function testMidTapeResizePreservesVisibleGrid(): void
    {
        $typed = '';
        for ($line = 1; $line <= 7; $line++) {
            $typed .= "L{$line}\r\n";
        }

        $cassette = new Cassette(self::header(), [
            new Event(t: 0.0, kind: EventKind::Input, payload: ['b' => $typed]),
            new Event(t: 1.0, kind: EventKind::Resize, payload: ['cols' => 20, 'rows' => 4]),
        ]);

        $terminal = Terminal::new(40, 6);
        $snapshots = self::collect((new Renderer())->render(new Player($cassette), $terminal, 30.0));

        $this->assertGreaterThanOrEqual(2, count($snapshots));
        $pre = $snapshots[0];
        $final = $snapshots[count($snapshots) - 1];

        $this->assertSame(20, $final->grid->cols, 'The frame carries the new width');
        $this->assertSame(4, $final->grid->rows, 'The frame carries the new height');

        // The resized grid must be EXACTLY what Buffer::resize computes from the
        // pre-resize grid (copy of the intersecting region + wide-cell repair):
        // content survives the boundary; the old path blanked it wholesale.
        $expected = $pre->grid->resize(20, 4);
        $diff = 0;
        for ($row = 0; $row < 4; $row++) {
            for ($col = 0; $col < 20; $col++) {
                if ($final->grid->cell($row, $col)->char !== $expected->cell($row, $col)->char) {
                    $diff++;
                }
            }
        }
        $this->assertSame(0, $diff, 'Resized frame equals the reflowed pre-resize grid');

        $hasContent = false;
        $rowText = static function (int $row) use ($final): string {
            $out = '';
            for ($col = 0; $col < 20; $col++) {
                $out .= $final->grid->cell($row, $col)->char;
            }

            return $out;
        };
        for ($row = 0; $row < 4; $row++) {
            if (trim($rowText($row)) !== '') {
                $hasContent = true;
            }
        }
        $this->assertTrue($hasContent, 'A resized frame must not be the blank wipe the old path produced');
    }

    /**
     * End-to-end proof of the trailing-Sleep fix: two otherwise identical
     * tapes, one closing with `Sleep 2s`, must encode different bytes — the
     * pre-fix pipeline dropped the final event's frame and flattened the
     * pause to a single 1/fps hold.
     */
    public function testTrailingSleepChangesRenderedGifBytes(): void
    {
        $body = implode("\n", [
            'Set Theme "TokyoNight"',
            'Set FontSize 14',
            'Set Width 280',
            'Set Height 120',
            'Type "final"',
        ]);

        $quietPath = $this->tempDir . '/quiet.tape';
        $pausedPath = $this->tempDir . '/paused.tape';
        file_put_contents($quietPath, $body . "\n");
        file_put_contents($pausedPath, $body . "\nSleep 2s\n");

        $pipeline = new TapeToGif(new Lexer(), new Parser(), new Compiler(), new Renderer(), new GdRasterizer(), new PhpGifEncoder());
        $quietGif = $pipeline->render($quietPath, $this->tempDir . '/quiet.gif', ['encoder' => 'php', 'backend' => 'gd']);
        $pausedGif = $pipeline->render($pausedPath, $this->tempDir . '/paused.gif', ['encoder' => 'php', 'backend' => 'gd']);

        $this->assertFileExists($quietGif);
        $this->assertFileExists($pausedGif);
        $this->assertNotSame(
            md5_file($quietGif),
            md5_file($pausedGif),
            'A trailing Sleep must be visible in the encoded GIF (hold bytes differ)'
        );
    }
}
