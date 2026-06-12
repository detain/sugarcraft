<?php

declare(strict_types=1);

namespace SugarCraft\Query\Db;

/**
 * Db-layer seam for connection-reconnect policy.
 *
 * Lets a DatabaseInterface implementation drive reconnection without
 * depending on the Admin layer where the concrete policy
 * (Admin\Resilience\ReconnectManager) lives. Declares only the slice
 * the Db layer actually calls — keeping the dependency direction
 * Admin → Db, never the reverse.
 */
interface ReconnectManagerInterface
{
    /**
     * Decide whether the given PDO error warrants a reconnect attempt.
     */
    public function shouldReconnect(\PDOException $e): bool;

    /**
     * Attempt a reconnect using the supplied connection factory.
     *
     * @param callable(): (DatabaseInterface|false) $connect
     * @return bool True when the new connection is live.
     */
    public function attemptReconnect(callable $connect): bool;

    /**
     * Remember the config to use for reconnection attempts.
     */
    public function setConnectionConfig(ConnectionConfig $config): void;
}
