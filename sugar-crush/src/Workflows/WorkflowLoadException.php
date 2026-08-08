<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Workflows;

/**
 * Thrown when a workflow file cannot be loaded or is invalid.
 */
final class WorkflowLoadException extends \InvalidArgumentException
{
    public function __construct(string $message = 'Failed to load workflow')
    {
        parent::__construct($message);
    }
}
