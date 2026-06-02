<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Connections;

/**
 * Value object holding a single processlist row.
 *
 * Truncates PROCESSLIST_INFO at 512 chars to prevent terminal overflow
 * from huge queries. The full info is preserved in the raw data if needed.
 *
 * @see Mirrors charmbracelet/lazysql processlist row
 */
final class ProcesslistResult
{
    private const MAX_INFO_LEN = 512;

    /**
     * @param int|string $processId    PROCESSLIST_ID from performance_schema or Id from SHOW PROCESSLIST
     * @param string     $user         PROCESSLIST_USER
     * @param string     $host         PROCESSLIST_HOST
     * @param string     $database    PROCESSLIST_DB (empty string if NULL)
     * @param string     $command     PROCESSLIST_COMMAND
     * @param int        $time        PROCESSLIST_TIME in seconds
     * @param string     $state       PROCESSLIST_STATE (empty string if NULL)
     * @param string     $info        PROCESSLIST_INFO (truncated at 512 chars; empty if NULL)
     * @param string     $connectionAttr PROCESSLIST_ATTRS from session_connect_attrs (may be empty)
     * @param bool       $isPS        True when row came from performance_schema (vs SHOW PROCESSLIST)
     */
    public function __construct(
        public readonly int|string $processId,
        public readonly string $user,
        public readonly string $host,
        public readonly string $database,
        public readonly string $command,
        public readonly int $time,
        public readonly string $state,
        public readonly string $info,
        public readonly string $connectionAttr,
        public readonly bool $isPS,
    ) {}

    /**
     * Create from a performance_schema.threads row.
     *
     * @param array<string, mixed> $row
     */
    public static function fromPSRow(array $row): self
    {
        return new self(
            processId: self::parseInt($row['PROCESSLIST_ID'] ?? $row['THREAD_ID'] ?? 0),
            user: (string) ($row['PROCESSLIST_USER'] ?? ''),
            host: (string) ($row['PROCESSLIST_HOST'] ?? ''),
            database: (string) ($row['PROCESSLIST_DB'] ?? ''),
            command: (string) ($row['PROCESSLIST_COMMAND'] ?? ''),
            time: self::parseInt($row['PROCESSLIST_TIME'] ?? 0),
            state: (string) ($row['PROCESSLIST_STATE'] ?? ''),
            info: self::truncateInfo((string) ($row['PROCESSLIST_INFO'] ?? '')),
            connectionAttr: (string) ($row['PROCESSLIST_ATTRS'] ?? ''),
            isPS: true,
        );
    }

    /**
     * Create from SHOW FULL PROCESSLIST row.
     *
     * @param array<string, mixed> $row
     */
    public static function fromShowProcesslist(array $row): self
    {
        return new self(
            processId: self::parseInt($row['Id'] ?? 0),
            user: (string) ($row['User'] ?? ''),
            host: (string) ($row['Host'] ?? ''),
            database: (string) ($row['db'] ?? ''),
            command: (string) ($row['Command'] ?? ''),
            time: self::parseInt($row['Time'] ?? 0),
            state: (string) ($row['State'] ?? ''),
            info: self::truncateInfo((string) ($row['Info'] ?? '')),
            connectionAttr: '',
            isPS: false,
        );
    }

    /**
     * True when this is a background/system thread (user is NULL or empty).
     */
    public function isBackground(): bool
    {
        return $this->user === '' || $this->user === 'NULL';
    }

    /**
     * True when the info field was truncated.
     */
    public function infoTruncated(): bool
    {
        return \strlen($this->info) >= self::MAX_INFO_LEN;
    }

    private static function parseInt(mixed $val): int
    {
        if (\is_int($val)) {
            return $val;
        }
        return (int) ($val ?? 0);
    }

    private static function truncateInfo(string $info): string
    {
        if ($info === '' || \strlen($info) <= self::MAX_INFO_LEN) {
            return $info;
        }
        return \substr($info, 0, self::MAX_INFO_LEN);
    }
}
