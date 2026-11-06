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

    public function testTheClampedStackSharesOneRectBetweenTheLastTwoBoxes(): void
    {
        $rects = self::clampedStack()->actionRects('', 80, 4);

        $keys = [];
        foreach ($rects as $rect) {
            $keys[] = $rect['alertIndex'] . '@' . $rect['row'] . ':' . $rect['col'];
        }
        self::assertContains('1@2:31', $keys, 'middle box rect (regression guard on geometry)');
        self::assertContains('2@2:31', $keys, 'newest box rect lands on the SAME row/col');
    }

    public function testTheSharedPositionResolvesToTheNewestBoxesAction(): void
    {
        $toast = self::clampedStack();

        // Base-state tell: forward queue order returned 'Mid', the box
        // painted UNDER the newest one.
        $hit = $toast->actionAt(33, 2, '', 80, 4);

        self::assertNotNull($hit, 'the shared position must hit a button');
        self::assertSame('New', $hit->label, 'actionAt() must fire the VISIBLE box, not the buried one');
    }

    public function testNonCollidingPositionsStillResolveToTheirOwnBox(): void
    {
        $toast = self::clampedStack();

        // 'Old' sits alone lower in the frame — its rect is untouched by the
        // reversal, and a position outside every rect stays null.
        self::assertSame('Old', $toast->actionAt(33, 6, '', 80, 4)?->label);
        self::assertNull($toast->actionAt(1, 1, '', 80, 4));
    }

    public function testTopMostWinsRegardlessOfWhichOfTheSharedLabelsIsQueried(): void
    {
        // Spelling the law without a tautology: across the whole shared span
        // the resolved label is always the newest box's.
        $toast = self::clampedStack();
        $newest = null;
        foreach ($toast->actionRects('', 80, 4) as $rect) {
            if ($rect['row'] === 2) {
                $newest = max($newest ?? 0, $rect['alertIndex']);
            }
        }
        self::assertSame(2, $newest);

        for ($col = 31; $col < 36; $col++) {
            self::assertSame('New', $toast->actionAt($col, 2, '', 80, 4)?->label,
                "column {$col} of the shared span must resolve to the top box");
        }
    }
}
