<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Components\Tree\TimeScale;

/**
 * D-143: Timeline/Gantt axis scaling selects its bucket width from this
 * enum; the four wire values are the config vocabulary.
 */
final class TimeScaleTest extends TestCase
{
    public function testRosterAndWireValues(): void
    {
        self::assertSame(
            ['Hours', 'Days', 'Weeks', 'Months'],
            array_map(static fn(TimeScale $s): string => $s->name, TimeScale::cases()),
        );
        self::assertSame(
            ['hours', 'days', 'weeks', 'months'],
            array_map(static fn(TimeScale $s): string => $s->value, TimeScale::cases()),
        );
    }

    public function testEveryCaseRoundTripsThroughItsBackedValue(): void
    {
        foreach (TimeScale::cases() as $case) {
            self::assertSame($case, TimeScale::from($case->value));
        }

        self::assertNull(TimeScale::tryFrom('years'));
    }
}
