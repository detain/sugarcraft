<?php

declare(strict_types=1);

namespace SugarCraft\Core\Progress;

use SugarCraft\Core\ProgressReporter;

/**
 * No-op progress reporter that does nothing.
 */
final class SilentProgressReporter implements ProgressReporter
{
    public function report(int $current, int $total, ?string $label = null): void
    {
        // No-op
    }
}
