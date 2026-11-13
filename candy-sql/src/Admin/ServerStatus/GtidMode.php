<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Admin\ServerStatus;

/**
 * Valid GTID_MODE values for SET @@GLOBAL.GTID_MODE.
 *
 * Used by the GTID mode selector in ServerStatusPage. The selector is
 * only active on MySQL ≥ 5.7.6 (GTID is not available before that) and
 * MySQL-family only — MariaDB has no gtid_mode variable at all, so the
 * dialog itself is flavor-gated (SQL-O06).
 * Values are ordered for cycling (c key) through the dialog.
 *
 * There are exactly FOUR documented values; an earlier shape carried a
 * fifth, OFF_SECURE, which the server refuses outright with ERROR 1231
 * on the very setting whose transition discipline is the dialog's whole
 * point (SQL-O06, candy-sql-once-006).
 *
 * @see Mirrors mysql-workbench/wb_admin_server_status GTID selector
 * @see https://dev.mysql.com/doc/refman/8.0/en/replication-gtids-howto.html GTID mode state values
 */
enum GtidMode: string
{
    case Off = 'OFF';
    case OffPermissive = 'OFF_PERMISSIVE';
    case OnPermissive = 'ON_PERMISSIVE';
    case On = 'ON';

    /**
     * All modes in cycling order for the dialog 'c' key.
     *
     * Pinned to exactly four members by GtidModeTest: a revived fifth
     * value (e.g. OFF_SECURE) breaks the dialog against real servers.
     *
     * @return list<GtidMode>
     */
    public static function values(): array
    {
        return [
            self::Off,
            self::OffPermissive,
            self::OnPermissive,
            self::On,
        ];
    }

    /**
     * True if this mode requires GTID to be enabled (ON or ON_PERMISSIVE).
     */
    public function requiresGtidOn(): bool
    {
        return match ($this) {
            self::On, self::OnPermissive => true,
            default => false,
        };
    }
}
