<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Dashboard\Components\Tree\PertStatus;
use SugarCraft\Dashboard\Components\Tree\PertTask;

/**
 * D-143: the CPM task carrier. withDependencies REPLACES the public
 * list (clone-assign, not append) and withDuration rebuilds through the
 * constructor while the others clone — the mixed doors are exactly where
 * a slot-carry bug hides.
 */
final class PertTaskTest extends TestCase
{
    public function testDefaultStatusIsPendingAndChannelsEmpty(): void
    {
        $task = new PertTask('t1', 'Design', 4);

        self::assertSame(PertStatus::Pending, $task->status);
        self::assertSame([], $task->dependencies);
        self::assertNull($task->color);
    }

    public function testWithDependenciesReplacesTheWholeList(): void
    {
        $task = (new PertTask('t1', 'Design', 4))->withDependencies(['a']);

        $replaced = $task->withDependencies(['b', 'c']);

        self::assertSame(['b', 'c'], $replaced->dependencies, 'the door is set-semantics, not push');
        self::assertSame(['a'], $task->dependencies, 'the source keeps its own list');
    }

    public function testWithDurationCarriesIdentityNameColorAndStatus(): void
    {
        $color = Color::hex('#F9E2AF');
        $task = (new PertTask('t1', 'Design', 4, $color, PertStatus::InProgress))
            ->withDependencies(['a']);

        $grown = $task->withDuration(9);

        self::assertSame(9, $grown->duration);
        self::assertSame('t1', $grown->id);
        self::assertSame('Design', $grown->name);
        self::assertSame($color, $grown->color);
        self::assertSame(PertStatus::InProgress, $grown->status);
        self::assertSame(['a'], $grown->dependencies, 'the rebuild path must carry dependencies too');
        self::assertSame(4, $task->duration);
    }

    public function testStatusAndColorDoorsCarryEverythingElse(): void
    {
        $task = new PertTask('t1', 'Design', 4, null, PertStatus::Pending);

        $delayed = $task->withStatus(PertStatus::Delayed);
        self::assertSame(PertStatus::Delayed, $delayed->status);
        self::assertSame('t1', $delayed->id);
        self::assertSame(4, $delayed->duration);
        self::assertSame(PertStatus::Pending, $task->status, 'source untouched');

        $colored = $task->withColor(Color::hex('#94E2D5'));
        self::assertNotNull($colored->color);
        self::assertSame(PertStatus::Pending, $colored->status);
        self::assertNull($task->color);

        // D-143 real-bug pin: the color rebuild door used to lose the
        // dependency list (no ctor slot for it) — silently un-scheduling
        // recolored tasks in the CPM pass.
        $dependent = $task->withDependencies(['a']);
        self::assertSame(['a'], $dependent->withColor(Color::ansi(7))->dependencies);
    }

    public function testPertStatusWireRoster(): void
    {
        self::assertSame(
            ['Pending', 'InProgress', 'Completed', 'Delayed'],
            array_map(static fn(PertStatus $s): string => $s->name, PertStatus::cases()),
        );
        self::assertSame(
            ['pending', 'in_progress', 'completed', 'delayed'],
            array_map(static fn(PertStatus $s): string => $s->value, PertStatus::cases()),
        );
    }
}
