<?php

declare(strict_types=1);

namespace SugarCraft\Code\Workflows;

/**
 * Thrown when a workflow file cannot be loaded or is invalid.
 *
 * Carries the full \Exception trio: the parse-wrap sites chain the original
 * PCRE/JSON error as $previous, so dropping it would silently erase the
 * author's root cause. Defaults preserve the single-message call sites.
 */
final class WorkflowLoadException extends \InvalidArgumentException
{
    public function __construct(string $message = 'Failed to load workflow', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
