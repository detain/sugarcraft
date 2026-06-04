<?php

declare(strict_types=1);

namespace SugarCraft\Query\Db;

/**
 * SQLite prepared statement wrapper implementing PreparedStatementInterface.
 */
final class SqlitePreparedStatement implements PreparedStatementInterface
{
    public function __construct(
        private readonly ?\PDOStatement $stmt,
    ) {}

    public function execute(?array $params = null): bool
    {
        if ($this->stmt === null) {
            return false;
        }
        return $this->stmt->execute($params);
    }

    /** @return array<string, mixed>|false */
    public function fetch(): array|false
    {
        if ($this->stmt === null) {
            return false;
        }
        $result = $this->stmt->fetch(\PDO::FETCH_ASSOC);
        return $result === false ? false : $result;
    }

    /** @return list<array<string, mixed>> */
    public function fetchAll(): array
    {
        if ($this->stmt === null) {
            return [];
        }
        return $this->stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function rowCount(): int
    {
        if ($this->stmt === null) {
            return 0;
        }
        return $this->stmt->rowCount();
    }

    public function closeCursor(): bool
    {
        if ($this->stmt === null) {
            return false;
        }
        return $this->stmt->closeCursor();
    }
}
