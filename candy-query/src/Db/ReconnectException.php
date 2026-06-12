<?php

declare(strict_types=1);

namespace SugarCraft\Query\Db;

/**
 * Thrown when reconnection to the database fails after a connection error.
 *
 * Lives in the Db layer (it is raised by DatabaseInterface implementations)
 * so concrete drivers do not have to reach up into the Admin layer.
 *
 * @see ReconnectManagerInterface::attemptReconnect()
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
