<?php

declare(strict_types=1);

namespace SugarCraft\Panels\Tests;

use SugarCraft\Panels\{Node, SugarPanels};
use PHPUnit\Framework\TestCase;

/**
 * Pins the frame protocol: every frame is self-positioning, and the erase that
 * a resize needs is the ONLY thing that ever wipes the screen.
 *
 * Diff ops address cells absolutely but carried no positioning before their
 * first change, so a delta used to paint wherever the previous frame left the
 * cursor — rendering 'A' then 'B' into a 1x1 cell produced 'AB' on screen.
 * Full frames were cursor-relative for the same reason.
 *
 * The frames pin the exact bytes sugar-cellbuffer's encoder emits plus panels'
 * own home: the encoder opens every stream with an unconditional home CUP
 * (sugar-cellbuffer 216b32c12), which the renderer peels on the diff path so a
 * frame carries exactly one home token — these pins are what turn a drift in
 * that prologue into a loud red instead of a silently double-homed frame.
 */
final class FrameProtocolTest extends TestCase
{
    private const HOME = "\x1b[H";
    private const ERASE = "\x1b[2J";

    public function testAFirstFrameOpensAtTheCursorOrigin(): void
    {
        $frame = SugarPanels::new()->render(Node::leaf('hello')->withBorder(false), 8, 2);

        $this->assertStringStartsWith(self::HOME, $frame, 'a full frame positions itself');
        $this->assertStringNotContainsString(self::ERASE, $frame, 'a first frame must not wipe the host screen');
    }

    public function testADeltaLandsOnTheSameOriginAsTheFrameBeforeIt(): void
    {
        $panels = SugarPanels::new();
        $first = $panels->render(Node::leaf('A')->withBorder(false), 1, 1);
        $second = $panels->render(Node::leaf('B')->withBorder(false), 1, 1);

        $this->assertSame(self::HOME . 'A', $first);
        $this->assertSame(self::HOME . 'B', $second, 'the changed cell is painted at the origin, not after the previous frame');
    }

    public function testADeltaCarriesExactlyOneHomeToken(): void
    {
        $panels = SugarPanels::new();
        $panels->render(Node::leaf('ABC')->withBorder(false), 3, 1);
        $delta = $panels->render(Node::leaf('A C')->withBorder(false), 3, 1);

        // The encoder's own home prologue (ESC [ 1;1H) is peeled by the
        // renderer — the frame above already homes — but a mid-stream CUP to
        // the changed cell must survive: strip the prologue here and this reads
        // as a bare space painted at the origin.
        $this->assertSame(self::HOME . "\x1b[1;2H ", $delta);
        $this->assertStringNotContainsString("\x1b[1;1H", $delta, 'one home token per frame');
    }

    public function testBothFramePathsOpenWithTheSameBytes(): void
    {
        $panels = SugarPanels::new();
        $node = Node::leaf('one')->withBorder(false);
        $full = $panels->render($node, 5, 1);
        $delta = $panels->render(Node::leaf('two')->withBorder(false), 5, 1);

        $this->assertSame(
            substr($full, 0, strlen(self::HOME)),
            substr($delta, 0, strlen(self::HOME)),
            'a host cannot tell a full frame from a delta by where it starts',
        );
    }

    public function testAResizeErasesBeforeRepainting(): void
    {
        $panels = SugarPanels::new();
        $panels->render(Node::leaf('hello')->withBorder(false), 8, 2);

        // 8x2 -> 4x2: cells the new viewport cannot reach keep the old glyphs
        // unless the frame clears them first.
        $shrunk = $panels->render(Node::leaf('hello')->withBorder(false), 4, 2);

        $this->assertStringStartsWith(self::ERASE . self::HOME, $shrunk, 'a resize retires cells it can no longer paint');
    }

    public function testAGrowingResizeAlsoErases(): void
    {
        $panels = SugarPanels::new();
        $panels->render(Node::leaf('a')->withBorder(false), 4, 1);
        $grown = $panels->render(Node::leaf('a')->withBorder(false), 9, 1);

        $this->assertStringStartsWith(self::ERASE . self::HOME, $grown);
    }

    public function testAFrameAfterAManualResetHomesWithoutErasing(): void
    {
        $panels = SugarPanels::new();
        $node = Node::leaf('hello')->withBorder(false);
        $panels->render($node, 8, 2);
        $panels->render($node, 4, 2);

        $panels->resetPreviousFrame();
        $frame = $panels->render($node, 4, 2);

        $this->assertStringStartsWith(self::HOME, $frame, 'a reset makes the next frame full');
        $this->assertStringNotContainsString(self::ERASE, $frame, 'a manual reset wipes nothing — the same size was already on screen');
    }

    public function testAZeroAreaFrameCostsNoProtocolBytes(): void
    {
        $empty = SugarPanels::new()->render(Node::leaf('x')->withBorder(false), 10, 0);
        $this->assertSame('', $empty, 'nothing to paint means nothing to position');

        $noColumns = SugarPanels::new()->render(Node::leaf('x')->withBorder(false), 0, 3);
        $this->assertStringNotContainsString("\x1b", $noColumns);
    }

    public function testEraseIsNotStoredAsContentAnywhereInTheBody(): void
    {
        $panels = SugarPanels::new();
        $panels->render(Node::leaf('hello')->withBorder(false), 8, 2);
        $shrunk = substr(
            $panels->render(Node::leaf('hello')->withBorder(false), 4, 2),
            strlen(self::ERASE . self::HOME),
        );

        $this->assertStringNotContainsString(self::ERASE, $shrunk, 'exactly one erase, at the front');
        $this->assertStringNotContainsString(self::HOME, $shrunk);
    }
}
