<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Db;

use SugarCraft\Sql\Lang;

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
     * Public so the README's driver table and consumers can cite the
     * authoritative list instead of hand-copying it (review m3).
     *
     * @var list<string>
     */
    public const SUPPORTED_DRIVERS = ['sqlite', 'mysql', 'pgsql'];

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
            throw new \InvalidArgumentException(Lang::t('err.dsn_empty'));
        }

        // parse_url() requires a valid URL scheme — must have '://'
        if (!str_contains($dsn, '://')) {
            throw new \InvalidArgumentException(Lang::t('err.dsn_no_separator'));
        }

        // Extract driver from scheme (first part before '://')
        $driver = explode('://', $dsn, 2)[0];

        if (!in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            throw new \InvalidArgumentException(Lang::t('err.driver_unsupported', ['driver' => $driver]));
        }

        // SQLite: parse_url() handles :memory: and /// paths inconsistently.
        // For :memory: it returns host=':memory' (no path); for /// paths it returns false.
        // Use direct regex extraction to handle SQLite.
        if ($driver === 'sqlite') {
            // Pattern: sqlite://[:memory] or sqlite:///path
            // sqlite://:memory: -> host=:memory, path=/
            // sqlite:///path -> host empty, path=/path
            if (preg_match('#^sqlite://([^/]*)?(.*)$#', $dsn, $m)) {
                $hostPart = $m[1];
                $remainder = $m[2];

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

                // File path: 'sqlite:///abs/path' puts the whole absolute path
                // in $remainder; 'sqlite://relative.db' parses with the path in
                // the HOST segment (review m6, 2026-11-03 — it used to fall
                // through to the empty-path arm below and become an in-memory
                // database, silently losing every write when the process
                // exited).
                $path = ($hostPart === '' ? $remainder : $hostPart . $remainder);
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

                // Keep the path as written: absolute stays absolute, relative
                // reaches the disk via ConnectionConfig::buildDsn()'s working-
                // directory anchor (a forced leading '/' here re-invented the
                // filesystem-root bug).
                $dbname = $path;
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

            throw new \InvalidArgumentException(Lang::t('err.dsn_sqlite_invalid'));
        }

        // Non-SQLite drivers
        $parsed = parse_url($dsn);

        if ($parsed === false) {
            throw new \InvalidArgumentException(Lang::t('err.dsn_parse_failed'));
        }

        if (!isset($parsed['host'])) {
            throw new \InvalidArgumentException(Lang::t('err.dsn_no_host'));
        }

        // Strip IPv6 brackets from host (parse_url keeps them)
        $host = $parsed['host'];
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        // A missing port must fall back to the driver's standard port, NOT 0.
        // PDO tolerates port 0 (libmysqlclient uses 3306), but the async
        // react/mysql driver connects to literal :0 and is refused.
        $port = isset($parsed['port']) ? (int) $parsed['port'] : self::defaultPort($driver);
        $user = isset($parsed['user']) ? rawurldecode($parsed['user']) : '';
        $pass = isset($parsed['pass']) ? rawurldecode($parsed['pass']) : '';

        // Extract dbname — path always starts with '/'
        $dbname = isset($parsed['path']) ? ltrim($parsed['path'], '/') : '';

        // Parse query string for ssl-mode. mn-4: accept the native libpq
        // spelling (?sslmode=…) too — a pgsql DSN copied from a libpq doc used
        // to silently keep 'prefer'. The documented hyphen form wins if both
        // are present.
        $sslMode = 'prefer';
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $params);
            if (isset($params['ssl-mode'])) {
                $sslMode = $params['ssl-mode'];
            } elseif (isset($params['sslmode'])) {
                $sslMode = $params['sslmode'];
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
     * The standard TCP port for a driver, used when the DSN/args omit one.
     * SQLite has no port. Returns 0 for unknown drivers (validated elsewhere).
     */
    private static function defaultPort(string $driver): int
    {
        return match ($driver) {
            'mysql' => 3306,
            'pgsql' => 5432,
            default => 0,
        };
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
                Lang::t('err.driver_unsupported', ['driver' => $config->driver]),
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

        // client-021: validate the driver VALUE before the missing-args
        // conjunction — --driver=bogus with no --db used to be misreported as
        // "missing --driver", burying the real fault (an unknown driver name).
        $driver = $args['driver'] ?? null;
        if ($driver !== null && !in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            throw new \InvalidArgumentException(
                Lang::t('err.driver_choose', ['driver' => $driver, 'list' => implode(', ', self::SUPPORTED_DRIVERS)]),
            );
        }

        // Otherwise build from individual arguments
        if (!isset($args['driver'], $args['db'])) {
            throw new \InvalidArgumentException(
                Lang::t('err.args_missing'),
            );
        }

        $config = ConnectionConfig::new(
            driver: $args['driver'],
            host: $args['host'] ?? '',
            port: isset($args['port']) ? (int) $args['port'] : self::defaultPort($args['driver']),
            user: $args['user'] ?? '',
            pass: $args['pass'] ?? '',
            dbname: $args['db'],
            sslMode: $args['ssl-mode'] ?? 'prefer',
        );

        return self::fromConfig($config);
    }

    /**
     * Option names accepted by fromArgv().
     *
     * @var list<string>
     */
    private const ARGV_OPTIONS = ['dsn', 'driver', 'db', 'host', 'port', 'user', 'pass', 'ssl-mode'];

    /**
     * Parse argv array into key-value map.
     *
     * Unknown --flags fail loudly (review m9): silently swallowing them
     * turned typos like --sslmode=require into a quiet downgrade to the
     * default, and --db name=mydb into a missing-argument error three
     * layers away. Positional arguments are skipped - the bin script
     * resolves those before calling fromArgv().
     *
     * @param array<string> $argv
     * @return array<string, string>
     * @throws \InvalidArgumentException On an unknown option or a valueless flag
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
                if (in_array($arg, self::ARGV_OPTIONS, true)) {
                    throw new \InvalidArgumentException(Lang::t('err.opt_needs_value', ['name' => $arg]));
                }
                throw new \InvalidArgumentException(Lang::t('err.opt_unknown', ['name' => $arg]));
            }

            [$key, $value] = explode('=', $arg, 2);
            if (!in_array($key, self::ARGV_OPTIONS, true)) {
                throw new \InvalidArgumentException(Lang::t('err.opt_unknown', ['name' => $key]));
            }
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
        // SQL-O15 (once-019): the no-create law guarded only the bare-path
        // launcher door (SqliteDatabase::open). A path-shaped DSN or flag
        // (--dsn=sqlite:///missing/app.sqlite, --driver=sqlite --db=…)
        // reached PDO's create-on-open default instead: the empty file was
        // minted silently and every write at exit landed in nothing — the
        // exact loss the refusal exists to prevent. Every SQLite FILE path
        // the factory dials now answers the same named refusal; ':memory:'
        // (and the empty dbname the resolver folds onto it) stay exempt.
        $path = $config->dbname;
        if ($path !== ':memory:' && $path !== '' && !\is_file($path)) {
            throw new \RuntimeException(Lang::t('database.no_file', ['path' => $path]));
        }

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
