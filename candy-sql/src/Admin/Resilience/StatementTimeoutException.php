<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Admin\Resilience;

use SugarCraft\Sql\Lang;

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
        ?string $message = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message ?? Lang::t('resilience.statement_timeout_default'), $code, $previous);
    }
}
