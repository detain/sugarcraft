<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\ServerStatus;

/**
 * Valid GTID_MODE values for SET @@GLOBAL.GTID_MODE.
 *
 * Used by the GTID mode selector in ServerStatusPage. The selector is
 * only active on MySQL ≥ 5.7.6 (GTID is not available before that).
 * Values are ordered for cycling (c key) through the dialog.
 *
 * @see Mirrors mysql-workbench/wb_admin_server_status GTID selector
 */
enum GtidMode: string
{
    case Off = 'OFF';
    case OffPermissive = 'OFF_PERMISSIVE';
    case OffSecure = 'OFF_SECURE';
    case OnPermissive = 'ON_PERMISSIVE';
    case On = 'ON';

    /**
     * All modes in cycling order for the dialog 'c' key.
     *
     * @return list<GtidMode>
     */
    public static function values(): array
    {
        return [
            self::Off,
            self::OffPermissive,
            self::OffSecure,
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
