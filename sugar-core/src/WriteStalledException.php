<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * A {@see Writer} made no progress for its stall bound and dropped the
 * bytes it had not handed off. Carries the backlog counters so a caller can
 * name how far the stream got (the worker pool reports them in its
 * rejection).
 */
final class WriteStalledException extends \RuntimeException
{
    public function __construct(
        public readonly float $seconds,
        public readonly int $written,
        public readonly int $total,
    ) {
        parent::__construct(sprintf(
            'output stream stalled: no progress for %.1fs, %d of %d bytes handed off',
            $seconds,
            $written,
            $total,
        ));
    }
}
