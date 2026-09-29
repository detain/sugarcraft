<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * @internal
 */
final class WorkerState
{
    public bool $idle = true;

    public ?string $currentJobId = null;

    public string $buffer = '';

    /** Path to the worker's temp script, for cleanup on close. */
    public ?string $scriptPath = null;

    public function __construct(
        public readonly int $id,
        public $process,
        public $stdin,
        public $stdout,
        public $stderr,
    ) {
    }
}
