<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Resilience;

/**
 * Thrown when a statement exceeds its wall-clock timeout.
 *
 * Uses pcntl_alarm() when available; degrades gracefully if not.
 *
 * @see StatementTimeout
 */
final class StatementTimeoutException extends \RuntimeException
{
    public function __construct(
        string $message = 'Statement execution timed out',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
