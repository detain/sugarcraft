<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Toast\{Action, Position, Toast, ToastFrame, ToastType};

/**
 * Pins the TST-FRAME crown (findings 001 + 026): the layout memo behind
 * view()/frame(), the ToastFrame value object, and the LogicException door
 * that stops hit-tests from silently describing a frame nobody rendered.
 */
final class ToastFrameTest extends TestCase
{
    private function toastWithAction(Position $position = Position::TopLeft): Toast
    {
        return Toast::new(40)
            ->withPosition($position)
            ->alert(ToastType::Info, 'frame under cursor', actions: [
                new Action('Save', static function (): void {}),
            ]);
    }

    /** @param list<string> $rows */
    private function background(array $rows): string
    {
        return \implode("\n", $rows) . "\n";
    }

    public function testFrameBytesMatchTheRenderExactly(): void
    {
        $toast = $this->toastWithAction();
        $bg = $this->background(\array_fill(0, 40, 'host row'));
        $out = $toast->view($bg, 120, 40);

        $frame = $toast->frame();

        $this->assertInstanceOf(ToastFrame::class, $frame);
        $this->assertSame($out, $frame->rows);
        $this->assertSame(\rtrim($out, "\n"), \implode("\n", $frame->boxRows()));

        // Top-box origin is the geometry the rects live inside: every rect
        // sits right of x and below y.
        $rects = $frame->actionRects();
        $this->assertNotSame([], $rects);
        foreach ($rects as $rect) {
            $this->assertGreaterThanOrEqual($frame->x, $rect['col']);
            $this->assertGreaterThanOrEqual($frame->y, $rect['row']);
        }
    }

    public function testActionAtAtDefaultsHitsTheRenderedFrame(): void
    {
        // The 001 repro: a BottomRight box over a 120x40 frame lands on rows
        // the legacy ''/80/24 recompute never placed it on. The default call
        // must describe the SCREEN, not a hypothetical terminal.
        $toast = $this->toastWithAction(Position::BottomRight);
        $bg = $this->background(\array_fill(0, 40, 'host row'));
        $toast->view($bg, 120, 40);

        $rect = $toast->actionRects()[0];
        $this->assertInstanceOf(Action::class, $toast->actionAt($rect['col'] + 1, $rect['row']));
        $this->assertNull($toast->actionAt($rect['col'] + 1, $rect['row'] - 3));
    }

    public function testFrameArgsDifferingFromTheRenderThrow(): void
    {
        $toast = $this->toastWithAction();
        $bg = $this->background(\array_fill(0, 12, 'host row'));
        $toast->view($bg, 60, 12);

        // Wrong size AND stale legacy defaults on both doors.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('the last view() rendered 60x12');
        $toast->actionRects('', 80, 24);
    }

    public function testMarkedBackgroundMismatchThrowsOnActionAt(): void
    {
        $toast = $this->toastWithAction();
        $bg = $this->background(\array_fill(0, 12, 'host row'));
        $toast->view($bg, 60, 12);

        $this->expectException(\LogicException::class);
        $toast->actionAt(5, 5, 'a different background', 60, 12);
    }

    public function testMismatchedHeightOnFrameDoorThrows(): void
    {
        $toast = $this->toastWithAction();
        $toast->view('', 60, 12);

        $this->expectException(\LogicException::class);
        $toast->frame('', 60, 13);
    }

    public function testMemoIsDroppedByStateMutation(): void
    {
        // mutate() must never carry a stale memo onto the fresh clone: with
        // the invalidation removed, the no-arg frame() below would read the
        // pre-alert 120x40 render instead of the legacy defaults.
        $toast = $this->toastWithAction();
        $bg = $this->background(\array_fill(0, 40, 'host row'));
        $toast->view($bg, 120, 40);

        $grown = $toast->alert(ToastType::Warning, 'second arrival', actions: [
            new Action('Later', static function (): void {}),
        ]);

        // Sequenced on purpose: frame() must settle BEFORE any view() can
        // refresh a stale inherited memo — capture first, render second.
        $frameRows = $grown->frame()?->rows;
        $this->assertSame($grown->view('', 80, 24), $frameRows);
    }

    public function testPreRenderDoorsKeepLegacyDefaultShape(): void
    {
        // Additive law: before any render, omitted args behave exactly like
        // the old ('', 80, 24) defaults — no exception, same rects.
        $toast = $this->toastWithAction();

        $this->assertSame($toast->actionRects('', 80, 24), $toast->actionRects());
        $rect = $toast->actionRects()[0];
        $this->assertInstanceOf(Action::class, $toast->actionAt($rect['col'], $rect['row']));
    }

    public function testPlaceAtInPlaceReproducesTheCompositedBoxRowsAndRects(): void
    {
        // FRAME-inset: over a blank background, the cropped box rows are
        // exactly the composited rows' box rectangle.
        $toast = $this->toastWithAction(Position::TopRight);
        $frame = $toast->frame('', 60, 10);
        $this->assertNotNull($frame->bounds);
        ['x' => $bx, 'y' => $by, 'width' => $bw, 'height' => $bh] = $frame->bounds;

        $placed = $frame->placeAt($bx, $by);
        $this->assertSame($frame->actionRects(), $placed->actionRects());
        $this->assertSame($bh, \count($placed->boxRows()));
        $full = $frame->boxRows();
        foreach ($placed->boxRows() as $i => $line) {
            $this->assertSame(
                \SugarCraft\Core\Util\Width::takeAnsi(\SugarCraft\Core\Util\Width::dropAnsi($full[$by + $i], $bx), $bw),
                $line
            );
            $this->assertSame($bw, \SugarCraft\Core\Util\Width::string($line));
        }
    }

    public function testPlaceAtInsetTranslatesOriginBoundsAndHitRects(): void
    {
        $toast = $this->toastWithAction(Position::TopRight);
        $frame = $toast->frame('', 60, 10);
        $inset = $frame->placeAt(3, 2);

        $this->assertSame(['x' => 3, 'y' => 2, 'width' => $frame->bounds['width'], 'height' => $frame->bounds['height']], $inset->bounds);
        $orig = $frame->actionRects()[0];
        $moved = $inset->actionRects()[0];
        $this->assertSame($orig['col'] - $frame->bounds['x'] + 3, $moved['col']);
        $this->assertSame($orig['row'] - $frame->bounds['y'] + 2, $moved['row']);
        $this->assertNotNull($inset->actionAt($moved['col'], $moved['row']));
        $this->assertNull($frame->placeAt(3, 2)->actionAt($orig['col'], $orig['row'] + 20));
    }

    public function testPlaceAtOnAHandBuiltFrameThrows(): void
    {
        $this->expectException(\LogicException::class);
        (new ToastFrame(0, 0, "x\n"))->placeAt(1, 1);
    }

    public function testBoundsSpanTheWholeStack(): void
    {
        $toast = Toast::new(30)->withPosition(Position::TopLeft)
            ->alert(ToastType::Info, 'one')->alert(ToastType::Info, 'two');
        $frame = $toast->frame('', 60, 20);
        $this->assertSame(0, $frame->bounds['x']);
        $this->assertSame(0, $frame->bounds['y']);
        $this->assertSame(6, $frame->bounds['height']);
    }
}
