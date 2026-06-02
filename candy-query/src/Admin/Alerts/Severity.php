<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Alerts;

/**
 * Alert severity tiers.
 *
 * Maps to ToastType from sugar-toast but defined locally so this
 * package has no hard dependency on sugar-toast internals.
 */
enum Severity
{
    case Info;
    case Warning;
    case Critical;

    /**
     * Convert to sugar-toast ToastType.
     *
     * Info maps to ToastType::Info, Warning to ToastType::Warning,
     * and Critical to ToastType::Error (since critical is more
     * severe than error in our taxonomy and maps to the most
     * prominent toast type).
     */
    public function toToastType(): \SugarCraft\Toast\ToastType
    {
        return match ($this) {
            self::Info     => \SugarCraft\Toast\ToastType::Info,
            self::Warning  => \SugarCraft\Toast\ToastType::Warning,
            self::Critical => \SugarCraft\Toast\ToastType::Error,
        };
    }
}
