<?php

declare(strict_types=1);

namespace SugarCraft\Query\Db;

/**
 * Factory for creating DatabaseInterface instances from various config sources.
 *
 * Supports DSN strings, ConnectionConfig objects, and CLI argv arrays.
 * Password is never echoed in any output.
 */
final class ConnectionFactory
{
    /**
     * Supported database drivers.
     *
     * @var list<string>
     */
    private const SUPPORTED_DRIVERS = ['sqlite', 'mysql', 'pgsql'];

    /**
     * Parse a DSN string into a ConnectionConfig.
     *
     * Uses parse_url() for robust parsing of special chars in passwords,
     * passwordless users, and IPv6 hosts.
     *
     * DSN format: driver://[user][:pass]@host[:port]/dbname[?query]
     * SQLite format: sqlite:///path/to/db.sqlite or sqlite://:memory:
     * IPv6: mysql://user:pass@[::1]:3306/dbname
     *
     * @param string $dsn DSN string to parse
     * @return ConnectionConfig
     * @throws \InvalidArgumentException If DSN format is invalid
     */
    public static function fromDsn(string $dsn): ConnectionConfig
    {
        if ($dsn === '') {
            throw new \InvalidArgumentException('DSN cannot be empty');
        }

        // parse_url() requires a valid URL scheme — must have '://'
        if (!str_contains($dsn, '://')) {
            throw new \InvalidArgumentException('Invalid DSN: missing "://" separator');
        }

        // Extract driver from scheme (first part before '://')
        $driver = explode('://', $dsn, 2)[0];

        if (!in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            throw new \InvalidArgumentException('Unsupported driver: ' . $driver);
        }

        // SQLite: parse_url() handles :memory: and /// paths inconsistently.
        // For :memory: it returns host=':memory' (no path); for /// paths it returns false.
        // Use direct regex extraction to handle SQLite.
        if ($driver === 'sqlite') {
            // Pattern: sqlite://[:memory] or sqlite:///path
            // sqlite://:memory: -> host=:memory, path=/
            // sqlite:///path -> host empty, path=/path
            if (preg_match('#^sqlite://([^/]*)?(.*)$#', $dsn, $m)) {
                $hostPart = $m[1] ?? '';
                $remainder = $m[2] ?? '';

                // :memory: or :memory (parse_url normalizes this way)
                if ($hostPart === ':memory:' || $hostPart === ':memory') {
                    return ConnectionConfig::new(
                        driver: 'sqlite',
                        host: '',
                        port: 0,
                        user: '',
                        pass: '',
                        dbname: ':memory:',
                        sslMode: '',
                    );
                }

                // File path: remainder starts with / (e.g., ///path -> remainder is //path)
                // or is empty (meaning ///path but we captured the leading /)
                $path = $remainder;
                if ($path === '' || $path === '/') {
                    // sqlite:// with no path is :memory:
                    return ConnectionConfig::new(
                        driver: 'sqlite',
                        host: '',
                        port: 0,
                        user: '',
                        pass: '',
                        dbname: ':memory:',
                        sslMode: '',
                    );
                }

                // Normalize path: ensure leading slash for PDO sqlite
                $dbname = str_starts_with($path, '/') ? $path : '/' . $path;
                return ConnectionConfig::new(
                    driver: 'sqlite',
                    host: '',
                    port: 0,
                    user: '',
                    pass: '',
                    dbname: $dbname,
                    sslMode: '',
                );
            }

            throw new \InvalidArgumentException('Invalid SQLite DSN');
        }

        // Non-SQLite drivers
        $parsed = parse_url($dsn);

        if ($parsed === false) {
            throw new \InvalidArgumentException('Invalid DSN: parse_url() failed');
        }

        if (!isset($parsed['host'])) {
            throw new \InvalidArgumentException('Invalid DSN: missing host');
        }

        // Strip IPv6 brackets from host (parse_url keeps them)
        $host = $parsed['host'];
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        $port = isset($parsed['port']) ? (int) $parsed['port'] : 0;
        $user = isset($parsed['user']) ? rawurldecode($parsed['user']) : '';
        $pass = isset($parsed['pass']) ? rawurldecode($parsed['pass']) : '';

        // Extract dbname — path always starts with '/'
        $dbname = isset($parsed['path']) ? ltrim($parsed['path'], '/') : '';

        // Parse query string for ssl-mode
        $sslMode = 'prefer';
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $params);
            if (isset($params['ssl-mode'])) {
                $sslMode = $params['ssl-mode'];
            }
        }

        return ConnectionConfig::new(
            driver: $driver,
            host: $host,
            port: $port,
            user: $user,
            pass: $pass,
            dbname: $dbname,
            sslMode: $sslMode,
        );
    }

    /**
     * Create a DatabaseInterface from a ConnectionConfig.
     *
     * @param ConnectionConfig $config Connection configuration
     * @return DatabaseInterface
     * @throws \InvalidArgumentException If driver is not supported
     */
    public static function fromConfig(ConnectionConfig $config): DatabaseInterface
    {
        return match ($config->driver) {
            'sqlite' => self::createSqlite($config),
            'mysql' => MysqlDatabase::connect($config),
            'pgsql' => PostgresDatabase::connect($config),
            default => throw new \InvalidArgumentException(
                'Unsupported driver: ' . $config->driver,
            ),
        };
    }

    /**
     * Create a DatabaseInterface from CLI argv arguments.
     *
     * Expected arguments:
     *   --driver=mysql --host=localhost --port=3306 --user=root --pass=secret --db=mydb
     *   --dsn=mysql://user:pass@host:3306/dbname?ssl-mode=require
     *
     * @param array<string> $argv Command line arguments
     * @return DatabaseInterface
     * @throws \InvalidArgumentException If required arguments are missing
     */
    public static function fromArgv(array $argv): DatabaseInterface
    {
        $args = self::parseArgv($argv);

        // If --dsn is provided, use it directly
        if (isset($args['dsn'])) {
            return self::fromConfig(self::fromDsn($args['dsn']));
        }

        // Otherwise build from individual arguments
        if (!isset($args['driver'], $args['db'])) {
            throw new \InvalidArgumentException(
                'Missing required arguments: --driver and --db are required',
            );
        }

        $config = ConnectionConfig::new(
            driver: $args['driver'],
            host: $args['host'] ?? '',
            port: isset($args['port']) ? (int) $args['port'] : 0,
            user: $args['user'] ?? '',
            pass: $args['pass'] ?? '',
            dbname: $args['db'],
            sslMode: $args['ssl-mode'] ?? 'prefer',
        );

        return self::fromConfig($config);
    }

    /**
     * Parse argv array into key-value map.
     *
     * @param array<string> $argv
     * @return array<string, string>
     */
    private static function parseArgv(array $argv): array
    {
        $args = [];

        foreach ($argv as $arg) {
            if (!str_starts_with($arg, '--')) {
                continue;
            }

            $arg = substr($arg, 2);

            if (!str_contains($arg, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $arg, 2);
            $args[$key] = $value;
        }

        return $args;
    }

    /**
     * Create a SQLite database connection from config.
     *
     * @param ConnectionConfig $config
     * @return SqliteDatabase
     */
    private static function createSqlite(ConnectionConfig $config): SqliteDatabase
    {
        $pdo = new \PDO($config->dsn);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return new SqliteDatabase($pdo, $config->dbname);
    }

    /**
     * Create a DatabaseInterface from a bare SQLite path.
     *
     * This handles the default CLI behavior where a bare path like
     * `app.sqlite` is interpreted as a SQLite database file.
     *
     * @param string $path SQLite database path (relative or absolute)
     * @return SqliteDatabase
     * @throws \RuntimeException If the database file does not exist
     */
    public static function fromPath(string $path): SqliteDatabase
    {
        return SqliteDatabase::open($path);
    }
}
