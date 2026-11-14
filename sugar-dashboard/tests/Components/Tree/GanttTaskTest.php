<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Dashboard\Components\Tree\GanttTask;

/**
 * D-143: asMilestone is a simultaneous three-slot coercion (duration to
 * 0, progress to 1.0, flag to true) and withProgress carries the other
 * five slots through a six-positional-arg rebuild — the exact place a
 * reordered-argument refactor silently corrupts bars.
 */
final class GanttTaskTest extends TestCase
{
    public function testDefaultsAreUnstartedNonMilestoneWithNullProgress(): void
    {
        $task = new GanttTask('design', 0, 5);

        self::assertNull($task->progress);
        self::assertFalse($task->milestone);
        self::assertNull($task->color);
    }

    public function testWithProgressCarriesEveryOtherSlot(): void
    {
        $color = Color::hex('#89B4FA');
        $base = new GanttTask('build', 3, 10, $color, null, false);

        $done = $base->withProgress(0.75);

        self::assertSame('build', $done->name);
        self::assertSame(3, $done->startDay);
        self::assertSame(10, $done->duration);
        self::assertSame($color, $done->color);
        self::assertSame(0.75, $done->progress);
        self::assertFalse($done->milestone);
        self::assertNull($base->progress, 'the source task must not move');
    }

    public function testAsMilestoneCoercesAllThreeSlotsAtOnce(): void
    {
        $color = Color::hex('#F38BA8');
        $task = new GanttTask('review', 4, 8, $color, 0.2, false);

        $mark = $task->asMilestone();

        // duration forces to 0, progress completes, the flag flips —
        // a milestone is an instantaneous completed checkpoint.
        self::assertSame(0, $mark->duration);
        self::assertSame(1.0, $mark->progress);
        self::assertTrue($mark->milestone);
        self::assertSame('review', $mark->name);
        self::assertSame(4, $mark->startDay);
        self::assertSame($color, $mark->color);
        // Source untouched.
        self::assertSame(8, $task->duration);
        self::assertSame(0.2, $task->progress);
        self::assertFalse($task->milestone);
    }
}
