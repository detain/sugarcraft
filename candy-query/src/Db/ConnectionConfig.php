<?php

declare(strict_types=1);

namespace SugarCraft\Query\Db;

/**
 * Readonly value object representing database connection configuration.
 *
 * All properties are public and readonly — access via ->property.
 * Password is never echoed in any output (logs, DSN strings, exceptions).
 *
 * For MySQL connections, SSL is NOT included in the DSN string — it is
 * applied as PDO driver options at connect time (see MysqlDatabase::connect()).
 *
 * @see MysqlDatabase::connect() For SSL driver option application
 */
final readonly class ConnectionConfig
{
    public function __construct(
        public string $driver,
        public string $host,
        public int $port,
        public string $user,
        public string $pass,
        public string $dbname,
        public string $sslMode,
        public string $dsn,
    ) {}

    /**
     * Build a DSN string from components.
     *
     * SSL is NOT included in the MySQL DSN — it is passed as PDO driver options
     * at connect time via MysqlDatabase::connect().
     *
     * @param string $driver Database driver (sqlite, mysql, pgsql)
     * @param string $host Host address
     * @param int $port Port number
     * @param string $dbname Database name
     * @return string DSN string
     */
    private static function buildDsn(string $driver, string $host, int $port, string $dbname): string
    {
        return match ($driver) {
            'sqlite' => $dbname === ':memory:' ? 'sqlite::memory:' : 'sqlite:/' . ltrim($dbname, '/'),
            'mysql' => sprintf(
                'mysql:host=%s;port=%d;dbname=%s',
                $host,
                $port,
                $dbname,
            ),
            'pgsql' => sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $dbname),
            default => sprintf('%s:host=%s;port=%d;dbname=%s', $driver, $host, $port, $dbname),
        };
    }

    /**
     * Create a ConnectionConfig from individual components.
     *
     * @param string $driver Database driver
     * @param string $host Host address
     * @param int $port Port number
     * @param string $user Username
     * @param string $pass Password
     * @param string $dbname Database name
     * @param string $sslMode SSL mode (for MySQL) — applied as PDO driver options, not in DSN
     * @return self
     */
    public static function new(
        string $driver,
        string $host,
        int $port,
        string $user,
        string $pass,
        string $dbname,
        string $sslMode = 'prefer',
    ): self {
        $dsn = self::buildDsn($driver, $host, $port, $dbname);
        return new self($driver, $host, $port, $user, $pass, $dbname, $sslMode, $dsn);
    }
}
