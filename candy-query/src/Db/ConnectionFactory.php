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
     * DSN format: driver://user:pass@host:port/dbname?ssl-mode=MODE
     * SQLite format: sqlite:///path/to/db.sqlite or sqlite://:memory:
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

        // Parse driver:// scheme
        if (!str_contains($dsn, '://')) {
            throw new \InvalidArgumentException('Invalid DSN: missing "://" separator');
        }

        [$driver, $remainder] = explode('://', $dsn, 2);

        if (!in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            throw new \InvalidArgumentException('Unsupported driver: ' . $driver);
        }

        // SQLite has no host/user/pass structure
        if ($driver === 'sqlite') {
            $path = $remainder;
            $dbname = $path === ':memory:' ? ':memory:' : '/' . ltrim($path, '/');
            return ConnectionConfig::create(
                driver: 'sqlite',
                host: '',
                port: 0,
                user: '',
                pass: '',
                dbname: $dbname,
                sslMode: '',
            );
        }

        // Parse user:pass@host:port/dbname?query
        if (!str_contains($remainder, '@')) {
            throw new \InvalidArgumentException('Invalid DSN: missing credentials separator');
        }

        [$credentials, $rest] = explode('@', $remainder, 2);

        if (!str_contains($credentials, ':')) {
            throw new \InvalidArgumentException('Invalid DSN: missing password separator');
        }

        [$user, $pass] = explode(':', $credentials, 2);

        if (!str_contains($rest, '/')) {
            throw new \InvalidArgumentException('Invalid DSN: missing database separator');
        }

        [$hostPort, $dbname] = explode('/', $rest, 2);

        // Parse host:port
        $host = $hostPort;
        $port = 0;

        if (str_contains($hostPort, ':')) {
            [$host, $portStr] = explode(':', $hostPort, 2);
            $port = (int) $portStr;
        }

        // Parse query string for ssl-mode
        $sslMode = 'prefer';
        if (str_contains($dbname, '?')) {
            [$dbname, $query] = explode('?', $dbname, 2);
            parse_str($query, $params);
            if (isset($params['ssl-mode'])) {
                $sslMode = $params['ssl-mode'];
            }
        }

        return ConnectionConfig::create(
            driver: $driver,
            host: $host,
            port: $port,
            user: urldecode($user),
            pass: urldecode($pass),
            dbname: $dbname,
            sslMode: $sslMode,
        );
    }

    /**
     * Create a DatabaseInterface from a ConnectionConfig.
     *
     * Currently only supports sqlite driver. Other drivers will be
     * added in subsequent steps.
     *
     * @param ConnectionConfig $config Connection configuration
     * @return DatabaseInterface
     * @throws \InvalidArgumentException If driver is not supported
     */
    public static function fromConfig(ConnectionConfig $config): DatabaseInterface
    {
        return match ($config->driver) {
            'sqlite' => self::createSqlite($config),
            default => throw new \InvalidArgumentException(
                'Driver "' . $config->driver . '" not yet supported. Only sqlite is available in this step.',
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

        $config = ConnectionConfig::create(
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
}
