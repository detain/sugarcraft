<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin\ServerStatus;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Admin\ServerStatus\GtidMode;

/**
 * Pins for the GTID mode whitelist (SQL-O06).
 *
 * MySQL documents exactly four gtid_mode values; an earlier shape carried
 * a fifth, OFF_SECURE, which the server refuses with ERROR 1231 — a
 * fictional mode on the replication-critical setting whose transition
 * discipline is the dialog's whole point. These pins make reviving it (or
 * reordering the cycle) fail loudly.
 */
final class GtidModeTest extends TestCase
{
    public function testValuesAreExactlyTheFourDocumentedModesInCycleOrder(): void
    {
        self::assertSame(
            ['OFF', 'OFF_PERMISSIVE', 'ON_PERMISSIVE', 'ON'],
            array_map(static fn (GtidMode $mode): string => $mode->value, GtidMode::values()),
        );
    }

    public function testEnumBackedCasesMatchCycleList(): void
    {
        // values() is the cycling whitelist; the enum itself must not grow
        // beyond it (a new case forgotten out of values() would be
        // unreachable, and one added to values() without the server knowing
        // it is a new OFF_SECURE).
        self::assertSame(
            ['OFF', 'OFF_PERMISSIVE', 'ON_PERMISSIVE', 'ON'],
            array_map(static fn (GtidMode $mode): string => $mode->value, GtidMode::cases()),
        );
    }

    public function testRequiresGtidOnFlagsTheTwoOnModes(): void
    {
        self::assertFalse(GtidMode::Off->requiresGtidOn());
        self::assertFalse(GtidMode::OffPermissive->requiresGtidOn());
        self::assertTrue(GtidMode::OnPermissive->requiresGtidOn());
        self::assertTrue(GtidMode::On->requiresGtidOn());
    }
}
