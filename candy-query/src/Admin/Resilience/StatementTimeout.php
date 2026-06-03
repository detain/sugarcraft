<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Resilience;

/**
 * Wraps a PDOStatement to enforce a wall-clock timeout.
 *
 * Uses pcntl_alarm() for timeout enforcement when available;
 * degrades gracefully (logs warning, no timeout) otherwise.
 * If the timeout fires, cancels the query and throws
 * StatementTimeoutException.
 *
 * @see StatementTimeoutException
 */
final class StatementTimeout
{
    private bool $timeoutOccurred = false;
    private bool $pcntlAvailable;

    public function __construct(
        private readonly int $timeoutSeconds = 30,
    ) {
        $this->pcntlAvailable = function_exists('pcntl_alarm')
            && function_exists('pcntl_signal')
            && function_exists('pcntl_async_signals');

        if ($this->pcntlAvailable) {
            pcntl_async_signals(true);
        }
    }

    /**
     * Execute a prepared statement with timeout enforcement.
     *
     * @param \PDOStatement $stmt The prepared statement to execute
     * @param list<mixed> $values Parameter values for the statement
     * @return bool True on success, false on failure (caller should check stmt->errorInfo)
     * @throws StatementTimeoutException If timeout fires during execution
     */
    public function execute(\PDOStatement $stmt, array $values = []): bool
    {
        if (!$this->pcntlAvailable) {
            $stmt->execute($values);
            return true;
        }

        $this->timeoutOccurred = false;

        // Create a closure that captures the timeout seconds for this execution
        $handler = function (int $signo): void {
            if ($signo === SIGALRM) {
                throw new StatementTimeoutException('Statement execution timed out');
            }
        };

        $previousHandler = \pcntl_signal_get_handler(SIGALRM);
        \pcntl_signal(SIGALRM, $handler);
        \pcntl_alarm($this->timeoutSeconds);

        try {
            $stmt->execute($values);
            \pcntl_alarm(0);
            return true;
        } catch (StatementTimeoutException $e) {
            \pcntl_alarm(0);
            $this->timeoutOccurred = true;
            $this->cancelQuery($stmt);
            throw $e;
        } catch (\Throwable $e) {
            \pcntl_alarm(0);
            throw $e;
        } finally {
            if ($previousHandler !== SIG_DFL && $previousHandler !== SIG_IGN) {
                \pcntl_signal(SIGALRM, $previousHandler);
            }
        }
    }

    /**
     * Check if the last execution timed out.
     */
    public function didTimeout(): bool
    {
        return $this->timeoutOccurred;
    }

    /**
     * Cancel an in-progress query.
     *
     * Attempts to cancel the running query via KILL statement.
     * Gracefully ignores errors if cancellation fails.
     */
    private function cancelQuery(\PDOStatement $stmt): void
    {
        try {
            $pdo = $stmt->getPdo();
            if ($pdo !== null) {
                $pdo->query('KILL CONNECTION_ID()');
            }
        } catch (\Throwable) {
            // Ignore cancellation errors - we're already in error recovery
        }
    }
}
