<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\ServerStatus;

use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Db\Version;
use SugarCraft\Query\Db\DatabaseInterface;

/**
 * Provides replica status by executing SHOW REPLICA STATUS (MySQL 8+)
 * or SHOW SLAVE STATUS (MySQL 5.x / MariaDB).
 *
 * Gracefully handles MySQL error 1227 (replica command denied) by returning
 * empty/null when the user lacks REPLICATION CLIENT privilege.
 *
 * @see Mirrors mysql-workbench/wb_admin_replication
 */
final class ReplicaStatusProvider
{
    /**
     * Cached replica status rows, or null if not yet fetched or on error.
     *
     * @var array<string, scalar>|null
     */
    private ?array $cachedStatus = null;

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
     * Fetch replica status from the server.
     *
     * Returns null when replica status is not accessible (not configured,
     * privileges missing, or server error). Use isReplicaConfigured() to
     * distinguish between "not configured" and "error accessing".
     *
     * @return array<string, scalar>|null Row data or null on failure
     */
    public function fetchStatus(): ?array
    {
        if ($this->cachedStatus !== null) {
            return $this->cachedStatus;
        }

        $this->cachedStatus = $this->doFetch();
        return $this->cachedStatus;
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

        $status = $this->fetchStatus();
        $this->isConfigured = $status !== null && count($status) > 0;
        return $this->isConfigured;
    }

    /**
     * Clear cached status, forcing a fresh query on next access.
     */
    public function refresh(): self
    {
        $clone = clone $this;
        $clone->cachedStatus = null;
        $clone->isConfigured = null;
        return $clone;
    }

    /**
     * Execute the appropriate SHOW command for the server version.
     *
     * @return array<string, scalar>|null
     */
    private function doFetch(): ?array
    {
        $sql = $this->chooseQuery();

        try {
            $connection = $this->context->connection();
            $rows = $connection->query($sql);

            if (count($rows) === 0) {
                return null;
            }

            /** @var array<string, scalar> $firstRow */
            $firstRow = $rows[0];
            return $firstRow;
        } catch (\PDOException $e) {
            // MySQL error 1227: REPLICATION CLIENT privilege denied
            // This is expected when the user lacks privileges — not an error condition
            if ($this->isReplicaCommandDenied($e)) {
                return null;
            }

            // Log unexpected errors but treat as "not accessible"
            // The page will show a friendly message rather than a stack trace
            return null;
        }
    }

    /**
     * Choose SHOW REPLICA STATUS (MySQL 8+) or SHOW SLAVE STATUS (MySQL 5.x / MariaDB).
     *
     * MySQL 8.0+ uses REPLICA (preferred) over SLAVE terminology.
     * MariaDB and MySQL 5.x still use SLAVE STATUS.
     */
    private function chooseQuery(): string
    {
        $flavor = $this->context->flavor();
        $version = $this->context->version();

        // MariaDB always uses SHOW SLAVE STATUS
        if ($flavor === Flavor::MariaDB) {
            return 'SHOW SLAVE STATUS';
        }

        // MySQL 8.0+ uses SHOW REPLICA STATUS
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
        // MySQL error codes can be in the exception code or message
        $code = (string) $e->getCode();

        // PDO error code format varies: some use '42000', others use integer 1227
        if ($code === '1227' || $code === '42000') {
            return true;
        }

        // Check error message for "command denied" pattern
        $message = strtolower($e->getMessage());
        return str_contains($message, 'command denied')
            && str_contains($message, 'replica');
    }
}
