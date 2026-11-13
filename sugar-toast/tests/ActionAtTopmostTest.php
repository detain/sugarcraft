<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Toast\Action;
use SugarCraft\Toast\Alert;
use SugarCraft\Toast\Position;
use SugarCraft\Toast\Toast;
use SugarCraft\Toast\ToastType;

/**
 * Pins the occlusion law of actionAt(): where a clamped stack drops two
 * boxes on the SAME frame rows, the hit-test resolves to the box the eye
 * sees — the newest, because view() composites boxes in queue order and a
 * later box paints OVER an earlier one. Queue-order matching (the old walk)
 * returned the buried button's callback, so 'the clickable set is exactly
 * what is on screen' was false on any clamped middle stack.
 */
final class ActionAtTopmostTest extends TestCase
{
    /**
     * Three never-expiring single-action alerts at MiddleRight inside a
     * viewport SHORTER than the stack: the middle anchor for the 2nd and
     * 3rd boxes clamps to row 0, so their action rows coincide. Labels are
     * equal length, keeping the shared rect's geometry identical.
     */
    private static function clampedStack(): Toast
    {
        $action = static fn(string $label): Action => new Action($label, static fn() => null);

        return Toast::new()
            ->withPosition(Position::MiddleRight)
            ->push(new Alert(ToastType::Info, 'first', null, null, [$action('Old')]))
            ->push(new Alert(ToastType::Info, 'second', null, null, [$action('Mid')]))
            ->push(new Alert(ToastType::Info, 'third', null, null, [$action('New')]));
    }

    public function testTheFoldedStackNoLongerPaintsOverlappingBoxes(): void
    {
        // TST-LAYOUT: the clamped middle stack used to paint boxes 1:1 over
        // each other (shared rects). The stack now folds to the budget, so
        // only the surviving box (the newest) has a rect — no buried button.
        $rects = self::clampedStack()->actionRects('', 80, 4);

        self::assertCount(1, $rects);
        self::assertSame(2, $rects[0]['alertIndex'], 'alertIndex stays in activeAlerts() space after a fold');
        self::assertSame('New', $rects[0]['action']->label);
        self::assertSame('New', self::clampedStack()->actionAt($rects[0]['col'], $rects[0]['row'], '', 80, 4)?->label);
        self::assertNull(self::clampedStack()->actionAt($rects[0]['col'], $rects[0]['row'] + 2, '', 80, 4));
    }

    public function testTopMostWinsOnAFrameWhoseRectsOverlap(): void
    {
        // The reverse-paint-order law still guards ToastFrame::actionAt():
        // later rects are composited OVER earlier ones, so they win a shared
        // cell. Built by hand now that Toast::frame() can no longer produce
        // the overlap.
        $old = new Action('Old', static fn() => null);
        $new = new Action('New', static fn() => null);
        $alert = new Alert(ToastType::Info, 'x');
        $rect = static fn (Action $a, int $i): array => [
            'action' => $a, 'alert' => $alert, 'alertIndex' => $i, 'actionIndex' => 0, 'row' => 2, 'col' => 31, 'width' => 5,
        ];
        $frame = new \SugarCraft\Toast\ToastFrame(0, 0, "x\n", [$rect($old, 0), $rect($new, 1)]);

        for ($col = 31; $col < 36; $col++) {
            self::assertSame('New', $frame->actionAt($col, 2)?->label, "column {$col}");
        }
        self::assertNull($frame->actionAt(36, 2));
    }
}
