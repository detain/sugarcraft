<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Workflows;

/**
 * Thrown when an operation requires a running or paused workflow but none exists.
 */
final class WorkflowNotRunningException extends \RuntimeException
{
}
