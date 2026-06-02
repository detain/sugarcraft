<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Resilience;

/**
 * Thrown when reconnection to MySQL fails after a connection error.
 *
 * @see ReconnectManager::attemptReconnect()
 */
final class ReconnectException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly \PDOException $original,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Get the original PDO exception that triggered the reconnect attempt.
     */
    public function getOriginal(): \PDOException
    {
        return $this->original;
    }
}
