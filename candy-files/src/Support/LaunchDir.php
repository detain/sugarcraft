<?php

declare(strict_types=1);

namespace SugarCraft\Files\Support;

use SugarCraft\Files\Lang;

/**
 * CF-22 (closes audit 031, bin leg): the launcher's door check on each
 * argv directory. CF-16 (closes 060) moved locale detection to
 * right-after-autoload in the bin, so the reason prose is translated
 * here — under the default 'en' registry the strings are byte-identical
 * to the old raw English the LaunchDirTest pins.
 *
 * Extracted from `bin/candy-files` because the TTY guard fires first
 * there: a piped test process can never reach the inline probes, so the
 * refusal logic must exist somewhere a unit can stand at the door.
 */
final class LaunchDir
{
    /** Reason prose for an unusable launch directory, or null when usable. */
    public static function refusal(string $dir): ?string
    {
        if (!\is_dir($dir)) {
            return Lang::t('cli.reason_missing');
        }
        if (!\is_readable($dir)) {
            return Lang::t('cli.reason_unreadable');
        }
        return null;
    }
}
