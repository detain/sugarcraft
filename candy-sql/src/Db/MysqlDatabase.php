<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Db;

/**
 * MySQL implementation of DatabaseInterface using PDO.
 *
 * Mirrors jorgerojas26/lazysql MySQL backend
 */
final class MysqlDatabase implements DatabaseInterface
{
    private ?\PDO $pdo = null;
    private ?float $lastUptime = null;
    private ?ConnectionConfig $connectionConfig = null;
    private ?ReconnectManagerInterface $reconnectManager = null;
    private ?SamplerInterface $sampler = null;

    private function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Connect to a MySQL database using connection configuration.
     *
     * SSL is configured via PDO driver options, not the DSN string — the mode
     * semantics live in {@see self::sslDriverOptions()}. Every option group
     * (modes, TLS, C11 network timeouts, C15 utf8mb4 negotiation) is assembled
     * in {@see self::pdoOptions()} so the connect and reconnect legs dial
     * identically.
     */
    public static function connect(ConnectionConfig $config): self
    {
        if ($config->driver !== 'mysql') {
            throw new \InvalidArgumentException(
                "The MySQL connector only accepts the mysql driver (got '{$config->driver}')",
            );
        }

        $pdo = new \PDO($config->dsn, $config->user, $config->pass, self::pdoOptions($config->sslMode));

        $instance = new self($pdo);
        $instance->connectionConfig = $config;

        return $instance;
    }

    /**
     * The wall-clock budget (seconds) for every remote MySQL network leg.
     *
     * SQL-C11: before this, a stalled server could freeze the whole UI on
     * any synchronous leg — the TLS-refused transport's run/browse path, the
     * admin wrapper's write-throughs — with no bound at any layer.
     */
    public const NETWORK_TIMEOUT_SECONDS = 30;

    /**
     * The connection charset every remote MySQL dial negotiates (SQL-C15).
     *
     * utf8mb4: the full-Unicode default of modern MySQL/MariaDB, and the
     * UTF-8-family precondition Identifier::quoteLiteral's doubling relies on.
     */
    public const CONNECTION_CHARSET = 'utf8mb4';

    /**
     * The complete option set one remote MySQL dial needs.
     *
     * SQL-C15: MYSQL_ATTR_INIT_COMMAND negotiates a UTF-8-family connection
     * charset (`SET NAMES utf8mb4`) on EVERY dial, connect and reconnect.
     * Identifier::quoteLiteral's doubling is only quote-safe when the
     * connection really runs a UTF-8/single-byte charset, and PreviewQuery's
     * static builders (the information_schema lookups) have no live handle to
     * call PDO::quote on — so without this, a GBK/SJIS-default server could let
     * a byte-level prefix collision re-open a quoted identifier-valued literal.
     * utf8mb4 is the modern MySQL/MariaDB default and a superset of the
     * single-byte charms; fixing it at connect removes the precondition gap
     * structurally rather than documenting it away.
     *
     * ATTR_TIMEOUT caps the connect phase; MYSQL_ATTR_READ_TIMEOUT/
     * WRITE_TIMEOUT cap every mid-flight network leg (a lock-waiting server
     * that keeps the socket open and never answers is exactly this class of
     * stall). Driver timeouts apply with or without pcntl, so they are also
     * the floor the Admin-layer {@see \SugarCraft\Sql\Admin\Resilience\StatementTimeout}
     * alarm degrades to on Windows — where the timeout then surfaces as a
     * PDOException from the transport instead of a named timeout error.
     *
     * @return array<int, mixed>
     */
    private static function pdoOptions(string $sslMode): array
    {
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT => self::NETWORK_TIMEOUT_SECONDS,
        ];
        // mysqlnd declares the driver attrs with the pdo_mysql driver; the
        // class loads on builds without it, so guard the constant refs.
        if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[\constant('PDO::MYSQL_ATTR_INIT_COMMAND')] = 'SET NAMES ' . self::CONNECTION_CHARSET;
        }
        if (defined('PDO::MYSQL_ATTR_READ_TIMEOUT')) {
            $options[\constant('PDO::MYSQL_ATTR_READ_TIMEOUT')] = self::NETWORK_TIMEOUT_SECONDS;
        }
        if (defined('PDO::MYSQL_ATTR_WRITE_TIMEOUT')) {
            $options[\constant('PDO::MYSQL_ATTR_WRITE_TIMEOUT')] = self::NETWORK_TIMEOUT_SECONDS;
        }

        return $options + self::sslDriverOptions($sslMode);
    }

    /**
     * PDO driver options that honour sslMode — case-insensitively and without
     * silent security downgrades.
     *
     * '' / 'disable'   -> no TLS options at all (plaintext connection).
     * 'allow'/'prefer' -> opportunistic TLS; the certificate is NOT verified.
     * 'require'/'verify_ca'/'verify_identity' -> TLS mandatory AND the server
     * certificate verified. An unverified 'require' was MITM-able, which is not
     * what callers of these modes mean; hyphen spellings ('verify-ca') and any
     * letter case are accepted so a formatting variant can never fall through
     * to the unverified arm — that was the original downgrade bug.
     * Anything else throws: a typo must fail loud, never negotiate silently.
     *
     * MYSQL_ATTR_SSL_CA stays null so PDO falls back to the system trust store.
     *
     * @return array<int, mixed>
     */
    private static function sslDriverOptions(string $sslMode): array
    {
        return match (self::normalizedSslMode($sslMode)) {
            '' => [],
            'allow', 'prefer', 'preferred' => [
                \PDO::MYSQL_ATTR_SSL_CA => null,
                \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
            ],
            'require', 'required', 'verify_ca', 'verify_identity' => [
                \PDO::MYSQL_ATTR_SSL_CA => null,
                \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
            ],
            default => throw new \InvalidArgumentException(
                'Unknown MySQL ssl-mode: ' . $sslMode,
            ),
        };
    }

    /**
     * Whether an ssl-mode DEMANDS a verified TLS connection on the sync PDO
     * path — the same table {@see self::sslDriverOptions()} honours, read as
     * a security contract instead of a driver-options map.
     *
     * Consumers that cannot honour the demand on their own transport (the
     * ReactPHP admin path dials a `mysql://` URI with no TLS material) must
     * refuse it, never silently downgrade to plaintext: an unverified async
     * fetch on a connection whose owner asked for verification defeats the
     * whole point of the mode. Unknown spellings throw exactly like
     * sslDriverOptions() — one table, one rejection, no drift between them.
     */
    public static function requiresVerifiedTls(string $sslMode): bool
    {
        return match (self::normalizedSslMode($sslMode)) {
            'require', 'required', 'verify_ca', 'verify_identity' => true,
            '', 'allow', 'prefer', 'preferred' => false,
            default => throw new \InvalidArgumentException(
                'Unknown MySQL ssl-mode: ' . $sslMode,
            ),
        };
    }

    /**
     * Canonical ssl-mode key: '' for plaintext-by-agreement (empty or
     * 'disable', any case), else lowercase with hyphens folded to
     * underscores, so formatting variants can never fall through to a
     * different arm than the caller meant.
     */
    private static function normalizedSslMode(string $sslMode): string
    {
        if ($sslMode === '' || strcasecmp($sslMode, 'disable') === 0) {
            return '';
        }

        return strtolower(str_replace('-', '_', $sslMode));
    }

    /** @return list<string> */
    public function tables(): array
    {
        if ($this->pdo === null) {
            return [];
        }

        $rows = $this->pdo->query(
            "SELECT table_name FROM information_schema.tables "
            . "WHERE table_schema = DATABASE() "
            . "AND table_type IN ('BASE TABLE', 'VIEW') "
            . "ORDER BY table_name",
        );

        if ($rows === false) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            // information_schema columns are uppercase on MySQL; normalize
            $row = array_change_key_case($row, CASE_LOWER);
            if (isset($row['table_name'])) {
                $out[] = (string) $row['table_name'];
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function rows(string $table, int $limit = 100, int $offset = 0): array
    {
        if ($this->pdo === null) {
            return [];
        }

        $sql = sprintf(
            'SELECT * FROM %s LIMIT %d OFFSET %d',
            Identifier::quote(Flavor::MySQL, $table),
            max(0, $limit),
            max(0, $offset),
        );
        $stmt = $this->pdo->query($sql);
        return $stmt === false ? [] : $stmt->fetchAll();
    }

    /**
     * Run a typed statement and materialise the complete result set.
     *
     * client-028 (honest bound): this is the full-fetch path by design —
     * exports and `affected` summaries need every row (SQL-C04's contract).
     * The QUERY display it feeds is bounded only at paint time: ResultTable
     * windows the viewport height (client-006) while the array behind it
     * holds the whole `SELECT * FROM huge_table`. A `SELECT` returning
     * unbounded rows therefore allocates unbounded memory before the first
     * painted row; add your own LIMIT until the bounded-display leg lands.
     *
     * @return list<array<string,mixed>>|null null when the handle is gone
     */
    public function query(string $sql): array|null
    {
        if ($this->pdo === null) {
            return null;
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();

            if ($stmt->columnCount() > 0) {
                return $stmt->fetchAll();
            }

            return [['affected' => $stmt->rowCount()]];
        } catch (\PDOException $e) {
            if ($this->reconnectManager !== null && $this->reconnectManager->shouldReconnect($e)) {
                $this->pdo = null;
                $reconnected = $this->reconnectManager->attemptReconnect(
                    fn () => $this->reconnect(),
                );
                if ($reconnected) {
                    $this->reconnectManager->setConnectionConfig($this->connectionConfig);
                    // Reset sampler to clear state after server restart
                    $this->sampler?->resetAll();
                    // Track uptime to detect future restarts
                    $this->trackUptimeAfterReconnect();
                    return null;
                }
                throw new ReconnectException(
                    'Failed to reconnect after connection error: ' . $e->getMessage(),
                    $e,
                );
            }
            throw $e;
        }
    }

    public function lastInsertId(): string|int
    {
        if ($this->pdo === null) {
            return 0;
        }

        return $this->pdo->lastInsertId();
    }

    public function quote(string $value): string
    {
        if ($this->pdo === null) {
            throw new \RuntimeException('Cannot quote without connection');
        }

        return $this->pdo->quote($value);
    }

    public function exec(string $sql): int
    {
        if ($this->pdo === null) {
            return 0;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function close(): void
    {
        $this->pdo = null;
        $this->connectionConfig = null;
    }

    public function serverVersion(): string
    {
        if ($this->pdo === null) {
            return 'MySQL version unknown';
        }

        $result = $this->pdo->query('SELECT VERSION() as ver');
        if ($result === false) {
            return 'MySQL version unknown';
        }

        $row = $result->fetch();
        $version = $row['ver'] ?? 'unknown';

        return 'MySQL version ' . $version;
    }

    public function driverName(): string
    {
        return 'mysql';
    }

    public function ping(): bool
    {
        if ($this->pdo === null) {
            return false;
        }

        try {
            $result = $this->pdo->query('SELECT 1');
            return $result !== false;
        } catch (\PDOException) {
            return false;
        }
    }

    /** @return list<string> */
    public function databases(): array
    {
        if ($this->pdo === null) {
            return [];
        }

        $rows = $this->pdo->query(
            "SELECT schema_name FROM information_schema.schemata "
            . "WHERE schema_name NOT IN ('information_schema','mysql','performance_schema','sys') "
            . "ORDER BY schema_name",
        );

        if ($rows === false) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['schema_name'])) {
                $out[] = (string) $row['schema_name'];
            }
        }
        return $out;
    }

    public function prepare(string $sql): ?PreparedStatementInterface
    {
        if ($this->pdo === null) {
            return null;
        }

        return new PdoPreparedStatement($this->pdo->prepare($sql));
    }

    /**
     * Get the last known server uptime.
     *
     * Returns null if uptime has not been tracked yet.
     */
    public function lastUptime(): ?float
    {
        return $this->lastUptime;
    }

    /**
     * Update the tracked server uptime.
     *
     * Called after fetching SHOW GLOBAL STATUS to detect server restarts.
     */
    public function trackUptime(?float $uptime): void
    {
        $this->lastUptime = $uptime;
    }

    /**
     * Inject a ReconnectManager for connection error handling.
     */
    public function setReconnectManager(ReconnectManagerInterface $reconnectManager): void
    {
        $this->reconnectManager = $reconnectManager;
    }

    /**
     * Inject a Sampler for reset signaling after server restarts.
     */
    public function setSampler(SamplerInterface $sampler): void
    {
        $this->sampler = $sampler;
    }

    /**
     * Attempt to reconnect using the stored connection config.
     *
     * @return DatabaseInterface|false Returns a new connection or false on failure
     */
    private function reconnect(): DatabaseInterface|false
    {
        if ($this->connectionConfig === null) {
            return false;
        }

        $config = $this->connectionConfig;

        try {
            $pdo = new \PDO(
                $config->dsn,
                $config->user,
                $config->pass,
                self::pdoOptions($config->sslMode),
            );
            $this->pdo = $pdo;
            return $this;
        } catch (\PDOException) {
            $this->pdo = null;
            return false;
        }
    }

    /**
     * Track uptime after a successful reconnect to detect future restarts.
     */
    private function trackUptimeAfterReconnect(): void
    {
        if ($this->pdo === null) {
            return;
        }

        try {
            $result = $this->pdo->query('SHOW GLOBAL STATUS LIKE "Uptime"');
            if ($result !== false) {
                $row = $result->fetch();
                if ($row !== false && isset($row['Value'])) {
                    $this->lastUptime = (float) $row['Value'];
                }
            }
        } catch (\PDOException) {
            // Silently ignore - uptime tracking is best-effort
        }
    }

    public function dsn(): string
    {
        return $this->connectionConfig?->dsn ?? '';
    }

    public function username(): string
    {
        return $this->connectionConfig?->user ?? '';
    }

    /**
     * WHY this exists: ServerContext::password() delegates here through a
     * method_exists() guard, and App's async admin fetch authenticates with it.
     * Audit commit c22d8d31f deleted this as "unused" — static analysis missed
     * the dynamic call, so every async admin query silently sent an empty
     * password ("using password: NO") and the dashboard stayed empty.
     */
    public function password(): string
    {
        return $this->connectionConfig?->pass ?? '';
    }

    /**
     * The ssl-mode this connection was opened with ('' before connect()).
     * App reads it to decide whether the async transport must stand down —
     * see {@see self::requiresVerifiedTls()}.
     */
    public function sslMode(): string
    {
        return $this->connectionConfig?->sslMode ?? '';
    }

}
