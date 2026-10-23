<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Db;

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
        return match ($driver) {
            'sqlite' => $dbname === ':memory:' ? 'sqlite::memory:' : 'sqlite:/' . ltrim($dbname, '/'),
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
     * irrelevant. '' / unknown-to-pg names omit the suffix and inherit libpq's
     * default; 'verify_full'/'verify_identity' both mean full verification.
     *
     * @return string Either '' or ';sslmode=<libpq-mode>'
     */
    private static function pgsqlSslModeSuffix(string $sslMode): string
    {
        if ($sslMode === '') {
            return '';
        }

        $libpqMode = match (strtolower(str_replace('_', '-', $sslMode))) {
            'disable' => 'disable',
            'allow' => 'allow',
            'prefer', 'preferred' => 'prefer',
            'require', 'required' => 'require',
            'verify-ca' => 'verify-ca',
            'verify-full', 'verify-identity' => 'verify-full',
            default => '',
        };

        return $libpqMode === '' ? '' : ';sslmode=' . $libpqMode;
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
