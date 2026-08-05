<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Workflows;

/**
 * Thrown when a requested workflow cannot be found.
 */
final class WorkflowNotFoundException extends \RuntimeException
{
    public function __construct(string $message = 'Workflow not found')
    {
        parent::__construct($message);
    }
}
