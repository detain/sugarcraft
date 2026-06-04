<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Connections;

/**
 * Value object holding a single processlist row.
 *
 * Carries both PROCESSLIST_ID (connection id, used by KILL) and THREAD_ID
 * (internal PS id, used for instrumentation/MDL queries).  Truncates
 * PROCESSLIST_INFO at 512 chars to prevent terminal overflow from huge
 * queries. Background detection uses threads.TYPE (FOREGROUND/BACKGROUND)
 * rather than the NULL/empty user heuristic.
 *
 * @see Mirrors charmbracelet/lazysql processlist row
 */
final class ProcesslistResult
{
    private const MAX_INFO_LEN = 512;

    /**
     * @param int|string $processId    PROCESSLIST_ID (connection id, used by KILL)
     * @param int|string $threadId     THREAD_ID (internal PS id, used for instrumentation/MDL)
     * @param string     $user         PROCESSLIST_USER
     * @param string     $host         PROCESSLIST_HOST
     * @param string     $database     PROCESSLIST_DB (empty string if NULL)
     * @param string     $command      PROCESSLIST_COMMAND
     * @param int        $time         PROCESSLIST_TIME in seconds
     * @param string     $state        PROCESSLIST_STATE (empty string if NULL)
     * @param string     $info         PROCESSLIST_INFO (truncated at 512 chars; empty if NULL)
     * @param string     $connectionAttr PROCESSLIST_ATTRS from session_connect_attrs (may be empty)
     * @param bool       $isPS         True when row came from performance_schema (vs SHOW PROCESSLIST)
     * @param bool       $isBackground True when thread TYPE is BACKGROUND
     * @param bool       $infoTruncated True when $info was truncated from the original value
     */
    public function __construct(
        public readonly int|string $processId,
        public readonly int|string $threadId,
        public readonly string $user,
        public readonly string $host,
        public readonly string $database,
        public readonly string $command,
        public readonly int $time,
        public readonly string $state,
        public readonly string $info,
        public readonly string $connectionAttr,
        public readonly bool $isPS,
        public readonly bool $isBackground,
        public readonly bool $infoTruncated,
    ) {}

    /**
     * Create from a performance_schema.threads row.
     *
     * PS rows provide both PROCESSLIST_ID (for KILL) and THREAD_ID (for
     * instrumentation/MDL). Background detection uses TYPE column.
     *
     * @param array<string, mixed> $row
     */
    public static function fromPSRow(array $row): self
    {
        $type = strtoupper((string) ($row['TYPE'] ?? ''));
        $isBackground = $type === 'BACKGROUND';
        $info = (string) ($row['PROCESSLIST_INFO'] ?? '');
        $infoTruncated = \mb_strlen($info) > self::MAX_INFO_LEN;

        return new self(
            processId: self::parseInt($row['PROCESSLIST_ID'] ?? null),
            threadId: self::parseInt($row['THREAD_ID'] ?? null),
            user: (string) ($row['PROCESSLIST_USER'] ?? ''),
            host: (string) ($row['PROCESSLIST_HOST'] ?? ''),
            database: (string) ($row['PROCESSLIST_DB'] ?? ''),
            command: (string) ($row['PROCESSLIST_COMMAND'] ?? ''),
            time: self::parseInt($row['PROCESSLIST_TIME'] ?? null),
            state: (string) ($row['PROCESSLIST_STATE'] ?? ''),
            info: self::truncateInfo($info),
            connectionAttr: (string) ($row['PROCESSLIST_ATTRS'] ?? ''),
            isPS: true,
            isBackground: $isBackground,
            infoTruncated: $infoTruncated,
        );
    }

    /**
     * Create from SHOW FULL PROCESSLIST row.
     *
     * SHOW PROCESSLIST does not provide THREAD_ID, so threadId mirrors
     * processId. Background detection falls back to empty/NULL user.
     *
     * @param array<string, mixed> $row
     */
    public static function fromShowProcesslist(array $row): self
    {
        $user = (string) ($row['User'] ?? '');
        $isBackground = $user === '' || $user === 'NULL';
        $info = (string) ($row['Info'] ?? '');
        $infoTruncated = \mb_strlen($info) > self::MAX_INFO_LEN;

        return new self(
            processId: self::parseInt($row['Id'] ?? null),
            threadId: self::parseInt($row['Id'] ?? null), // not available from SHOW PROCESSLIST
            user: $user,
            host: (string) ($row['Host'] ?? ''),
            database: (string) ($row['db'] ?? ''),
            command: (string) ($row['Command'] ?? ''),
            time: self::parseInt($row['Time'] ?? null),
            state: (string) ($row['State'] ?? ''),
            info: self::truncateInfo($info),
            connectionAttr: '',
            isPS: false,
            isBackground: $isBackground,
            infoTruncated: $infoTruncated,
        );
    }

    /**
     * Create from a normalized Postgres processlist row.
     *
     * Accepts the normalized array format returned by AdminProviderInterface::fetchProcesslist()
     * for PostgreSQL connections (already transformed from pg_stat_activity columns).
     *
     * @param array<string, mixed> $row Normalized row with keys:
     *   processId, user, host, database, command, time, state, info, connectionAttr
     */
    public static function fromPostgresRow(array $row): self
    {
        $connectionAttr = '';
        if (isset($row['connectionAttr']) && is_array($row['connectionAttr'])) {
            $connectionAttr = implode(' ', $row['connectionAttr']);
        } elseif (isset($row['connectionAttr']) && is_string($row['connectionAttr'])) {
            $connectionAttr = $row['connectionAttr'];
        }

        $user = (string) ($row['user'] ?? '');
        $isBackground = $user === '' || $user === 'NULL';
        $info = (string) ($row['info'] ?? '');
        $infoTruncated = \mb_strlen($info) > self::MAX_INFO_LEN;

        return new self(
            processId: self::parseInt($row['processId'] ?? null),
            threadId: self::parseInt($row['processId'] ?? null), // Postgres uses pid as the kill target
            user: $user,
            host: (string) ($row['host'] ?? ''),
            database: (string) ($row['database'] ?? ''),
            command: (string) ($row['command'] ?? 'unknown'),
            time: (int) ($row['time'] ?? 0),
            state: ($row['state'] ?? null) !== null ? (string) $row['state'] : '',
            info: self::truncateInfo($info),
            connectionAttr: $connectionAttr,
            isPS: false,
            isBackground: $isBackground,
            infoTruncated: $infoTruncated,
        );
    }

    /**
     * @deprecated Use the $isBackground property directly instead.
     *
     * True when this is a background/system thread.
     */
    public function isBackground(): bool
    {
        return $this->isBackground;
    }

    private static function parseInt(mixed $val): int
    {
        if ($val === null || $val === '') {
            return 0;
        }
        if (\is_int($val)) {
            return $val;
        }
        return (int) $val;
    }

    private static function truncateInfo(string $info): string
    {
        if ($info === '' || \mb_strlen($info) <= self::MAX_INFO_LEN) {
            return $info;
        }
        return \mb_substr($info, 0, self::MAX_INFO_LEN);
    }
}
