<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Db;

use SugarCraft\Sql\Lang;

/**
 * Readonly value object representing database connection configuration.
 *
 * All properties are public and readonly — access via ->property.
 * Password is never echoed in any output (logs, DSN strings, exceptions).
 *
 * For MySQL connections, SSL is NOT included in the DSN string — it is
 * applied as PDO driver options at connect time (see MysqlDatabase::connect()).
 *
 * @see MysqlDatabase::sslDriverOptions() For MySQL SSL driver option application
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
     * MySQL SSL is NOT included in the DSN — it is passed as PDO driver options
     * at connect time via MysqlDatabase::sslDriverOptions(). PostgreSQL has no
     * equivalent driver option, so its mode rides the libpq `sslmode=` DSN
     * parameter; previously sslMode was never consulted for pgsql and every
     * --ssl-mode / ?ssl-mode on a Postgres connection was silently ignored.
     *
     * @param string $driver Database driver (sqlite, mysql, pgsql)
     * @param string $host Host address
     * @param int $port Port number
     * @param string $dbname Database name
     * @param string $sslMode SSL mode (PostgreSQL only — translated to libpq spellings)
     * @return string DSN string
     */
    private static function buildDsn(
        string $driver,
        string $host,
        int $port,
        string $dbname,
        string $sslMode = '',
    ): string {
        // SQLite: ':memory:' stays in-memory; a path is used as written —
        // an absolute one verbatim, a relative one resolved against the
        // process working directory (review m6, 2026-11-03: the old
        // 'sqlite:/' . ltrim() form silently turned `candy-sql app.sqlite`
        // into /app.sqlite at the filesystem root).
        if ($driver === 'sqlite') {
            return 'sqlite:' . self::resolveSqlitePath($dbname);
        }

        // ';' is the PDO DSN option separator: a host or dbname carrying one
        // appends/overrides libpq/mysql options in the shared connection string
        // (review m7, 2026-11-03). Refuse the splice at the boundary instead of
        // half-escaping — ';' is legal in no hostname and in no database name
        // worth dialing.
        if (str_contains($host, ';') || str_contains($dbname, ';')) {
            throw new \InvalidArgumentException(
                Lang::t('db.err_dsn_semicolon'),
            );
        }

        return match ($driver) {
            'mysql' => sprintf(
                'mysql:host=%s;port=%d;dbname=%s',
                $host,
                $port,
                $dbname,
            ),
            'pgsql' => sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $dbname)
                . self::pgsqlSslModeSuffix($sslMode),
            default => sprintf('%s:host=%s;port=%d;dbname=%s', $driver, $host, $port, $dbname),
        };
    }

    /**
     * Translate an sslMode into the libpq `sslmode=` DSN parameter.
     *
     * MySQL-flavoured spellings (underscores, 'required', 'verify_identity')
     * are accepted because one --ssl-mode flag feeds both drivers; case is
     * irrelevant. '' omits the suffix and inherits libpq's default; every
     * verification-demanding spelling ('require' included, exactly like the
     * MySQL arm) lands on 'verify-full'. An unknown spelling throws — the
     * same fail-loud contract {@see \SugarCraft\Sql\Db\MysqlDatabase::sslDriverOptions()}
     * enforces, so a typo can never negotiate silently on either driver.
     *
     * @return string Either '' or ';sslmode=<libpq-mode>'
     */
    private static function pgsqlSslModeSuffix(string $sslMode): string
    {
        if ($sslMode === '') {
            return '';
        }

        // One doctrine across drivers (review M4, 2026-11-03): the MySQL arm
        // (MysqlDatabase::sslDriverOptions) treats 'require' as TLS-with-
        // certificate-verification — libpq's bare 'require' is TLS-without-
        // verification, so passing the word through kept the weaker,
        // MITM-able reading the MySQL side explicitly rejected as the original
        // downgrade bug. Every verification-demanding spelling therefore
        // lands on libpq 'verify-full' here, and typos THROW instead of
        // falling through to '' (which silently inherited libpq's 'prefer'
        // default while the identical MySQL spelling failed loud).
        $libpqMode = match (strtolower(str_replace('_', '-', $sslMode))) {
            'disable' => 'disable',
            'allow' => 'allow',
            'prefer', 'preferred' => 'prefer',
            'require', 'required',
            'verify-ca',
            'verify-full', 'verify-identity' => 'verify-full',
            default => throw new \InvalidArgumentException(
                Lang::t('db.err_unknown_pg_ssl', ['mode' => $sslMode]),
            ),
        };

        return ';sslmode=' . $libpqMode;
    }

    /**
     * The on-disk path a SQLite dbname means: absolute paths verbatim,
     * ':memory:' verbatim, relative paths anchored at the working directory
     * (never the filesystem root — review m6).
     */
    private static function resolveSqlitePath(string $dbname): string
    {
        if ($dbname === ':memory:' || $dbname === '') {
            return $dbname === '' ? ':memory:' : $dbname;
        }

        if (str_starts_with($dbname, '/') || preg_match('#^[A-Za-z]:[/\\\\]#', $dbname)) {
            return $dbname;
        }

        return (string) getcwd() . DIRECTORY_SEPARATOR . $dbname;
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
     * @param string $sslMode SSL mode — MySQL applies it as PDO driver options, PostgreSQL carries it in the DSN as sslmode=
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
        $dsn = self::buildDsn($driver, $host, $port, $dbname, $sslMode);
        return new self($driver, $host, $port, $user, $pass, $dbname, $sslMode, $dsn);
    }
}
