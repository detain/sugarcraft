<?php

declare(strict_types=1);

namespace SugarCraft\Query\Db;

/**
 * Generic PDO prepared statement wrapper implementing PreparedStatementInterface.
 *
 * Wraps any PDOStatement to provide a driver-neutral interface for
 * the common prepared statement operations needed by callers.
 */
final class PdoPreparedStatement implements PreparedStatementInterface
{
    public function __construct(
        private readonly ?\PDOStatement $inner,
    ) {}

    public function execute(?array $params = null): bool
    {
        if ($this->inner === null) {
            return false;
        }
        return $this->inner->execute($params);
    }

    /** @return array<string, mixed>|false */
    public function fetch(): array|false
    {
        if ($this->inner === null) {
            return false;
        }
        $result = $this->inner->fetch(\PDO::FETCH_ASSOC);
        return $result === false ? false : $result;
    }

    /** @return list<array<string, mixed>> */
    public function fetchAll(): array
    {
        if ($this->inner === null) {
            return [];
        }
        return $this->inner->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function rowCount(): int
    {
        if ($this->inner === null) {
            return 0;
        }
        return $this->inner->rowCount();
    }

    public function closeCursor(): bool
    {
        if ($this->inner === null) {
            return false;
        }
        return $this->inner->closeCursor();
    }
}
