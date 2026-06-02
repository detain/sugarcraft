<?php

declare(strict_types=1);

namespace SugarCraft\Query\Db;

/**
 * Readonly value object representing database connection configuration.
 *
 * Stores all connection parameters without getter methods - access via ->property.
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
     * @param string $driver Database driver (sqlite, mysql, pgsql)
     * @param string $host Host address
     * @param int $port Port number
     * @param string $dbname Database name
     * @param string $sslMode SSL mode
     * @return string DSN string
     */
    private static function buildDsn(string $driver, string $host, int $port, string $dbname, string $sslMode): string
    {
        return match ($driver) {
            'sqlite' => $dbname === ':memory:' ? 'sqlite::memory:' : 'sqlite:/' . ltrim($dbname, '/'),
            'mysql' => sprintf(
                'mysql:host=%s;port=%d;dbname=%s;ssl-mode=%s',
                $host,
                $port,
                $dbname,
                $sslMode,
            ),
            'pgsql' => sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $dbname),
            default => throw new \InvalidArgumentException('Unsupported driver: ' . $driver),
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
     * @param string $sslMode SSL mode (for MySQL)
     * @return self
     */
    public static function create(
        string $driver,
        string $host,
        int $port,
        string $user,
        string $pass,
        string $dbname,
        string $sslMode = 'prefer',
    ): self {
        $dsn = self::buildDsn($driver, $host, $port, $dbname, $sslMode);
        return new self($driver, $host, $port, $user, $pass, $dbname, $sslMode, $dsn);
    }
}
