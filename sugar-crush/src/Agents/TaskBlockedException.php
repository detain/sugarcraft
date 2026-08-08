<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

/**
 * Thrown when a TaskCreated hook blocks the insertion of a new task.
 */
final class TaskBlockedException extends \RuntimeException
{
    public function __construct(
        public readonly string $taskId,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : "Task creation blocked: {$taskId}");
    }
}
