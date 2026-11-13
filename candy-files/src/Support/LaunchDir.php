<?php

declare(strict_types=1);

namespace SugarCraft\Files\Support;

/**
 * CF-22 (closes audit 031, bin leg): the launcher's door check on each
 * argv directory. Prose is deliberately raw English — the check runs
 * BEFORE locale detection (`T::detect()` lives below it in the bin), the
 * same headless-stderr shape as the CF-19 TTY gate.
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
            return 'does not exist';
        }
        if (!\is_readable($dir)) {
            return 'is not readable';
        }
        return null;
    }
}
