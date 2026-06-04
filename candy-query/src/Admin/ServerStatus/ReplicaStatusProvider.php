<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\ServerStatus;

use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Db\Version;
use SugarCraft\Query\Db\DatabaseInterface;

/**
 * Provides replica status by executing SHOW REPLICA STATUS (MySQL 8+),
 * SHOW SLAVE STATUS (MySQL 5.x), or SHOW ALL SLAVES STATUS (MariaDB).
 *
 * Gracefully distinguishes: configured (rows returned), not configured
 * (empty result), insufficient privileges (error 1227), and server errors.
 * All rows are returned to support multi-channel replication setups.
 *
 * @see Mirrors mysql-workbench/wb_admin_replication
 */
final class ReplicaStatusProvider
{
    /**
     * Cached replica status rows.
     *
     * @var list<array<string, scalar>>|null
     */
    private ?array $cachedRows = null;

    /** @var ReplicaStatusKind|null */
    private ?ReplicaStatusKind $cachedKind = null;

    private ?bool $isConfigured = null;

    public function __construct(
        private readonly ServerContextInterface $context,
    ) {}

    /**
     * Create a new instance with default context.
     */
    public static function new(ServerContextInterface $context): self
    {
        return new self($context);
    }

    /**
     * Fetch all replica status rows from the server.
     *
     * Returns all channels when configured (multi-row). Empty array when
     * not configured or inaccessible. Use lastFetchKind() to distinguish
     * the specific condition.
     *
     * @return list<array<string, scalar>>
     */
    public function fetchStatus(): array
    {
        if ($this->cachedRows !== null) {
            return $this->cachedRows;
        }

        $this->doFetch();
        return $this->cachedRows ?? [];
    }

    /**
     * The specific condition last encountered when fetching status.
     *
     * Allows the UI to display distinct messages for: configured rows
     * returned, empty result (not configured), error 1227 (permission
     * denied), or an unexpected error.
     */
    public function lastFetchKind(): ReplicaStatusKind
    {
        if ($this->cachedKind !== null) {
            return $this->cachedKind;
        }

        $this->doFetch();
        return $this->cachedKind ?? ReplicaStatusKind::NotConfigured;
    }

    /**
     * True when replica status is configured on this server.
     *
     * Distinguishes "not configured" (returns false, no error) from
     * "error accessing status" (returns false, with logged error).
     */
    public function isReplicaConfigured(): bool
    {
        if ($this->isConfigured !== null) {
            return $this->isConfigured;
        }

        $kind = $this->lastFetchKind();
        $this->isConfigured = $kind === ReplicaStatusKind::Configured;
        return $this->isConfigured;
    }

    /**
     * Clear cached status, forcing a fresh query on next access.
     */
    public function refresh(): self
    {
        $clone = clone $this;
        $clone->cachedRows = null;
        $clone->cachedKind = null;
        $clone->isConfigured = null;
        return $clone;
    }

    /**
     * Execute the appropriate SHOW command for the server version.
     *
     * @return list<array<string, scalar>>
     */
    private function doFetch(): void
    {
        $sql = $this->chooseQuery();

        try {
            $connection = $this->context->connection();
            $rows = $connection->query($sql);

            if (count($rows) === 0) {
                $this->cachedRows = [];
                $this->cachedKind = ReplicaStatusKind::NotConfigured;
                return;
            }

            /** @var list<array<string, scalar>> $rows */
            $this->cachedRows = $rows;
            $this->cachedKind = ReplicaStatusKind::Configured;
        } catch (\PDOException $e) {
            $this->cachedRows = [];
            if ($this->isReplicaCommandDenied($e)) {
                $this->cachedKind = ReplicaStatusKind::PermissionDenied;
                return;
            }

            // Unexpected error — treat as inaccessible
            $this->cachedKind = ReplicaStatusKind::Error;
        }
    }

    /**
     * Choose the correct SHOW command for the server flavor and version.
     */
    private function chooseQuery(): string
    {
        $flavor = $this->context->flavor();
        $version = $this->context->version();

        // MariaDB uses SHOW ALL SLAVES STATUS for multi-channel support
        if ($flavor === Flavor::MariaDB) {
            return 'SHOW ALL SLAVES STATUS';
        }

        // MySQL 8.0+ uses SHOW REPLICA STATUS (preferred over SLAVE)
        if ($flavor === Flavor::MySQL && $version->major >= 8) {
            return 'SHOW REPLICA STATUS';
        }

        // MySQL 5.x and Percona fall back to SHOW SLAVE STATUS
        return 'SHOW SLAVE STATUS';
    }

    /**
     * True when the exception indicates error 1227 (command denied).
     */
    private function isReplicaCommandDenied(\PDOException $e): bool
    {
        $code = (string) $e->getCode();

        // PDO error code format varies: some use '42000', others use integer 1227
        if ($code === '1227' || $code === '42000') {
            return true;
        }

        // Check error message for "command denied" + "replica" or "slave" pattern
        $message = strtolower($e->getMessage());
        return str_contains($message, 'command denied')
            && (str_contains($message, 'replica') || str_contains($message, 'slave'));
    }
}
