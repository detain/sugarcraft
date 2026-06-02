<?php

declare(strict_types=1);

namespace SugarCraft\Query;

use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Db\SqliteDatabase;
use SugarCraft\Query\Db\Export\CsvExporter;
use SugarCraft\Query\Db\Export\SqlExporter;

/**
 * Thin SQLite wrapper implementing DatabaseInterface.
 *
 * @deprecated Use SqliteDatabase directly instead.
 *             This class is kept for backwards compatibility.
 *
 * Everything else in this app talks to a {@see Database} (an interface
 * in spirit, sealed concrete class in practice — now implements
 * DatabaseInterface so any impl can be substituted).
 *
 * The wrapper is split out so the Model can be tested against an
 * in-memory `:memory:` PDO with no fixture files on disk.
 */
final class Database implements DatabaseInterface
{
    private SqliteDatabase $delegate;
    private ?CsvExporter $csvExporter = null;
    private ?SqlExporter $sqlExporter = null;

    /**
     * @deprecated Use SqliteDatabase::open() instead
     */
    public static function open(string $path): self
    {
        // Validate path BEFORE creating PDO (same as original implementation)
        if ($path !== ':memory:' && !is_file($path)) {
            throw new \RuntimeException(Lang::t('database.no_file', ['path' => $path]));
        }
        $pdo = new \PDO('sqlite:' . $path);
        $instance = new self($pdo);
        // Initialize delegate via SqliteDatabase::open for proper path tracking
        $instance->delegate = SqliteDatabase::open($path);
        return $instance;
    }

    /**
     * @deprecated Use SqliteDatabase directly for PDO access
     */
    public function __construct(public readonly \PDO $pdo)
    {
        // For backwards compatibility - store pdo but also create delegate
        $this->delegate = new SqliteDatabase($this->pdo, ':memory:');
    }

    /**
     * Re-initialize the delegate with a specific path.
     * Used by open() to properly set the path in the delegate.
     */
    private function setDelegatePath(string $path): void
    {
        $this->delegate = SqliteDatabase::open($path);
    }

    /**
     * @deprecated Use csvExporter() instead
     */
    public function csvExporter(): CsvExporter
    {
        $this->csvExporter ??= new CsvExporter($this->delegate);
        return $this->csvExporter;
    }

    /**
     * @deprecated Use sqlExporter() instead
     */
    public function sqlExporter(): SqlExporter
    {
        $this->sqlExporter ??= new SqlExporter($this->delegate);
        return $this->sqlExporter;
    }

    /** @return list<string> */
    public function tables(): array
    {
        return $this->delegate->tables();
    }

    /** @return list<array<string,mixed>> */
    public function rows(string $table, int $limit = 100): array
    {
        return $this->delegate->rows($table, $limit);
    }

    /** @return list<array<string,mixed>> */
    public function query(string $sql): array
    {
        return $this->delegate->query($sql);
    }

    public function lastInsertId(): string|int
    {
        return $this->delegate->lastInsertId();
    }

    public function quote(string $value): string
    {
        return $this->delegate->quote($value);
    }

    public function exec(string $sql): int
    {
        return $this->delegate->exec($sql);
    }

    public function close(): void
    {
        $this->delegate->close();
    }

    public function serverVersion(): string
    {
        return $this->delegate->serverVersion();
    }

    public function driverName(): string
    {
        return $this->delegate->driverName();
    }

    public function ping(): bool
    {
        return $this->delegate->ping();
    }

    /** @return list<string> */
    public function databases(): array
    {
        return $this->delegate->databases();
    }

    public function prepare(string $sql): mixed
    {
        return $this->delegate->prepare($sql);
    }

    /**
     * @deprecated Use CsvExporter via csvExporter() instead
     */
    public function importCsv(string $path, string $table): void
    {
        $this->csvExporter()->importCsv($path, $table);
    }

    /**
     * @deprecated Use CsvExporter via csvExporter() instead
     */
    public function exportCsv(string $path, string $table): void
    {
        $this->csvExporter()->exportCsv($path, $table);
    }

    /**
     * @deprecated Use SqlExporter via sqlExporter() instead
     */
    public function exportSql(string $path): void
    {
        $this->sqlExporter()->exportSql($path);
    }
}
