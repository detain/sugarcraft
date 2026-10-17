<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * Progress reporter interface for indicating task completion.
 */
interface ProgressReporter
{
    /**
     * Report progress of an operation.
     *
     * @param int $current Current step (0-indexed)
     * @param int $total Total number of steps
     * @param string|null $label Optional label describing the operation
     */
    public function report(int $current, int $total, ?string $label = null): void;
}
