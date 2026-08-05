<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Workflows;

/**
 * Thrown when a stage type (e.g., 'parallel', 'pipeline') is not supported
 * by the current workflow engine implementation.
 */
final class UnsupportedStageTypeException extends \RuntimeException
{
    public function __construct(string $message = 'Stage type not supported')
    {
        parent::__construct($message);
    }
}
