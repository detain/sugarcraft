<?php

declare(strict_types=1);

namespace SugarCraft\Core\Msg;

use SugarCraft\Core\Msg;

/**
 * Dispatched when a worker finishes executing a callable, carrying
 * the result (or exception) back to the calling model.
 */
final readonly class WorkerResultMsg implements Msg
{
    /**
     * @param mixed $result The serialized return value of the worker callable
     * @param ?\Throwable $error Any exception thrown inside the worker
     * @param int $workerId Which worker processed this job (for debugging/logging)
     */
    public function __construct(
        public mixed $result,
        public ?\Throwable $error = null,
        public int $workerId = 0,
    ) {}
}
